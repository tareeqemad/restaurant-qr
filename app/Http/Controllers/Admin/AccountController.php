<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\AccountService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Accountant-facing chart-of-accounts management.
 *
 * Gated by the new `chart_of_accounts.*` permission group (seeded by
 * a migration so existing installs get it). System accounts (seeded
 * by canonical migrations) are guarded by AccountService — the UI
 * surfaces locks for the protected fields but the service enforces
 * even if a hand-crafted POST tries to bypass them.
 */
class AccountController extends Controller
{
    public function __construct(protected AccountService $service) {}

    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        // Load the full chart in a SINGLE query. The view renders the
        // tree by walking an in-memory map keyed by parent_account_id,
        // so there's no N+1 even with 100+ accounts.
        $allAccounts = Account::orderBy('type')
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();

        return view('admin.accounts.index', compact('allAccounts'));
    }

    public function create(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        // The "+" button on a tree node deep-links here with
        // ?parent=<id> so the form opens pre-scoped under that parent.
        // We hand the parent object to the form so it can pre-pick the
        // matching type/normal_balance defaults too.
        $parentId = $request->get('parent');
        $parent   = $parentId ? Account::find($parentId) : null;

        return view('admin.accounts.create', [
            'parentOptions' => Account::orderBy('code')->get(),
            'prefilledParent' => $parent,
        ]);
    }

    /**
     * Return the account's data as JSON for the edit modal. Powers the
     * GFC-style "tree → click → edit modal" flow. Includes the parent's
     * label so the modal can show "تحت 5100 — تشغيلية" without a second
     * round-trip.
     */
    public function showJson(Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $account->loadMissing('parent');

        return response()->json([
            'id'                => $account->id,
            'code'              => $account->code,
            'name'              => $account->name,
            'description'       => $account->description,
            'type'              => $account->type,
            'normal_balance'    => $account->normal_balance,
            'parent_account_id' => $account->parent_account_id,
            'parent_label'      => $account->parent ? "{$account->parent->code} — {$account->parent->name}" : null,
            'is_active'         => (bool) $account->is_active,
            'is_system'         => (bool) $account->is_system,
            'display_order'     => (int) $account->display_order,
            'has_journal_lines' => $account->hasJournalLines(),
            'has_children'      => $account->children()->exists(),
            'is_deletable'      => $account->isDeletable(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        try {
            $account = $this->service->create($request->all());
            // The tree UI POSTs via fetch() — return JSON so the JS can
            // re-render the new node in place without a full page reload.
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => true,
                    'message' => "تم إنشاء الحساب «{$account->code} — {$account->name}».",
                    'account' => $account,
                ]);
            }
            return redirect()->route('admin.accounts.index')
                ->with('success', "تم إنشاء الحساب «{$account->code} — {$account->name}».");
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
            }
            return back()->withErrors($e->errors())->withInput();
        }
    }

    public function edit(Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        return view('admin.accounts.edit', [
            'account'         => $account->load('parent', 'children'),
            'parentOptions'   => Account::where('id', '!=', $account->id)
                                    ->orderBy('code')->get(),
            'prefilledParent' => null,    // edit doesn't use prefill — form has the saved values
        ]);
    }

    public function update(Request $request, Account $account)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        try {
            $this->service->update($account, $request->all());
            if ($request->wantsJson() || $request->ajax()) {
                $fresh = $account->fresh();
                return response()->json([
                    'ok' => true,
                    'message' => "تم تحديث الحساب «{$fresh->code} — {$fresh->name}».",
                    'account' => $fresh,
                ]);
            }
            return redirect()->route('admin.accounts.index')
                ->with('success', "تم تحديث الحساب «{$account->code} — {$account->name}».");
        } catch (ValidationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
            }
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
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => true, 'message' => 'تم حذف الحساب.']);
            }
            return redirect()->route('admin.accounts.index')->with('success', "تم حذف الحساب.");
        } catch (ValidationException $e) {
            $msg = $e->errors()['delete'][0] ?? 'تعذّر حذف الحساب.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }
    }
}
