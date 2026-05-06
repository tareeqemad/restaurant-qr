<?php

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live Operations Monitor - full-screen, all branches in one frame.
 *
 * Designed to live on a TV in the owner's office. Each branch gets a column;
 * the column shows: today's sales, in-flight orders count, table occupancy,
 * and the last few orders ticking by. Updates flow in via the same Reverb
 * broadcasts the kitchen and tables boards listen to, so a new order in
 * Khan Yunis appears in this monitor at the same instant the chef sees it
 * on his KDS.
 *
 * Why a separate component (not a re-skin of Partner Overview):
 *   - Different audience: glance-able on a far screen vs. clickable cards.
 *   - Different data shape: the monitor cares about RECENT orders streaming
 *     in (tail of the order log), not just summary KPIs.
 *   - Different update cadence: stricter - every 10s safety poll on top of
 *     event-driven refresh. Money is being made; lag matters.
 */
new class extends Component
{
    /** Number of recent orders to show per branch. */
    public int $recentLimit = 6;

    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.order.status_changed')]
    #[On('echo-private:waiters,.invoice.paid')]
    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->branches);
    }

    /**
     * Per-branch payload - KPIs + recent orders. We intentionally do per-
     * branch queries here (not aggregate) because the recent-orders tail
     * needs ORDER BY + LIMIT scoped to each branch, which doesn't fold
     * nicely into a single GROUP BY query.
     *
     * Number of branches for an SMB restaurant chain (1-10) keeps this
     * affordable; revisit if a chain ever blows past ~20.
     *
     * @return array<int,array<string,mixed>>
     */
    #[Computed]
    public function branches(): array
    {
        $today = now()->toDateString();
        $start = $today.' 00:00:00';
        $end   = $today.' 23:59:59';

        // Owner-level sees every branch; branch admin/manager sees only the
        // branches they belong to. Without this scope a branch user would
        // see another branch's columns in the monitor.
        $branchIds = auth()->user()?->accessibleBranchIds() ?? [];
        if (empty($branchIds)) {
            return [];
        }

        return Branch::active()
            ->whereIn('id', $branchIds)
            ->orderBy('display_order')
            ->get()
            ->map(function (Branch $b) use ($start, $end) {
            // Today sales - paid + partially paid invoices.
            $sales = (float) Invoice::query()->withoutGlobalScopes()
                ->where('branch_id', $b->id)
                ->whereBetween('issued_at', [$start, $end])
                ->whereIn('status', ['paid', 'partially_paid'])
                ->sum('paid_total');

            $invoiceCount = Invoice::query()->withoutGlobalScopes()
                ->where('branch_id', $b->id)
                ->whereBetween('issued_at', [$start, $end])
                ->whereIn('status', ['paid', 'partially_paid'])
                ->count();

            // Active orders (in flight on the line).
            $activeOrders = Order::query()->withoutGlobalScopes()
                ->where('branch_id', $b->id)
                ->whereIn('status', ['approved', 'preparing', 'ready'])
                ->count();

            $todayOrders = Order::query()->withoutGlobalScopes()
                ->where('branch_id', $b->id)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            // Tables.
            $tables = Table::query()->withoutGlobalScopes()
                ->where('branch_id', $b->id)
                ->where('active', true)
                ->get(['id', 'number', 'status']);
            $occupied = $tables->where('status', 'occupied')->count();

            // Recent orders tail - shows the LAST N orders regardless of status
            // so the owner sees both fresh tickets and ones that just closed.
            $recent = Order::query()->withoutGlobalScopes()
                ->with(['table:id,number'])
                ->where('branch_id', $b->id)
                ->whereBetween('created_at', [$start, $end])
                ->latest()
                ->limit($this->recentLimit)
                ->get(['id', 'number', 'table_id', 'status', 'total', 'created_at']);

            return [
                'branch'        => $b,
                'sales'         => $sales,
                'invoices'      => $invoiceCount,
                'active_orders' => $activeOrders,
                'today_orders'  => $todayOrders,
                'tables'        => $tables,
                'occupied'      => $occupied,
                'total_tables'  => $tables->count(),
                'avg_ticket'    => $invoiceCount > 0 ? $sales / $invoiceCount : 0,
                'recent'        => $recent,
            ];
        })->all();
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'pending'    => '#9ca3af',
            'approved'   => '#3b82f6',
            'preparing'  => '#f59e0b',
            'ready'      => '#10b981',
            'delivered'  => '#10b981',
            'completed'  => '#22c55e',
            'cancelled'  => '#ef4444',
            default      => '#6b7280',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'    => 'في الانتظار',
            'approved'   => 'معتمد',
            'preparing'  => 'يحضر',
            'ready'      => 'جاهز',
            'delivered'  => 'مسلم',
            'completed'  => 'مكتمل',
            'cancelled'  => 'ملغى',
            default      => $status,
        };
    }
}
?>

