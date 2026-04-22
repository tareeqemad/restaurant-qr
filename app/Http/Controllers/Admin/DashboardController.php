<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Table;
use App\Services\AlertsService;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        $stats = [
            'pending_orders'  => Order::where('status', OrderStatus::Pending->value)->count(),
            'active_orders'   => Order::whereIn('status', OrderStatus::active())->count(),
            'today_orders'    => Order::whereDate('created_at', $today)->count(),
            'today_sales'     => (float) Invoice::whereDate('issued_at', $today)
                                                ->whereIn('status', ['paid', 'partially_paid'])
                                                ->sum('paid_total'),
            'occupied_tables' => Table::where('status', 'occupied')->count(),
            'total_tables'    => Table::where('active', true)->count(),
            'low_stock'       => Ingredient::where('track_stock', true)
                                           ->whereColumn('current_stock', '<=', 'reorder_threshold')
                                           ->count(),
            'menu_items'      => MenuItem::where('is_available', true)->count(),
        ];

        $recentOrders = Order::with(['table', 'items'])->latest()->limit(8)->get();

        $topItems = \DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereDate('orders.created_at', '>=', now()->subDays(7))
            ->where('order_items.status', '!=', 'cancelled')
            ->select(
                'order_items.name_snapshot',
                \DB::raw('SUM(order_items.quantity) as qty'),
                \DB::raw('SUM(order_items.subtotal) as total')
            )
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        // 7-day sales trend (for the sparkline chart).
        // Fill every day so the chart doesn't skip missing days.
        $salesByDay = Invoice::whereIn('status', ['paid', 'partially_paid'])
            ->whereDate('issued_at', '>=', now()->subDays(6)->toDateString())
            ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = collect();
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $trend->push([
                'date'  => $d,
                'label' => now()->subDays($i)->locale('ar')->isoFormat('ddd'),
                'value' => (float) ($salesByDay[$d] ?? 0),
            ]);
        }

        // Hour heatmap — today's orders by hour (0-23).
        $hourly = Order::whereDate('created_at', $today)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->pluck('count', 'hour');
        $hourly = collect(range(0, 23))->map(fn($h) => [
            'hour'  => $h,
            'count' => (int) ($hourly[$h] ?? 0),
        ]);

        // Operational alerts — expiry, AP, low stock, stale count, pending orders...
        $alerts = app(AlertsService::class)->dashboardSnapshot();

        return view('admin.dashboard.index', compact(
            'stats', 'recentOrders', 'topItems', 'trend', 'hourly', 'alerts'
        ));
    }
}
