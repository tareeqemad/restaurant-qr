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
        ]);

        [$from, $to] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);

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

        $accounts = Account::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($movements) {
                $movement = $movements->get($account->id);
                $debit = round((float) ($movement?->debit ?? 0), 4);
                $credit = round((float) ($movement?->credit ?? 0), 4);
                $net = round($debit - $credit, 4);

                $account->movement_debit = $debit;
                $account->movement_credit = $credit;
                $account->balance_debit = max($net, 0);
                $account->balance_credit = max(-$net, 0);

                return $account;
            });

        $totalMovementDebit = round((float) $accounts->sum('movement_debit'), 4);
        $totalMovementCredit = round((float) $accounts->sum('movement_credit'), 4);
        $totalBalanceDebit = round((float) $accounts->sum('balance_debit'), 4);
        $totalBalanceCredit = round((float) $accounts->sum('balance_credit'), 4);

        return view('admin.accounting.trial-balance', [
            'accounts' => $accounts,
            'activeAccountsCount' => $accounts->filter(fn ($account) => $account->movement_debit > 0 || $account->movement_credit > 0)->count(),
            'from' => $from,
            'to' => $to,
            'typeLabels' => $this->typeLabels(),
            'normalBalanceLabels' => ['debit' => 'مدين', 'credit' => 'دائن'],
            'totalMovementDebit' => $totalMovementDebit,
            'totalMovementCredit' => $totalMovementCredit,
            'totalBalanceDebit' => $totalBalanceDebit,
            'totalBalanceCredit' => $totalBalanceCredit,
            'isBalanced' => abs($totalBalanceDebit - $totalBalanceCredit) < 0.01,
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
}