<div class="lm-wrap" wire:poll.10s="refreshFromBroadcast">
    @php
        $branches = collect($this->branches);
        $totals = [
            'sales' => $branches->sum('sales'),
            'active_orders' => $branches->sum('active_orders'),
            'today_orders' => $branches->sum('today_orders'),
            'occupied' => $branches->sum('occupied'),
            'tables' => $branches->sum('total_tables'),
            'invoices' => $branches->sum('invoices'),
        ];
        $totals['avg_ticket'] = $totals['invoices'] > 0 ? $totals['sales'] / $totals['invoices'] : 0;
        $occupancyRate = $totals['tables'] > 0 ? round(($totals['occupied'] / $totals['tables']) * 100) : 0;
    @endphp
    {{-- Header strip --}}
    <header class="lm-header">
        <div class="lm-header__title">
            <i class="bi bi-tv-fill"></i>
            <span>المراقبة المباشرة</span>
        </div>
        <div class="lm-header__meta">
            <span class="lm-clock" x-data x-init="
                const tick = () => $el.textContent = new Date().toLocaleTimeString('ar-EG', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
                tick(); setInterval(tick, 1000);
            ">--:--:--</span>
            <span class="lm-date">{{ now()->translatedFormat('l، d F Y') }}</span>
            <span class="lm-pulse" title="مباشر"></span>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="lm-exit" title="عودة للوحة التحكم">
            <i class="bi bi-x-lg"></i>
        </a>
    </header>

    <section class="lm-overview" aria-label="ملخص التشغيل المباشر">
        <article class="lm-overview-card lm-overview-card--primary">
            <span class="lm-overview-card__label">مبيعات اليوم</span>
            <strong>{{ number_format($totals['sales'], 0) }}</strong>
            <small>على {{ $totals['invoices'] }} فاتورة</small>
        </article>
        <article class="lm-overview-card">
            <span class="lm-overview-card__label">طلبات نشطة</span>
            <strong>{{ $totals['active_orders'] }}<em>/{{ $totals['today_orders'] }}</em></strong>
            <small>قيد التنفيذ / إجمالي اليوم</small>
        </article>
        <article class="lm-overview-card">
            <span class="lm-overview-card__label">إشغال الصالة</span>
            <strong>{{ $occupancyRate }}%</strong>
            <small>{{ $totals['occupied'] }} من {{ $totals['tables'] }} طاولة</small>
        </article>
        <article class="lm-overview-card">
            <span class="lm-overview-card__label">متوسط الفاتورة</span>
            <strong>{{ number_format($totals['avg_ticket'], 1) }}</strong>
            <small>حسب المدفوع اليوم</small>
        </article>
    </section>

    {{-- Branches grid --}}
    <main class="lm-grid" style="--columns: {{ max(1, min($branches->count(), 4)) }};">
        @foreach($branches as $b)
            @php $hue = ($b['branch']->id * 47) % 360; @endphp
            <section class="lm-col" style="--hue: {{ $hue }};" wire:key="lm-{{ $b['branch']->id }}">
                {{-- Column header --}}
                <header class="lm-col__head">
                    <span class="lm-col__avatar">{{ mb_substr($b['branch']->name, 0, 1, 'UTF-8') }}</span>
                    <div class="lm-col__title">
                        <h2>{{ $b['branch']->name }}</h2>
                        @if($b['branch']->city)<span>{{ $b['branch']->city }}</span>@endif
                    </div>
                </header>

                @php
                    $branchOccupancyRate = $b['total_tables'] > 0 ? round(($b['occupied'] / $b['total_tables']) * 100) : 0;
                @endphp
                <div class="lm-capacity">
                    <div class="lm-capacity__head">
                        <span>إشغال الطاولات</span>
                        <strong>{{ $branchOccupancyRate }}%</strong>
                    </div>
                    <div class="lm-capacity__bar" aria-hidden="true">
                        <span style="width: {{ min($branchOccupancyRate, 100) }}%"></span>
                    </div>
                </div>

                {{-- KPI strip --}}
                <div class="lm-kpis">
                    <div class="lm-kpi">
                        <div class="lm-kpi__value">{{ number_format($b['sales'], 0) }}</div>
                        <div class="lm-kpi__label">مبيعات اليوم (ر.ع)</div>
                    </div>
                    <div class="lm-kpi">
                        <div class="lm-kpi__value">{{ $b['active_orders'] }}<span class="lm-kpi__sub">/{{ $b['today_orders'] }}</span></div>
                        <div class="lm-kpi__label">قيد التنفيذ / إجمالي</div>
                    </div>
                    <div class="lm-kpi">
                        <div class="lm-kpi__value">{{ $b['occupied'] }}<span class="lm-kpi__sub">/{{ $b['total_tables'] }}</span></div>
                        <div class="lm-kpi__label">طاولات مشغولة</div>
                    </div>
                    <div class="lm-kpi">
                        <div class="lm-kpi__value">{{ number_format($b['avg_ticket'], 1) }}</div>
                        <div class="lm-kpi__label">متوسط الفاتورة</div>
                    </div>
                </div>

                {{-- Tables mini-grid --}}
                @if($b['tables']->isNotEmpty())
                    <div class="lm-tables" title="حالة الطاولات">
                        @foreach($b['tables'] as $t)
                            <span class="lm-table lm-table--{{ $t->status }}" title="طاولة {{ $t->number }} - {{ $t->status }}">
                                {{ $t->number }}
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Recent orders tail --}}
                <div class="lm-recent">
                    <div class="lm-recent__title">آخر الطلبات</div>
                    @forelse($b['recent'] as $order)
                        <div class="lm-order"
                             style="--st: {{ $this->statusColor($order->status) }};">
                            <div class="lm-order__main">
                                <span class="lm-order__ref">{{ $order->number ?? '#'.$order->id }}</span>
                                @if($order->table)
                                    <span class="lm-order__table">طاولة {{ $order->table->number }}</span>
                                @endif
                            </div>
                            <div class="lm-order__meta">
                                <span class="lm-order__status">{{ $this->statusLabel($order->status) }}</span>
                                <span class="lm-order__total">{{ number_format((float) $order->total, 2) }} ر.ع</span>
                                <span class="lm-order__time">{{ $order->created_at->diffForHumans(syntax: \Carbon\Carbon::DIFF_RELATIVE_TO_NOW, short: true) }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="lm-empty">لا طلبات اليوم بعد</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </main>
</div>

{{-- Alpine for the live clock; bundled by @livewireScripts in v3+. --}}

