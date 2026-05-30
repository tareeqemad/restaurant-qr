<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
        ]);
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
            'invoice_issued' => 'إصدار فاتورة',
            'invoice_cancelled' => 'إلغاء فاتورة',
            'payment_received' => 'تحصيل دفعة',
            'invoice_writeoff' => 'شطب ذمة',
            'refund_completed' => 'استرداد مكتمل',
            'expense_approved' => 'اعتماد مصروف',
            'supplier_invoice_created' => 'فاتورة مورد',
            'supplier_invoice_cancelled' => 'إلغاء فاتورة مورد',
            'supplier_payment_recorded' => 'دفعة لمورد',
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

        return view('admin.accounting.manual-entry', compact('accounts'));
    }

    public function storeManualEntry(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('chart_of_accounts.create'), 403);

        $data = $request->validate([
            'posted_on'   => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines'       => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ], [], [
            'lines' => 'سطور القيد',
        ]);

        // Normalise: empty numbers → 0; verify each line has EITHER
        // debit OR credit (never both, never neither).
        $lines = collect($data['lines'])->map(function ($line) {
            return [
                'account'     => (int) $line['account_id'],
                'debit'       => round((float) ($line['debit']  ?? 0), 4),
                'credit'      => round((float) ($line['credit'] ?? 0), 4),
                'description' => $line['description'] ?? null,
            ];
        })->filter(fn ($l) => $l['debit'] > 0.0001 || $l['credit'] > 0.0001);

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
        $codeMap = Account::whereIn('id', $accountIds)->pluck('code', 'id');

        $postLines = $lines->map(fn ($l) => [
            'account'     => $codeMap[$l['account']] ?? null,
            'debit'       => $l['debit'],
            'credit'      => $l['credit'],
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
                source:      $source,
                branchId:    BranchContext::current(),
                postedOn:    $data['posted_on'],
                description: $data['description'],
                lines:       $postLines,
                metadata:    ['posted_by_username' => $source->username ?? null],
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
}
