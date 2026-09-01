<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Table;
use App\Support\LiveRefreshPulse;

/**
 * Full-screen live operations monitor — management-level access.
 *
 * Built for the office TV during peak hours: every accessible branch in one
 * frame, orders + sales + table state ticking live. Served by Inertia/Vue.
 *
 * Scoping is the thing to get right: owner-level sees every active branch,
 * a branch admin/manager sees only the branches they belong to. Because the
 * screen deliberately reads ACROSS branches, every query drops the global
 * BranchScope and filters on an explicit `branch_id` from the user's own
 * accessible list — never on ambient context.
 */
class LiveMonitorController extends Controller
{
    /** Recent orders shown per branch — the tail of the order log. */
    private const RECENT_LIMIT = 6;

    public function index()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403,
            'شاشة المراقبة المباشرة متاحة للإدارة فقط.');

        \Inertia\Inertia::setRootView('inertia-admin');

        return \Inertia\Inertia::render('Admin/Monitor/Live', [
            'branches' => $this->branchPayload(),
            'live' => [
                'version' => LiveRefreshPulse::version(),
            ],
            'currency' => config('restaurant.currency_symbol', '₪'),
            'generatedAt' => now()->toIso8601String(),
            'urls' => [
                'self' => route('admin.partner.live-monitor'),
                'pulse' => route('admin.partner.live-monitor.pulse'),
                'overview' => route('admin.partner.overview'),
                'home' => route('admin.dashboard'),
            ],
        ]);
    }

    /** Cheap poll target — the global pulse (this screen spans branches). */
    public function pulse()
    {
        abort_unless(auth()->user()?->isManagementLevel(), 403);

        return response()->json(['version' => LiveRefreshPulse::version()]);
    }

    /**
     * Per-branch KPIs + the recent-orders tail. Deliberately per-branch
     * queries rather than one GROUP BY: the tail needs ORDER BY + LIMIT
     * scoped to each branch, which doesn't fold into an aggregate. Fine
     * for the 1–10 branches an SMB chain runs; revisit past ~20.
     */
    protected function branchPayload(): array
    {
        $today = now()->toDateString();
        $start = $today.' 00:00:00';
        $end = $today.' 23:59:59';

        $branchIds = auth()->user()?->accessibleBranchIds() ?? [];
        if (empty($branchIds)) {
            return [];
        }

        $branches = Branch::active()
            ->whereIn('id', $branchIds)
            ->orderBy('display_order')
            ->get();

        $visibleIds = $branches->pluck('id')->all();
        $salesByBranch = Invoice::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->selectRaw('branch_id, SUM(paid_total) as sales, COUNT(*) as invoices, AVG(paid_total) as avg_ticket')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');
        $tablesByBranch = Table::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->where('active', true)
            ->get(['id', 'branch_id', 'number', 'status'])
            ->groupBy('branch_id');
        $todayOrdersByBranch = Order::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('branch_id, COUNT(*) as aggregate')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');
        $statusRowsByBranch = Order::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->whereIn('status', OrderStatus::active())
            ->selectRaw('branch_id, status, COUNT(*) as aggregate')
            ->groupBy('branch_id', 'status')
            ->get()->groupBy('branch_id');
        $delayedByBranch = Order::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->whereIn('status', [OrderStatus::Approved->value, OrderStatus::Preparing->value])
            ->whereNotNull('estimated_ready_at')
            ->where('estimated_ready_at', '<', now())
            ->selectRaw('branch_id, COUNT(*) as aggregate')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');
        $oldestByBranch = Order::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $visibleIds)
            ->whereIn('status', OrderStatus::active())
            ->selectRaw('branch_id, MIN(created_at) as oldest_at')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');

        return $branches
            ->map(function (Branch $b) use (
                $start, $end, $salesByBranch, $tablesByBranch, $todayOrdersByBranch,
                $statusRowsByBranch, $delayedByBranch, $oldestByBranch
            ) {
                $salesRow = $salesByBranch->get($b->id);
                $sales = (float) ($salesRow?->sales ?? 0);
                $invoiceCount = (int) ($salesRow?->invoices ?? 0);
                $tables = $tablesByBranch->get($b->id, collect())->values();
                $occupied = $tables->where('status', 'occupied')->count();
                $statusCounts = $statusRowsByBranch->get($b->id, collect())
                    ->pluck('aggregate', 'status');
                $activeOrders = (int) $statusCounts->sum();
                $delayedOrders = (int) ($delayedByBranch->get($b->id)?->aggregate ?? 0);
                $oldestActiveAt = $oldestByBranch->get($b->id)?->oldest_at;
                $oldestActiveMinutes = $oldestActiveAt
                    ? (int) \Carbon\Carbon::parse($oldestActiveAt)->diffInMinutes(now())
                    : 0;
                $occupancy = $tables->count() > 0
                    ? (int) round(($occupied / $tables->count()) * 100)
                    : 0;
                $pressureScore = min(100, (int) round(
                    ($delayedOrders * 25)
                    + ((int) ($statusCounts[OrderStatus::Pending->value] ?? 0) * 12)
                    + ($activeOrders * 4)
                    + ($occupancy * .24)
                ));
                $health = match (true) {
                    $delayedOrders > 0 => ['tone' => 'danger', 'label' => 'ضغط مرتفع'],
                    $pressureScore >= 55 => ['tone' => 'warning', 'label' => 'نشاط مرتفع'],
                    default => ['tone' => 'calm', 'label' => 'مستقر'],
                };

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'city' => $b->city,
                    'sales' => $sales,
                    'invoices' => $invoiceCount,
                    'avgTicket' => (float) ($salesRow?->avg_ticket ?? 0),
                    'activeOrders' => $activeOrders,
                    'delayedOrders' => $delayedOrders,
                    'oldestActiveMinutes' => $oldestActiveMinutes,
                    'statusCounts' => [
                        'pending' => (int) ($statusCounts[OrderStatus::Pending->value] ?? 0),
                        'approved' => (int) ($statusCounts[OrderStatus::Approved->value] ?? 0),
                        'preparing' => (int) ($statusCounts[OrderStatus::Preparing->value] ?? 0),
                        'ready' => (int) ($statusCounts[OrderStatus::Ready->value] ?? 0),
                        'delivered' => (int) ($statusCounts[OrderStatus::Delivered->value] ?? 0),
                    ],
                    'occupancy' => $occupancy,
                    'pressure' => $pressureScore,
                    'health' => $health,
                    'todayOrders' => (int) ($todayOrdersByBranch->get($b->id)?->aggregate ?? 0),
                    'totalTables' => $tables->count(),
                    'occupied' => $occupied,
                    'tables' => $tables->map(fn ($t) => [
                        'id' => $t->id,
                        'number' => $t->number,
                        'status' => $t->status,
                    ])->values()->all(),
                    // The tail shows the last N orders regardless of status,
                    // so the owner sees fresh tickets AND ones just closed.
                    'recent' => Order::query()->withoutGlobalScopes()
                        ->with(['table:id,number'])
                        ->where('branch_id', $b->id)
                        ->whereBetween('created_at', [$start, $end])
                        ->latest()
                        ->limit(self::RECENT_LIMIT)
                        ->get(['id', 'number', 'table_id', 'status', 'total', 'created_at', 'estimated_ready_at'])
                        ->map(fn (Order $o) => [
                            'id' => $o->id,
                            'number' => $o->number,
                            'tableNumber' => $o->table?->number,
                            'status' => $o->status,
                            'statusLabel' => $this->statusLabel($o->status),
                            'statusColor' => $this->statusColor($o->status),
                            'total' => (float) $o->total,
                            'at' => $o->created_at?->format('H:i'),
                            'ageMinutes' => $o->created_at?->diffInMinutes(now()) ?? 0,
                            'active' => in_array($o->status, OrderStatus::active(), true),
                            'delayed' => in_array($o->status, [OrderStatus::Approved->value, OrderStatus::Preparing->value], true)
                                && $o->estimated_ready_at
                                && $o->estimated_ready_at->isPast(),
                        ])->values()->all(),
                ];
            })->values()->all();
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'pending' => '#9ca3af',
            'approved' => '#3b82f6',
            'preparing' => '#f59e0b',
            'ready', 'delivered' => '#10b981',
            'completed' => '#22c55e',
            'cancelled' => '#ef4444',
            default => '#6b7280',
        };
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'في الانتظار',
            'approved' => 'معتمد',
            'preparing' => 'يحضر',
            'ready' => 'جاهز',
            'delivered' => 'مسلم',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
            default => $status,
        };
    }
}
