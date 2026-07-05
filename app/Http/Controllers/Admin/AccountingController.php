<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CashReconciliation;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lookup;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Order;
use App\Models\Shift;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\TableSession;
use App\Models\TaxJurisdiction;
use App\Enums\OrderStatus;
use App\Support\BranchContext;
use App\Support\MarketProfile;
use App\Services\Accounting\AccountingService;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function journal(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);

        $entriesQuery = $this->scopeToCurrentBranch(
            JournalEntry::query()->with(['branch', 'creator', 'lines.account'])
        )
            ->when($from, fn (Builder $query) => $query->whereDate('posted_on', '>=', $from))
            ->whereDate('posted_on', '<=', $to)
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $query, string $search) {
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('entry_no', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('posted_on')
            ->orderByDesc('id');

        $entries = $entriesQuery->paginate(20)->withQueryString();
        $reversedEntryIds = $this->reversedEntryIds();

        $eventTypes = $this->scopeToCurrentBranch(JournalEntry::query())
            ->whereNotNull('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type');

        return view('admin.accounting.journal', [
            'entries' => $entries,
            'eventTypes' => $eventTypes,
            'eventLabels' => $this->eventLabels(),
            'from' => $from,
            'to' => $to,
            'selectedEvent' => $filters['event_type'] ?? '',
            'search' => $filters['search'] ?? '',
            'reversedEntryIds' => $reversedEntryIds,
        ]);
    }

    public function exportJournalCsv(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'max:80'],
        ]);
        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $from ??= Carbon::parse($to)->startOfMonth()->toDateString();

        $query = $this->scopeToCurrentBranch(JournalEntry::query()->with('lines.account'))
            ->whereDate('posted_on', '>=', $from)
            ->whereDate('posted_on', '<=', $to)
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->orderBy('posted_on')
            ->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry_no', 'posted_on', 'event_type', 'entry_description', 'base_currency', 'account_code', 'account_name', 'line_description', 'debit', 'credit', 'currency', 'exchange_rate', 'foreign_debit', 'foreign_credit']);

            $query->chunk(100, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    foreach ($entry->lines as $line) {
                        fputcsv($out, [
                            $entry->entry_no,
                            optional($entry->posted_on)->toDateString(),
                            $entry->event_type,
                            $entry->description,
                            $entry->base_currency_code,
                            $line->account?->code,
                            $line->account?->name,
                            $line->description,
                            number_format((float) $line->debit, 4, '.', ''),
                            number_format((float) $line->credit, 4, '.', ''),
                            $line->currency_code,
                            number_format((float) $line->exchange_rate, 8, '.', ''),
                            number_format((float) $line->foreign_debit, 4, '.', ''),
                            number_format((float) $line->foreign_credit, 4, '.', ''),
                        ]);
                    }
                }
            });

            fclose($out);
        }, 'general-ledger-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function trialBalance(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // `1` = hide active accounts with zero movement (default — the
            // common case: a chart of 20+ accounts where only 6 actually
            // moved this period). `0` shows the full chart for auditors
            // who want to confirm coverage.
            'show_empty' => ['nullable', 'boolean'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);
        $showEmpty = $request->boolean('show_empty');

        $lineQuery = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        if ($branchId = BranchContext::current()) {
            $lineQuery->where('journal_entries.branch_id', $branchId);
        }

        $movements = $lineQuery
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->get()
            ->keyBy('account_id');

        // Two pools: active accounts (always considered for display),
        // and inactive accounts that HAVE activity (so the totals still
        // match what's in the journal — silently dropping an inactive
        // account that someone posted to would unbalance the trial).
        $activeAccounts = Account::query()->where('is_active', true)->get();
        $inactiveWithMovement = Account::query()
            ->where('is_active', false)
            ->whereIn('id', $movements->keys())
            ->get();
        $allAccounts = $activeAccounts->concat($inactiveWithMovement)
            ->sortBy('code')
            ->values();

        $accounts = $allAccounts->map(function (Account $account) use ($movements) {
            $movement = $movements->get($account->id);
            $debit = round((float) ($movement?->debit ?? 0), 4);
            $credit = round((float) ($movement?->credit ?? 0), 4);
            $net = round($debit - $credit, 4);

            $account->movement_debit = $debit;
            $account->movement_credit = $credit;
            $account->balance_debit = max($net, 0);
            $account->balance_credit = max(-$net, 0);
            $account->is_zero = abs($debit) < 0.0001 && abs($credit) < 0.0001;

            return $account;
        });

        // Totals are computed BEFORE the empty-row filter so the bottom
        // row always matches the journal — hiding visual zeros must not
        // change the math.
        $totalMovementDebit = round((float) $accounts->sum('movement_debit'), 4);
        $totalMovementCredit = round((float) $accounts->sum('movement_credit'), 4);
        $totalBalanceDebit = round((float) $accounts->sum('balance_debit'), 4);
        $totalBalanceCredit = round((float) $accounts->sum('balance_credit'), 4);

        $visibleAccounts = $showEmpty
            ? $accounts
            : $accounts->filter(fn ($a) => ! $a->is_zero)->values();

        return view('admin.accounting.trial-balance', [
            'accounts'            => $visibleAccounts,
            'totalAccountsInChart'=> $accounts->count(),
            'hiddenZeroCount'     => $accounts->count() - $visibleAccounts->count(),
            'activeAccountsCount' => $accounts->filter(fn ($a) => ! $a->is_zero)->count(),
            'from'                => $from,
            'to'                  => $to,
            'showEmpty'           => $showEmpty,
            'typeLabels'          => $this->typeLabels(),
            'normalBalanceLabels' => ['debit' => 'مدين', 'credit' => 'دائن'],
            'totalMovementDebit'  => $totalMovementDebit,
            'totalMovementCredit' => $totalMovementCredit,
            'totalBalanceDebit'   => $totalBalanceDebit,
            'totalBalanceCredit'  => $totalBalanceCredit,
            'isBalanced'          => abs($totalBalanceDebit - $totalBalanceCredit) < 0.01,
        ]);
    }

    private function scopeToCurrentBranch(Builder $query): Builder
    {
        if ($branchId = BranchContext::current()) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function dateRange(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from)->toDateString() : null;
        $toDate = Carbon::parse($to ?: now())->toDateString();

        if ($fromDate && $fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [$fromDate, $toDate];
    }

    private function eventLabels(): array
    {
        return [
            'tax_payment' => 'سداد ضريبة',
            'tips_payout' => 'صرف إكراميات',
            'payment_clearing_settlement' => 'تسوية بوابة دفع',
            'fixed_asset_acquired' => 'شراء أصل ثابت',
            'fixed_asset_depreciation' => 'إهلاك أصل ثابت',
            'fixed_asset_disposal' => 'استبعاد أصل ثابت',
            'invoice_issued' => 'إصدار فاتورة',
            'invoice_cancelled' => 'إلغاء فاتورة',
            'payment_received' => 'تحصيل دفعة',
            'invoice_writeoff' => 'شطب ذمة',
            'refund_completed' => 'استرداد مكتمل',
            'expense_approved' => 'اعتماد مصروف',
            'supplier_invoice_created' => 'فاتورة مورد',
            'supplier_invoice_cancelled' => 'إلغاء فاتورة مورد',
            'supplier_payment_recorded' => 'دفعة لمورد',
            'opening_balance' => 'أرصدة افتتاحية',
            'period_closing' => 'إقفال فترة',
            'period_closing_reversal' => 'عكس إقفال فترة',
            'fiscal_year_closing' => 'إقفال سنة مالية',
            'fiscal_year_closing_reversal' => 'عكس إقفال سنة مالية',
        ];
    }

    private function typeLabels(): array
    {
        return [
            'asset' => 'أصل',
            'liability' => 'التزام',
            'equity' => 'حقوق ملكية',
            'revenue' => 'إيراد',
            'contra_revenue' => 'تخفيض إيراد',
            'expense' => 'مصروف',
        ];
    }

    // ───────────────────────────────────────────────────────────────
    // Manual journal entry — the bridge that gives custom accounts
    // actual purpose. Without this, an accountant adds a new account
    // to the chart but nothing in the operational flow ever writes
    // to it. With this, they can post DR/CR pairs to any account
    // they want (subject to active-status validation).
    // ───────────────────────────────────────────────────────────────

    public function createManualEntry()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        // Only ACTIVE accounts can be posted to. System inactives are
        // weeded out so the dropdown doesn't tempt accountants into
        // bringing a deactivated account back into the books by accident.
        $accounts = Account::where('is_active', true)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return view('admin.accounting.manual-entry', [
            'accounts' => $accounts,
            'currencies' => $this->accountingCurrencies(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
        ]);
    }

    public function storeManualEntry(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        if ($dupe = $this->duplicateSubmitGuard($request)) return $dupe;

        $data = $request->validate([
            'posted_on'   => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines'       => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lines' => 'سطور القيد',
        ]);

        // Normalise: empty numbers → 0; verify each line has EITHER
        // debit OR credit (never both, never neither).
        try {
            $lines = collect($data['lines'])->map(function ($line) use ($data) {
                $currencyCode = $this->normalizeCurrencyCode($line['currency_code'] ?? $this->accountingBaseCurrencyCode());
                $exchangeRate = (float) ($line['exchange_rate'] ?? ($currencyCode === $this->accountingBaseCurrencyCode() ? 1 : $this->configuredExchangeRate($currencyCode, $data['posted_on'])));
                $hasForeignAmounts = array_key_exists('foreign_debit', $line) || array_key_exists('foreign_credit', $line);
                $foreignDebit = round((float) ($line['foreign_debit'] ?? 0), 4);
                $foreignCredit = round((float) ($line['foreign_credit'] ?? 0), 4);
                $debit = $hasForeignAmounts ? round($foreignDebit * $exchangeRate, 4) : round((float) ($line['debit'] ?? 0), 4);
                $credit = $hasForeignAmounts ? round($foreignCredit * $exchangeRate, 4) : round((float) ($line['credit'] ?? 0), 4);

                return [
                    'account'     => (int) $line['account_id'],
                    'debit'       => $debit,
                    'credit'      => $credit,
                    'currency_code' => $currencyCode,
                    'exchange_rate' => $exchangeRate,
                    'foreign_debit' => $hasForeignAmounts ? $foreignDebit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $debit : round($debit / $exchangeRate, 4)),
                    'foreign_credit' => $hasForeignAmounts ? $foreignCredit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $credit : round($credit / $exchangeRate, 4)),
                    'description' => $line['description'] ?? null,
                ];
            })->filter(fn ($l) => $l['debit'] > 0.0001 || $l['credit'] > 0.0001);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($lines->count() < 2) {
            return back()->withInput()->with('error',
                'يلزم على الأقل سطرين (مدين + دائن) للقيد.');
        }

        foreach ($lines as $i => $l) {
            if ($l['debit'] > 0.0001 && $l['credit'] > 0.0001) {
                return back()->withInput()->with('error',
                    'سطر #'.($i+1).': لا يمكن أن يكون مديناً ودائناً في نفس الوقت — اختر واحداً.');
            }
        }

        // Convert to AccountingService::post() shape — we look up the
        // account code from the id (post() uses code as the address).
        $accountIds = $lines->pluck('account')->unique()->all();
        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');
        $inactiveAccounts = $accounts->filter(fn (Account $account) => ! $account->is_active);

        if ($inactiveAccounts->isNotEmpty()) {
            return back()->withInput()->with('error',
                'ظ„ط§ ظٹظ…ظƒظ† ط§ظ„طھط±ط­ظٹظ„ ط¹ظ„ظ‰ ط­ط³ط§ط¨ ط؛ظٹط± ظ†ط´ط·: '.
                $inactiveAccounts->map(fn (Account $account) => "{$account->code} â€” {$account->name}")->implode('طŒ '));
        }

        $codeMap = $accounts->pluck('code', 'id');

        $postLines = $lines->map(fn ($l) => [
            'account'     => $codeMap[$l['account']] ?? null,
            'debit'       => $l['debit'],
            'credit'      => $l['credit'],
            'currency_code' => $l['currency_code'],
            'exchange_rate' => $l['exchange_rate'],
            'foreign_debit' => $l['foreign_debit'],
            'foreign_credit' => $l['foreign_credit'],
            'description' => $l['description'],
        ])->all();

        // Wrap an arbitrary source so the journal_entries.source_* columns
        // get filled — we use the user record as a pragmatic stand-in
        // since "the user who posted this" is the most natural reference
        // for a manual entry.
        $source = auth()->user();

        try {
            $entry = app(\App\Services\Accounting\AccountingService::class)->post(
                eventType:   'manual_journal',
                source:      null,
                branchId:    BranchContext::current(),
                postedOn:    $data['posted_on'],
                description: $data['description'],
                lines:       $postLines,
                metadata:    ['posted_by_user_id' => $source->id, 'posted_by_username' => $source->username ?? null],
                createdBy:   $source->id,
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        \App\Models\ActivityLog::log(
            'journal.manual_posted',
            "قيد يدوي #{$entry->id}: {$data['description']}",
            $entry,
            ['lines' => $postLines],
        );

        return redirect()->route('admin.accounting.journal')
            ->with('success', "تم تسجيل القيد رقم #{$entry->id} بنجاح.");
    }

    public function createEntryAdjustment(JournalEntry $entry)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        $this->assertEntryInCurrentBranch($entry);

        $entry->load('branch', 'creator', 'lines.account');

        $accounts = Account::where('is_active', true)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        return view('admin.accounting.entry-adjustment', [
            'entry' => $entry,
            'accounts' => $accounts,
            'isReversed' => $this->entryIsReversed($entry),
            'currencies' => $this->accountingCurrencies(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
        ]);
    }

    public function storeEntryAdjustment(Request $request, JournalEntry $entry)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        $this->assertEntryInCurrentBranch($entry);

        // Closing entries carry period state — they zero the P&L into retained
        // earnings and belong to a closed period/year. Reversing one from the
        // journal screen dates the reversal OUTSIDE the closed period (the lock
        // pushes it forward), shifting a whole month's revenue into the next
        // month while the period still reads "closed". Undoing a close is only
        // correct via the Reopen flow, which restores the period status and
        // dates the reversal back inside the period.
        if (in_array($entry->event_type, [
            'period_closing', 'fiscal_year_closing',
            'period_closing_reversal', 'fiscal_year_closing_reversal',
        ], true)) {
            return back()->with('error', 'قيود الإقفال لا تُعكس من شاشة اليومية. استخدم «إعادة فتح الفترة أو السنة المالية» من شاشة الفترات.');
        }

        if ($this->entryIsReversed($entry)) {
            return back()->with('error', 'هذا القيد تم عكسه مسبقا. لا يمكن عكس نفس القيد مرتين.');
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in(['reverse', 'correct'])],
            'posted_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'correction_description' => ['required_if:mode,correct', 'nullable', 'string', 'max:255'],
            'lines' => ['required_if:mode,correct', 'array'],
            'lines.*.account_id' => ['required_if:mode,correct', 'nullable', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = DB::transaction(function () use ($entry, $data) {
                $accounting = app(AccountingService::class);
                $entry->loadMissing('lines.account');

                $reversal = $accounting->reverseEntry(
                    original: $entry,
                    eventType: 'manual_entry_reversal_'.$entry->id,
                    postedOn: $data['posted_on'],
                    description: 'عكس قيد '.$entry->entry_no,
                    metadata: [
                        'reason' => $data['reason'],
                        'mode' => $data['mode'],
                    ],
                    createdBy: auth()->id(),
                );

                $correction = null;
                if ($data['mode'] === 'correct') {
                    $correction = $accounting->post(
                        eventType: 'manual_journal_correction',
                        source: null,
                        branchId: $entry->branch_id,
                        postedOn: $data['posted_on'],
                        description: $data['correction_description'] ?: 'تصحيح قيد '.$entry->entry_no,
                        lines: $this->postLinesFromRequest($data['lines'] ?? [], $data['posted_on']),
                        metadata: [
                            'corrects_entry_id' => $entry->id,
                            'reversal_entry_id' => $reversal?->id,
                            'reason' => $data['reason'],
                        ],
                        createdBy: auth()->id(),
                    );
                }

                ActivityLog::log(
                    $data['mode'] === 'correct' ? 'journal.corrected' : 'journal.reversed',
                    ($data['mode'] === 'correct' ? 'تصحيح قيد ' : 'عكس قيد ').$entry->entry_no,
                    $correction ?: $reversal,
                    ['original_entry_id' => $entry->id, 'reason' => $data['reason']],
                );

                return ['reversal' => $reversal, 'correction' => $correction];
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $result['correction']
            ? 'تم عكس القيد الأصلي وترحيل قيد التصحيح.'
            : 'تم عكس القيد بنجاح.';

        return redirect()->route('admin.accounting.journal')
            ->with('success', $message);
    }

    public function accountMappings()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $mappings = AccountMapping::with('account')->get()
            ->keyBy(fn (AccountMapping $mapping) => $mapping->context.'|'.$mapping->key);
        $accounts = Account::where('is_active', true)->orderBy('code')->get();
        $accountsByType = $accounts->groupBy('type');
        $accountsByCode = Account::orderBy('code')->get()->keyBy('code');
        $postingRoleGroups = collect(AccountingService::postingRoleDefinitions())->groupBy('group', true);

        return view('admin.accounting.account-mappings', [
            'expenseCategories' => Lookup::for('expense_categories'),
            'paymentMethods' => $this->paymentMethodOptions(),
            'expenseAccounts' => $accountsByType->get('expense', collect()),
            'paymentAccounts' => $accountsByType->get('asset', collect()),
            'accountsByType' => $accountsByType,
            'accountsByCode' => $accountsByCode,
            'postingRoleGroups' => $postingRoleGroups,
            'mappings' => $mappings,
        ]);
    }

    public function storeAccountMappings(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'expense_category_accounts' => ['nullable', 'array'],
            'expense_category_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
            'payment_method_accounts' => ['nullable', 'array'],
            'payment_method_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
            'posting_role_accounts' => ['nullable', 'array'],
            'posting_role_accounts.*' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        try {
            DB::transaction(function () use ($data) {
                $this->syncPostingRoleMappings(
                    $data['posting_role_accounts'] ?? [],
                    AccountingService::postingRoleDefinitions(),
                );

                $expenseCategories = Lookup::for('expense_categories');
                $expenseCategoryAccounts = [];
                foreach ($expenseCategories as $category) {
                    $key = AccountMapping::keyForLookup($category);
                    if ($key) {
                        $expenseCategoryAccounts[$key] = $data['expense_category_accounts'][$category->id] ?? null;
                    }
                }

                $this->syncAccountMappings(
                    AccountMapping::CONTEXT_EXPENSE_CATEGORY,
                    $expenseCategoryAccounts,
                    array_keys($expenseCategoryAccounts),
                    ['expense'],
                );

                $this->syncAccountMappings(
                    AccountMapping::CONTEXT_PAYMENT_METHOD,
                    $data['payment_method_accounts'] ?? [],
                    array_keys($this->paymentMethodOptions()),
                    ['asset'],
                );
            });
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('account_mappings.updated', 'تحديث خرائط الحسابات التشغيلية');

        return back()->with('success', 'تم حفظ خرائط الحسابات.');
    }

    public function openingBalances()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $accounts = Account::where('is_active', true)
            ->orderBy('type')
            ->orderBy('code')
            ->get();
        $openingEntries = $this->scopeToCurrentBranch(
            JournalEntry::with('creator')->where('event_type', 'opening_balance')
        )
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('admin.accounting.opening-balances', [
            'accounts' => $accounts,
            'openingEntries' => $openingEntries,
            'equityAccount' => $this->postingRoleAccount('opening_balance_equity'),
            'currencies' => $this->accountingCurrencies(),
            'baseCurrencyCode' => $this->accountingBaseCurrencyCode(),
        ]);
    }

    public function storeOpeningBalances(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);
        if ($dupe = $this->duplicateSubmitGuard($request)) return $dupe;

        $data = $request->validate([
            'posted_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'auto_balance' => ['nullable', 'boolean'],
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'lines.*.exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999'],
            'lines.*.foreign_debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.foreign_credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $lines = $this->postLinesFromRequest($data['lines'] ?? [], $data['posted_on'], 1);
            $debit = round(array_sum(array_column($lines, 'debit')), 4);
            $credit = round(array_sum(array_column($lines, 'credit')), 4);
            $diff = round($debit - $credit, 4);

            if (abs($diff) > 0.0001 && $request->boolean('auto_balance', true)) {
                $equity = $this->postingRoleAccount('opening_balance_equity');
                $lines[] = [
                    'account' => $equity->code,
                    'debit' => $diff < 0 ? abs($diff) : 0,
                    'credit' => $diff > 0 ? abs($diff) : 0,
                    'currency_code' => $this->accountingBaseCurrencyCode(),
                    'exchange_rate' => 1,
                    'foreign_debit' => $diff < 0 ? abs($diff) : 0,
                    'foreign_credit' => $diff > 0 ? abs($diff) : 0,
                    'description' => 'موازنة تلقائية للأرصدة الافتتاحية',
                ];
            }

            app(AccountingService::class)->post(
                eventType: 'opening_balance',
                source: null,
                branchId: BranchContext::current(),
                postedOn: $data['posted_on'],
                description: $data['description'],
                lines: $lines,
                metadata: ['type' => 'opening_balance'],
                createdBy: auth()->id(),
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.opening_balance_posted', 'ترحيل أرصدة افتتاحية');

        return redirect()->route('admin.accounting.opening-balances')
            ->with('success', 'تم ترحيل الأرصدة الافتتاحية كقيد محاسبي.');
    }

    public function periods()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $periods = $this->scopeToCurrentBranch(AccountingPeriod::with(['closer', 'closingEntry']))
            ->orderByDesc('starts_on')
            ->paginate(20);
        $periodChecklists = $periods->getCollection()
            ->mapWithKeys(fn (AccountingPeriod $period) => [
                $period->id => $this->closingChecklist(
                    $period->starts_on->toDateString(),
                    $period->ends_on->toDateString(),
                    $period->branch_id,
                ),
            ]);

        return view('admin.accounting.periods', [
            'periods' => $periods,
            'periodChecklists' => $periodChecklists,
        ]);
    }

    public function storePeriod(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
        ]);

        $branchId = BranchContext::current();
        $overlap = AccountingPeriod::query()
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد فترة محاسبية متداخلة مع هذه التواريخ.');
        }

        AccountingPeriod::create([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'open',
        ]);

        return back()->with('success', 'تم إنشاء الفترة المحاسبية.');
    }

    public function closePeriod(AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if ($period->isClosed()) {
            return back()->with('success', 'الفترة مقفلة مسبقا.');
        }

        try {
            [$closingEntry, $netIncome] = DB::transaction(function () use ($period) {
                $period = AccountingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                $this->assertPeriodInCurrentBranch($period);
                $this->assertClosingChecklistClear(
                    $period->starts_on->toDateString(),
                    $period->ends_on->toDateString(),
                    $period->branch_id,
                );

                $movement = $this->ledgerTotals($period->starts_on->toDateString(), $period->ends_on->toDateString(), $period->branch_id);
                if (abs($movement['debit'] - $movement['credit']) > 0.01) {
                    throw new \RuntimeException('لا يمكن إقفال فترة ميزانها غير متوازن.');
                }

                $payload = $this->periodClosingPayload($period);
                $closingEntry = null;

                if ($payload['lines'] !== []) {
                    $closingEntry = app(AccountingService::class)->post(
                        eventType: 'period_closing',
                        source: null,
                        branchId: $period->branch_id,
                        postedOn: $period->ends_on,
                        description: 'إقفال الفترة '.$period->name,
                        lines: $payload['lines'],
                        metadata: [
                            'accounting_period_id' => $period->id,
                            'period_name' => $period->name,
                            'starts_on' => $period->starts_on->toDateString(),
                            'ends_on' => $period->ends_on->toDateString(),
                            'net_income' => $payload['net_income'],
                        ],
                        createdBy: auth()->id(),
                    );
                }

                $period->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'closing_journal_entry_id' => $closingEntry?->id,
                ]);

                return [$closingEntry, $payload['net_income']];
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.period_closed', 'إقفال فترة محاسبية '.$period->name, $period);

        $suffix = $closingEntry
            ? ' وتم إنشاء قيد الإقفال بصافي '.number_format(abs((float) $netIncome), 2).' '.(((float) $netIncome) >= 0 ? 'ربح' : 'خسارة').'.'
            : ' ولا يوجد رصيد إيرادات أو مصاريف يحتاج قيد إقفال.';

        return back()->with('success', 'تم إقفال الفترة. أي قيد داخلها سيرفضه النظام.'.$suffix);
    }

    public function reopenPeriod(AccountingPeriod $period)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertPeriodInCurrentBranch($period);

        if (! $period->isClosed()) {
            return back()->with('success', 'الفترة مفتوحة مسبقا.');
        }

        try {
            $reversal = DB::transaction(function () use ($period) {
                $period = AccountingPeriod::with('closingEntry.lines.account')->whereKey($period->id)->lockForUpdate()->firstOrFail();
                $this->assertPeriodInCurrentBranch($period);

                $closingEntry = $period->closingEntry;

                $period->update([
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'closing_journal_entry_id' => null,
                ]);

                if ($closingEntry && ! $this->entryIsReversed($closingEntry)) {
                    return app(AccountingService::class)->reverseEntry(
                        original: $closingEntry,
                        eventType: 'period_closing_reversal',
                        postedOn: $period->ends_on,
                        description: 'عكس إقفال الفترة '.$period->name,
                        metadata: [
                            'accounting_period_id' => $period->id,
                            'reason' => 'period_reopened',
                        ],
                        createdBy: auth()->id(),
                    );
                }

                return null;
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.period_reopened', 'إعادة فتح فترة محاسبية '.$period->name, $period);

        return back()->with('success', $reversal
            ? 'تمت إعادة فتح الفترة وعكس قيد الإقفال تلقائيا.'
            : 'تمت إعادة فتح الفترة.');
    }

    public function fiscalYears()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $years = $this->scopeToCurrentBranch(FiscalYear::with(['closer', 'closingEntry']))
            ->orderByDesc('starts_on')
            ->paginate(20);
        $yearChecklists = $years->getCollection()
            ->mapWithKeys(fn (FiscalYear $year) => [
                $year->id => $this->closingChecklist(
                    $year->starts_on->toDateString(),
                    $year->ends_on->toDateString(),
                    $year->branch_id,
                ),
            ]);

        return view('admin.accounting.fiscal-years', [
            'years' => $years,
            'yearChecklists' => $yearChecklists,
        ]);
    }

    public function storeFiscalYear(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string'],
        ]);

        $branchId = BranchContext::current();
        $overlap = FiscalYear::query()
            ->where(function ($query) use ($branchId) {
                $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');
            })
            ->whereDate('starts_on', '<=', $data['ends_on'])
            ->whereDate('ends_on', '>=', $data['starts_on'])
            ->exists();

        if ($overlap) {
            return back()->withInput()->with('error', 'يوجد سنة مالية متداخلة مع هذه التواريخ.');
        }

        FiscalYear::create([
            ...$data,
            'branch_id' => $branchId,
            'status' => 'open',
        ]);

        return back()->with('success', 'تم إنشاء السنة المالية.');
    }

    public function closeFiscalYear(FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        if ($year->isClosed()) {
            return back()->with('success', 'السنة المالية مقفلة مسبقا.');
        }

        try {
            [$closingEntry, $netIncome] = DB::transaction(function () use ($year) {
                $year = FiscalYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
                $this->assertFiscalYearInCurrentBranch($year);
                $this->assertClosingChecklistClear(
                    $year->starts_on->toDateString(),
                    $year->ends_on->toDateString(),
                    $year->branch_id,
                );

                $movement = $this->ledgerTotals($year->starts_on->toDateString(), $year->ends_on->toDateString(), $year->branch_id);
                if (abs($movement['debit'] - $movement['credit']) > 0.01) {
                    throw new \RuntimeException('لا يمكن إقفال سنة مالية ميزانها غير متوازن.');
                }

                $payload = $this->periodClosingPayload($year);
                $closingEntry = null;

                if ($payload['lines'] !== []) {
                    $closingEntry = app(AccountingService::class)->post(
                        eventType: 'fiscal_year_closing',
                        source: null,
                        branchId: $year->branch_id,
                        postedOn: $year->ends_on,
                        description: 'إقفال السنة المالية '.$year->name,
                        lines: $payload['lines'],
                        metadata: [
                            'fiscal_year_id' => $year->id,
                            'fiscal_year_name' => $year->name,
                            'starts_on' => $year->starts_on->toDateString(),
                            'ends_on' => $year->ends_on->toDateString(),
                            'net_income' => $payload['net_income'],
                        ],
                        createdBy: auth()->id(),
                    );
                }

                $year->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'closing_journal_entry_id' => $closingEntry?->id,
                ]);

                return [$closingEntry, $payload['net_income']];
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.fiscal_year_closed', 'إقفال سنة مالية '.$year->name, $year);

        $suffix = $closingEntry
            ? ' وتم إنشاء قيد إقفال السنة بصافي '.number_format(abs((float) $netIncome), 2).' '.(((float) $netIncome) >= 0 ? 'ربح' : 'خسارة').'.'
            : ' ولا يوجد رصيد إيرادات أو مصاريف يحتاج قيد سنة إضافي.';

        return back()->with('success', 'تم إقفال السنة المالية. أي قيد داخلها سيرفضه النظام.'.$suffix);
    }

    public function reopenFiscalYear(FiscalYear $year)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);
        $this->assertFiscalYearInCurrentBranch($year);

        if (! $year->isClosed()) {
            return back()->with('success', 'السنة المالية مفتوحة مسبقا.');
        }

        try {
            $reversal = DB::transaction(function () use ($year) {
                $year = FiscalYear::with('closingEntry.lines.account')->whereKey($year->id)->lockForUpdate()->firstOrFail();
                $this->assertFiscalYearInCurrentBranch($year);

                $closingEntry = $year->closingEntry;

                $year->update([
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'closing_journal_entry_id' => null,
                ]);

                if ($closingEntry && ! $this->entryIsReversed($closingEntry)) {
                    return app(AccountingService::class)->reverseEntry(
                        original: $closingEntry,
                        eventType: 'fiscal_year_closing_reversal',
                        postedOn: $year->ends_on,
                        description: 'عكس إقفال السنة المالية '.$year->name,
                        metadata: [
                            'fiscal_year_id' => $year->id,
                            'reason' => 'fiscal_year_reopened',
                        ],
                        createdBy: auth()->id(),
                    );
                }

                return null;
            });
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log('accounting.fiscal_year_reopened', 'إعادة فتح سنة مالية '.$year->name, $year);

        return back()->with('success', $reversal
            ? 'تمت إعادة فتح السنة المالية وعكس قيد الإقفال تلقائيا.'
            : 'تمت إعادة فتح السنة المالية.');
    }

    public function taxJurisdictions()
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        return view('admin.accounting.tax-jurisdictions', [
            'rules' => $this->scopeToCurrentBranch(TaxJurisdiction::with('branch'))
                ->orderByDesc('is_default')
                ->orderBy('priority')
                ->orderBy('state')
                ->orderBy('city')
                ->paginate(30),
            'branches' => Branch::active()->orderBy('display_order')->orderBy('name')->get(),
        ]);
    }

    public function storeTaxJurisdiction(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:191'],
            'country' => ['required', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string'],
        ]);

        $branchId = BranchContext::current();
        if ($branchId) {
            $data['branch_id'] = $branchId;
        }

        $data['country'] = strtoupper($data['country']);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['priority'] = (int) ($data['priority'] ?? 100);

        if ($data['is_default']) {
            TaxJurisdiction::query()
                ->where(function ($query) use ($data) {
                    $data['branch_id'] ? $query->where('branch_id', $data['branch_id']) : $query->whereNull('branch_id');
                })
                ->update(['is_default' => false]);
        }

        TaxJurisdiction::create($data);

        return back()->with('success', 'تم حفظ قاعدة ضريبة المبيعات.');
    }

    public function destroyTaxJurisdiction(TaxJurisdiction $jurisdiction)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        if (($branchId = BranchContext::current()) && (int) $jurisdiction->branch_id !== (int) $branchId) {
            abort(404);
        }

        $jurisdiction->delete();

        return back()->with('success', 'تم حذف قاعدة الضريبة.');
    }

    public function balanceSheet(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = Carbon::parse($data['as_of'] ?? now())->toDateString();
        $rows = $this->ledgerAccountRows(null, $asOf);

        $assets = $this->balanceSheetRows($rows, ['asset'], 'debit');
        $liabilities = $this->balanceSheetRows($rows, ['liability'], 'credit');
        $equity = $this->balanceSheetRows($rows, ['equity'], 'credit');
        $currentEarnings = $this->currentEarnings($rows);

        $totalAssets = round($assets->sum('balance'), 2);
        $totalLiabilities = round($liabilities->sum('balance'), 2);
        $totalEquity = round($equity->sum('balance') + $currentEarnings, 2);

        return view('admin.accounting.balance-sheet', [
            'asOf' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'currentEarnings' => round($currentEarnings, 2),
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'isBalanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ]);
    }

    public function taxReport(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        [$from, $to] = $this->dateRange($filters['from'] ?? now()->startOfMonth()->toDateString(), $filters['to'] ?? null);
        $rows = $this->ledgerAccountRows($from, $to);

        $outputTax = $this->netForCodes($rows, $this->postingRoleCodes('output_vat'), 'credit');
        $inputTax = $this->netForCodes($rows, $this->postingRoleCodes('input_vat'), 'debit');
        $payable = MarketProfile::isUs() ? $outputTax : $outputTax - $inputTax;

        return view('admin.accounting.tax-report', [
            'from' => $from,
            'to' => $to,
            'isUsMarket' => MarketProfile::isUs(),
            'taxLabel' => MarketProfile::taxLabel(),
            'outputTax' => round($outputTax, 2),
            'inputTax' => round($inputTax, 2),
            'payable' => round($payable, 2),
            'outputCodes' => $this->postingRoleCodes('output_vat'),
            'inputCodes' => $this->postingRoleCodes('input_vat'),
        ]);
    }

    public function aging(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.viewAny'), 403);

        $data = $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = Carbon::parse($data['as_of'] ?? now())->toDateString();
        $branchId = BranchContext::current();
        $receivableBalances = $this->receivableLedgerBalances($asOf, $branchId);
        $payableBalances = $this->payableLedgerBalances($asOf, $branchId);

        $arRows = Invoice::with('customer')
            ->whereIn('id', array_keys($receivableBalances))
            ->whereDate('issued_at', '<=', $asOf)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('issued_at')
            ->get()
            ->map(fn (Invoice $invoice) => $this->agingRow(
                $invoice->number,
                $invoice->customer?->name ?? $invoice->customer_name ?? 'عميل غير محدد',
                $invoice->issued_at?->toDateString() ?? $invoice->created_at?->toDateString(),
                (float) ($receivableBalances[$invoice->id] ?? 0),
                $asOf,
            ))
            ->filter(fn (array $row) => $row['amount'] > 0.01)
            ->values();

        $apRows = SupplierInvoice::with('supplier')
            ->whereIn('id', array_keys($payableBalances))
            ->whereDate('invoice_date', '<=', $asOf)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (SupplierInvoice $invoice) => $this->agingRow(
                $invoice->number,
                $invoice->supplier?->name ?? 'مورد غير محدد',
                $invoice->invoice_date?->toDateString(),
                (float) ($payableBalances[$invoice->id] ?? 0),
                $asOf,
            ))
            ->filter(fn (array $row) => $row['amount'] > 0.01)
            ->values();

        $arTotals = $this->agingTotals($arRows);
        $apTotals = $this->agingTotals($apRows);

        // Tie-out to the general ledger. The invoice-level aging total can differ
        // from the 1100 / 2000 account balance because AR/AP can also carry
        // opening balances and manual journal entries (which have no invoice
        // document) plus per-invoice negatives dropped above. Surfacing that
        // residual lets the report reconcile to the trial balance instead of
        // silently disagreeing with it.
        $arLedger = $this->bookBalanceForRole('accounts_receivable', 'debit', $asOf);
        $apLedger = $this->bookBalanceForRole('accounts_payable', 'credit', $asOf);

        return view('admin.accounting.aging', [
            'asOf' => $asOf,
            'arRows' => $arRows,
            'apRows' => $apRows,
            'arTotals' => $arTotals,
            'apTotals' => $apTotals,
            'arLedgerBalance' => $arLedger,
            'apLedgerBalance' => $apLedger,
            'arUnassigned' => round($arLedger - (float) $arTotals['total'], 2),
            'apUnassigned' => round($apLedger - (float) $apTotals['total'], 2),
        ]);
    }

    public function reconciliations(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $accounts = Account::where('is_active', true)
            ->where('type', 'asset')
            ->orderBy('code')
            ->get();
        $selectedAccount = $accounts->firstWhere('id', (int) $request->get('account_id')) ?: $accounts->first();
        $statementDate = Carbon::parse($request->get('statement_date', now()))->toDateString();
        $bookBalance = $selectedAccount ? $this->bookBalanceForAccount($selectedAccount->id, $statementDate) : 0;
        $period = $this->periodForDate($statementDate);

        $reconciliations = $this->scopeToCurrentBranch(CashReconciliation::with('account', 'period', 'reconciler'))
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.accounting.reconciliations', [
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'statementDate' => $statementDate,
            'bookBalance' => round($bookBalance, 2),
            'period' => $period,
            'reconciliations' => $reconciliations,
        ]);
    }

    public function storeReconciliation(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        if (! $account->is_active || $account->type !== 'asset') {
            return back()->withInput()->with('error', 'المطابقة مسموحة فقط لحسابات الأصول النشطة.');
        }

        $statementDate = Carbon::parse($data['statement_date'])->toDateString();
        $bookBalance = $this->bookBalanceForAccount($account->id, $statementDate);
        $statementBalance = round((float) $data['statement_balance'], 4);

        $reconciliation = CashReconciliation::create([
            'branch_id' => BranchContext::current(),
            'accounting_period_id' => $this->periodForDate($statementDate)?->id,
            'account_id' => $account->id,
            'statement_date' => $statementDate,
            'book_balance' => $bookBalance,
            'statement_balance' => $statementBalance,
            'difference' => round($statementBalance - $bookBalance, 4),
            'status' => 'reconciled',
            'reconciled_at' => now(),
            'reconciled_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::log('accounting.reconciled', 'مطابقة حساب '.$account->code, $reconciliation);

        return redirect()->route('admin.accounting.reconciliations', [
            'account_id' => $account->id,
            'statement_date' => $statementDate,
        ])->with('success', 'تم حفظ المطابقة.');
    }

    public function settlements(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'as_of' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? now()->startOfMonth()->toDateString(), $filters['to'] ?? null);
        $asOf = Carbon::parse($filters['as_of'] ?? now())->toDateString();
        $taxAmounts = $this->taxSettlementAmounts($from, $to);
        $tipsPayable = max(0, $this->bookBalanceForRole('tips_payable', 'credit', $asOf));

        $assetAccounts = Account::where('is_active', true)
            ->where('type', 'asset')
            ->orderBy('code')
            ->get();

        $recentSettlements = $this->scopeToCurrentBranch(
            JournalEntry::with(['lines.account', 'creator'])
                ->whereIn('event_type', ['tax_payment', 'tips_payout', 'payment_clearing_settlement'])
        )
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('admin.accounting.settlements', [
            'from' => $from,
            'to' => $to,
            'asOf' => $asOf,
            'taxAmounts' => $taxAmounts,
            'tipsPayable' => round($tipsPayable, 2),
            'assetAccounts' => $assetAccounts,
            'paymentMethods' => $this->paymentMethodOptions(),
            'recentSettlements' => $recentSettlements,
        ]);
    }

    public function storeTaxPayment(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'posted_on' => ['required', 'date'],
            'payment_method' => ['required', 'string', Rule::in(array_keys($this->paymentMethodOptions()))],
        ]);

        [$from, $to] = $this->dateRange($data['from'], $data['to']);
        $postedOn = Carbon::parse($data['posted_on'])->toDateString();
        $taxAmounts = $this->taxSettlementAmounts($from, $to);

        if ($taxAmounts['payable'] <= 0.01) {
            return back()->withInput()->with('error', 'لا يوجد رصيد ضريبة مستحق لهذا المدى.');
        }

        try {
            $entry = app(AccountingService::class)->recordTaxPayment(
                outputTax: $taxAmounts['output_tax'],
                inputTax: $taxAmounts['input_tax'],
                paymentMethod: $data['payment_method'],
                branchId: BranchContext::current(),
                postedOn: $postedOn,
                createdBy: auth()->id(),
                metadata: [
                    'from' => $from,
                    'to' => $to,
                    'tax_label' => $taxAmounts['tax_label'],
                ],
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.tax_payment_posted', 'سداد ضريبة', $entry);

        return redirect()->route('admin.accounting.journal', ['event_type' => 'tax_payment'])
            ->with('success', 'تم ترحيل قيد سداد الضريبة.');
    }

    public function storeTipPayout(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'posted_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', Rule::in(array_keys($this->paymentMethodOptions()))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $postedOn = Carbon::parse($data['posted_on'])->toDateString();
        $amount = round((float) $data['amount'], 4);
        $tipsPayable = max(0, $this->bookBalanceForRole('tips_payable', 'credit', $postedOn));

        if ($amount > $tipsPayable + 0.01) {
            return back()->withInput()->with('error', 'مبلغ صرف الإكراميات أكبر من الرصيد المستحق.');
        }

        try {
            $entry = app(AccountingService::class)->recordTipPayout(
                amount: $amount,
                paymentMethod: $data['payment_method'],
                branchId: BranchContext::current(),
                postedOn: $postedOn,
                createdBy: auth()->id(),
                notes: $data['notes'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.tips_payout_posted', 'صرف إكراميات', $entry);

        return redirect()->route('admin.accounting.journal', ['event_type' => 'tips_payout'])
            ->with('success', 'تم ترحيل قيد صرف الإكراميات.');
    }

    public function storePaymentClearingSettlement(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.update'), 403);

        $data = $request->validate([
            'posted_on' => ['required', 'date'],
            'clearing_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'deposit_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'gross_amount' => ['required', 'numeric', 'gt:0'],
            'fee_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ((int) $data['clearing_account_id'] === (int) $data['deposit_account_id']) {
            return back()->withInput()->with('error', 'حساب التحصيل وحساب الإيداع يجب أن يكونا مختلفين.');
        }

        $grossAmount = round((float) $data['gross_amount'], 4);
        $feeAmount = round((float) $data['fee_amount'], 4);
        if ($feeAmount > $grossAmount) {
            return back()->withInput()->with('error', 'العمولة لا يمكن أن تكون أكبر من إجمالي التسوية.');
        }

        $clearingAccount = Account::where('is_active', true)->where('type', 'asset')->findOrFail($data['clearing_account_id']);
        $depositAccount = Account::where('is_active', true)->where('type', 'asset')->findOrFail($data['deposit_account_id']);

        try {
            $entry = app(AccountingService::class)->recordPaymentClearingSettlement(
                clearingAccount: $clearingAccount,
                depositAccount: $depositAccount,
                grossAmount: $grossAmount,
                feeAmount: $feeAmount,
                branchId: BranchContext::current(),
                postedOn: Carbon::parse($data['posted_on'])->toDateString(),
                createdBy: auth()->id(),
                notes: $data['notes'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        ActivityLog::log('accounting.payment_clearing_settlement_posted', 'تسوية بوابة دفع', $entry);

        return redirect()->route('admin.accounting.journal', ['event_type' => 'payment_clearing_settlement'])
            ->with('success', 'تم ترحيل قيد تسوية بوابة الدفع.');
    }

    private function reversedEntryIds(): array
    {
        return $this->scopeToCurrentBranch(JournalEntry::query())
            ->whereNotNull('metadata')
            ->get(['metadata'])
            ->map(fn (JournalEntry $entry) => (int) ($entry->metadata['reverses_entry_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function entryIsReversed(JournalEntry $entry): bool
    {
        return in_array((int) $entry->id, $this->reversedEntryIds(), true);
    }

    private function assertEntryInCurrentBranch(JournalEntry $entry): void
    {
        if (($branchId = BranchContext::current()) && (int) $entry->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function postLinesFromRequest(array $rawLines, mixed $postedOn, int $minimumLines = 2): array
    {
        $lines = collect($rawLines)->map(function (array $line) use ($postedOn) {
            $currencyCode = $this->normalizeCurrencyCode($line['currency_code'] ?? $this->accountingBaseCurrencyCode());
            $exchangeRate = (float) ($line['exchange_rate'] ?? ($currencyCode === $this->accountingBaseCurrencyCode() ? 1 : $this->configuredExchangeRate($currencyCode, $postedOn)));
            $hasForeignAmounts = array_key_exists('foreign_debit', $line) || array_key_exists('foreign_credit', $line);
            $foreignDebit = round((float) ($line['foreign_debit'] ?? 0), 4);
            $foreignCredit = round((float) ($line['foreign_credit'] ?? 0), 4);
            $debit = $hasForeignAmounts ? round($foreignDebit * $exchangeRate, 4) : round((float) ($line['debit'] ?? 0), 4);
            $credit = $hasForeignAmounts ? round($foreignCredit * $exchangeRate, 4) : round((float) ($line['credit'] ?? 0), 4);

            return [
                'account' => (int) ($line['account_id'] ?? 0),
                'debit' => $debit,
                'credit' => $credit,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'foreign_debit' => $hasForeignAmounts ? $foreignDebit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $debit : round($debit / $exchangeRate, 4)),
                'foreign_credit' => $hasForeignAmounts ? $foreignCredit : ($currencyCode === $this->accountingBaseCurrencyCode() ? $credit : round($credit / $exchangeRate, 4)),
                'description' => $line['description'] ?? null,
            ];
        })->filter(fn ($line) => $line['debit'] > 0.0001 || $line['credit'] > 0.0001)->values();

        if ($lines->count() < $minimumLines) {
            throw new \RuntimeException($minimumLines > 1
                ? 'يلزم على الأقل سطرين (مدين + دائن) لقيد التصحيح.'
                : 'يلزم إدخال سطر واحد على الأقل.');
        }

        foreach ($lines as $index => $line) {
            if ($line['debit'] > 0.0001 && $line['credit'] > 0.0001) {
                throw new \RuntimeException('سطر #'.($index + 1).': لا يمكن أن يكون مدينا ودائنا في نفس الوقت.');
            }
        }

        $accounts = Account::whereIn('id', $lines->pluck('account')->unique()->all())->get()->keyBy('id');
        foreach ($lines as $line) {
            $account = $accounts->get($line['account']);
            if (! $account || ! $account->is_active) {
                throw new \RuntimeException('كل سطور التصحيح يجب أن تستخدم حسابات نشطة.');
            }
        }

        return $lines->map(fn ($line) => [
            'account' => $accounts->get($line['account'])->code,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'currency_code' => $line['currency_code'],
            'exchange_rate' => $line['exchange_rate'],
            'foreign_debit' => $line['foreign_debit'],
            'foreign_credit' => $line['foreign_credit'],
            'description' => $line['description'],
        ])->all();
    }

    private function accountingBaseCurrencyCode(): string
    {
        return $this->normalizeCurrencyCode(Setting::get('accounting_base_currency', Currency::base()?->code ?? MarketProfile::currency()));
    }

    private function accountingCurrencies()
    {
        $currencies = Currency::orderByDesc('is_base')->orderBy('display_order')->orderBy('code')->get();
        if ($currencies->isNotEmpty()) {
            return $currencies;
        }

        return collect([
            new Currency([
                'code' => $this->accountingBaseCurrencyCode(),
                'name' => $this->accountingBaseCurrencyCode(),
                'symbol' => Setting::get('accounting_currency_symbol', Setting::get('currency_symbol', config('restaurant.currency_symbol', '$'))),
                'rate_to_base' => 1,
                'is_base' => true,
                'is_active' => true,
            ]),
        ]);
    }

    private function configuredExchangeRate(string $currencyCode, mixed $postedOn = null): float
    {
        $currencyCode = $this->normalizeCurrencyCode($currencyCode);
        if ($currencyCode === $this->accountingBaseCurrencyCode()) {
            return 1.0;
        }

        return app(ExchangeRateService::class)->rateFor($currencyCode, $this->accountingBaseCurrencyCode(), $postedOn);
    }

    private function normalizeCurrencyCode(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : $this->accountingBaseCurrencyCode();
    }

    private function paymentMethodOptions(): array
    {
        return [
            'cash' => 'نقدي',
            'card' => 'بطاقة',
            'transfer' => 'تحويل',
            'bank_transfer' => 'تحويل بنكي',
            'cheque' => 'شيك',
            'app' => 'محفظة / تطبيق',
            'credit' => 'بيع آجل',
            'credit_note' => 'إشعار دائن',
            'other' => 'أخرى',
        ];
    }

    private function syncAccountMappings(string $context, array $submitted, array $allowedKeys, array $allowedTypes): void
    {
        $allowedKeys = array_map('strval', $allowedKeys);

        AccountMapping::where('context', $context)->whereNotIn('key', $allowedKeys)->delete();

        foreach ($allowedKeys as $key) {
            $accountId = $submitted[$key] ?? null;

            if (! $accountId) {
                AccountMapping::where('context', $context)->where('key', $key)->delete();
                continue;
            }

            $account = Account::find((int) $accountId);
            if (! $account || ! $account->is_active || ! in_array($account->type, $allowedTypes, true)) {
                throw new \RuntimeException('تم اختيار حساب غير صالح لأحد الربوط.');
            }

            AccountMapping::updateOrCreate(
                ['context' => $context, 'key' => $key],
                ['account_id' => $account->id],
            );
        }
    }

    private function ledgerTotals(?string $from, string $to, ?int $branchId = null): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        $branchId ??= BranchContext::current();
        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        $row = $query->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')->first();

        return [
            'debit' => round((float) ($row->debit ?? 0), 4),
            'credit' => round((float) ($row->credit ?? 0), 4),
        ];
    }

    private function ledgerAccountRows(?string $from, string $to, ?int $branchId = null)
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->when($from, fn ($query) => $query->whereDate('journal_entries.posted_on', '>=', $from))
            ->whereDate('journal_entries.posted_on', '<=', $to);

        $branchId ??= BranchContext::current();
        if ($branchId) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        return $query
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->selectRaw('
                accounts.id,
                accounts.code,
                accounts.name,
                accounts.type,
                accounts.normal_balance,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get()
            ->map(function ($row) {
                $row->debit = (float) $row->debit;
                $row->credit = (float) $row->credit;
                return $row;
            });
    }

    private function assertClosingChecklistClear(string $from, string $to, ?int $branchId): void
    {
        $blockers = collect($this->closingChecklist($from, $to, $branchId))
            ->filter(fn (array $item) => ($item['severity'] ?? '') === 'block' && ! ($item['ok'] ?? false))
            ->pluck('message')
            ->values();

        if ($blockers->isNotEmpty()) {
            throw new \RuntimeException('قبل الإقفال يجب معالجة: '.$blockers->implode('، '));
        }
    }

    /**
     * One-shot idempotency guard for source-less financial posts (manual journal,
     * opening balances). The form carries a fresh `_idem` UUID; the first submit
     * claims it atomically, a duplicate (F5 / back-then-resubmit) finds it taken
     * and is bounced with a clear message instead of double-posting.
     */
    private function duplicateSubmitGuard(Request $request)
    {
        $token = (string) $request->input('_idem', '');
        if ($token === '') {
            return null;   // stale cached form without the field — let it through
        }
        if (! \Illuminate\Support\Facades\Cache::add('idem:acct:'.$token, true, now()->addMinutes(10))) {
            return back()->withInput()->with('error', 'تم ترحيل هذا القيد مسبقاً — مُنع إرسال مكرر.');
        }
        return null;
    }

    private function closingChecklist(string $from, string $to, ?int $branchId): array
    {
        $ledger = $this->ledgerTotals($from, $to, $branchId);
        $openShifts = Shift::query()
            ->where('status', 'open')
            ->whereDate('opened_at', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $activeSessions = TableSession::query()
            ->where('status', 'active')
            ->whereDate('opened_at', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $activeOrders = Order::query()
            ->whereIn('status', OrderStatus::active())
            ->where(function ($query) use ($to) {
                $query->whereDate('submitted_at', '<=', $to)
                    ->orWhere(function ($query) use ($to) {
                        $query->whereNull('submitted_at')->whereDate('created_at', '<=', $to);
                    });
            })
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $openSupplierInvoices = SupplierInvoice::query()
            ->whereIn('status', ['unpaid', 'partially_paid'])
            ->whereDate('invoice_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->count();

        $reconciliationsQuery = CashReconciliation::query()
            ->whereDate('statement_date', '>=', $from)
            ->whereDate('statement_date', '<=', $to)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $reconciliations = (clone $reconciliationsQuery)->count();
        // A saved reconciliation with a non-zero difference is NOT resolved — it
        // must not turn the checklist item green just by existing.
        $unbalancedReconciliations = (clone $reconciliationsQuery)
            ->whereRaw('ABS(difference) > 0.01')
            ->count();

        return [
            [
                'label' => 'توازن دفتر القيود',
                'ok' => abs($ledger['debit'] - $ledger['credit']) <= 0.01,
                'severity' => 'block',
                'message' => 'ميزان الفترة غير متوازن',
                'detail' => 'مدين '.number_format($ledger['debit'], 2).' / دائن '.number_format($ledger['credit'], 2),
            ],
            [
                'label' => 'إغلاق الوردية',
                'ok' => $openShifts === 0,
                'severity' => 'block',
                'message' => 'يوجد ورديات مفتوحة',
                'detail' => $openShifts.' وردية مفتوحة',
            ],
            [
                'label' => 'إغلاق جلسات الطاولات',
                'ok' => $activeSessions === 0,
                'severity' => 'block',
                'message' => 'يوجد جلسات طاولات نشطة',
                'detail' => $activeSessions.' جلسة نشطة',
            ],
            [
                'label' => 'إنهاء الطلبات المفتوحة',
                'ok' => $activeOrders === 0,
                'severity' => 'block',
                'message' => 'يوجد طلبات لم تكتمل',
                'detail' => $activeOrders.' طلب مفتوح',
            ],
            [
                'label' => 'فواتير الموردين المفتوحة',
                'ok' => $openSupplierInvoices === 0,
                'severity' => 'warn',
                'message' => 'يوجد فواتير موردين غير مدفوعة بالكامل',
                'detail' => $openSupplierInvoices.' فاتورة مفتوحة',
            ],
            [
                'label' => 'مطابقة الصندوق/البنك',
                'ok' => $reconciliations > 0 && $unbalancedReconciliations === 0,
                'severity' => 'warn',
                'message' => $reconciliations === 0
                    ? 'لا توجد مطابقة صندوق أو بنك داخل المدى'
                    : 'توجد مطابقة بفرق غير مسوّى بين الدفتر والكشف',
                'detail' => $unbalancedReconciliations > 0
                    ? $reconciliations.' مطابقة — منها '.$unbalancedReconciliations.' بفرق غير مسوّى'
                    : $reconciliations.' مطابقة محفوظة',
            ],
        ];
    }

    private function periodClosingPayload(AccountingPeriod|FiscalYear $period): array
    {
        $rows = $this->ledgerAccountRows($period->starts_on->toDateString(), $period->ends_on->toDateString(), $period->branch_id)
            ->filter(fn ($row) => in_array($row->type, ['revenue', 'contra_revenue', 'expense'], true))
            ->sortBy('code')
            ->values();

        $lines = [];
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($rows as $row) {
            $net = round((float) $row->debit - (float) $row->credit, 4);
            if (abs($net) <= 0.0001) {
                continue;
            }

            $amount = abs($net);
            $line = [
                'account' => $row->code,
                'debit' => $net < 0 ? $amount : 0.0,
                'credit' => $net > 0 ? $amount : 0.0,
                'description' => 'إقفال رصيد '.$row->code.' - '.$row->name,
            ];

            $debitTotal += (float) $line['debit'];
            $creditTotal += (float) $line['credit'];
            $lines[] = $line;
        }

        $netIncome = round($debitTotal - $creditTotal, 4);
        if (abs($netIncome) > 0.0001) {
            $retainedEarnings = $this->postingRoleAccount('retained_earnings');
            $lines[] = [
                'account' => $retainedEarnings->code,
                'debit' => $netIncome < 0 ? abs($netIncome) : 0.0,
                'credit' => $netIncome > 0 ? $netIncome : 0.0,
                'description' => $netIncome > 0
                    ? 'ترحيل صافي ربح الفترة إلى الأرباح المحتجزة'
                    : 'ترحيل صافي خسارة الفترة إلى الأرباح المحتجزة',
            ];
        }

        return [
            'lines' => $lines,
            'net_income' => $netIncome,
        ];
    }

    private function balanceSheetRows($rows, array $types, string $normalBalance)
    {
        return collect($rows)
            ->whereIn('type', $types)
            ->map(function ($row) use ($normalBalance) {
                $row->balance = round($normalBalance === 'credit'
                    ? (float) $row->credit - (float) $row->debit
                    : (float) $row->debit - (float) $row->credit, 2);

                return $row;
            })
            ->filter(fn ($row) => abs((float) $row->balance) > 0.0001)
            ->values();
    }

    private function currentEarnings($rows): float
    {
        return round((float) collect($rows)
            ->filter(fn ($row) => in_array($row->type, ['revenue', 'contra_revenue', 'expense'], true))
            ->sum(function ($row) {
                return match ($row->type) {
                    'revenue' => (float) $row->credit - (float) $row->debit,
                    'contra_revenue', 'expense' => -((float) $row->debit - (float) $row->credit),
                    default => 0,
                };
            }), 2);
    }

    private function netForCodes($rows, array $codes, string $normalBalance): float
    {
        return collect($rows)
            ->filter(fn ($row) => in_array($row->code, $codes, true))
            ->sum(fn ($row) => $normalBalance === 'credit'
                ? (float) $row->credit - (float) $row->debit
                : (float) $row->debit - (float) $row->credit);
    }

    private function bookBalanceForAccount(int $accountId, string $asOf): float
    {
        $account = Account::findOrFail($accountId);
        $rows = $this->ledgerAccountRows(null, $asOf);
        $row = collect($rows)->firstWhere('id', $account->id);
        if (! $row) {
            return 0.0;
        }

        return round($account->normal_balance === 'credit'
            ? (float) $row->credit - (float) $row->debit
            : (float) $row->debit - (float) $row->credit, 4);
    }

    private function bookBalanceForRole(string $role, string $normalBalance, string $asOf): float
    {
        return round($this->netForCodes(
            $this->ledgerAccountRows(null, $asOf),
            $this->postingRoleCodes($role),
            $normalBalance,
        ), 4);
    }

    private function taxSettlementAmounts(string $from, string $to): array
    {
        // Balance-based settlement: the payable is the OUTSTANDING balance of the
        // VAT accounts as of TODAY (cumulative from the start of the books), which
        // every prior tax_payment already reduced (it debits 2100 / credits 1300).
        // We measure as of today — NOT the range end $to — because a settlement is
        // dated when it's actually paid (often after the period it covers). Using
        // $to would exclude that later payment and let the same range be paid
        // twice. Measuring to today captures every prior payment, so re-opening
        // shows only what's still owed and a payment never bleeds into the next
        // period. $from/$to are kept only for the on-screen label / metadata.
        $rows = $this->ledgerAccountRows(null, now()->toDateString());
        $outputTax = round(max(0, $this->netForCodes($rows, $this->postingRoleCodes('output_vat'), 'credit')), 2);
        $inputTax = MarketProfile::isUs()
            ? 0.0
            : round(max(0, $this->netForCodes($rows, $this->postingRoleCodes('input_vat'), 'debit')), 2);
        $payable = round(max(0, $outputTax - $inputTax), 2);

        return [
            'output_tax' => $outputTax,
            'input_tax' => $inputTax,
            'payable' => $payable,
            'tax_label' => MarketProfile::taxLabel(),
            'is_us_market' => MarketProfile::isUs(),
        ];
    }

    private function postingRoleCodes(string $role): array
    {
        $definition = AccountingService::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            return [];
        }

        $codes = [$definition['default']];
        $mapping = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $role)
            ->first();
        $account = $mapping?->account;

        if ($account && $account->is_active && in_array($account->type, $definition['types'], true)) {
            $codes[] = $account->code;
        }

        return collect($codes)->filter()->unique()->values()->all();
    }

    private function postingRoleAccount(string $role): Account
    {
        $definition = AccountingService::postingRoleDefinitions()[$role] ?? null;
        if (! $definition) {
            throw new \RuntimeException("دور ترحيل غير معروف: {$role}");
        }

        $mapping = AccountMapping::with('account')
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $role)
            ->first();
        $account = $mapping?->account;
        if ($account && $account->is_active && in_array($account->type, $definition['types'], true)) {
            return $account;
        }

        return Account::where('code', $definition['default'])->firstOrFail();
    }

    private function receivableLedgerBalances(string $asOf, ?int $branchId): array
    {
        return $this->ledgerDocumentBalances(
            asOf: $asOf,
            branchId: $branchId,
            accountCodes: $this->postingRoleCodes('accounts_receivable'),
            documentSourceType: Invoice::class,
            paymentSourceType: Payment::class,
            paymentTable: 'payments',
            paymentDocumentColumn: 'invoice_id',
            normalBalance: 'debit',
        );
    }

    private function payableLedgerBalances(string $asOf, ?int $branchId): array
    {
        return $this->ledgerDocumentBalances(
            asOf: $asOf,
            branchId: $branchId,
            accountCodes: $this->postingRoleCodes('accounts_payable'),
            documentSourceType: SupplierInvoice::class,
            paymentSourceType: SupplierPayment::class,
            paymentTable: 'supplier_payments',
            paymentDocumentColumn: 'supplier_invoice_id',
            normalBalance: 'credit',
        );
    }

    private function ledgerDocumentBalances(
        string $asOf,
        ?int $branchId,
        array $accountCodes,
        string $documentSourceType,
        string $paymentSourceType,
        string $paymentTable,
        string $paymentDocumentColumn,
        string $normalBalance,
    ): array {
        if ($accountCodes === []) {
            return [];
        }

        $balances = [];

        $directRows = $this->ledgerDocumentBalanceQuery($asOf, $branchId, $accountCodes)
            ->where('journal_entries.source_type', $documentSourceType)
            ->whereNotNull('journal_entries.source_id')
            ->groupBy('journal_entries.source_id')
            ->selectRaw('
                journal_entries.source_id as document_id,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get();

        foreach ($directRows as $row) {
            $this->addLedgerDocumentBalance($balances, $row, $normalBalance);
        }

        $paymentRows = $this->ledgerDocumentBalanceQuery($asOf, $branchId, $accountCodes)
            ->join($paymentTable, "{$paymentTable}.id", '=', 'journal_entries.source_id')
            ->where('journal_entries.source_type', $paymentSourceType)
            ->whereNotNull("{$paymentTable}.{$paymentDocumentColumn}")
            ->groupBy("{$paymentTable}.{$paymentDocumentColumn}")
            ->selectRaw("
                {$paymentTable}.{$paymentDocumentColumn} as document_id,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ")
            ->get();

        foreach ($paymentRows as $row) {
            $this->addLedgerDocumentBalance($balances, $row, $normalBalance);
        }

        return collect($balances)
            ->filter(fn (float $balance) => $balance > 0.01)
            ->map(fn (float $balance) => round($balance, 2))
            ->all();
    }

    private function ledgerDocumentBalanceQuery(string $asOf, ?int $branchId, array $accountCodes)
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.posted_on', '<=', $asOf)
            ->whereIn('accounts.code', $accountCodes)
            ->when($branchId, fn ($query) => $query->where('journal_entries.branch_id', $branchId));
    }

    private function addLedgerDocumentBalance(array &$balances, object $row, string $normalBalance): void
    {
        $documentId = (int) ($row->document_id ?? 0);
        if ($documentId <= 0) {
            return;
        }

        $amount = $normalBalance === 'credit'
            ? (float) $row->credit - (float) $row->debit
            : (float) $row->debit - (float) $row->credit;

        $balances[$documentId] = round(($balances[$documentId] ?? 0) + $amount, 4);
    }

    private function agingRow(string $number, string $party, ?string $date, float $amount, string $asOf): array
    {
        $days = $date ? max(0, Carbon::parse($date)->diffInDays(Carbon::parse($asOf), false)) : 0;
        $bucket = match (true) {
            $days <= 30 => 'current',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };

        return [
            'number' => $number,
            'party' => $party,
            'date' => $date,
            'days' => $days,
            'amount' => round($amount, 2),
            'bucket' => $bucket,
        ];
    }

    private function agingTotals($rows): array
    {
        $totals = [
            'current' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            'over_90' => 0.0,
            'total' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals[$row['bucket']] += $row['amount'];
            $totals['total'] += $row['amount'];
        }

        return array_map(fn ($value) => round((float) $value, 2), $totals);
    }

    private function periodForDate(string $date): ?AccountingPeriod
    {
        $branchId = BranchContext::current();

        return AccountingPeriod::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->where(function ($query) use ($branchId) {
                $query->whereNull('branch_id');
                if ($branchId) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->orderByRaw('branch_id is null')
            ->first();
    }

    private function assertPeriodInCurrentBranch(AccountingPeriod $period): void
    {
        if (($branchId = BranchContext::current()) && (int) $period->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function assertFiscalYearInCurrentBranch(FiscalYear $year): void
    {
        if (($branchId = BranchContext::current()) && (int) $year->branch_id !== (int) $branchId) {
            abort(404);
        }
    }

    private function syncPostingRoleMappings(array $submitted, array $definitions): void
    {
        $allowedKeys = array_keys($definitions);

        AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->whereNotIn('key', $allowedKeys)
            ->delete();

        foreach ($definitions as $key => $definition) {
            $accountId = $submitted[$key] ?? null;

            if (! $accountId) {
                AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)
                    ->where('key', $key)
                    ->delete();
                continue;
            }

            $allowedTypes = $definition['types'] ?? [];
            $account = Account::find((int) $accountId);
            if (! $account || ! $account->is_active || ! in_array($account->type, $allowedTypes, true)) {
                throw new \RuntimeException('تم اختيار حساب غير صالح لإحدى قواعد الترحيل.');
            }

            AccountMapping::updateOrCreate(
                ['context' => AccountMapping::CONTEXT_POSTING_ROLE, 'key' => $key],
                ['account_id' => $account->id],
            );
        }
    }
}
