<?php

use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\SupplierInvoice;
use App\Models\Table;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.order.status_changed')]
    #[On('echo-private:waiters,.invoice.paid')]
    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->summaries, $this->totals, $this->trend, $this->actionCenter);
    }

    #[Computed]
    public function summaries(): array
    {
        // Branch admin/manager sees only their pivot-assigned branches; owner-
        // level sees every active branch. Used to scope every aggregate below
        // — without this, a branch user would see another branch's totals.
        $branchIds = auth()->user()?->accessibleBranchIds() ?? [];
        if (empty($branchIds)) {
            return [];
        }

        return BranchContext::unscoped(function () use ($branchIds) {
            $today = now()->toDateString();
            $start = $today.' 00:00:00';
            $end = $today.' 23:59:59';

            $branches = Branch::active()
                ->whereIn('id', $branchIds)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get();

            $sales = Invoice::whereIn('branch_id', $branchIds)
                ->whereBetween('issued_at', [$start, $end])
                ->whereIn('status', ['paid', 'partially_paid'])
                ->selectRaw('branch_id, SUM(paid_total) as sales, COUNT(*) as invoices, AVG(paid_total) as avg_ticket')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $todayOrders = Order::whereIn('branch_id', $branchIds)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('branch_id, COUNT(*) as orders')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $activeOrders = Order::whereIn('branch_id', $branchIds)
                ->whereIn('status', OrderStatus::active())
                ->selectRaw('branch_id, COUNT(*) as active_orders')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $delayedOrders = Order::whereIn('branch_id', $branchIds)
                ->whereIn('status', [
                    OrderStatus::Approved->value,
                    OrderStatus::Preparing->value,
                ])
                ->whereNotNull('estimated_ready_at')
                ->where('estimated_ready_at', '<', now())
                ->selectRaw('branch_id, COUNT(*) as delayed_orders')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $pendingOrders = Order::whereIn('branch_id', $branchIds)
                ->where('status', OrderStatus::Pending->value)
                ->selectRaw('branch_id, COUNT(*) as pending_orders')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $tables = Table::whereIn('branch_id', $branchIds)
                ->where('active', true)
                ->selectRaw('branch_id, COUNT(*) as total_tables, SUM(CASE WHEN status = "occupied" THEN 1 ELSE 0 END) as occupied_tables')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $expenses = Expense::approved()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('expense_date', $today)
                ->selectRaw('branch_id, SUM(amount) as expenses, COUNT(*) as expense_count')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $refunds = DB::table('refunds')
                ->join('invoices', 'refunds.invoice_id', '=', 'invoices.id')
                ->whereIn('invoices.branch_id', $branchIds)
                ->whereNull('refunds.deleted_at')
                ->whereNull('invoices.deleted_at')
                ->where('refunds.status', 'completed')
                ->whereDate('refunds.refunded_at', $today)
                ->selectRaw('invoices.branch_id, SUM(refunds.amount) as refunds, COUNT(*) as refund_count')
                ->groupBy('invoices.branch_id')
                ->get()
                ->keyBy('branch_id');

            $reservations = Reservation::whereIn('branch_id', $branchIds)
                ->whereDate('reserved_for', $today)
                ->selectRaw('branch_id, COUNT(*) as reservations_today, SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_reservations')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $reviews = Review::published()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('created_at', '>=', now()->subDays(14)->toDateString())
                ->selectRaw('branch_id, AVG(rating) as avg_rating, SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as low_reviews, COUNT(*) as review_count')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $ap = SupplierInvoice::whereIn('branch_id', $branchIds)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->selectRaw('branch_id, SUM(balance) as ap_due, SUM(CASE WHEN due_date < CURDATE() THEN balance ELSE 0 END) as overdue_ap, SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_ap_count')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $po = PurchaseOrder::whereIn('branch_id', $branchIds)
                ->selectRaw('
                    branch_id,
                    SUM(CASE WHEN status = "draft" AND approved_at IS NULL THEN 1 ELSE 0 END) as po_needs_approval,
                    SUM(CASE WHEN status IN ("sent","partially_received") AND expected_at IS NOT NULL AND expected_at < CURDATE() THEN 1 ELSE 0 END) as overdue_pos,
                    SUM(CASE WHEN status IN ("draft","sent","partially_received") THEN 1 ELSE 0 END) as open_pos
                ')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $receipts = PurchaseReceipt::whereIn('branch_id', $branchIds)
                ->where('received_at', '>=', now()->subDays(7))
                ->selectRaw('branch_id, COUNT(*) as receipts_7d')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $invoiceVariances = DB::table('supplier_invoice_items')
                ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->whereIn('supplier_invoices.branch_id', $branchIds)
                ->whereNull('supplier_invoices.deleted_at')
                ->where(function ($query) {
                    $query->whereRaw('ABS(COALESCE(supplier_invoice_items.variance_qty, 0)) > 0.0001')
                        ->orWhereRaw('ABS(COALESCE(supplier_invoice_items.variance_total, 0)) > 0.01');
                })
                ->selectRaw('supplier_invoices.branch_id, COUNT(*) as invoice_variances')
                ->groupBy('supplier_invoices.branch_id')
                ->get()
                ->keyBy('branch_id');

            $waste = InventoryMovement::whereIn('branch_id', $branchIds)
                ->where('type', 'waste')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->selectRaw('branch_id, SUM(total_cost) as waste_7d')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            $lowStock = DB::table('ingredient_stock')
                ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
                ->join('ingredients', 'ingredient_stock.ingredient_id', '=', 'ingredients.id')
                ->whereIn('storage_locations.branch_id', $branchIds)
                ->whereNull('storage_locations.deleted_at')
                ->whereNull('ingredients.deleted_at')
                ->where('storage_locations.active', true)
                ->where('ingredients.active', true)
                ->where('ingredients.track_stock', true)
                ->whereRaw('ingredient_stock.quantity <= COALESCE(ingredient_stock.reorder_threshold, ingredients.reorder_threshold)')
                ->selectRaw('storage_locations.branch_id, COUNT(*) as low_stock, SUM(CASE WHEN ingredient_stock.quantity <= 0 THEN 1 ELSE 0 END) as out_stock')
                ->groupBy('storage_locations.branch_id')
                ->get()
                ->keyBy('branch_id');

            $expiringBatches = IngredientBatch::whereIn('branch_id', $branchIds)
                ->where('remaining_qty', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
                ->selectRaw('branch_id, COUNT(*) as expiring_batches')
                ->groupBy('branch_id')
                ->get()
                ->keyBy('branch_id');

            return $branches->map(function (Branch $branch) use (
                $sales,
                $todayOrders,
                $activeOrders,
                $delayedOrders,
                $pendingOrders,
                $tables,
                $expenses,
                $refunds,
                $reservations,
                $reviews,
                $ap,
                $po,
                $receipts,
                $invoiceVariances,
                $waste,
                $lowStock,
                $expiringBatches
            ) {
                $branchSales = (float) ($sales[$branch->id]->sales ?? 0);
                $branchExpenses = (float) ($expenses[$branch->id]->expenses ?? 0);
                $branchRefunds = (float) ($refunds[$branch->id]->refunds ?? 0);
                $occupied = (int) ($tables[$branch->id]->occupied_tables ?? 0);
                $totalTables = (int) ($tables[$branch->id]->total_tables ?? 0);

                $attentionCount =
                    (int) ($delayedOrders[$branch->id]->delayed_orders ?? 0)
                    + (int) ($pendingOrders[$branch->id]->pending_orders ?? 0)
                    + (int) ($reservations[$branch->id]->pending_reservations ?? 0)
                    + (int) ($reviews[$branch->id]->low_reviews ?? 0)
                    + (int) ($ap[$branch->id]->overdue_ap_count ?? 0)
                    + (int) ($po[$branch->id]->po_needs_approval ?? 0)
                    + (int) ($po[$branch->id]->overdue_pos ?? 0)
                    + (int) ($lowStock[$branch->id]->low_stock ?? 0)
                    + (int) ($expiringBatches[$branch->id]->expiring_batches ?? 0)
                    + (int) ($invoiceVariances[$branch->id]->invoice_variances ?? 0);

                return [
                    'branch' => $branch,
                    'sales' => $branchSales,
                    'expenses' => $branchExpenses,
                    'refunds' => $branchRefunds,
                    'net' => $branchSales - $branchRefunds - $branchExpenses,
                    'paid_invoices' => (int) ($sales[$branch->id]->invoices ?? 0),
                    'avg_ticket' => (float) ($sales[$branch->id]->avg_ticket ?? 0),
                    'today_orders' => (int) ($todayOrders[$branch->id]->orders ?? 0),
                    'active_orders' => (int) ($activeOrders[$branch->id]->active_orders ?? 0),
                    'delayed_orders' => (int) ($delayedOrders[$branch->id]->delayed_orders ?? 0),
                    'pending_orders' => (int) ($pendingOrders[$branch->id]->pending_orders ?? 0),
                    'occupied' => $occupied,
                    'total_tables' => $totalTables,
                    'occupancy' => $totalTables > 0 ? round(($occupied / $totalTables) * 100) : 0,
                    'reservations_today' => (int) ($reservations[$branch->id]->reservations_today ?? 0),
                    'pending_reservations' => (int) ($reservations[$branch->id]->pending_reservations ?? 0),
                    'avg_rating' => round((float) ($reviews[$branch->id]->avg_rating ?? 0), 1),
                    'low_reviews' => (int) ($reviews[$branch->id]->low_reviews ?? 0),
                    'review_count' => (int) ($reviews[$branch->id]->review_count ?? 0),
                    'ap_due' => (float) ($ap[$branch->id]->ap_due ?? 0),
                    'overdue_ap' => (float) ($ap[$branch->id]->overdue_ap ?? 0),
                    'overdue_ap_count' => (int) ($ap[$branch->id]->overdue_ap_count ?? 0),
                    'po_needs_approval' => (int) ($po[$branch->id]->po_needs_approval ?? 0),
                    'overdue_pos' => (int) ($po[$branch->id]->overdue_pos ?? 0),
                    'open_pos' => (int) ($po[$branch->id]->open_pos ?? 0),
                    'receipts_7d' => (int) ($receipts[$branch->id]->receipts_7d ?? 0),
                    'invoice_variances' => (int) ($invoiceVariances[$branch->id]->invoice_variances ?? 0),
                    'waste_7d' => (float) ($waste[$branch->id]->waste_7d ?? 0),
                    'low_stock' => (int) ($lowStock[$branch->id]->low_stock ?? 0),
                    'out_stock' => (int) ($lowStock[$branch->id]->out_stock ?? 0),
                    'expiring_batches' => (int) ($expiringBatches[$branch->id]->expiring_batches ?? 0),
                    'attention_count' => $attentionCount,
                    'needs_attention' => $attentionCount > 0,
                ];
            })->values()->all();
        });
    }

    #[Computed]
    public function totals(): array
    {
        $cards = collect($this->summaries);

        return [
            'branches' => $cards->count(),
            'sales' => (float) $cards->sum('sales'),
            'refunds' => (float) $cards->sum('refunds'),
            'expenses' => (float) $cards->sum('expenses'),
            'net' => (float) $cards->sum('net'),
            'orders' => (int) $cards->sum('today_orders'),
            'active' => (int) $cards->sum('active_orders'),
            'delayed' => (int) $cards->sum('delayed_orders'),
            'invoices' => (int) $cards->sum('paid_invoices'),
            'occupied' => (int) $cards->sum('occupied'),
            'total_tables' => (int) $cards->sum('total_tables'),
            'occupancy' => $cards->sum('total_tables') > 0
                ? round(($cards->sum('occupied') / $cards->sum('total_tables')) * 100)
                : 0,
            'attention' => (int) $cards->sum('attention_count'),
            'critical_branches' => (int) $cards->where('needs_attention', true)->count(),
            'ap_due' => (float) $cards->sum('ap_due'),
            'overdue_ap' => (float) $cards->sum('overdue_ap'),
            'low_stock' => (int) $cards->sum('low_stock'),
            'out_stock' => (int) $cards->sum('out_stock'),
            'pending_reservations' => (int) $cards->sum('pending_reservations'),
            'low_reviews' => (int) $cards->sum('low_reviews'),
            'invoice_variances' => (int) $cards->sum('invoice_variances'),
            'waste_7d' => (float) $cards->sum('waste_7d'),
        ];
    }

    #[Computed]
    public function trend(): array
    {
        $branchIds = auth()->user()?->accessibleBranchIds() ?? [];
        if (empty($branchIds)) {
            return array_fill(0, 7, ['label' => '', 'value' => 0.0]);
        }

        return BranchContext::unscoped(function () use ($branchIds) {
            $salesByDay = Invoice::whereIn('branch_id', $branchIds)
                ->whereIn('status', ['paid', 'partially_paid'])
                ->whereDate('issued_at', '>=', now()->subDays(6)->toDateString())
                ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $rows = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $key = $date->toDateString();
                $rows[] = [
                    'label' => $date->locale(\App\Support\MarketProfile::lang())->isoFormat('ddd'),
                    'value' => (float) ($salesByDay[$key] ?? 0),
                ];
            }

            return $rows;
        });
    }

    #[Computed]
    public function actionCenter(): array
    {
        $totals = $this->totals;

        return collect([
            [
                'label' => 'طلبات متأخرة',
                'value' => $totals['delayed'],
                'route' => route('admin.orders.index'),
                'icon' => 'bi-lightning-charge-fill',
                'tone' => 'danger',
            ],
            [
                'label' => 'مخزون منخفض',
                'value' => $totals['low_stock'],
                'route' => route('admin.inventory.dashboard'),
                'icon' => 'bi-exclamation-triangle-fill',
                'tone' => $totals['out_stock'] > 0 ? 'danger' : 'warning',
            ],
            [
                'label' => 'فواتير موردين متأخرة',
                'value' => $totals['overdue_ap'],
                'display' => \App\Helpers\Money::format($totals['overdue_ap']),
                'route' => route('admin.supplier-invoices.index', ['overdue' => 1]),
                'icon' => 'bi-cash-coin',
                'tone' => 'danger',
            ],
            [
                'label' => 'حجوزات معلقة',
                'value' => $totals['pending_reservations'],
                'route' => route('admin.reservations.index', ['status' => ReservationStatus::Pending->value]),
                'icon' => 'bi-calendar-event-fill',
                'tone' => 'warning',
            ],
            [
                'label' => 'تقييمات منخفضة',
                'value' => $totals['low_reviews'],
                'route' => route('admin.reviews.index', ['rating' => 2]),
                'icon' => 'bi-star-half',
                'tone' => 'danger',
            ],
            [
                'label' => 'فروقات فاتورة/استلام',
                'value' => $totals['invoice_variances'],
                'route' => route('admin.inventory.dashboard'),
                'icon' => 'bi-slash-circle-fill',
                'tone' => 'warning',
            ],
        ])->filter(fn ($item) => (float) $item['value'] > 0)->values()->all();
    }
};
?>

