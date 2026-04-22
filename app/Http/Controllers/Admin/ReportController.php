<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $rows = Invoice::whereBetween('issued_at', [$from.' 00:00:00', $to.' 23:59:59'])
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

        return view('admin.reports.sales', compact('rows', 'from', 'to'));
    }

    public function items(Request $request)
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $rows = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('order_items.status', '!=', 'cancelled')
            ->select('order_items.name_snapshot',
                DB::raw('SUM(order_items.quantity) as qty'),
                DB::raw('SUM(order_items.subtotal) as total'),
            )
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->paginate(50);

        return view('admin.reports.items', compact('rows', 'from', 'to'));
    }

    public function inventory(Request $request)
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to = $request->get('to', now()->toDateString());

        $rows = InventoryMovement::with('ingredient', 'unit')
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
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
        $shifts = Shift::with('user')->latest('opened_at')->paginate(30);
        return view('admin.reports.shifts', compact('shifts'));
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
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $items = DB::table('order_items')
            ->join('orders',      'order_items.order_id',     '=', 'orders.id')
            ->join('menu_items',  'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereBetween('orders.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('order_items.status', '!=', 'cancelled')
            ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed'])
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
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $rows = DB::table('orders')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNotIn('status', ['cancelled'])
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
     * Reorder Suggestions — "what to order from whom, how much".
     *
     * Picks ingredients at or below their reorder threshold, groups them by
     * supplier, and suggests a reorder qty = max(30-day consumption, 2 × threshold).
     */
    public function reorderSuggestions()
    {
        // 30-day consumption per ingredient (out + waste from movements)
        $usage = DB::table('inventory_movements')
            ->whereIn('type', ['out', 'waste'])
            ->where('occurred_at', '>=', now()->subDays(30))
            ->selectRaw('ingredient_id, SUM(quantity_in_base) as used')
            ->groupBy('ingredient_id')
            ->pluck('used', 'ingredient_id');

        $candidates = \App\Models\Ingredient::with('baseUnit', 'supplier')
            ->where('track_stock', true)
            ->whereColumn('current_stock', '<=', 'reorder_threshold')
            ->orderBy('supplier_id')
            ->orderBy('name')
            ->get();

        foreach ($candidates as $ing) {
            $used30 = (float) ($usage[$ing->id] ?? 0);
            $suggested = max($used30, 2 * (float) $ing->reorder_threshold, 1);
            $ing->used_30d     = $used30;
            $ing->suggested_qty = $suggested;
            $ing->estimated_cost = $suggested * (float) $ing->cost_per_unit;
        }

        // Group by supplier for convenient PO-per-supplier creation
        $bySupplier = $candidates->groupBy(fn($i) => $i->supplier_id ?? 0);

        $totalCost = (float) $candidates->sum('estimated_cost');

        return view('admin.reports.reorder-suggestions', [
            'bySupplier' => $bySupplier,
            'totalCost'  => $totalCost,
            'totalItems' => $candidates->count(),
        ]);
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
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $start = $from.' 00:00:00';
        $end   = $to.' 23:59:59';

        // Revenue: paid & partially paid invoices
        $revenue = (float) Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->sum('paid_total');

        // Invoice count + avg ticket
        $invoiceCount = Invoice::whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->count();

        // COGS: sum of (quantity × menu_item.cost) for every sold, non-cancelled item.
        // We use menu_items.cost (the denormalized recipe cost) as the source of truth.
        $cogs = (float) DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('order_items.status', '!=', 'cancelled')
            ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed'])
            ->selectRaw('SUM(order_items.quantity * menu_items.cost) as cogs')
            ->value('cogs') ?? 0;

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

        $cogsByDay = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('order_items.status', '!=', 'cancelled')
            ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed'])
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
        $topProfit = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('order_items.status', '!=', 'cancelled')
            ->whereIn('orders.status', ['approved', 'preparing', 'ready', 'delivered', 'completed'])
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

        $topItems = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->where('order_items.status', '!=', 'cancelled')
            ->select('order_items.name_snapshot', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.subtotal) as total'))
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->limit(15)
            ->get();

        $inventoryUsage = DB::table('inventory_movements')
            ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
            ->whereBetween('inventory_movements.occurred_at', [$start, $end])
            ->whereIn('inventory_movements.type', ['out', 'waste'])
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
