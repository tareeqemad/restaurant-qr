<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Support\BranchContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Inject the active-branch filter into a raw query builder. Eloquent
     * models with the BelongsToBranch trait scope automatically; raw
     * `DB::table(...)` queries bypass that scope, so this helper has to
     * stamp the WHERE clause explicitly.
     *
     * No-op when no branch is bound (Super Admin global view).
     */
    protected function scopeRaw(QueryBuilder $query, string $branchColumn): QueryBuilder
    {
        if ($branchId = BranchContext::current()) {
            $query->where($branchColumn, $branchId);
        }
        return $query;
    }

    /**
     * Resolve a from/to date range from the request (defaulting to the last
     * `$defaultDays` days) and return the four forms every report needs:
     * date strings + start/end datetime strings padded to the day boundaries.
     *
     * @return array{0:string,1:string,2:string,3:string}  [from, to, start, end]
     */
    protected function dateRange(Request $request, int $defaultDays = 30): array
    {
        $from = $request->get('from', now()->subDays($defaultDays)->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        return [$from, $to, $from.' 00:00:00', $to.' 23:59:59'];
    }

    /**
     * Base query: order_items joined to orders + menu_items, scoped to the
     * active branch and filtered to "really sold" items (non-cancelled item,
     * non-cancelled order in a status that means it actually went out).
     *
     * Centralizing this means the definition of "a sale" lives in one place;
     * if the status whitelist changes (e.g. a new "refunded" state), every
     * report inherits the fix.
     */
    protected function soldItemsQuery(string $start, string $end): QueryBuilder
    {
        return $this->scopeRaw(
            DB::table('order_items')
                ->join('orders',     'order_items.order_id',     '=', 'orders.id')
                ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled')
                ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed']),
            'orders.branch_id'
        );
    }

    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());
        [$start, $end] = [$from.' 00:00:00', $to.' 23:59:59'];

        $invoiceQuery = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid']);

        $revenue = (float) (clone $invoiceQuery)->sum('paid_total');
        $invoiceCount = (int) (clone $invoiceQuery)->count();

        $ordersQuery = \App\Models\Order::whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', ['cancelled']);
        $ordersCount = (int) (clone $ordersQuery)->count();

        $cogs = (float) ($this->soldItemsQuery($start, $end)
            ->selectRaw('SUM(order_items.quantity * menu_items.cost) as cogs')
            ->value('cogs') ?? 0);

        $wasteCost = (float) InventoryMovement::whereBetween('occurred_at', [$start, $end])
            ->where('type', 'waste')
            ->sum('total_cost');

        $commissionPaid = (float) ($this->scopeRaw(
            DB::table('orders')
                ->whereBetween('created_at', [$start, $end])
                ->whereNotIn('status', ['cancelled']),
            'orders.branch_id'
        )
            ->selectRaw('SUM(total * platform_commission_pct / 100) as commission_paid')
            ->value('commission_paid') ?? 0);

        $unpaidBalance = (float) Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['issued', 'partially_paid'])
            ->sum('balance');

        $grossProfit = $revenue - $cogs;
        $marginPct = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;
        $averageTicket = $invoiceCount > 0 ? $revenue / $invoiceCount : 0;
        $daysCount = \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1;
        $dailyAverage = $daysCount > 0 ? $revenue / $daysCount : 0;

        $revenueByDay = (clone $invoiceQuery)
            ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as revenue, COUNT(*) as invoices_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $trend = collect();
        for ($i = 0; $i < $daysCount; $i++) {
            $day = \Carbon\Carbon::parse($from)->addDays($i)->toDateString();
            $row = $revenueByDay->get($day);
            $trend->push((object) [
                'day' => $day,
                'label' => \Carbon\Carbon::parse($day)->format('d/m'),
                'revenue' => (float) ($row->revenue ?? 0),
                'invoices_count' => (int) ($row->invoices_count ?? 0),
            ]);
        }

        $topItems = $this->soldItemsQuery($start, $end)
            ->selectRaw('
                menu_items.name,
                SUM(order_items.quantity) as qty,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.subtotal - (order_items.quantity * menu_items.cost)) as profit
            ')
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('profit')
            ->limit(6)
            ->get();

        $sourceMix = $this->scopeRaw(
            DB::table('orders')
                ->whereBetween('created_at', [$start, $end])
                ->whereNotIn('status', ['cancelled']),
            'orders.branch_id'
        )
            ->selectRaw('order_source, COUNT(*) as orders_count, SUM(total) as revenue')
            ->groupBy('order_source')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $enum = \App\Enums\OrderSource::tryFrom($row->order_source);
                $row->label = $enum?->label() ?? ($row->order_source ?: 'غير محدد');
                $row->icon = $enum?->icon() ?? 'bi-box';
                $row->color = $enum?->color() ?? '#6b7280';
                return $row;
            });

        $alerts = collect();
        if ($revenue > 0 && $marginPct < 30) {
            $alerts->push([
                'tone' => 'danger',
                'icon' => 'bi-exclamation-triangle-fill',
                'title' => 'هامش الربح منخفض',
                'body' => 'الهامش الحالي '.number_format($marginPct, 1).'%؛ راجع تكلفة الوصفات والأسعار في تقرير الأرباح والخسائر.',
                'link' => route('admin.reports.profit-loss', compact('from', 'to')),
            ]);
        }
        if ($cogs > 0 && $wasteCost > ($cogs * 0.05)) {
            $alerts->push([
                'tone' => 'warning',
                'icon' => 'bi-trash3-fill',
                'title' => 'الهدر أعلى من الطبيعي',
                'body' => 'قيمة الهدر تمثل '.number_format(($wasteCost / $cogs) * 100, 1).'% من تكلفة المبيعات.',
                'link' => route('admin.reports.inventory', compact('from', 'to')),
            ]);
        }
        if ($revenue > 0 && $commissionPaid > ($revenue * 0.08)) {
            $alerts->push([
                'tone' => 'warning',
                'icon' => 'bi-percent',
                'title' => 'عمولات المنصات مؤثرة',
                'body' => 'العمولات المدفوعة تساوي '.number_format(($commissionPaid / $revenue) * 100, 1).'% من الإيراد المحصل.',
                'link' => route('admin.reports.sales-by-platform', compact('from', 'to')),
            ]);
        }
        if ($unpaidBalance > 0) {
            $alerts->push([
                'tone' => 'info',
                'icon' => 'bi-wallet2',
                'title' => 'رصيد غير محصل',
                'body' => 'يوجد رصيد فواتير غير محصل بقيمة '.\App\Helpers\Money::format($unpaidBalance).'.',
                'link' => null,
            ]);
        }
        if ($alerts->isEmpty()) {
            $alerts->push([
                'tone' => 'success',
                'icon' => 'bi-check-circle-fill',
                'title' => 'المؤشرات مستقرة',
                'body' => 'لا توجد إشارات حرجة ضمن الفترة المختارة. استخدم البطاقات أدناه للتعمق.',
                'link' => null,
            ]);
        }

        return view('admin.reports.index', compact(
            'from', 'to', 'revenue', 'invoiceCount', 'ordersCount', 'cogs',
            'grossProfit', 'marginPct', 'averageTicket', 'dailyAverage',
            'wasteCost', 'commissionPaid', 'unpaidBalance', 'trend',
            'topItems', 'sourceMix', 'alerts'
        ));
    }

    public function sales(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        $rows = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid', 'unpaid_writeoff'])
            ->select(DB::raw('DATE(issued_at) as day'),
                DB::raw('COUNT(*) as invoices_count'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(tax_total) as tax'),
                DB::raw('SUM(service_total) as service'),
                DB::raw('SUM(total) as total'),
                DB::raw('SUM(paid_total) as paid'),
            )
            ->groupBy('day')
            ->orderBy('day', 'desc')
            ->get();

        $totals = [
            'invoices_count' => (int) $rows->sum('invoices_count'),
            'subtotal'       => (float) $rows->sum('subtotal'),
            'tax'            => (float) $rows->sum('tax'),
            'service'        => (float) $rows->sum('service'),
            'total'          => (float) $rows->sum('total'),
            'paid'           => (float) $rows->sum('paid'),
        ];

        $daysCount = \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1;
        $averageDaily = $daysCount > 0 ? $totals['paid'] / $daysCount : 0;
        $averageInvoice = $totals['invoices_count'] > 0 ? $totals['paid'] / $totals['invoices_count'] : 0;
        $collectionGap = max(0, $totals['total'] - $totals['paid']);
        $collectionRate = $totals['total'] > 0 ? ($totals['paid'] / $totals['total']) * 100 : 0;
        $bestDay = $rows->sortByDesc('paid')->first();
        $quietDay = $rows->where('paid', '>', 0)->sortBy('paid')->first();

        return view('admin.reports.sales', compact(
            'rows', 'from', 'to', 'totals', 'averageDaily', 'averageInvoice',
            'collectionGap', 'collectionRate', 'bestDay', 'quietDay'
        ));
    }

    public function items(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        // Items report keeps its own simpler join (no menu_items, no status whitelist) —
        // it counts everything that was sent to a station, including items still in flight.
        $rows = $this->scopeRaw(
            DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled'),
            'orders.branch_id'
        )
            ->select('order_items.name_snapshot',
                DB::raw('SUM(order_items.quantity) as qty'),
                DB::raw('SUM(order_items.subtotal) as total'),
            )
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->paginate(50)
            ->withQueryString();

        return view('admin.reports.items', compact('rows', 'from', 'to'));
    }

    public function inventory(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        $rows = InventoryMovement::with('ingredient', 'unit')
            ->whereBetween('occurred_at', [$start, $end])
            ->select('ingredient_id', 'type',
                DB::raw('SUM(quantity_in_base) as qty'),
                DB::raw('SUM(total_cost) as total_cost'),
            )
            ->groupBy('ingredient_id', 'type')
            ->get()
            ->groupBy('ingredient_id');

        return view('admin.reports.inventory', compact('rows', 'from', 'to'));
    }

    public function shifts(Request $request)
    {
        $shifts = Shift::with('user')
            ->latest('opened_at')
            ->paginate(30)
            ->withQueryString();
        $monthStart = now()->startOfMonth();
        $shiftStats = [
            'open' => Shift::whereNull('closed_at')->count(),
            'today' => Shift::whereDate('opened_at', now()->toDateString())->count(),
            'month_sales' => (float) Shift::whereBetween('opened_at', [$monthStart, now()])->sum('total_sales'),
            'cash_variance' => (float) Shift::whereBetween('opened_at', [$monthStart, now()])->sum('cash_variance'),
        ];

        return view('admin.reports.shifts', compact('shifts', 'shiftStats'));
    }

    /**
     * Menu Engineering — classifies menu items into a 2×2 matrix:
     *
     *              High margin        │ Low margin
     *   ─────────┼───────────────────┼────────────────
     *   High pop │ ⭐ Star            │ 🐎 Plowhorse
     *   Low pop  │ 🧩 Puzzle          │ 🐕 Dog
     *
     *   - Star:       keep as-is, highlight on menu
     *   - Plowhorse:  popular but low-margin → raise price or re-engineer recipe
     *   - Puzzle:     profitable but unpopular → market more, move to prominent place
     *   - Dog:        drop from menu or reinvent
     *
     * Uses MEDIAN split (not mean) so one viral bestseller doesn't skew the axis.
     */
    public function menuEngineering(Request $request)
    {
        [$from, $to, $start, $end] = $this->dateRange($request);

        $items = $this->soldItemsQuery($start, $end)
            ->selectRaw('
                menu_items.id,
                menu_items.name,
                menu_items.price,
                menu_items.cost,
                SUM(order_items.quantity) as qty_sold,
                SUM(order_items.subtotal) as revenue,
                SUM(order_items.quantity * menu_items.cost) as cogs
            ')
            ->groupBy('menu_items.id', 'menu_items.name', 'menu_items.price', 'menu_items.cost')
            ->get();

        if ($items->isEmpty()) {
            return view('admin.reports.menu-engineering', [
                'from' => $from, 'to' => $to,
                'classified' => collect(),
                'buckets'    => ['star' => 0, 'plowhorse' => 0, 'puzzle' => 0, 'dog' => 0],
                'thresholds' => null,
            ]);
        }

        foreach ($items as $it) {
            $it->profit     = (float) $it->revenue - (float) $it->cogs;
            $it->margin_pct = (float) $it->revenue > 0
                ? ($it->profit / (float) $it->revenue) * 100
                : 0;
        }

        // Median split on both axes
        $sortedByQty    = $items->sortBy('qty_sold')->values();
        $sortedByMargin = $items->sortBy('margin_pct')->values();

        $midIndex     = intdiv($items->count(), 2);
        $medianQty    = (float) $sortedByQty[$midIndex]->qty_sold;
        $medianMargin = (float) $sortedByMargin[$midIndex]->margin_pct;

        foreach ($items as $it) {
            $highPop    = (float) $it->qty_sold   >= $medianQty;
            $highMargin = (float) $it->margin_pct >= $medianMargin;

            $it->class = match (true) {
                $highPop  &&  $highMargin => 'star',
                $highPop  && !$highMargin => 'plowhorse',
                !$highPop &&  $highMargin => 'puzzle',
                default                   => 'dog',
            };
        }

        $buckets = [
            'star'      => $items->where('class', 'star')->count(),
            'plowhorse' => $items->where('class', 'plowhorse')->count(),
            'puzzle'    => $items->where('class', 'puzzle')->count(),
            'dog'       => $items->where('class', 'dog')->count(),
        ];

        return view('admin.reports.menu-engineering', [
            'from'       => $from,
            'to'         => $to,
            'classified' => $items->sortByDesc('profit'),
            'buckets'    => $buckets,
            'thresholds' => [
                'median_qty'    => $medianQty,
                'median_margin' => $medianMargin,
            ],
        ]);
    }

    /**
     * Sales by delivery platform — breakdown of revenue by acquisition channel
     * with commission deducted to show net revenue.
     */
    public function salesByPlatform(Request $request)
    {
        // Default to month-to-date (not the trailing 30 days) — owners think
        // of acquisition channels in calendar months for commission reporting.
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to',   now()->toDateString());
        [$start, $end] = [$from.' 00:00:00', $to.' 23:59:59'];

        $rows = $this->scopeRaw(
            DB::table('orders')
                ->whereBetween('created_at', [$start, $end])
                ->whereNotIn('status', ['cancelled']),
            'orders.branch_id'
        )
            ->selectRaw('
                order_source,
                COUNT(*) as order_count,
                SUM(total) as gross_revenue,
                AVG(platform_commission_pct) as avg_commission_pct,
                SUM(total * platform_commission_pct / 100) as commission_paid,
                SUM(total * (1 - platform_commission_pct/100)) as net_revenue
            ')
            ->groupBy('order_source')
            ->orderByDesc('gross_revenue')
            ->get();

        // Enrich with enum helpers for UI
        $enrichedRows = $rows->map(function ($r) {
            $enum = \App\Enums\OrderSource::tryFrom($r->order_source);
            $r->label = $enum?->label() ?? $r->order_source;
            $r->color = $enum?->color() ?? '#6b7280';
            $r->icon  = $enum?->icon()  ?? 'bi-box';
            return $r;
        });

        $totals = [
            'order_count'     => (int) $rows->sum('order_count'),
            'gross_revenue'   => (float) $rows->sum('gross_revenue'),
            'commission_paid' => (float) $rows->sum('commission_paid'),
            'net_revenue'     => (float) $rows->sum('net_revenue'),
        ];

        return view('admin.reports.sales-by-platform', [
            'from'   => $from,
            'to'     => $to,
            'rows'   => $enrichedRows,
            'totals' => $totals,
        ]);
    }

    /**
     * Reorder Suggestions — "what to order from whom, how much, and how
     * urgently".
     *
     * Picks ingredients at or below their reorder threshold, groups them by
     * supplier, and computes:
     *   - suggested_qty   = max(30-day consumption, 2 × threshold, 1)
     *   - estimated_cost  = qty × cost_per_unit
     *   - daily_burn      = used_30d / 30
     *   - days_to_stockout = current_stock / daily_burn  (∞ if no usage)
     *   - urgency         = 'critical' (≤2 days) | 'high' (≤7) | 'medium' (≤14) | 'low' (>14)
     *
     * Days-to-stockout is the actionable number: it answers "do I order now
     * or can it wait until tomorrow's delivery run?".
     */
    public function reorderSuggestions()
    {
        // 30-day consumption per ingredient (out + waste from movements)
        // — already scoped to active branch by scopeRaw().
        $usage = $this->scopeRaw(
            DB::table('inventory_movements')
                ->whereIn('type', ['out', 'waste'])
                ->where('occurred_at', '>=', now()->subDays(30)),
            'inventory_movements.branch_id'
        )
            ->selectRaw('ingredient_id, SUM(quantity_in_base) as used')
            ->groupBy('ingredient_id')
            ->pluck('used', 'ingredient_id');

        // Branch-aware candidate selection: when a branch is active, an
        // ingredient is a candidate iff its PER-BRANCH stock is at or below
        // its PER-BRANCH threshold. Otherwise fall back to global comparison.
        $branchId = BranchContext::current();

        if ($branchId) {
            // Filter in PHP using helpers — pivot+sum query is more complex
            // and the candidate set is small (only tracked ingredients).
            $tracked = \App\Models\Ingredient::with('baseUnit', 'supplier')
                ->where('track_stock', true)
                ->orderBy('supplier_id')
                ->orderBy('name')
                ->get();
            $candidates = $tracked->filter(fn ($i) => $i->isLowStockAtBranch($branchId))->values();
        } else {
            $candidates = \App\Models\Ingredient::with('baseUnit', 'supplier')
                ->where('track_stock', true)
                ->whereColumn('current_stock', '<=', 'reorder_threshold')
                ->orderBy('supplier_id')
                ->orderBy('name')
                ->get();
        }

        foreach ($candidates as $ing) {
            $used30 = (float) ($usage[$ing->id] ?? 0);

            // Per-branch numbers when branch context is set.
            $current   = $branchId ? $ing->stockAtBranch($branchId)              : (float) $ing->current_stock;
            $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId)   : (float) $ing->reorder_threshold;
            $cpu       = $branchId ? $ing->costAtBranch($branchId)               : (float) $ing->cost_per_unit;

            $suggested = max($used30, 2 * $threshold, 1);
            $dailyBurn = $used30 / 30;
            $days      = $dailyBurn > 0 ? $current / $dailyBurn : INF;

            $ing->used_30d         = $used30;
            $ing->suggested_qty    = $suggested;
            $ing->estimated_cost   = $suggested * $cpu;
            $ing->daily_burn       = $dailyBurn;
            $ing->days_to_stockout = $days;
            $ing->urgency          = $this->urgencyBucket($days, $current);

            // Override the model attributes for the view so it shows per-branch values
            $ing->setAttribute('current_stock', $current);
            $ing->setAttribute('reorder_threshold', $threshold);
            $ing->setAttribute('cost_per_unit', $cpu);
        }

        // Group by supplier for convenient PO-per-supplier creation
        $bySupplier = $candidates->groupBy(fn($i) => $i->supplier_id ?? 0);

        $totalCost = (float) $candidates->sum('estimated_cost');

        // Aggregate urgency counts for the stat rail
        $urgencyCounts = [
            'critical' => $candidates->where('urgency', 'critical')->count(),
            'high'     => $candidates->where('urgency', 'high')->count(),
            'medium'   => $candidates->where('urgency', 'medium')->count(),
            'low'      => $candidates->where('urgency', 'low')->count(),
        ];

        return view('admin.reports.reorder-suggestions', [
            'bySupplier'   => $bySupplier,
            'totalCost'    => $totalCost,
            'totalItems'   => $candidates->count(),
            'urgencyCounts'=> $urgencyCounts,
        ]);
    }

    protected function urgencyBucket(float $days, float $currentStock): string
    {
        // Already at/below zero — emergency.
        if ($currentStock <= 0)         return 'critical';
        if ($days <= 2)                 return 'critical';
        if ($days <= 7)                 return 'high';
        if ($days <= 14)                return 'medium';
        return 'low';
    }

    /**
     * Bulk-create draft POs from selected reorder candidates.
     *
     * The form posts:
     *   selections[] = "ingredientId:qty"   (one entry per checked row)
     *
     * We group by supplier, create one DRAFT PO per supplier (using the
     * existing PurchaseOrderService::create), and redirect to the PO list
     * with a per-supplier summary in the flash bag. The buyer can then open
     * each draft, tweak quantities/prices, and send.
     *
     * Items without a supplier are skipped with a clear message — they
     * must be assigned a supplier first via the ingredient edit page.
     */
    public function createBulkReorderPOs(Request $request, \App\Services\PurchaseOrderService $poService)
    {
        $this->authorize('create', \App\Models\PurchaseOrder::class);

        $data = $request->validate([
            'selections'   => ['required', 'array', 'min:1'],
            'selections.*' => ['regex:/^\d+:[\d\.]+$/'],
        ], [
            'selections.required' => 'لم يتم اختيار أي مكوّن.',
        ]);

        // Parse "id:qty" → [ ingredient_id => qty ]
        $picks = [];
        foreach ($data['selections'] as $entry) {
            [$id, $qty] = explode(':', $entry);
            $picks[(int) $id] = (float) $qty;
        }

        $ingredients = \App\Models\Ingredient::with('baseUnit', 'supplier')
            ->whereIn('id', array_keys($picks))
            ->get();

        // Group by supplier_id; null suppliers go into a "skipped" bucket.
        $bySupplier = $ingredients->groupBy('supplier_id');

        $createdPOs = [];
        $skipped    = [];

        foreach ($bySupplier as $supplierId => $items) {
            if (! $supplierId) {
                $skipped = array_merge($skipped, $items->pluck('name')->toArray());
                continue;
            }

            $lines = $items->map(fn ($ing) => [
                'ingredient_id'    => $ing->id,
                'unit_id'          => $ing->base_unit_id,           // suggested in base unit
                'quantity_ordered' => $picks[$ing->id] ?? 0,
                'unit_price'       => (float) $ing->cost_per_unit,  // last known cost — buyer will edit
                'notes'             => 'مولّد آلياً من اقتراحات إعادة الطلب',
            ])->filter(fn ($l) => $l['quantity_ordered'] > 0)->values()->toArray();

            if (empty($lines)) continue;

            try {
                $po = $poService->create(
                    data: [
                        'supplier_id' => $supplierId,
                        'expected_at' => null,
                        'notes'       => 'تم توليد هذا الـ PO آلياً من اقتراحات إعادة الطلب.'
                            . ' راجع الكميات والأسعار قبل الإرسال.',
                    ],
                    lines: $lines,
                    userId: $request->user()->id,
                );
                $createdPOs[] = $po;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $msg = count($createdPOs) === 0
            ? 'لم يتم إنشاء أي أمر شراء.'
            : 'تم إنشاء ' . count($createdPOs) . ' أمر شراء كمسودات. راجعها قبل الإرسال.';

        if (! empty($skipped)) {
            $msg .= ' تم تخطي مكوّنات بلا مورّد محدد: ' . implode('، ', array_unique($skipped))
                . '. حدد مورّداً لها من شاشة المكونات أولاً.';
        }

        return redirect()->route('admin.purchase-orders.index', ['status' => 'draft'])
            ->with('success', $msg);
    }

    /**
     * P&L — the real profit picture.
     *
     *   Gross Revenue = Σ(paid_total) for invoices in range
     *   COGS          = Σ(order_item.quantity × menu_item.cost) for sold items
     *   Gross Profit  = Revenue − COGS
     *   Waste         = Σ(total_cost) of inventory_movements with type='waste'
     *   Purchases     = Σ(total_cost) of inventory_movements with type='in'
     *                   (cash paid out for stock in range)
     *   Net Operating = Gross Profit − Waste
     *
     * Note: Purchases are shown separately (cash flow) because they don't
     * reduce operating profit immediately — they become inventory until consumed.
     */
    public function profitLoss(Request $request)
    {
        // Default to month-to-date — P&L is a monthly conversation with owners.
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to',   now()->toDateString());
        [$start, $end] = [$from.' 00:00:00', $to.' 23:59:59'];

        // Revenue: paid & partially paid invoices
        $revenue = (float) Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->sum('paid_total');

        // Invoice count + avg ticket
        $invoiceCount = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->count();

        // COGS: sum of (quantity × menu_item.cost). When in a branch context
        // and the user opts in via ?per_branch_cost=1, we re-cost each line
        // using the BRANCH-SPECIFIC recipe cost (per-branch ingredient cost
        // → per-branch recipe cost). This is more accurate when branches buy
        // at different prices, at the cost of a slightly heavier compute.
        $branchId = BranchContext::current();
        $usePerBranchCost = $branchId && $request->boolean('per_branch_cost');

        if ($usePerBranchCost) {
            $rows = $this->soldItemsQuery($start, $end)
                ->selectRaw('menu_items.id as menu_item_id, SUM(order_items.quantity) as qty')
                ->groupBy('menu_items.id')
                ->get();
            $itemIds = $rows->pluck('menu_item_id')->all();
            $items = \App\Models\MenuItem::with('recipeItems.ingredient.baseUnit')
                ->whereIn('id', $itemIds)
                ->get()
                ->keyBy('id');
            $cogs = 0.0;
            foreach ($rows as $r) {
                $item = $items->get($r->menu_item_id);
                if (! $item) continue;
                $cogs += (float) $r->qty * $item->costAtBranch($branchId);
            }
        } else {
            $cogs = (float) $this->soldItemsQuery($start, $end)
                ->selectRaw('SUM(order_items.quantity * menu_items.cost) as cogs')
                ->value('cogs') ?? 0;
        }

        // Waste: value of ingredients discarded in range
        $wasteCost = (float) InventoryMovement::whereBetween('occurred_at', [$start, $end])
            ->where('type', 'waste')
            ->sum('total_cost');

        // Purchases (cash out for stock received in range)
        $purchasesCost = (float) InventoryMovement::whereBetween('occurred_at', [$start, $end])
            ->where('type', 'in')
            ->sum('total_cost');

        // Revenue by day (for the sparkline)
        $revenueByDay = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $cogsByDay = $this->soldItemsQuery($start, $end)
            ->selectRaw('DATE(orders.created_at) as day, SUM(order_items.quantity * menu_items.cost) as cogs')
            ->groupBy('day')
            ->pluck('cogs', 'day');

        // Build day-by-day trend — fill all days in range
        $daysCount = \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1;
        $trend = collect();
        for ($i = 0; $i < $daysCount; $i++) {
            $d = \Carbon\Carbon::parse($from)->addDays($i)->toDateString();
            $rev = (float) ($revenueByDay[$d] ?? 0);
            $cog = (float) ($cogsByDay[$d]    ?? 0);
            $trend->push([
                'date'    => $d,
                'label'   => \Carbon\Carbon::parse($d)->locale('ar')->isoFormat('ddd D/M'),
                'revenue' => $rev,
                'cogs'    => $cog,
                'profit'  => $rev - $cog,
            ]);
        }

        // Top profitable items (margin × volume = contribution)
        $topProfit = $this->soldItemsQuery($start, $end)
            ->selectRaw('
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

        $grossProfit  = $revenue - $cogs;
        $netOperating = $grossProfit - $wasteCost;
        $marginPct    = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

        return view('admin.reports.profit-loss', compact(
            'from', 'to',
            'revenue', 'cogs', 'grossProfit', 'wasteCost', 'netOperating',
            'purchasesCost', 'invoiceCount', 'marginPct',
            'trend', 'topProfit'
        ));
    }

    /**
     * Stock Valuation — point-in-time snapshot of inventory worth.
     *
     * "As of date X, what was each tracked SKU's stock × cost worth?"
     * Two modes:
     *   - `as_of` not set → use the live `current_stock` × `cost_per_unit`
     *     (cheap, current, what the kitchen actually holds right now)
     *   - `as_of` provided → "rewind" by replaying movements: start from
     *     current_stock and SUBTRACT every movement that occurred AFTER
     *     as_of (since they happened after our cutoff date). Cost per unit
     *     is taken as the weighted-average AT that time, derived from the
     *     last 'in' movement before the cutoff. This is the close-of-day
     *     valuation accountants ask for at month-end.
     *
     * Includes ABC analysis (Pareto): rank SKUs by value desc, mark top
     * 80% as A, next 15% as B, last 5% as C. Helps the buyer focus
     * negotiation and counts on the items that matter.
     *
     * CSV export available via `?export=csv`.
     */
    public function stockValuation(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);

        $asOf = $request->get('as_of');                  // YYYY-MM-DD or null
        $asOfTs = $asOf ? \Carbon\Carbon::parse($asOf)->endOfDay() : null;

        $ingredients = \App\Models\Ingredient::with('baseUnit', 'supplier')
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        // For historical mode: aggregate signed movements PER ingredient
        // AFTER the cutoff. We invert their effect to recover the qty as it
        // was at $asOfTs. Cheap because of the (ingredient_id, occurred_at)
        // index already on the table.
        $reverseDeltas = collect();
        if ($asOfTs) {
            $rows = \App\Models\InventoryMovement::query()
                ->where('occurred_at', '>', $asOfTs)
                ->selectRaw("
                    ingredient_id,
                    SUM(CASE
                        WHEN type IN ('out','waste') THEN -quantity_in_base
                        ELSE quantity_in_base
                    END) as net_after_cutoff
                ")
                ->groupBy('ingredient_id')
                ->get();
            $reverseDeltas = $rows->keyBy('ingredient_id');
        }

        $valuationRows = collect();
        foreach ($ingredients as $ing) {
            $qty = (float) $ing->current_stock;
            if ($asOfTs && isset($reverseDeltas[$ing->id])) {
                // Subtract the "after cutoff" net effect to rewind
                $qty -= (float) $reverseDeltas[$ing->id]->net_after_cutoff;
            }

            // Skip rows with no stock at the chosen point
            if ($qty <= 0.0001 && ! $asOfTs) {
                // For live mode, KEEP zero rows — useful to see "we hold none of X"
                // Only filter out negatives produced by historical rewind anomalies.
                $qty = max(0, $qty);
            }

            // Cost: live mode uses current cost_per_unit. Historical mode
            // uses the most recent 'in' movement at-or-before the cutoff.
            $unitCost = (float) $ing->cost_per_unit;
            if ($asOfTs) {
                $hist = \App\Models\InventoryMovement::query()
                    ->where('ingredient_id', $ing->id)
                    ->where('type', 'in')
                    ->where('occurred_at', '<=', $asOfTs)
                    ->orderByDesc('occurred_at')
                    ->value('unit_cost');
                if ($hist !== null) $unitCost = (float) $hist;
            }

            $value = $qty * $unitCost;

            $valuationRows->push((object) [
                'ingredient_id' => $ing->id,
                'name'          => $ing->name,
                'sku'           => $ing->sku,
                'supplier'      => $ing->supplier?->name,
                'unit_code'     => $ing->baseUnit?->code,
                'qty'           => $qty,
                'unit_cost'     => $unitCost,
                'value'         => $value,
                'is_low_stock'  => $ing->isLowStock(),
            ]);
        }

        // Sort by value desc and apply ABC classification (Pareto: 80/15/5)
        $sorted = $valuationRows->sortByDesc('value')->values();
        $totalValue = (float) $sorted->sum('value');

        $cumulative = 0.0;
        foreach ($sorted as $row) {
            $cumulative += $row->value;
            $sharePct = $totalValue > 0 ? ($cumulative / $totalValue) * 100 : 0;
            $row->cumulative_pct = $sharePct;
            $row->abc_class = match (true) {
                $sharePct <= 80 => 'A',
                $sharePct <= 95 => 'B',
                default          => 'C',
            };
        }

        // Aggregates for stat rail + ABC summary
        $abcCounts = [
            'A' => $sorted->where('abc_class', 'A')->count(),
            'B' => $sorted->where('abc_class', 'B')->count(),
            'C' => $sorted->where('abc_class', 'C')->count(),
        ];
        $abcValues = [
            'A' => (float) $sorted->where('abc_class', 'A')->sum('value'),
            'B' => (float) $sorted->where('abc_class', 'B')->sum('value'),
            'C' => (float) $sorted->where('abc_class', 'C')->sum('value'),
        ];

        $lowStockValue = (float) $sorted->where('is_low_stock', true)->sum('value');
        $rowCount      = $sorted->count();

        // CSV export
        if ($request->get('export') === 'csv') {
            return $this->exportValuationCsv($sorted, $asOf);
        }

        return view('admin.reports.stock-valuation', [
            'rows'          => $sorted,
            'totalValue'    => $totalValue,
            'rowCount'      => $rowCount,
            'lowStockValue' => $lowStockValue,
            'abcCounts'     => $abcCounts,
            'abcValues'     => $abcValues,
            'asOf'          => $asOf,
        ]);
    }

    protected function exportValuationCsv($rows, ?string $asOf)
    {
        $filename = 'stock-valuation-' . ($asOf ?: now()->toDateString()) . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel recognises UTF-8 (Arabic columns)
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'SKU', 'Ingredient', 'Supplier', 'Unit',
                'Quantity', 'Unit Cost', 'Total Value',
                'ABC Class', 'Cumulative %', 'Low Stock?',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->sku,
                    $r->name,
                    $r->supplier ?? '',
                    $r->unit_code ?? '',
                    number_format($r->qty, 4, '.', ''),
                    number_format($r->unit_cost, 4, '.', ''),
                    number_format($r->value, 2, '.', ''),
                    $r->abc_class,
                    number_format($r->cumulative_pct, 2, '.', ''),
                    $r->is_low_stock ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Branch Comparison Report — side-by-side KPIs across every active branch.
     *
     * Restricted to owner-level users (Super Admin + Partner) since it
     * surfaces data from all branches at once. Branch admins/managers
     * already have their own per-branch reports.
     *
     * KPIs shown (rows) × Branches (columns):
     *   - Stock value (Σ qty × cost across branch's locations)
     *   - Low-stock SKUs count
     *   - 30-day waste cost
     *   - 30-day COGS (out movements × unit_cost)
     *   - 30-day purchases value (in movements × unit_cost)
     *   - 30-day revenue (paid invoices)
     *   - Active orders count
     *   - Active table sessions
     *   - Open POs count
     *   - Outstanding AP balance (supplier invoices)
     *
     * Each KPI row marks the best/worst branch with a green/red highlight
     * so an owner can spot patterns in seconds.
     */
    public function branchComparison(Request $request)
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'isOwnerLevel') || ! $user->isOwnerLevel()) {
            abort(403, 'هذا التقرير متاح فقط لمستخدمي المستوى المالك (Super Admin / Partner).');
        }

        [$from, $to, $start, $end] = $this->dateRange($request);

        $branches = \App\Models\Branch::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Bypass BranchScope to read across all branches.
        $rows = \App\Support\BranchContext::unscoped(function () use ($branches, $start, $end) {
            return $branches->map(function ($branch) use ($start, $end) {
                $bid = $branch->id;

                // Stock value at branch = Σ over the branch's storage_locations
                // of (qty × ingredient.cost_per_unit). Used the global cpu here
                // intentionally — a branch-cost view for valuation is the
                // job of the dedicated stock-valuation report.
                $stockValue = (float) \App\Models\IngredientStock::query()
                    ->join('ingredients',       'ingredient_stock.ingredient_id',       '=', 'ingredients.id')
                    ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
                    ->where('storage_locations.branch_id', $bid)
                    ->selectRaw('SUM(ingredient_stock.quantity * ingredients.cost_per_unit) as v')
                    ->value('v') ?? 0;

                // Tracked ingredients with stock at this branch ≤ branch threshold
                $lowCount = \App\Models\Ingredient::where('track_stock', true)
                    ->get()
                    ->filter(fn ($i) => $i->isLowStockAtBranch($bid)
                        && $i->stockAtBranch($bid) > 0)
                    ->count();

                $wasteCost = (float) \App\Models\InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'waste')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $cogs = (float) \App\Models\InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'out')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $purchases = (float) \App\Models\InventoryMovement::query()
                    ->where('branch_id', $bid)
                    ->where('type', 'in')
                    ->whereBetween('occurred_at', [$start, $end])
                    ->sum('total_cost');

                $revenue = (float) \App\Models\Invoice::query()
                    ->where('branch_id', $bid)
                    ->whereBetween('issued_at', [$start, $end])
                    ->whereIn('status', ['paid', 'partially_paid'])
                    ->sum('paid_total');

                $openPOs = \App\Models\PurchaseOrder::query()
                    ->where('branch_id', $bid)
                    ->whereIn('status', ['draft', 'sent', 'partially_received'])
                    ->count();

                $apBalance = (float) \App\Models\SupplierInvoice::query()
                    ->where('branch_id', $bid)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->sum('balance');

                $invoiceCount = \App\Models\Invoice::query()
                    ->where('branch_id', $bid)
                    ->whereBetween('issued_at', [$start, $end])
                    ->count();

                return (object) [
                    'branch'         => $branch,
                    'stock_value'    => $stockValue,
                    'low_count'      => $lowCount,
                    'waste_cost'     => $wasteCost,
                    'cogs'           => $cogs,
                    'purchases'      => $purchases,
                    'revenue'        => $revenue,
                    'invoice_count'  => $invoiceCount,
                    'open_pos'       => $openPOs,
                    'ap_balance'     => $apBalance,
                    'gross_profit'   => $revenue - $cogs,
                    'profit_margin'  => $revenue > 0 ? (($revenue - $cogs) / $revenue) * 100 : 0,
                    'waste_pct_of_cogs' => $cogs > 0 ? ($wasteCost / $cogs) * 100 : 0,
                ];
            });
        });

        // Decide which direction is "good" for each KPI so the heatmap
        // can highlight best (green) vs worst (red).
        // higher_is_better = true → max value is good
        // higher_is_better = false → min value is good (lower waste = better)
        $kpiDefs = [
            ['key' => 'revenue',          'label' => 'الإيراد',                 'fmt' => 'money',  'higher_is_better' => true],
            ['key' => 'cogs',             'label' => 'COGS',                     'fmt' => 'money',  'higher_is_better' => null],  // contextual
            ['key' => 'gross_profit',     'label' => 'الربح الإجمالي',           'fmt' => 'money',  'higher_is_better' => true],
            ['key' => 'profit_margin',    'label' => 'هامش الربح %',            'fmt' => 'percent','higher_is_better' => true],
            ['key' => 'waste_cost',       'label' => 'تكلفة الهدر',              'fmt' => 'money',  'higher_is_better' => false],
            ['key' => 'waste_pct_of_cogs','label' => 'الهدر كـ % من COGS',      'fmt' => 'percent','higher_is_better' => false],
            ['key' => 'purchases',        'label' => 'مشتريات الفترة',           'fmt' => 'money',  'higher_is_better' => null],
            ['key' => 'stock_value',      'label' => 'قيمة المخزون الحالي',     'fmt' => 'money',  'higher_is_better' => null],
            ['key' => 'low_count',        'label' => 'مكونات بمخزون منخفض',     'fmt' => 'int',    'higher_is_better' => false],
            ['key' => 'invoice_count',    'label' => 'عدد الفواتير',             'fmt' => 'int',    'higher_is_better' => true],
            ['key' => 'open_pos',         'label' => 'POs مفتوحة',              'fmt' => 'int',    'higher_is_better' => null],
            ['key' => 'ap_balance',       'label' => 'مستحقات للموردين',        'fmt' => 'money',  'higher_is_better' => false],
        ];

        // Pre-compute best/worst index per KPI for highlighting
        foreach ($kpiDefs as &$kpi) {
            if ($kpi['higher_is_better'] === null) {
                $kpi['best_idx']  = null;
                $kpi['worst_idx'] = null;
                continue;
            }
            $values = $rows->pluck($kpi['key'])->all();
            if (empty($values) || max($values) === min($values)) {
                $kpi['best_idx']  = null;
                $kpi['worst_idx'] = null;
                continue;
            }
            $kpi['best_idx']  = $kpi['higher_is_better']
                ? array_search(max($values), $values, true)
                : array_search(min($values), $values, true);
            $kpi['worst_idx'] = $kpi['higher_is_better']
                ? array_search(min($values), $values, true)
                : array_search(max($values), $values, true);
        }
        unset($kpi);

        // CSV export — same data, same shape, ready for Excel/Google Sheets.
        // The accountant just wants the matrix as a file.
        if ($request->get('export') === 'csv') {
            return $this->exportBranchComparisonCsv($branches, $rows, $kpiDefs, $from, $to);
        }

        return view('admin.reports.branch-comparison', [
            'branches' => $branches,
            'rows'     => $rows,
            'kpiDefs'  => $kpiDefs,
            'from'     => $from,
            'to'       => $to,
        ]);
    }

    /**
     * Stream the branch-comparison matrix as a CSV. The output matches
     * what the on-screen table shows: KPIs in rows, branches in columns,
     * preceded by a small header (date range + generated-at timestamp).
     *
     * UTF-8 BOM so Arabic labels render correctly when opened directly
     * in Excel without going through the import wizard.
     */
    protected function exportBranchComparisonCsv($branches, $rows, $kpiDefs, string $from, string $to)
    {
        $filename = "branch-comparison-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($branches, $rows, $kpiDefs, $from, $to) {
            $out = fopen('php://output', 'w');
            // BOM so Excel recognises UTF-8 (Arabic labels)
            fwrite($out, "\xEF\xBB\xBF");

            // Two-line header: scope + timestamp
            fputcsv($out, ['Branch Comparison Report', "From: {$from}", "To: {$to}", 'Generated: '.now()->toDateTimeString()]);
            fputcsv($out, []);

            // Column header row: "KPI" + each branch name
            $header = ['KPI', 'Direction'];
            foreach ($branches as $branch) {
                $header[] = $branch->name . ($branch->code ? " ({$branch->code})" : '');
            }
            fputcsv($out, $header);

            // Each KPI gets its own row
            foreach ($kpiDefs as $kpi) {
                $direction = match ($kpi['higher_is_better']) {
                    true  => 'higher = better',
                    false => 'lower = better',
                    null  => '',
                };
                $line = [$kpi['label'], $direction];
                foreach ($rows as $row) {
                    $value = $row->{$kpi['key']};
                    if (is_null($value)) {
                        $line[] = '';
                    } elseif ($kpi['fmt'] === 'money' || $kpi['fmt'] === 'percent') {
                        $line[] = number_format((float) $value, 2, '.', '');
                    } else {
                        $line[] = (int) $value;
                    }
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function endOfDay(Request $request)
    {
        $date = $request->get('date', now()->toDateString());
        $start = $date.' 00:00:00';
        $end = $date.' 23:59:59';

        $invoices = Invoice::whereBetween('issued_at', [$start, $end])->get();
        $payments = \App\Models\Payment::whereBetween('paid_at', [$start, $end])->get();

        $byMethod = $payments->groupBy('method')->map(fn($g) => [
            'count' => $g->count(),
            'total' => (float) $g->sum('amount'),
        ]);

        $orders = \App\Models\Order::whereBetween('created_at', [$start, $end])->get();

        // EoD top items mirrors the simpler items() report (no menu_items join,
        // no status whitelist) — owners want to see "what went out today",
        // including items still on the line.
        $topItems = $this->scopeRaw(
            DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$start, $end])
                ->where('order_items.status', '!=', 'cancelled'),
            'orders.branch_id'
        )
            ->select('order_items.name_snapshot', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.subtotal) as total'))
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->limit(15)
            ->get();

        $inventoryUsage = $this->scopeRaw(
            DB::table('inventory_movements')
                ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
                ->whereBetween('inventory_movements.occurred_at', [$start, $end])
                ->whereIn('inventory_movements.type', ['out', 'waste']),
            'inventory_movements.branch_id'
        )
            ->select('ingredients.name', 'inventory_movements.type',
                DB::raw('SUM(inventory_movements.quantity_in_base) as qty'),
                DB::raw('SUM(inventory_movements.total_cost) as cost'))
            ->groupBy('ingredients.name', 'inventory_movements.type')
            ->get()
            ->groupBy('name');

        $shifts = Shift::with('user')->whereDate('opened_at', $date)->get();

        $summary = [
            'invoices_count' => $invoices->count(),
            'invoices_paid' => $invoices->where('status', 'paid')->count(),
            'invoices_unpaid' => $invoices->whereIn('status', ['issued', 'partially_paid'])->count(),
            'invoices_writeoff' => $invoices->where('status', 'unpaid_writeoff')->count(),
            'orders_count' => $orders->count(),
            'orders_cancelled' => $orders->where('status', 'cancelled')->count(),
            'gross_sales' => (float) $invoices->sum('subtotal'),
            'tax_total' => (float) $invoices->sum('tax_total'),
            'service_total' => (float) $invoices->sum('service_total'),
            'discount_total' => (float) $invoices->sum('discount_total'),
            'total_billed' => (float) $invoices->sum('total'),
            'total_collected' => (float) $payments->sum('amount'),
        ];

        return view('admin.reports.end-of-day', compact('date', 'summary', 'byMethod', 'topItems', 'inventoryUsage', 'shifts'));
    }
}