@php
    $totals = $this->totals;
    $summaries = collect($this->summaries);
    $topBranches = $summaries->sortByDesc('sales')->take(5)->values();
    $trend = collect($this->trend);
    $maxTrend = max($trend->pluck('value')->all() ?: [0]) ?: 1;
@endphp

<div class="owner-overview" wire:poll.visible.60s="refreshFromBroadcast">
    <section class="owner-hero">
        <div class="owner-hero-main">
            <span class="owner-kicker">نظرة المالك اليوم</span>
            <h2>{{ \App\Helpers\Money::format($totals['net']) }}</h2>
            <p>صافي اليوم بعد الاستردادات والمصروفات عبر {{ $totals['branches'] }} فرع نشط.</p>
        </div>
        <div class="owner-hero-grid">
            <a href="{{ route('admin.reports.sales') }}" class="owner-hero-stat">
                <span>المبيعات</span>
                <strong>{{ \App\Helpers\Money::format($totals['sales']) }}</strong>
            </a>
            <a href="{{ route('admin.orders.index') }}" class="owner-hero-stat">
                <span>طلبات اليوم</span>
                <strong>{{ $totals['orders'] }}</strong>
            </a>
            <a href="{{ route('admin.tables.index') }}" class="owner-hero-stat">
                <span>إشغال الطاولات</span>
                <strong>{{ $totals['occupancy'] }}%</strong>
            </a>
            <a href="{{ route('admin.inventory.dashboard') }}" class="owner-hero-stat {{ $totals['attention'] > 0 ? 'is-hot' : '' }}">
                <span>نقاط تحتاج قرار</span>
                <strong>{{ $totals['attention'] }}</strong>
            </a>
        </div>
    </section>

    <div class="owner-action-grid">
        @forelse($this->actionCenter as $action)
            <a href="{{ $action['route'] }}" class="owner-action owner-action--{{ $action['tone'] }}">
                <span class="owner-action-icon"><i class="bi {{ $action['icon'] }}"></i></span>
                <span class="owner-action-body">
                    <span>{{ $action['label'] }}</span>
                    <strong>{{ $action['display'] ?? $action['value'] }}</strong>
                </span>
                <i class="bi bi-arrow-left"></i>
            </a>
        @empty
            <div class="owner-action owner-action--ok">
                <span class="owner-action-icon"><i class="bi bi-check2-circle"></i></span>
                <span class="owner-action-body">
                    <span>لا توجد إجراءات حرجة</span>
                    <strong>كل الفروع هادئة</strong>
                </span>
            </div>
        @endforelse
    </div>

    <div class="owner-layout">
        <div class="owner-main">
        <section class="owner-panel owner-panel--wide">
            <div class="owner-panel-head">
                <div>
                    <span class="owner-kicker">مقارنة الفروع</span>
                    <h3>الأداء التشغيلي والمالي</h3>
                </div>
                <a href="{{ route('admin.reports.branch-comparison') }}" class="btn btn-light btn-sm">
                    <i class="bi bi-arrows-collapse"></i> تقرير المقارنة
                </a>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0 owner-table">
                    <thead>
                        <tr>
                            <th>الفرع</th>
                            <th class="text-end">صافي اليوم</th>
                            <th class="text-end">المبيعات</th>
                            <th class="text-center">طلبات</th>
                            <th class="text-center">نشطة</th>
                            <th class="text-center">إشغال</th>
                            <th class="text-center">تقييم</th>
                            <th class="text-center">تنبيهات</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($summaries as $row)
                            @php $branch = $row['branch']; @endphp
                            <tr>
                                <td>
                                    <div class="owner-branch-name">{{ $branch->name }}</div>
                                    <div class="text-muted small">{{ $branch->city ?: 'بدون مدينة' }}</div>
                                </td>
                                <td class="text-end fw-bold {{ $row['net'] < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ \App\Helpers\Money::format($row['net']) }}
                                </td>
                                <td class="text-end">{{ \App\Helpers\Money::format($row['sales']) }}</td>
                                <td class="text-center">{{ $row['today_orders'] }}</td>
                                <td class="text-center">
                                    <span class="badge bg-primary-transparent">{{ $row['active_orders'] }}</span>
                                    @if($row['delayed_orders'] > 0)
                                        <span class="badge bg-danger ms-1">{{ $row['delayed_orders'] }} متأخر</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $row['occupancy'] }}%</td>
                                <td class="text-center">
                                    @if($row['review_count'] > 0)
                                        <span class="{{ $row['low_reviews'] > 0 ? 'text-danger fw-bold' : 'text-warning fw-bold' }}">
                                            {{ $row['avg_rating'] }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="owner-attention {{ $row['attention_count'] > 0 ? 'is-hot' : '' }}">
                                        {{ $row['attention_count'] }}
                                    </span>
                                </td>
                                <td>
                                    <form action="{{ route('admin.branches.switch', $branch) }}" method="POST" class="m-0">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-box-arrow-in-right"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="owner-card-grid">
            @foreach($summaries as $row)
                @php $branch = $row['branch']; $hue = ($branch->id * 47) % 360; @endphp
                <article class="owner-card {{ $row['needs_attention'] ? 'is-warn' : '' }}" style="--hue: {{ $hue }};">
                    <header class="owner-card-head">
                        <span class="owner-card-avatar">{{ mb_substr($branch->name, 0, 1, 'UTF-8') }}</span>
                        <div>
                            <h3>{{ $branch->name }}</h3>
                            <span>{{ $branch->city ?: 'فرع نشط' }}</span>
                        </div>
                        <span class="owner-live-dot" title="مباشر"></span>
                    </header>

                    <div class="owner-card-metrics">
                        <div><span>صافي</span><strong>{{ \App\Helpers\Money::format($row['net']) }}</strong></div>
                        <div><span>متوسط فاتورة</span><strong>{{ \App\Helpers\Money::format($row['avg_ticket']) }}</strong></div>
                        <div><span>طلبات نشطة</span><strong>{{ $row['active_orders'] }}</strong></div>
                        <div><span>حجوزات اليوم</span><strong>{{ $row['reservations_today'] }}</strong></div>
                        <div><span>أوامر شراء</span><strong>{{ $row['open_pos'] }}</strong></div>
                        <div><span>استلامات 7 أيام</span><strong>{{ $row['receipts_7d'] }}</strong></div>
                    </div>

                    <div class="owner-card-alert {{ $row['attention_count'] > 0 ? 'is-hot' : '' }}">
                        <i class="bi {{ $row['attention_count'] > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' }}"></i>
                        @if($row['attention_count'] > 0)
                            {{ $row['attention_count'] }} نقطة تحتاج متابعة
                        @else
                            التشغيل مستقر
                        @endif
                    </div>

                    <form action="{{ route('admin.branches.switch', $branch) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="bi bi-box-arrow-in-right"></i> دخول الفرع
                        </button>
                    </form>
                </article>
            @endforeach
        </div>
        </div>{{-- /.owner-main --}}

        <aside class="owner-side">
            <section class="owner-panel">
                <div class="owner-panel-head">
                    <div>
                        <span class="owner-kicker">آخر 7 أيام</span>
                        <h3>اتجاه المبيعات</h3>
                    </div>
                </div>
                <div class="owner-bars">
                    @foreach($trend as $day)
                        <div class="owner-bar">
                            <span class="owner-bar-fill" style="height: {{ max(8, round(($day['value'] / $maxTrend) * 100)) }}%"></span>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="owner-panel">
                <div class="owner-panel-head">
                    <div>
                        <span class="owner-kicker">الأعلى مبيعاً</span>
                        <h3>أفضل الفروع اليوم</h3>
                    </div>
                </div>
                <div class="owner-rank-list">
                    @forelse($topBranches as $idx => $row)
                        <form action="{{ route('admin.branches.switch', $row['branch']) }}" method="POST" class="owner-rank">
                            @csrf
                            <button type="submit">
                                <span class="owner-rank-no">{{ $idx + 1 }}</span>
                                <span class="owner-rank-name">{{ $row['branch']->name }}</span>
                                <strong>{{ \App\Helpers\Money::format($row['sales']) }}</strong>
                            </button>
                        </form>
                    @empty
                        <div class="text-muted small">لا توجد فروع نشطة.</div>
                    @endforelse
                </div>
            </section>

            <section class="owner-panel">
                <div class="owner-panel-head">
                    <div>
                        <span class="owner-kicker">الذمم والمخزون</span>
                        <h3>مخاطر مختصرة</h3>
                    </div>
                </div>
                <div class="owner-risk-list">
                    <a href="{{ route('admin.supplier-invoices.index') }}">
                        <span>ذمم موردين</span>
                        <strong>{{ \App\Helpers\Money::format($totals['ap_due']) }}</strong>
                    </a>
                    <a href="{{ route('admin.supplier-invoices.index', ['overdue' => 1]) }}">
                        <span>متأخر منها</span>
                        <strong class="text-danger">{{ \App\Helpers\Money::format($totals['overdue_ap']) }}</strong>
                    </a>
                    <a href="{{ route('admin.inventory.dashboard') }}">
                        <span>مخزون منخفض / نافد</span>
                        <strong>{{ $totals['low_stock'] }} / {{ $totals['out_stock'] }}</strong>
                    </a>
                    <a href="{{ route('admin.waste.index') }}">
                        <span>هدر آخر 7 أيام</span>
                        <strong>{{ \App\Helpers\Money::format($totals['waste_7d']) }}</strong>
                    </a>
                </div>
            </section>
        </aside>
    </div>
</div>
