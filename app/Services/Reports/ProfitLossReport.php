<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\AccountMapping;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\OrderDiscount;
use App\Models\Refund;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use App\Support\MarketProfile;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The complete restaurant Profit & Loss calculator.
 *
 * One canonical source of truth for the P&L numbers — the screen, the Excel
 * export, and the PDF report all hand the request to this service and read
 * back the same `toArray()`. Pulling the math out of the controller keeps
 * the formula sheet auditable in one place: every line below has a comment
 * explaining what it represents and why a given source/filter is the right
 * one for that line.
 *
 * Date semantics (deliberate, NOT accidental):
 *   - Invoices       → filter by `issued_at` (when the bill was cut)
 *   - Expenses       → filter by `expense_date` (operating month, not entry day)
 *   - Refunds        → filter by `refunded_at` (cash actually went back)
 *   - Inventory      → filter by `occurred_at` (movement timestamp)
 * Mixing these is what causes "the numbers don't tie" surprises in P&Ls.
 */
class ProfitLossReport
{
    public string $from;
    public string $to;
    public string $start;     // 'Y-m-d 00:00:00'
    public string $end;       // 'Y-m-d 23:59:59'
    public ?int   $branchId;
    public bool   $usePerBranchCost;
    public string $source;
    protected ?array $postingRoleCodes = null;

    public function __construct(string $from, string $to, ?int $branchId = null, bool $usePerBranchCost = false, string $source = 'ledger')
    {
        $this->from             = $from;
        $this->to               = $to;
        $this->start            = $from.' 00:00:00';
        $this->end              = $to.' 23:59:59';
        $this->branchId         = $branchId;
        $this->usePerBranchCost = $usePerBranchCost && $branchId;
        $this->source           = $source === 'operations' ? 'operations' : 'ledger';
    }

