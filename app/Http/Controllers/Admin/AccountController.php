<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalLine;
use App\Services\Accounting\AccountService;
use App\Support\AccountingWorkspace;
use App\Support\AdminShell;
use App\Support\TaxConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Accountant-facing chart-of-accounts management.
 *
 * Gated by the `chart_of_accounts.*` permission group (seeded by a
 * migration so existing installs get it). System accounts (seeded by
 * canonical migrations) are guarded by AccountService — the UI surfaces
 * locks for the protected fields but the service enforces them even if a
 * hand-crafted POST tries to bypass them.
 *
 * Wave 6 (MIGRATION-PILOT.md §13): the three read actions render Inertia
 * pages. Everything the old Blade computed inside the template — the tree
 * assembly, the type sections, the per-node protection flags, every route
 * string — is computed here now, so the client renders and never decides.
 *
 * The write actions no longer answer JSON. @inertiajs/core sends
 * `X-Requested-With: XMLHttpRequest` on every visit, so the old
 * `$request->ajax()` branches would have answered an Inertia POST with a
 * bare JSON body and broken every write. Their only consumer was the
 * fetch() JS inside the deleted Blade.
 */
class AccountController extends Controller
{
    /** Fixed section order + chrome for the six account types. */
    private const TYPE_META = [
        'asset' => ['label' => 'الأصول',          'icon' => 'bi-bank2'],
        'liability' => ['label' => 'الالتزامات',      'icon' => 'bi-file-earmark-x'],
        'equity' => ['label' => 'حقوق الملكية',    'icon' => 'bi-shield-check'],
        'revenue' => ['label' => 'الإيرادات',       'icon' => 'bi-graph-up-arrow'],
        'contra_revenue' => ['label' => 'مقابلات الإيراد', 'icon' => 'bi-arrow-down-right'],
        'expense' => ['label' => 'المصاريف',        'icon' => 'bi-graph-down'],
    ];

    /** Long labels — the type dropdown. Mirrors Account::typeLabel(). */
    private const TYPE_LABELS = [
        'asset' => 'أصل',
        'liability' => 'التزام',
        'equity' => 'حقوق ملكية',
        'revenue' => 'إيراد',
        'contra_revenue' => 'مقابل إيراد',
        'expense' => 'مصروف',
    ];

    /** Short labels — parent dropdown suffix only. Deliberately NOT the long set. */
    private const TYPE_SHORT_LABELS = [
        'asset' => 'أصل',
        'liability' => 'التزام',
        'equity' => 'حقوق',
        'revenue' => 'إيراد',
        'contra_revenue' => 'مقابل',
        'expense' => 'مصروف',
    ];

    /**
     * AccountService::expectedNormalBalanceForType, mirrored for the client.
     * The form binds normal_balance to type from this map so the accountant
     * cannot build a combination the service will reject with a 422.
     */
    private const BALANCE_BY_TYPE = [
        'asset' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'revenue' => 'credit',
        'contra_revenue' => 'debit',
        'expense' => 'debit',
    ];

