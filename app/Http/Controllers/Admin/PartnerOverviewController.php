<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
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
use App\Support\LiveRefreshPulse;
use Illuminate\Support\Facades\DB;

/**
 * Branches overview — the owner's multi-branch Inertia/Vue dashboard.
 *
 * Scoping rule, enforced on every aggregate: owner-level sees every active
 * branch, a branch admin/manager sees only their pivot-assigned branches.
 * The whole computation runs inside `BranchContext::unscoped()` and filters
 * on an explicit id list — never on ambient branch context.
 *
 * Every aggregate is one grouped query keyed by branch_id, so adding a
 * branch costs rows, not round-trips.
 */
class PartnerOverviewController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403,
            'هذه الشاشة متاحة للإدارة فقط.');

        $summaries = $this->summaries();
        $totals = $this->totals($summaries);

        return \App\Support\AdminShell::render('Admin/Partner/Overview', [
            'summaries' => $summaries,
            'totals' => $totals,
            'trend' => $this->trend(),
            'actions' => $this->actionCenter($totals),
            'live' => [
                'version' => LiveRefreshPulse::version(),
            ],
            'currency' => config('restaurant.currency_symbol', '₪'),
            'generatedAt' => now()->toIso8601String(),
            'urls' => [
                'self' => route('admin.partner.overview'),
                'pulse' => route('admin.partner.overview.pulse'),
                'liveMonitor' => route('admin.partner.live-monitor'),
            ],
        ]);
    }

    public function pulse()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403);

        return response()->json(['version' => LiveRefreshPulse::version()]);
    }

    protected function summaries(): array
    {
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
                ->orderBy('display_order')->orderBy('name')
                ->get();

            $sales = Invoice::whereIn('branch_id', $branchIds)
                ->whereBetween('issued_at', [$start, $end])
                ->whereIn('status', ['paid', 'partially_paid'])
                ->selectRaw('branch_id, SUM(paid_total) as sales, COUNT(*) as invoices, AVG(paid_total) as avg_ticket')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $todayOrders = Order::whereIn('branch_id', $branchIds)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('branch_id, COUNT(*) as orders')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $activeOrders = Order::whereIn('branch_id', $branchIds)
                ->whereIn('status', OrderStatus::active())
                ->selectRaw('branch_id, COUNT(*) as active_orders')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $delayedOrders = Order::whereIn('branch_id', $branchIds)
                ->whereIn('status', [OrderStatus::Approved->value, OrderStatus::Preparing->value])
                ->whereNotNull('estimated_ready_at')
                ->where('estimated_ready_at', '<', now())
                ->selectRaw('branch_id, COUNT(*) as delayed_orders')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $pendingOrders = Order::whereIn('branch_id', $branchIds)
                ->where('status', OrderStatus::Pending->value)
                ->selectRaw('branch_id, COUNT(*) as pending_orders')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            // Single-quoted literal + bound dates: the retired component used
            // MySQL-only syntax here (double quotes, CURDATE()), which is a
            // portability trap the moment anything but MySQL runs the query.
            $tables = Table::whereIn('branch_id', $branchIds)
                ->where('active', true)
                ->selectRaw("branch_id, COUNT(*) as total_tables, SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_tables")
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $expenses = Expense::approved()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('expense_date', $today)
                ->selectRaw('branch_id, SUM(amount) as expenses')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $refunds = DB::table('refunds')
                ->join('invoices', 'refunds.invoice_id', '=', 'invoices.id')
                ->whereIn('invoices.branch_id', $branchIds)
                ->whereNull('refunds.deleted_at')->whereNull('invoices.deleted_at')
                ->where('refunds.status', 'completed')
                ->whereDate('refunds.refunded_at', $today)
                ->selectRaw('invoices.branch_id as branch_id, SUM(refunds.amount) as refunds')
                ->groupBy('invoices.branch_id')->get()->keyBy('branch_id');

            $reservations = Reservation::whereIn('branch_id', $branchIds)
                ->whereDate('reserved_for', $today)
                ->selectRaw("branch_id, COUNT(*) as reservations_today, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reservations")
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $reviews = Review::published()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('created_at', '>=', now()->subDays(14)->toDateString())
                ->selectRaw('branch_id, AVG(rating) as avg_rating, SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as low_reviews, COUNT(*) as review_count')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $ap = SupplierInvoice::whereIn('branch_id', $branchIds)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->selectRaw('branch_id, SUM(balance) as ap_due, SUM(CASE WHEN due_date < ? THEN balance ELSE 0 END) as overdue_ap, SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) as overdue_ap_count', [$today, $today])
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $po = PurchaseOrder::whereIn('branch_id', $branchIds)
                ->selectRaw("
                    branch_id,
                    SUM(CASE WHEN status = 'draft' AND approved_at IS NULL THEN 1 ELSE 0 END) as po_needs_approval,
                    SUM(CASE WHEN status IN ('sent','partially_received') AND expected_at IS NOT NULL AND expected_at < ? THEN 1 ELSE 0 END) as overdue_pos,
                    SUM(CASE WHEN status IN ('draft','sent','partially_received') THEN 1 ELSE 0 END) as open_pos
                ", [$today])
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $receipts = PurchaseReceipt::whereIn('branch_id', $branchIds)
                ->where('received_at', '>=', now()->subDays(7))
                ->selectRaw('branch_id, COUNT(*) as receipts_7d')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $invoiceVariances = DB::table('supplier_invoice_items')
                ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->whereIn('supplier_invoices.branch_id', $branchIds)
                ->whereNull('supplier_invoices.deleted_at')
                ->where(function ($query) {
                    $query->whereRaw('ABS(COALESCE(supplier_invoice_items.variance_qty, 0)) > 0.0001')
                        ->orWhereRaw('ABS(COALESCE(supplier_invoice_items.variance_total, 0)) > 0.01');
                })
                ->selectRaw('supplier_invoices.branch_id as branch_id, COUNT(*) as invoice_variances')
                ->groupBy('supplier_invoices.branch_id')->get()->keyBy('branch_id');

            $waste = InventoryMovement::whereIn('branch_id', $branchIds)
                ->where('type', 'waste')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->selectRaw('branch_id, SUM(total_cost) as waste_7d')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            $lowStock = DB::table('ingredient_stock')
                ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
                ->join('ingredients', 'ingredient_stock.ingredient_id', '=', 'ingredients.id')
                ->whereIn('storage_locations.branch_id', $branchIds)
                ->whereNull('storage_locations.deleted_at')->whereNull('ingredients.deleted_at')
                ->where('storage_locations.active', true)
                ->where('ingredients.active', true)
                ->where('ingredients.track_stock', true)
                ->whereRaw('ingredient_stock.quantity <= COALESCE(ingredient_stock.reorder_threshold, ingredients.reorder_threshold)')
                ->selectRaw('storage_locations.branch_id as branch_id, COUNT(*) as low_stock, SUM(CASE WHEN ingredient_stock.quantity <= 0 THEN 1 ELSE 0 END) as out_stock')
                ->groupBy('storage_locations.branch_id')->get()->keyBy('branch_id');

            $expiringBatches = IngredientBatch::whereIn('branch_id', $branchIds)
                ->where('remaining_qty', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(7)->toDateString())
                ->selectRaw('branch_id, COUNT(*) as expiring_batches')
                ->groupBy('branch_id')->get()->keyBy('branch_id');

            return $branches->map(function (Branch $b) use (
                $sales, $todayOrders, $activeOrders, $delayedOrders, $pendingOrders, $tables,
                $expenses, $refunds, $reservations, $reviews, $ap, $po, $receipts,
                $invoiceVariances, $waste, $lowStock, $expiringBatches
            ) {
                $id = $b->id;
                $branchSales = (float) ($sales[$id]->sales ?? 0);
                $branchExpenses = (float) ($expenses[$id]->expenses ?? 0);
                $branchRefunds = (float) ($refunds[$id]->refunds ?? 0);
                $occupied = (int) ($tables[$id]->occupied_tables ?? 0);
                $totalTables = (int) ($tables[$id]->total_tables ?? 0);

                // Everything a human would have to chase today, in one number.
                $attention =
                    (int) ($delayedOrders[$id]->delayed_orders ?? 0)
                    + (int) ($pendingOrders[$id]->pending_orders ?? 0)
                    + (int) ($reservations[$id]->pending_reservations ?? 0)
                    + (int) ($reviews[$id]->low_reviews ?? 0)
                    + (int) ($ap[$id]->overdue_ap_count ?? 0)
                    + (int) ($po[$id]->po_needs_approval ?? 0)
                    + (int) ($po[$id]->overdue_pos ?? 0)
                    + (int) ($lowStock[$id]->low_stock ?? 0)
                    + (int) ($expiringBatches[$id]->expiring_batches ?? 0)
                    + (int) ($invoiceVariances[$id]->invoice_variances ?? 0);

                $delayed = (int) ($delayedOrders[$id]->delayed_orders ?? 0);
                $pending = (int) ($pendingOrders[$id]->pending_orders ?? 0);
                $out = (int) ($lowStock[$id]->out_stock ?? 0);
                $overdueSupplierCount = (int) ($ap[$id]->overdue_ap_count ?? 0);
                $lowRatingCount = (int) ($reviews[$id]->low_reviews ?? 0);
                $critical = $delayed + $out + $overdueSupplierCount + $lowRatingCount;
                $health = match (true) {
                    $critical > 0 => ['tone' => 'danger', 'label' => 'يحتاج تدخلاً'],
                    $attention > 0 => ['tone' => 'warning', 'label' => 'يحتاج متابعة'],
                    default => ['tone' => 'calm', 'label' => 'مستقر'],
                };
                $pressure = min(100, (int) round(
                    ($delayed * 24)
                    + ($pending * 12)
                    + ((int) ($activeOrders[$id]->active_orders ?? 0) * 4)
                    + ($totalTables > 0 ? ($occupied / $totalTables) * 24 : 0)
                ));

                return [
                    'id' => $id,
                    'name' => $b->name,
                    'city' => $b->city,
                    'sales' => $branchSales,
                    'expenses' => $branchExpenses,
                    'refunds' => $branchRefunds,
                    'net' => $branchSales - $branchRefunds - $branchExpenses,
                    'paidInvoices' => (int) ($sales[$id]->invoices ?? 0),
                    'avgTicket' => (float) ($sales[$id]->avg_ticket ?? 0),
                    'todayOrders' => (int) ($todayOrders[$id]->orders ?? 0),
                    'activeOrders' => (int) ($activeOrders[$id]->active_orders ?? 0),
                    'delayedOrders' => $delayed,
                    'pendingOrders' => $pending,
                    'occupied' => $occupied,
                    'totalTables' => $totalTables,
                    'occupancy' => $totalTables > 0 ? (int) round(($occupied / $totalTables) * 100) : 0,
                    'reservationsToday' => (int) ($reservations[$id]->reservations_today ?? 0),
                    'pendingReservations' => (int) ($reservations[$id]->pending_reservations ?? 0),
                    'avgRating' => round((float) ($reviews[$id]->avg_rating ?? 0), 1),
                    'lowReviews' => $lowRatingCount,
                    'reviewCount' => (int) ($reviews[$id]->review_count ?? 0),
                    'apDue' => (float) ($ap[$id]->ap_due ?? 0),
                    'overdueAp' => (float) ($ap[$id]->overdue_ap ?? 0),
                    'overdueApCount' => $overdueSupplierCount,
                    'poNeedsApproval' => (int) ($po[$id]->po_needs_approval ?? 0),
                    'overduePos' => (int) ($po[$id]->overdue_pos ?? 0),
                    'openPos' => (int) ($po[$id]->open_pos ?? 0),
                    'receipts7d' => (int) ($receipts[$id]->receipts_7d ?? 0),
                    'invoiceVariances' => (int) ($invoiceVariances[$id]->invoice_variances ?? 0),
                    'waste7d' => (float) ($waste[$id]->waste_7d ?? 0),
                    'lowStock' => (int) ($lowStock[$id]->low_stock ?? 0),
                    'outStock' => $out,
                    'expiringBatches' => (int) ($expiringBatches[$id]->expiring_batches ?? 0),
                    'attention' => $attention,
                    'needsAttention' => $attention > 0,
                    'health' => $health,
                    'pressure' => $pressure,
                ];
            })->values()->all();
        });
    }

    protected function totals(array $summaries): array
    {
        $cards = collect($summaries);
        $tables = (int) $cards->sum('totalTables');
        $occupied = (int) $cards->sum('occupied');

        return [
            'branches' => $cards->count(),
            'sales' => (float) $cards->sum('sales'),
            'refunds' => (float) $cards->sum('refunds'),
            'expenses' => (float) $cards->sum('expenses'),
            'net' => (float) $cards->sum('net'),
            'orders' => (int) $cards->sum('todayOrders'),
            'active' => (int) $cards->sum('activeOrders'),
            'delayed' => (int) $cards->sum('delayedOrders'),
            'invoices' => (int) $cards->sum('paidInvoices'),
            'occupied' => $occupied,
            'totalTables' => $tables,
            'occupancy' => $tables > 0 ? (int) round(($occupied / $tables) * 100) : 0,
            'attention' => (int) $cards->sum('attention'),
            'criticalBranches' => $cards->where('needsAttention', true)->count(),
            'apDue' => (float) $cards->sum('apDue'),
            'overdueAp' => (float) $cards->sum('overdueAp'),
            'lowStock' => (int) $cards->sum('lowStock'),
            'outStock' => (int) $cards->sum('outStock'),
            'pendingReservations' => (int) $cards->sum('pendingReservations'),
            'lowReviews' => (int) $cards->sum('lowReviews'),
            'invoiceVariances' => (int) $cards->sum('invoiceVariances'),
            'waste7d' => (float) $cards->sum('waste7d'),
        ];
    }

    /** Seven days of paid sales, oldest first — the sparkline's data. */
    protected function trend(): array
    {
        $branchIds = auth()->user()?->accessibleBranchIds() ?? [];
        if (empty($branchIds)) {
            return [];
        }

        return BranchContext::unscoped(function () use ($branchIds) {
            $byDay = Invoice::whereIn('branch_id', $branchIds)
                ->whereIn('status', ['paid', 'partially_paid'])
                ->whereDate('issued_at', '>=', now()->subDays(6)->toDateString())
                ->selectRaw('DATE(issued_at) as day, SUM(paid_total) as total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $rows = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $rows[] = [
                    'label' => $date->locale('ar')->isoFormat('ddd'),
                    'value' => (float) ($byDay[$date->toDateString()] ?? 0),
                ];
            }

            return $rows;
        });
    }

    /**
     * The "what needs a human today" strip — only non-zero items survive,
     * each linking straight to the screen that resolves it.
     */
    protected function actionCenter(array $totals): array
    {
        return collect([
            [
                'label' => 'طلبات متأخرة',
                'value' => $totals['delayed'],
                'display' => (string) $totals['delayed'],
                'url' => route('admin.orders.index'),
                'icon' => 'bi-lightning-charge-fill',
                'tone' => 'danger',
            ],
            [
                'label' => 'مخزون منخفض',
                'value' => $totals['lowStock'],
                'display' => (string) $totals['lowStock'],
                'url' => route('admin.inventory.dashboard'),
                'icon' => 'bi-exclamation-triangle-fill',
                'tone' => $totals['outStock'] > 0 ? 'danger' : 'warning',
            ],
            [
                'label' => 'فواتير موردين متأخرة',
                'value' => $totals['overdueAp'],
                'display' => \App\Helpers\Money::format($totals['overdueAp']),
                'url' => route('admin.supplier-invoices.index', ['overdue' => 1]),
                'icon' => 'bi-cash-coin',
                'tone' => 'danger',
            ],
            [
                'label' => 'حجوزات معلقة',
                'value' => $totals['pendingReservations'],
                'display' => (string) $totals['pendingReservations'],
                'url' => route('admin.reservations.index', ['status' => ReservationStatus::Pending->value]),
                'icon' => 'bi-calendar-event-fill',
                'tone' => 'warning',
            ],
            [
                'label' => 'تقييمات منخفضة',
                'value' => $totals['lowReviews'],
                'display' => (string) $totals['lowReviews'],
                // 'low' is the moderation lens the reviews board actually has.
                'url' => route('admin.reviews.index', ['rating' => 'low', 'status' => 'published']),
                'icon' => 'bi-star-half',
                'tone' => 'danger',
            ],
            [
                'label' => 'فروقات فاتورة/استلام',
                'value' => $totals['invoiceVariances'],
                'display' => (string) $totals['invoiceVariances'],
                'url' => route('admin.inventory.dashboard'),
                'icon' => 'bi-slash-circle-fill',
                'tone' => 'warning',
            ],
        ])->filter(fn ($item) => (float) $item['value'] > 0)->values()->all();
    }
}