    /**
     * Compute the full report. Returns a deeply nested array shaped for both
     * the Blade view and the export sheets — keeping the contract here makes
     * it easy to spot when a key is renamed or removed.
     */
    public function compute(): array
    {
        if ($this->source === 'ledger') {
            return $this->computeFromLedger();
        }

        // ─── 1. Settled invoices in the period (the basis for revenue) ──
        // "Settled" = paid OR partially paid. Issued-only invoices are
        // earned but uncollected; we surface them as `unpaid_balance` so
        // the manager sees what's still in receivables.
        $invoiceQuery = Invoice::whereBetween('issued_at', [$this->start, $this->end])
            ->whereIn('status', ['paid', 'partially_paid']);
        $invoiceQuery = $this->scopeEloquent($invoiceQuery, 'invoices.branch_id');

        $sumsQuery = (clone $invoiceQuery);
        $totals = $sumsQuery->selectRaw('
            COUNT(*) as invoice_count,
            COALESCE(SUM(subtotal),0)        as gross_sales,
            COALESCE(SUM(discount_total),0)  as discounts_total,
            COALESCE(SUM(tax_total),0)       as tax_collected,
            COALESCE(SUM(service_total),0)   as service_collected,
            COALESCE(SUM(tip),0)             as tip_collected,
            COALESCE(SUM(delivery_fee),0)    as delivery_collected,
            COALESCE(SUM(total),0)           as total_billed,
            COALESCE(SUM(paid_total),0)      as total_collected
        ')->first();

        $grossSales       = (float) $totals->gross_sales;
        $discountsTotal   = (float) $totals->discounts_total;
        $netSales         = max(0, $grossSales - $discountsTotal);
        $taxCollected     = (float) $totals->tax_collected;
        $serviceCollected = (float) $totals->service_collected;
        $tipCollected     = (float) $totals->tip_collected;
        $deliveryCollected= (float) $totals->delivery_collected;
        $totalBilled      = (float) $totals->total_billed;
        $totalCollected   = (float) $totals->total_collected;
        $invoiceCount     = (int)   $totals->invoice_count;

        // Unpaid receivables — issued but not yet paid (still inside period)
        $unpaidQuery = Invoice::whereBetween('issued_at', [$this->start, $this->end])
            ->whereIn('status', ['issued', 'partially_paid']);
        $unpaidBalance = (float) $this->scopeEloquent($unpaidQuery, 'invoices.branch_id')->sum('balance');

        // ─── 2. Cost of Goods Sold (COGS) ───────────────────────────────
        // Sum of (qty × menu_item.cost) for non-cancelled items belonging
        // to non-cancelled orders billed in the period. When the per-branch
        // cost toggle is on, recipes are re-priced using the branch's own
        // weighted-average ingredient cost — more accurate when branches
        // buy from different suppliers.
        $cogs = $this->cogs();

        // ─── 3. Inventory shrinkage ─────────────────────────────────────
        // Logged via Waste forms — separate from sale-driven consumption.
        $wasteQuery = InventoryMovement::whereBetween('occurred_at', [$this->start, $this->end])
            ->where('type', 'waste');
        $wasteCost = (float) $this->scopeEloquent($wasteQuery, 'inventory_movements.branch_id')->sum('total_cost');

        // ─── 4. Operating Expenses ──────────────────────────────────────
        // Approved petty-cash + ops expenses. Filter by `expense_date` so a
        // March rent paid in early April still shows under March.
        $expensesQuery = Expense::whereBetween('expense_date', [$this->from, $this->to])
            ->where('status', 'approved');
        $expensesQuery = $this->scopeEloquent($expensesQuery, 'expenses.branch_id');
        $expensesTotal = (float) (clone $expensesQuery)->sum('amount');

        // ─── 5. Platform commissions ────────────────────────────────────
        // External delivery platforms (Talabat / Careem / Uber) charge a
        // % of order total. Stored on each order; multiply through.
        $ordersForCommissionQuery = DB::table('orders')
            ->whereBetween('created_at', [$this->start, $this->end])
            ->whereNotIn('status', ['cancelled']);
        $ordersForCommissionQuery = $this->scopeRaw($ordersForCommissionQuery, 'orders.branch_id');
        $platformCommission = (float) ($ordersForCommissionQuery
            ->selectRaw('COALESCE(SUM(total * platform_commission_pct / 100),0) as commission')
            ->value('commission') ?? 0);

        // ─── 6. Refunds (issued back to customers in the period) ────────
        $refundsQuery = Refund::whereBetween('refunded_at', [$this->start, $this->end])
            ->whereIn('status', ['processed', 'pending']);
        $refundsTotal = (float) $this->scopeRefunds($refundsQuery)->sum('amount');

        // ─── 7. The profit ladder ───────────────────────────────────────
        // gross_profit = net_sales − cogs − waste
        // operating_profit = gross_profit − expenses − platform_commission
        // net_profit = operating_profit − refunds
        // Margins always vs. net_sales (income earned on items).
        $grossProfit     = $netSales - $cogs - $wasteCost;
        $operatingProfit = $grossProfit - $expensesTotal - $platformCommission;
        $netProfit       = $operatingProfit - $refundsTotal;

        $grossMarginPct  = $netSales > 0 ? ($grossProfit / $netSales) * 100 : 0;
        $operatingMarginPct = $netSales > 0 ? ($operatingProfit / $netSales) * 100 : 0;
        $netMarginPct    = $netSales > 0 ? ($netProfit / $netSales) * 100 : 0;

        $averageTicket   = $invoiceCount > 0 ? $totalCollected / $invoiceCount : 0;
        $daysCount       = max(1, Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1);
        $dailyAverage    = $totalCollected / $daysCount;

        // ─── 8. Daily trend (for charts) ────────────────────────────────
        $trend = $this->dailyTrend();

        // ─── 9. Discount breakdowns ─────────────────────────────────────
        $discountsByCategory = $this->discountsByCategory();
        $discountsByCashier  = $this->discountsByCashier();
        $discountsCount      = (int) (clone $this->discountsBaseQuery())->count();

        // ─── 10. Expense breakdowns ─────────────────────────────────────
        $expensesByCategory = $this->expensesByCategory($expensesQuery);
        $expensesByMethod   = $this->expensesByMethod($expensesQuery);
        $recentExpenses     = (clone $expensesQuery)
            ->with(['category', 'creator'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // ─── 11. Top profitable items ───────────────────────────────────
        $topItems = $this->topItems();

        return [
            'period' => [
                'from'        => $this->from,
                'to'          => $this->to,
                'days'        => $daysCount,
                'branch_id'   => $this->branchId,
                'per_branch_cost' => $this->usePerBranchCost,
                'source'      => $this->source,
            ],
            'sales' => [
                'gross_sales'        => round($grossSales, 2),
                'discounts_total'    => round($discountsTotal, 2),
                'net_sales'          => round($netSales, 2),
                'tax_collected'      => round($taxCollected, 2),
                'service_collected'  => round($serviceCollected, 2),
                'tip_collected'      => round($tipCollected, 2),
                'delivery_collected' => round($deliveryCollected, 2),
                'total_billed'       => round($totalBilled, 2),
                'total_collected'    => round($totalCollected, 2),
                'unpaid_balance'     => round($unpaidBalance, 2),
                'invoice_count'      => $invoiceCount,
                'average_ticket'     => round($averageTicket, 2),
                'daily_average'      => round($dailyAverage, 2),
            ],
            'costs' => [
                'cogs'                => round($cogs, 2),
                'waste'               => round($wasteCost, 2),
                'expenses'            => round($expensesTotal, 2),
                'platform_commission' => round($platformCommission, 2),
                'refunds'             => round($refundsTotal, 2),
            ],
            'profit' => [
                'gross_profit'         => round($grossProfit, 2),
                'operating_profit'     => round($operatingProfit, 2),
                'net_profit'           => round($netProfit, 2),
                'gross_margin_pct'     => round($grossMarginPct, 2),
                'operating_margin_pct' => round($operatingMarginPct, 2),
                'net_margin_pct'       => round($netMarginPct, 2),
            ],
            'discounts' => [
                'count'        => $discountsCount,
                'total'        => round($discountsTotal, 2),
                'by_category'  => $discountsByCategory,
                'by_cashier'   => $discountsByCashier,
            ],
            'expenses_breakdown' => [
                'by_category' => $expensesByCategory,
                'by_method'   => $expensesByMethod,
                'recent'      => $recentExpenses,
            ],
            'trend'    => $trend,
            'top_items'=> $topItems,
        ];
    }

    // ─── COGS computation (extracted) ──────────────────────────────────

    protected function computeFromLedger(): array
    {
        $rows = $this->ledgerRows();
        $pieces = $this->ledgerPieces($rows);

        $daysCount = max(1, Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1);
        $totalCollected = $this->ledgerPaymentDebitTotal();
        $totalBilled = $this->ledgerInvoiceReceivableNet();
        $unpaidBalance = max(0, $this->ledgerNetAny($rows, $this->postingRoleCodes('accounts_receivable'), 'debit'));
        $invoiceCount = $this->ledgerInvoiceCount();
        $averageTicket = $invoiceCount > 0 ? $totalCollected / $invoiceCount : 0;
        $dailyAverage = $totalCollected / $daysCount;

        return [
            'period' => [
                'from'        => $this->from,
                'to'          => $this->to,
                'days'        => $daysCount,
                'branch_id'   => $this->branchId,
                'per_branch_cost' => false,
                'source'      => 'ledger',
            ],
            'sales' => [
                'gross_sales'        => round($pieces['gross_sales'], 2),
                'discounts_total'    => round($pieces['discounts_total'], 2),
                'net_sales'          => round($pieces['net_sales'], 2),
                'tax_collected'      => round($this->ledgerNetAny($rows, $this->postingRoleCodes('output_vat'), 'credit'), 2),
                'service_collected'  => round($this->ledgerNetAny($rows, $this->postingRoleCodes('service_revenue'), 'credit'), 2),
                'tip_collected'      => round($this->ledgerNetAny($rows, $this->postingRoleCodes('tips_payable'), 'credit'), 2),
                'delivery_collected' => round($this->ledgerNetAny($rows, $this->postingRoleCodes('delivery_revenue'), 'credit'), 2),
                'total_billed'       => round($totalBilled, 2),
                'total_collected'    => round($totalCollected, 2),
                'unpaid_balance'     => round($unpaidBalance, 2),
                'invoice_count'      => $invoiceCount,
                'average_ticket'     => round($averageTicket, 2),
                'daily_average'      => round($dailyAverage, 2),
            ],
            'costs' => [
                'cogs'                => round($pieces['cogs'], 2),
                'waste'               => round($pieces['waste'], 2),
                'expenses'            => round($pieces['expenses'], 2),
                'platform_commission' => round($pieces['platform_commission'], 2),
                'refunds'             => round($pieces['refunds'], 2),
            ],
            'profit' => [
                'gross_profit'         => round($pieces['gross_profit'], 2),
                'operating_profit'     => round($pieces['operating_profit'], 2),
                'net_profit'           => round($pieces['net_profit'], 2),
                'gross_margin_pct'     => round($pieces['gross_margin_pct'], 2),
                'operating_margin_pct' => round($pieces['operating_margin_pct'], 2),
                'net_margin_pct'       => round($pieces['net_margin_pct'], 2),
            ],
            'discounts' => [
                'count'        => (int) collect($this->ledgerContraBreakdown($rows))->sum('count'),
                'total'        => round($pieces['discounts_total'], 2),
                'by_category'  => $this->ledgerContraBreakdown($rows),
                'by_cashier'   => [],
            ],
            'expenses_breakdown' => [
                'by_category' => $this->ledgerExpensesByAccount($rows),
                'by_method'   => [],
                'recent'      => collect(),
            ],
            'trend'    => $this->ledgerDailyTrend(),
            'top_items'=> collect(),
        ];
    }

    protected function ledgerRows()
    {
        return $this->ledgerLineQuery()
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('
                accounts.id,
                accounts.code,
                accounts.name,
                accounts.type,
                COUNT(journal_lines.id) as line_count,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get();
    }

    protected function ledgerLineQuery(): QueryBuilder
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.posted_on', '>=', $this->from)
            ->whereDate('journal_entries.posted_on', '<=', $this->to)
            // Closing entries zero revenue/expense into retained earnings — they
            // are a bookkeeping mechanism, not operating activity, so the income
            // statement must exclude BOTH the monthly period close AND the annual
            // fiscal-year close (the latter was missing → an annual P&L that spans
            // the year-end close showed zero revenue/expense).
            ->whereNotIn('journal_entries.event_type', [
                'period_closing', 'period_closing_reversal',
                'fiscal_year_closing', 'fiscal_year_closing_reversal',
            ]);

        if ($this->branchId) {
            $query->where('journal_entries.branch_id', $this->branchId);
        }

        return $query;
    }

    protected function postingRoleCodes(string $role): array
    {
        if ($this->postingRoleCodes === null) {
            $definitions = AccountingService::postingRoleDefinitions();
            $mappings = AccountMapping::with('account')
                ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
                ->get()
                ->keyBy('key');

            $this->postingRoleCodes = [];
            foreach ($definitions as $key => $definition) {
                $codes = [$definition['default']];
                $account = $mappings->get($key)?->account;
                $allowedTypes = $definition['types'] ?? [];

                if ($account && $account->is_active && in_array($account->type, $allowedTypes, true)) {
                    $codes[] = $account->code;
                }

                $this->postingRoleCodes[$key] = collect($codes)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        return $this->postingRoleCodes[$role]
            ?? [AccountingService::postingRoleDefinitions()[$role]['default'] ?? ''];
    }

    protected function ledgerPieces($rows): array
    {
        $grossSales = (float) collect($rows)
            ->where('type', 'revenue')
            ->sum(fn ($row) => (float) $row->credit - (float) $row->debit);

        $contraTotal = (float) collect($rows)
            ->where('type', 'contra_revenue')
            ->sum(fn ($row) => (float) $row->debit - (float) $row->credit);

        $refunds = $this->ledgerNetAny($rows, $this->postingRoleCodes('sales_returns'), 'debit');
        $discountsTotal = $contraTotal - $refunds;
        $netSales = $grossSales - $discountsTotal;

        $expenseTotal = (float) collect($rows)
            ->where('type', 'expense')
            ->sum(fn ($row) => (float) $row->debit - (float) $row->credit);

        $cogs = $this->ledgerNetAny($rows, $this->postingRoleCodes('cost_of_goods_sold'), 'debit');
        $waste = $this->ledgerNetAny($rows, $this->postingRoleCodes('waste_expense'), 'debit');
        $platformCommission = $this->ledgerNetAny($rows, $this->postingRoleCodes('bank_and_card_fees'), 'debit');
        $expenses = $expenseTotal - $cogs - $waste - $platformCommission;

        $grossProfit = $netSales - $cogs - $waste;
        $operatingProfit = $grossProfit - $expenses - $platformCommission;
        $netProfit = $operatingProfit - $refunds;

        return [
            'gross_sales' => $grossSales,
            'discounts_total' => $discountsTotal,
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'waste' => $waste,
            'expenses' => $expenses,
            'platform_commission' => $platformCommission,
            'refunds' => $refunds,
            'gross_profit' => $grossProfit,
            'operating_profit' => $operatingProfit,
            'net_profit' => $netProfit,
            'gross_margin_pct' => $netSales != 0.0 ? ($grossProfit / $netSales) * 100 : 0,
            'operating_margin_pct' => $netSales != 0.0 ? ($operatingProfit / $netSales) * 100 : 0,
            'net_margin_pct' => $netSales != 0.0 ? ($netProfit / $netSales) * 100 : 0,
        ];
    }

    protected function ledgerNet($rows, string $code, string $normalBalance): float
    {
        $row = collect($rows)->firstWhere('code', $code);
        if (! $row) {
            return 0.0;
        }

        $debit = (float) $row->debit;
        $credit = (float) $row->credit;

        return $normalBalance === 'credit'
            ? $credit - $debit
            : $debit - $credit;
    }

    protected function ledgerNetAny($rows, array $codes, string $normalBalance): float
    {
        return collect($codes)
            ->unique()
            ->sum(fn (string $code) => $this->ledgerNet($rows, $code, $normalBalance));
    }

    protected function ledgerPaymentDebitTotal(): float
    {
        return (float) $this->ledgerLineQuery()
            ->where('journal_entries.event_type', 'payment_received')
            ->sum('journal_lines.debit');
    }

    protected function ledgerInvoiceReceivableNet(): float
    {
        $row = $this->ledgerLineQuery()
            ->whereIn('accounts.code', $this->postingRoleCodes('accounts_receivable'))
            ->where(function ($query) {
                $query->whereIn('journal_entries.event_type', ['invoice_issued', 'invoice_cancelled'])
                    ->orWhere('journal_entries.event_type', 'like', 'invoice_reissued_%');
            })
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as net')
            ->first();

        return (float) ($row->net ?? 0);
    }

    protected function ledgerInvoiceCount(): int
    {
        $reversedIds = $this->ledgerReversedEntryIds();

        $query = JournalEntry::query()
            ->where('status', 'posted')
            ->where('source_type', Invoice::class)
            ->whereDate('posted_on', '>=', $this->from)
            ->whereDate('posted_on', '<=', $this->to)
            ->where(function ($query) {
                $query->where('event_type', 'invoice_issued')
                    ->orWhere('event_type', 'like', 'invoice_reissued_%');
            });

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        return $query->get(['id', 'source_id'])
            ->reject(fn (JournalEntry $entry) => in_array((int) $entry->id, $reversedIds, true))
            ->pluck('source_id')
            ->filter()
            ->unique()
            ->count();
    }

    protected function ledgerReversedEntryIds(): array
    {
        $query = JournalEntry::query()
            ->where('status', 'posted')
            ->whereNotNull('metadata')
            ->whereDate('posted_on', '>=', $this->from)
            ->whereDate('posted_on', '<=', $this->to);

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        return $query->get(['metadata'])
            ->map(fn (JournalEntry $entry) => (int) ($entry->metadata['reverses_entry_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function ledgerContraBreakdown($rows): array
    {
        $refundCodes = $this->postingRoleCodes('sales_returns');

        return collect($rows)
            ->filter(fn ($row) => $row->type === 'contra_revenue' && ! in_array($row->code, $refundCodes, true))
            ->map(fn ($row) => [
                'id' => $row->id,
                'label' => "{$row->code} - {$row->name}",
                'color' => '#94a3b8',
                'count' => (int) $row->line_count,
                'total' => round((float) $row->debit - (float) $row->credit, 2),
            ])
            ->filter(fn ($row) => abs($row['total']) > 0.0001)
            ->sortByDesc(fn ($row) => abs($row['total']))
            ->values()
            ->all();
    }

    protected function ledgerExpensesByAccount($rows): array
    {
        $excluded = collect([
            ...$this->postingRoleCodes('cost_of_goods_sold'),
            ...$this->postingRoleCodes('waste_expense'),
            ...$this->postingRoleCodes('bank_and_card_fees'),
        ])->unique()->values()->all();

        return collect($rows)
            ->filter(fn ($row) => $row->type === 'expense' && ! in_array($row->code, $excluded, true))
            ->map(fn ($row) => [
                'id' => $row->id,
                'label' => "{$row->code} - {$row->name}",
                'color' => '#94a3b8',
                'count' => (int) $row->line_count,
                'total' => round((float) $row->debit - (float) $row->credit, 2),
            ])
            ->filter(fn ($row) => abs($row['total']) > 0.0001)
            ->sortByDesc(fn ($row) => abs($row['total']))
            ->values()
            ->all();
    }

    protected function ledgerDailyTrend()
    {
        $rowsByDay = $this->ledgerLineQuery()
            ->groupBy('journal_entries.posted_on', 'accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->selectRaw('
                journal_entries.posted_on as day,
                accounts.id,
                accounts.code,
                accounts.name,
                accounts.type,
                COUNT(journal_lines.id) as line_count,
                COALESCE(SUM(journal_lines.debit), 0) as debit,
                COALESCE(SUM(journal_lines.credit), 0) as credit
            ')
            ->get()
            ->groupBy('day');

        $collectedByDay = $this->ledgerLineQuery()
            ->where('journal_entries.event_type', 'payment_received')
            ->groupBy('journal_entries.posted_on')
            ->selectRaw('journal_entries.posted_on as day, COALESCE(SUM(journal_lines.debit), 0) as collected')
            ->get()
            ->pluck('collected', 'day');

        $days = collect();
        $cursor = Carbon::parse($this->from);
        $endCursor = Carbon::parse($this->to);

        while ($cursor->lte($endCursor)) {
            $day = $cursor->toDateString();
            $pieces = $this->ledgerPieces($rowsByDay->get($day, collect()));

            $days->push([
                'date'      => $day,
                'label'     => Carbon::parse($day)->locale(MarketProfile::lang())->isoFormat('ddd D/M'),
                'net_sales' => round($pieces['net_sales'], 2),
                'collected' => round((float) ($collectedByDay[$day] ?? 0), 2),
                'cogs'      => round($pieces['cogs'], 2),
                'expenses'  => round($pieces['expenses'], 2),
                'profit'    => round($pieces['net_profit'], 2),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    protected function cogs(): float
    {
        if ($this->usePerBranchCost) {
            $rows = $this->soldItemsQuery()
                ->selectRaw('menu_items.id as menu_item_id, SUM(order_items.quantity) as qty')
                ->groupBy('menu_items.id')
                ->get();
            $itemIds = $rows->pluck('menu_item_id')->all();
            $items = MenuItem::with('recipeItems.ingredient.baseUnit')
                ->whereIn('id', $itemIds)
                ->get()
                ->keyBy('id');
            $cogs = 0.0;
            foreach ($rows as $r) {
                $item = $items->get($r->menu_item_id);
                if (! $item) continue;
                $cogs += (float) $r->qty * $item->costAtBranch($this->branchId);
            }
            return $cogs;
        }

        return (float) ($this->soldItemsQuery()
            ->selectRaw('SUM(order_items.quantity * menu_items.cost) as cogs')
            ->value('cogs') ?? 0);
    }

    // ─── Daily trend (rev / cogs / expenses / profit per day) ──────────

    protected function dailyTrend()
    {
        $revQ = Invoice::whereBetween('issued_at', [$this->start, $this->end])
            ->whereIn('status', ['paid', 'partially_paid']);
        $revByDay = $this->scopeEloquent($revQ, 'invoices.branch_id')
            ->selectRaw('DATE(issued_at) as day, SUM(subtotal - discount_total) as net_sales, SUM(paid_total) as collected')
            ->groupBy('day')
            ->get()->keyBy('day');

        $cogsByDay = $this->soldItemsQuery()
            ->selectRaw('DATE(orders.created_at) as day, SUM(order_items.quantity * menu_items.cost) as cogs')
            ->groupBy('day')
            ->pluck('cogs', 'day');

        $expQ = Expense::whereBetween('expense_date', [$this->from, $this->to])->where('status', 'approved');
        $expensesByDay = $this->scopeEloquent($expQ, 'expenses.branch_id')
            ->selectRaw('DATE(expense_date) as day, SUM(amount) as expenses')
            ->groupBy('day')
            ->pluck('expenses', 'day');

        $days = collect();
        $cursor = Carbon::parse($this->from);
        $endCursor = Carbon::parse($this->to);
        while ($cursor->lte($endCursor)) {
            $d = $cursor->toDateString();
            $row = $revByDay->get($d);
            $netSales = (float) ($row->net_sales ?? 0);
            $collected = (float) ($row->collected ?? 0);
            $cogs = (float) ($cogsByDay[$d] ?? 0);
            $exp = (float) ($expensesByDay[$d] ?? 0);
            $days->push([
                'date'      => $d,
                'label'     => Carbon::parse($d)->locale(MarketProfile::lang())->isoFormat('ddd D/M'),
                'net_sales' => round($netSales, 2),
                'collected' => round($collected, 2),
                'cogs'      => round($cogs, 2),
                'expenses'  => round($exp, 2),
                'profit'    => round($netSales - $cogs - $exp, 2),
            ]);
            $cursor->addDay();
        }

        return $days;
    }

    // ─── Discount breakdowns ───────────────────────────────────────────

    protected function discountsBaseQuery()
    {
        // Discounts attached to orders whose invoices were settled in the
        // period. Joining via invoice keeps us aligned with the revenue
        // window (issued_at) rather than the discount creation timestamp.
        $q = OrderDiscount::query()
            ->join('orders', 'order_discounts.order_id', '=', 'orders.id')
            ->join('invoices', function ($join) {
                $join->on('invoices.order_id', '=', 'orders.id')
                    ->orOn('invoices.table_session_id', '=', 'orders.table_session_id');
            })
            ->whereBetween('invoices.issued_at', [$this->start, $this->end])
            ->whereIn('invoices.status', ['paid', 'partially_paid']);

        if ($this->branchId) {
            $q->where('invoices.branch_id', $this->branchId);
        }
        return $q;
    }

    protected function discountsByCategory(): array
    {
        return $this->discountsBaseQuery()
            ->leftJoin('lookups', 'order_discounts.category_lookup_id', '=', 'lookups.id')
            ->selectRaw('lookups.id as id, lookups.label as label, lookups.color as color, COUNT(*) as count, SUM(order_discounts.amount) as total')
            ->groupBy('lookups.id', 'lookups.label', 'lookups.color')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id'    => $r->id,
                'label' => $r->label ?: 'بدون تصنيف',
                'color' => $r->color ?: '#94a3b8',
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ])
            ->toArray();
    }

    protected function discountsByCashier(): array
    {
        return $this->discountsBaseQuery()
            ->leftJoin('users', 'order_discounts.applied_by_user_id', '=', 'users.id')
            ->selectRaw('users.id as id, users.name as name, COUNT(*) as count, SUM(order_discounts.amount) as total')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'    => $r->id,
                'name'  => $r->name ?: 'غير محدد',
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ])
            ->toArray();
    }

    // ─── Expense breakdowns ────────────────────────────────────────────

    protected function expensesByCategory($baseQuery): array
    {
        return (clone $baseQuery)
            ->leftJoin('lookups', 'expenses.expense_category_id', '=', 'lookups.id')
            ->selectRaw('lookups.id as id, lookups.label as label, lookups.color as color, COUNT(*) as count, SUM(expenses.amount) as total')
            ->groupBy('lookups.id', 'lookups.label', 'lookups.color')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'id'    => $r->id,
                'label' => $r->label ?: 'بدون تصنيف',
                'color' => $r->color ?: '#94a3b8',
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ])
            ->toArray();
    }

    protected function expensesByMethod($baseQuery): array
    {
        $labels = ['cash' => 'نقدي', 'card' => 'بطاقة', 'bank_transfer' => 'حوالة بنكية', 'cheque' => 'شيك', 'other' => 'أخرى'];
        return (clone $baseQuery)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->payment_method,
                'label'  => $labels[$r->payment_method] ?? $r->payment_method,
                'count'  => (int) $r->count,
                'total'  => round((float) $r->total, 2),
            ])
            ->toArray();
    }

    // ─── Top items by profit contribution ──────────────────────────────

    protected function topItems()
    {
        return $this->soldItemsQuery()
            ->selectRaw('
                menu_items.id,
                menu_items.name,
                SUM(order_items.quantity) as qty,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.quantity * menu_items.cost) as cogs,
                SUM(order_items.subtotal - (order_items.quantity * menu_items.cost)) as profit
            ')
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('profit')
            ->limit(10)
            ->get();
    }

    // ─── Branch scoping helpers ────────────────────────────────────────

    protected function scopeRaw(QueryBuilder $query, string $branchColumn): QueryBuilder
    {
        if ($this->branchId) {
            $query->where($branchColumn, $this->branchId);
        }
        return $query;
    }

    protected function scopeEloquent($query, string $branchColumn)
    {
        if ($this->branchId) {
            $query->where($branchColumn, $this->branchId);
        }
        return $query;
    }

    /**
     * Refunds inherit branch via their parent invoice. Eloquent has no
     * Originally routed through `invoice` because Refund had no direct
     * `branch_id`. As of May 2026 the column exists and BelongsToBranch
     * auto-filters via BranchContext. We still expose this method for
     * call-site stability but it now just respects the context.
     */
    protected function scopeRefunds($query)
    {
        if ($this->branchId) {
            // Defensive direct filter — the report may run outside an
            // HTTP request (CLI, queue) where BranchContext isn't bound,
            // so we don't rely on the global scope alone.
            $query->where('refunds.branch_id', $this->branchId);
        }
        return $query;
    }

    protected function soldItemsQuery(): QueryBuilder
    {
        $q = DB::table('order_items')
            ->join('orders',     'order_items.order_id',     '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereBetween('orders.created_at', [$this->start, $this->end])
            ->where('order_items.status', '!=', 'cancelled')
            ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed']);
        return $this->scopeRaw($q, 'orders.branch_id');
    }
}
