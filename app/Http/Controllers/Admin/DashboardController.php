<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Shift;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Table;
use App\Services\AlertsService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        $branchId = BranchContext::current();
        $user = auth()->user();

        $todayInvoiceQuery = Invoice::whereDate('issued_at', $today)
            ->whereIn('status', ['paid', 'partially_paid']);
        $todayInvoiceCount = (clone $todayInvoiceQuery)->count();
        $todaySales = (float) (clone $todayInvoiceQuery)->sum('paid_total');

        $todayPaymentsQuery = $this->branchScopedPayments($branchId)
            ->whereDate('paid_at', $today);
        $todayPayments = (float) (clone $todayPaymentsQuery)->sum('amount');

        $paymentMethodLabels = [
            'cash' => 'نقدي',
            'card' => 'بطاقة',
            'transfer' => 'تحويل',
            'bank_transfer' => 'تحويل بنكي',
            'cheque' => 'شيك',
            'app' => 'محفظة',
            'credit' => 'آجل',
            'other' => 'أخرى',
        ];

        $paymentMethods = (clone $todayPaymentsQuery)
            ->select('method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'label' => $paymentMethodLabels[$row->method] ?? $row->method,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        $todayRefundsQuery = $this->branchScopedRefunds($branchId)
            ->where('status', 'completed')
            ->whereDate('refunded_at', $today);
        $todayRefunds = (float) (clone $todayRefundsQuery)->sum('amount');
        $todayRefundsCount = (clone $todayRefundsQuery)->count();

        $todayExpensesQuery = Expense::approved()->whereDate('expense_date', $today);
        $todayExpenses = (float) (clone $todayExpensesQuery)->sum('amount');

        $financialPulse = [
            'gross_sales' => $todaySales,
            'payments' => $todayPayments,
            'refunds' => $todayRefunds,
            'expenses' => $todayExpenses,
            'net_operating' => $todaySales - $todayRefunds - $todayExpenses,
            'cash_after_outflows' => $todayPayments - $todayRefunds - $todayExpenses,
            'invoice_count' => $todayInvoiceCount,
            'average_invoice' => $todayInvoiceCount > 0 ? $todaySales / $todayInvoiceCount : 0,
            'open_balance' => (float) Invoice::where('balance', '>', 0)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->sum('balance'),
            'refunds_count' => $todayRefundsCount,
            'payment_methods' => $paymentMethods,
        ];

        $pendingOrders = Order::where('status', OrderStatus::Pending->value)->count();
        $activeOrders = Order::whereIn('status', OrderStatus::active())->count();
        $todayOrders = Order::whereDate('created_at', $today)->count();
        $occupiedTables = Table::where('status', 'occupied')->count();
        $totalTables = Table::where('active', true)->count();
        $lowStock = Ingredient::where('track_stock', true)
            ->whereColumn('current_stock', '<=', 'reorder_threshold')
            ->where('current_stock', '>', 0)
            ->count();
        $outOfStock = Ingredient::where('track_stock', true)
            ->where('current_stock', '<=', 0)
            ->count();
        $menuItems = MenuItem::where('is_available', true)->count();

        $purchaseOrdersNeedApproval = PurchaseOrder::where('status', 'draft')
            ->whereNull('approved_at')
            ->count();
        $overduePurchaseOrders = PurchaseOrder::whereIn('status', ['sent', 'partially_received'])
            ->whereNotNull('expected_at')
            ->whereDate('expected_at', '<', $today)
            ->count();
        $uninvoicedPurchaseOrders = PurchaseOrder::whereIn('status', ['received', 'partially_received'])
            ->whereDoesntHave('supplierInvoices', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->count();

        $supplierInvoiceQueue = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
            ->where(function ($q) {
                $q->whereNull('due_date')
                    ->orWhereDate('due_date', '<=', now()->addDays(7)->toDateString());
            });
        $supplierInvoiceQueueCount = (clone $supplierInvoiceQueue)->count();

        $overdueSupplierInvoices = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
            ->whereDate('due_date', '<', $today);
        $overdueSupplierInvoiceCount = (clone $overdueSupplierInvoices)->count();
        $overdueSupplierInvoiceAmount = (float) (clone $overdueSupplierInvoices)->sum('balance');

        $pendingReservations = Reservation::withStatus(ReservationStatus::Pending)->count();
        $todayReservations = Reservation::forDate($today)->count();
        $lowReviews = Review::published()
            ->where('rating', '<=', 2)
            ->whereDate('created_at', '>=', now()->subDays(14)->toDateString())
            ->count();
        $pendingRefunds = $this->branchScopedRefunds($branchId)
            ->where('status', 'pending')
            ->count();
        $pendingExpenses = Expense::pending()->count();

        $expiringBatches = IngredientBatch::where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
            ->count();

        $invoiceVariancesQuery = SupplierInvoiceItem::where(function ($query) {
            $query->whereRaw('ABS(COALESCE(variance_qty, 0)) > 0.0001')
                ->orWhereRaw('ABS(COALESCE(variance_total, 0)) > 0.01');
        });
        if ($branchId) {
            $invoiceVariancesQuery->whereHas('supplierInvoice', fn ($q) => $q->where('branch_id', $branchId));
        }
        $invoiceVariances = $invoiceVariancesQuery->count();

        $actionCenter = collect([
            [
                'title' => 'طلبات بانتظار الاعتماد',
                'count' => $pendingOrders,
                'description' => 'طلبات زبائن لم تدخل خط الإنتاج بعد.',
                'route' => route('admin.orders.index', ['status' => 'pending']),
                'icon' => 'bi-hourglass-split',
                'severity' => 'warning',
            ],
            [
                'title' => 'أوامر شراء تحتاج اعتماد',
                'count' => $purchaseOrdersNeedApproval,
                'description' => 'مسودات شراء جاهزة للمراجعة قبل الإرسال.',
                'route' => route('admin.purchase-orders.index', ['status' => 'draft']),
                'icon' => 'bi-clipboard-check',
                'severity' => 'warning',
            ],
            [
                'title' => 'فواتير موردين متأخرة',
                'count' => $overdueSupplierInvoiceCount,
                'description' => $overdueSupplierInvoiceAmount > 0
                    ? 'قيمة متأخرة: '.\App\Helpers\Money::format($overdueSupplierInvoiceAmount)
                    : 'فواتير تجاوزت تاريخ الاستحقاق.',
                'route' => route('admin.supplier-invoices.index', ['overdue' => 1]),
                'icon' => 'bi-cash-coin',
                'severity' => 'critical',
            ],
            [
                'title' => 'حجوزات بانتظار التأكيد',
                'count' => $pendingReservations,
                'description' => 'حجوزات تحتاج قرار سريع من الفريق.',
                'route' => route('admin.reservations.index', ['status' => ReservationStatus::Pending->value]),
                'icon' => 'bi-calendar-event-fill',
                'severity' => 'warning',
            ],
            [
                'title' => 'تقييمات منخفضة',
                'count' => $lowReviews,
                'description' => 'تقييمات 1-2 نجوم خلال آخر 14 يوم.',
                'route' => route('admin.reviews.index', ['rating' => 2]),
                'icon' => 'bi-star-half',
                'severity' => 'critical',
            ],
            [
                'title' => 'مخزون يحتاج شراء',
                'count' => $lowStock + $outOfStock,
                'description' => 'مكونات منخفضة أو نافدة.',
                'route' => route('admin.reports.reorder-suggestions'),
                'icon' => 'bi-cart-plus-fill',
                'severity' => $outOfStock > 0 ? 'critical' : 'warning',
            ],
            [
                'title' => 'فروقات فاتورة/استلام',
                'count' => $invoiceVariances,
                'description' => 'بنود تحتاج مطابقة بين فاتورة المورد والاستلام.',
                'route' => route('admin.inventory.dashboard'),
                'icon' => 'bi-slash-circle-fill',
                'severity' => 'warning',
            ],
            [
                'title' => 'استردادات معلقة',
                'count' => $pendingRefunds,
                'description' => 'طلبات استرداد لم تكتمل بعد.',
                'route' => route('admin.refunds.index', ['status' => 'pending']),
                'icon' => 'bi-arrow-counterclockwise',
                'severity' => 'info',
            ],
            [
                'title' => 'مصروفات بانتظار الاعتماد',
                'count' => $pendingExpenses,
                'description' => 'مصروفات تحتاج اعتماد المدير.',
                'route' => route('admin.expenses.index', ['status' => 'pending_approval']),
                'icon' => 'bi-receipt-cutoff',
                'severity' => 'info',
            ],
        ])->filter(fn ($item) => (int) $item['count'] > 0)->values();

        $criticalActions = $actionCenter
            ->where('severity', 'critical')
            ->sum(fn ($item) => (int) $item['count']);
        $actionCount = $actionCenter->sum(fn ($item) => (int) $item['count']);

        $stats = [
            'pending_orders'  => $pendingOrders,
            'active_orders'   => $activeOrders,
            'today_orders'    => $todayOrders,
            'today_sales'     => $todaySales,
            'net_operating'   => $financialPulse['net_operating'],
            'occupied_tables' => $occupiedTables,
            'total_tables'    => $totalTables,
            'low_stock'       => $lowStock + $outOfStock,
            'menu_items'      => $menuItems,
            'action_count'    => $actionCount,
            'critical_actions'=> $criticalActions,
        ];

        $recentOrders = Order::with(['table', 'items'])->latest()->limit(8)->get();

        // Top items in the last 7 days. Raw query — bypasses BranchScope —
        // so we stamp the branch filter manually when one is bound.
        $topItemsQuery = \DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereDate('orders.created_at', '>=', now()->subDays(7))
            ->where('order_items.status', '!=', 'cancelled');

        if ($branchId = BranchContext::current()) {
            $topItemsQuery->where('orders.branch_id', $branchId);
        }

        $topItems = $topItemsQuery
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

        $inventoryProcurement = [
            'tracked' => Ingredient::where('track_stock', true)->where('active', true)->count(),
            'low_stock' => $lowStock,
            'out_stock' => $outOfStock,
            'expiring_batches' => $expiringBatches,
            'open_pos' => PurchaseOrder::whereIn('status', ['draft', 'sent', 'partially_received'])->count(),
            'po_needs_approval' => $purchaseOrdersNeedApproval,
            'overdue_pos' => $overduePurchaseOrders,
            'uninvoiced_pos' => $uninvoicedPurchaseOrders,
            'supplier_invoice_queue' => $supplierInvoiceQueueCount,
            'invoice_variances' => $invoiceVariances,
            'receipts_7d' => PurchaseReceipt::where('received_at', '>=', now()->subDays(7))->count(),
            'ap_due' => (float) SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])->sum('balance'),
            'ap_overdue' => $overdueSupplierInvoiceAmount,
            'waste_7d' => (float) InventoryMovement::where('type', 'waste')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->sum('total_cost'),
        ];

        $recentReceipts = PurchaseReceipt::with('purchaseOrder', 'supplier')
            ->latest('received_at')
            ->limit(5)
            ->get();

        $newCustomersQuery = Customer::query();
        if ($branchId) {
            $newCustomersQuery->where('default_branch_id', $branchId);
        }

        $customerPulse = [
            'new_today' => (clone $newCustomersQuery)->whereDate('created_at', $today)->count(),
            'new_7d' => (clone $newCustomersQuery)->whereDate('created_at', '>=', now()->subDays(6)->toDateString())->count(),
            'reservations_today' => $todayReservations,
            'reservations_pending' => $pendingReservations,
            'reservations_upcoming' => Reservation::upcoming()->count(),
            'reviews_today' => Review::whereDate('created_at', $today)->count(),
            'average_rating' => round((float) Review::published()->avg('rating'), 1),
            'low_reviews' => $lowReviews,
            'identified_orders_today' => Order::whereDate('created_at', $today)->whereNotNull('customer_id')->count(),
        ];

        $recentReviews = Review::with('customer')
            ->latest()
            ->limit(4)
            ->get();

        $delayedOrders = Order::whereIn('status', [
                OrderStatus::Approved->value,
                OrderStatus::Preparing->value,
            ])
            ->whereNotNull('estimated_ready_at')
            ->where('estimated_ready_at', '<', now())
            ->count();

        $dailyOps = [
            'open_shifts' => Shift::where('status', 'open')->count(),
            'open_attendance' => Attendance::open()->count(),
            'active_orders' => $activeOrders,
            'ready_orders' => Order::where('status', OrderStatus::Ready->value)->count(),
            'delayed_orders' => $delayedOrders,
            'occupied_tables' => $occupiedTables,
            'table_utilization' => $totalTables > 0 ? round(($occupiedTables / $totalTables) * 100) : 0,
            'pending_refunds' => $pendingRefunds,
            'pending_expenses' => $pendingExpenses,
        ];

        $ordersByStatus = Order::whereDate('created_at', $today)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $branchSnapshot = collect();
        if ($user?->isOwnerLevel()) {
            $branches = Branch::active()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name', 'city']);

            $branchSnapshot = BranchContext::unscoped(function () use ($branches, $today) {
                $sales = Invoice::whereDate('issued_at', $today)
                    ->whereIn('status', ['paid', 'partially_paid'])
                    ->select('branch_id', DB::raw('SUM(paid_total) as total'), DB::raw('COUNT(*) as invoices'))
                    ->groupBy('branch_id')
                    ->get()
                    ->keyBy('branch_id');

                $orders = Order::whereDate('created_at', $today)
                    ->select('branch_id', DB::raw('COUNT(*) as orders'))
                    ->groupBy('branch_id')
                    ->get()
                    ->keyBy('branch_id');

                $active = Order::whereIn('status', OrderStatus::active())
                    ->select('branch_id', DB::raw('COUNT(*) as active_orders'))
                    ->groupBy('branch_id')
                    ->get()
                    ->keyBy('branch_id');

                $reservations = Reservation::forDate($today)
                    ->select('branch_id', DB::raw('COUNT(*) as reservations'))
                    ->groupBy('branch_id')
                    ->get()
                    ->keyBy('branch_id');

                $overdueAp = SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                    ->whereDate('due_date', '<', $today)
                    ->select('branch_id', DB::raw('SUM(balance) as overdue_ap'))
                    ->groupBy('branch_id')
                    ->get()
                    ->keyBy('branch_id');

                return $branches
                    ->map(fn (Branch $branch) => [
                        'branch' => $branch,
                        'sales' => (float) ($sales[$branch->id]->total ?? 0),
                        'invoices' => (int) ($sales[$branch->id]->invoices ?? 0),
                        'orders' => (int) ($orders[$branch->id]->orders ?? 0),
                        'active_orders' => (int) ($active[$branch->id]->active_orders ?? 0),
                        'reservations' => (int) ($reservations[$branch->id]->reservations ?? 0),
                        'overdue_ap' => (float) ($overdueAp[$branch->id]->overdue_ap ?? 0),
                    ])
                    ->sortByDesc('sales')
                    ->take(6)
                    ->values();
            });
        }

        $quickActions = [
            [
                'label' => 'الكاشير',
                'hint' => 'إصدار ودفع الفواتير',
                'icon' => 'bi-cash-register',
                'route' => route('admin.cashier.index'),
                'color' => 'primary',
            ],
            [
                'label' => 'طلبات الصالة',
                'hint' => 'متابعة الإنتاج والحالات',
                'icon' => 'bi-receipt',
                'route' => route('admin.orders.index'),
                'color' => 'success',
            ],
            [
                'label' => 'أمر شراء',
                'hint' => 'إنشاء طلب توريد جديد',
                'icon' => 'bi-bag-plus-fill',
                'route' => route('admin.purchase-orders.create'),
                'color' => 'warning',
            ],
            [
                'label' => 'فاتورة مورد',
                'hint' => 'تسجيل ومطابقة فاتورة',
                'icon' => 'bi-file-earmark-text-fill',
                'route' => route('admin.supplier-invoices.create'),
                'color' => 'accent',
            ],
            [
                'label' => 'مصروف جديد',
                'hint' => 'تسجيل مصروف تشغيلي',
                'icon' => 'bi-receipt-cutoff',
                'route' => route('admin.expenses.create'),
                'color' => 'danger',
            ],
            [
                'label' => 'الحجوزات',
                'hint' => 'اليوم والمعلّق والقادم',
                'icon' => 'bi-calendar-event-fill',
                'route' => route('admin.reservations.index'),
                'color' => 'primary',
            ],
            [
                'label' => 'مخزون ومشتريات',
                'hint' => 'النواقص والاستلامات',
                'icon' => 'bi-box-seam-fill',
                'route' => route('admin.inventory.dashboard'),
                'color' => 'success',
            ],
            [
                'label' => 'التقارير',
                'hint' => 'تحليل المبيعات والربح',
                'icon' => 'bi-graph-up-arrow',
                'route' => route('admin.reports.index'),
                'color' => 'accent',
            ],
            [
                'label' => 'جرد جديد',
                'hint' => 'تسوية المخزون فعلياً',
                'icon' => 'bi-clipboard2-check-fill',
                'route' => route('admin.stock-counts.create'),
                'color' => 'muted',
            ],
        ];

        // Operational alerts — expiry, AP, low stock, stale count, pending orders...
        $alerts = app(AlertsService::class)->dashboardSnapshot();

        return view('admin.dashboard.index', compact(
            'stats',
            'financialPulse',
            'actionCenter',
            'inventoryProcurement',
            'customerPulse',
            'dailyOps',
            'ordersByStatus',
            'branchSnapshot',
            'quickActions',
            'recentOrders',
            'recentReceipts',
            'recentReviews',
            'topItems',
            'trend',
            'hourly',
            'alerts'
        ));
    }

    /**
     * As of May 2026, Payment::branch_id exists and BelongsToBranch
     * auto-applies the BranchScope. The legacy whereHas('invoice')
     * subquery was both slow (correlated subquery on every aggregate)
     * AND redundant. We keep this helper for call-site stability but
     * it's now a thin pass-through that lets the global scope do its
     * job.
     *
     * NOTE: $branchId param is ignored — BranchContext is the source of
     * truth. The dashboard controller resolves $branchId once at the top
     * and that value is what BranchContext was set to by the middleware,
     * so passing it here would just duplicate the WHERE.
     */
    private function branchScopedPayments(?int $branchId): Builder
    {
        return Payment::query();
    }

    private function branchScopedRefunds(?int $branchId): Builder
    {
        return Refund::query();
    }
}