    public function __construct(protected AccountService $service) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('chart_of_accounts.viewAny'), 403);

        $can = [
            'create' => (bool) $user->hasPermission('chart_of_accounts.create'),
            'update' => (bool) $user->hasPermission('chart_of_accounts.update'),
            'delete' => (bool) $user->hasPermission('chart_of_accounts.delete'),
        ];

        // Load the full chart in a SINGLE query. The tree below is walked
        // from an in-memory map keyed by parent_account_id, so there's no
        // N+1 even with 100+ accounts.
        $allAccounts = Account::query()
            ->whereNotIn('id', TaxConfiguration::operationallyHiddenAccountIds())
            ->orderBy('type')
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();

        // Bulk protection facts. Account::isDeletable() costs TWO queries
        // per row (lines()->exists() + children()->count()); on a 200-node
        // chart that is 400 queries. These three flat lookups replace all
        // of it and are deliberately computed over the WHOLE table —
        // isDeletable must see a tax-hidden child too, or the client would
        // offer a delete the service refuses.
        $postedIds = JournalLine::query()->select('account_id')->distinct()
            ->pluck('account_id')->map(fn ($id) => (int) $id)->flip();
        $parentsWithChildren = Account::query()->whereNotNull('parent_account_id')
            ->distinct()->pluck('parent_account_id')->map(fn ($id) => (int) $id)->flip();
        // Parent labels come from the whole table on purpose: a tax-hidden
        // parent is absent from $allAccounts, and its children must still
        // show «تحت الحساب الأب».
        $labels = Account::query()->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($a) => [(int) $a->id => "{$a->code} — {$a->name}"]);

        $byParent = $allAccounts->groupBy(fn ($a) => (int) ($a->parent_account_id ?? 0));

        $build = function (Account $a) use (&$build, $byParent, $postedIds, $parentsWithChildren, $labels, $can): array {
            // NOTE: children sort by display_order THEN code, while the type
            // sections below sort their roots by code ALONE. That asymmetry
            // is what the Blade did; it is reproduced, not fixed.
            $children = $byParent->get((int) $a->id, collect())
                ->sortBy([['display_order', 'asc'], ['code', 'asc']]);

            $hasJournalLines = $postedIds->has((int) $a->id);
            $isDeletable = ! $a->isProtected()
                && ! $hasJournalLines
                && ! $parentsWithChildren->has((int) $a->id);

            $balanceLabel = $a->normalBalanceLabel();
            $parentId = $a->parent_account_id ? (int) $a->parent_account_id : null;

            return [
                'id' => (int) $a->id,
                'code' => (string) $a->code,
                'name' => (string) $a->name,
                'description' => $a->description,
                'type' => $a->type,
                'typeLabel' => $a->typeLabel(),
                // Read from the STORED column, never derived from type — a
                // protected contra-asset (asset + credit) must print «دائن».
                'normalBalance' => $a->normal_balance,
                'normalBalanceLabel' => $balanceLabel,
                'parentId' => $parentId,
                'parentLabel' => $parentId ? ($labels[$parentId] ?? null) : null,
                'isActive' => (bool) $a->is_active,
                'isSystem' => $a->isProtected(),
                'displayOrder' => (int) $a->display_order,
                'hasJournalLines' => $hasJournalLines,
                'hasChildren' => $children->isNotEmpty(),
                'isDeletable' => $isDeletable,
                'title' => "{$a->code} — {$a->name}"
                    .($a->description ? ' • '.$a->description : ''),
                // The search haystack is the whole row text, exactly as the
                // Blade read span.textContent — badges included.
                'searchText' => "{$a->code} {$a->name} · {$balanceLabel}"
                    .($a->isProtected() ? ' نظامي' : '')
                    .($a->is_active ? '' : ' معطّل'),
                'urls' => [
                    'edit' => route('admin.accounts.edit', $a->id),
                    'update' => route('admin.accounts.update', $a->id),
                    'toggle' => route('admin.accounts.toggle', $a->id),
                    'destroy' => route('admin.accounts.destroy', $a->id),
                    'ledger' => route('admin.accounting.ledger').'?account_id='.$a->id,
                ],
                'can' => [
                    'update' => $can['update'],
                    // Mirrors AccountPolicy::delete — permission AND deletable.
                    'delete' => $can['delete'] && $isDeletable,
                ],
                'children' => $children->map(fn (Account $child) => $build($child))->values()->all(),
            ];
        };

        $roots = $byParent->get(0, collect());
        $sections = [];
        foreach (self::TYPE_META as $type => $meta) {
            $typeRoots = $roots->where('type', $type);
            if ($typeRoots->isEmpty()) {
                continue;
            }
            $sections[] = [
                'type' => $type,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'activeRootCount' => $typeRoots->where('is_active', true)->count(),
                'totalRootCount' => $typeRoots->count(),
                'searchText' => $meta['label'],
                'nodes' => $typeRoots->sortBy('code')
                    ->map(fn (Account $root) => $build($root))->values()->all(),
            ];
        }

        return AdminShell::render('Admin/Accounts/Index', [
            'sections' => $sections,
            'stats' => [
                'active' => $allAccounts->where('is_active', true)->count(),
                'inactive' => $allAccounts->where('is_active', false)->count(),
                'system' => $allAccounts->where('is_system', true)->count(),
                'total' => $allAccounts->count(),
            ],
            'can' => $can,
            // Every code in the table, not just the rendered ones. The old
            // JS read codes out of the DOM and could therefore suggest a
            // code already taken by a tax-hidden account — an unexplainable
            // 422. Deliberate deviation.
            'existingCodes' => Account::query()->pluck('code')->map(fn ($c) => (string) $c)->values()->all(),
            'urls' => [
                'index' => route('admin.accounts.index'),
                'store' => route('admin.accounts.store'),
                'create' => $can['create'] ? route('admin.accounts.create') : null,
                // The Blade rendered this link ungated while the same route
                // is permission-gated in the nav map. Gate it here too.
                'mappings' => $can['update'] ? route('admin.accounting.mappings') : null,
                'guide' => route('admin.accounting.guide').'#chart-guide',
                'ledger' => route('admin.accounting.ledger'),
                'workspace' => AccountingWorkspace::urls(),
            ],
        ] + $this->formOptions());
    }

    public function create(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        // The full-page form can be deep-linked with ?parent=<id> so it
        // opens pre-scoped under that parent (type + normal balance +
        // parent are pre-picked to match).
        $parentId = $request->get('parent');
        $parent = $parentId ? Account::find($parentId) : null;

        return AdminShell::render('Admin/Accounts/Create', [
            'account' => null,
            'prefilledParent' => $parent ? [
                'id' => (int) $parent->id,
                'code' => (string) $parent->code,
                'name' => (string) $parent->name,
                'type' => $parent->type,
                'normalBalance' => $parent->normal_balance,
            ] : null,
            'parentOptions' => $this->parentOptions(Account::orderBy('code')->get()),
            'urls' => [
                'store' => route('admin.accounts.store'),
                'index' => route('admin.accounts.index'),
                'workspace' => AccountingWorkspace::urls(),
            ],
        ] + $this->formOptions());
    }

    /**
     * Return the account's data as JSON. The tree modal used to fetch this;
     * the Vue tree already carries every field as a prop, so nothing in the
     * app calls it today. Kept because the route is public surface.
     */
    public function showJson(Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $account->loadMissing('parent');

        return response()->json([
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'description' => $account->description,
            'type' => $account->type,
            'normal_balance' => $account->normal_balance,
            'parent_account_id' => $account->parent_account_id,
            'parent_label' => $account->parent ? "{$account->parent->code} — {$account->parent->name}" : null,
            'is_active' => (bool) $account->is_active,
            'is_system' => (bool) $account->is_system,
            'display_order' => (int) $account->display_order,
            'has_journal_lines' => $account->hasJournalLines(),
            'has_children' => $account->children()->exists(),
            'is_deletable' => $account->isDeletable(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        try {
            $account = $this->service->create($request->all());

            return redirect()->route('admin.accounts.index')
                ->with('success', "تم إنشاء الحساب «{$account->code} — {$account->name}».");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    public function edit(Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $account->loadMissing('parent');
        $hasJournalLines = $account->hasJournalLines();
        $hasChildren = $account->children()->exists();

        return AdminShell::render('Admin/Accounts/Edit', [
            'account' => [
                'id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'description' => $account->description,
                'type' => $account->type,
                'typeLabel' => $account->typeLabel(),
                'normalBalance' => $account->normal_balance,
                'normalBalanceLabel' => $account->normalBalanceLabel(),
                'parentAccountId' => $account->parent_account_id ? (int) $account->parent_account_id : null,
                'parentLabel' => $account->parent
                    ? "{$account->parent->code} — {$account->parent->name}" : null,
                'isActive' => (bool) $account->is_active,
                'isSystem' => $account->isProtected(),
                'displayOrder' => (int) $account->display_order,
                'hasJournalLines' => $hasJournalLines,
                'hasChildren' => $hasChildren,
                'isDeletable' => ! $account->isProtected() && ! $hasJournalLines && ! $hasChildren,
            ],
            // Edit never prefills from ?parent= — the saved values win.
            'prefilledParent' => null,
            'parentOptions' => $this->parentOptions(
                Account::where('id', '!=', $account->id)->orderBy('code')->get()
            ),
            'urls' => [
                'update' => route('admin.accounts.update', $account->id),
                'index' => route('admin.accounts.index'),
                'workspace' => AccountingWorkspace::urls(),
            ],
        ] + $this->formOptions());
    }

    public function update(Request $request, Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        try {
            $this->service->update($account, $request->all());

            return redirect()->route('admin.accounts.index')
                ->with('success', "تم تحديث الحساب «{$account->code} — {$account->name}».");
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }
    }

    /**
     * Toggle the active flag in one click. The service refuses to
     * deactivate system accounts so the cashier flow keeps working.
     */
    public function toggle(Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        try {
            $this->service->setActive($account, ! $account->is_active);
            $verb = $account->fresh()->is_active ? 'تشغيل' : 'تعطيل';

            return back()->with('success', "تم {$verb} الحساب «{$account->code}».");
        } catch (ValidationException $e) {
            return back()->with('error', $e->errors()['is_active'][0] ?? 'تعذّر تغيير حالة الحساب.');
        }
    }

    public function destroy(Request $request, Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.delete'), 403);
        try {
            $this->service->delete($account);

            return redirect()->route('admin.accounts.index')->with('success', 'تم حذف الحساب.');
        } catch (ValidationException $e) {
            return back()->with('error', $e->errors()['delete'][0] ?? 'تعذّر حذف الحساب.');
        }
    }

    // ────────────────────────────────────────────────────────────────
    // Shared prop shapes
    // ────────────────────────────────────────────────────────────────

    /** The three option sets every account form (page or modal) needs. */
    private function formOptions(): array
    {
        return [
            'typeOptions' => collect(self::TYPE_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()->all(),
            'balanceOptions' => [
                ['value' => 'debit',  'label' => 'مدين'],
                ['value' => 'credit', 'label' => 'دائن'],
            ],
            'balanceByType' => self::BALANCE_BY_TYPE,
        ];
    }

    /**
     * Parent dropdown rows. Assemble the localized label in PHP so its
     * presentation stays out of the query and remains easy to change.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function parentOptions(Collection $accounts): array
    {
        return $accounts->map(fn (Account $a) => [
            'id' => (int) $a->id,
            'code' => (string) $a->code,
            'name' => (string) $a->name,
            'type' => $a->type,
            'typeShortLabel' => self::TYPE_SHORT_LABELS[$a->type] ?? $a->type,
            'label' => "{$a->code} — {$a->name} (".(self::TYPE_SHORT_LABELS[$a->type] ?? $a->type).')',
        ])->values()->all();
    }
}
