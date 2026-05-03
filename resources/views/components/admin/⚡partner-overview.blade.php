<?php

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Table;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Partner Overview — multi-branch live KPI dashboard.
 *
 * One card per active branch, each showing today's hard numbers + a green/red
 * pulse if anything needs attention. Designed for the owner's daily question:
 * "kif dayfir aljaw fil furu' alyom?" — answer it without forcing them to
 * switch branches one by one.
 *
 * Refresh strategy: event-driven via Reverb (order created, status changed,
 * invoice paid) + a slow 60s safety poll for branches that aren't generating
 * events (closed branch, etc.). Polling is `.visible.` so a backgrounded tab
 * doesn't hammer the DB.
 *
 * Implementation note: every cross-branch query bypasses the BranchScope
 * with `withoutGlobalScopes()` and groups by `branch_id`. Doing per-branch
 * `BranchContext::forBranch(...)` loops would N+1 the dashboard for owners
 * with many branches; one aggregate query is fine for any chain size.
 */
new class extends Component
{
    /** Cards refresh on any of these broadcasts. */
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.order.status_changed')]
    #[On('echo-private:waiters,.invoice.paid')]
    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->summaries);
    }

    /**
     * Per-branch today snapshot — sales, orders, table occupancy, attention
     * count. Computed in two aggregate queries (one for invoice/sales, one for
     * orders) + one for tables; all unscoped so the owner sees every branch
     * without their session's BranchContext interfering.
     *
     * @return array<int,array{branch:Branch, sales:float, paid_invoices:int, today_orders:int, active_orders:int, occupied:int, total_tables:int, avg_ticket:float, needs_attention:bool, attention_count:int}>
     */
    #[Computed]
    public function summaries(): array
    {
        $today = now()->toDateString();
        $start = $today.' 00:00:00';
        $end   = $today.' 23:59:59';

        $branches = Branch::active()->orderBy('display_order')->get();

        // ── Sales + paid invoice count (today) ──
        $sales = Invoice::query()->withoutGlobalScopes()
            ->whereBetween('issued_at', [$start, $end])
            ->whereIn('status', ['paid', 'partially_paid'])
            ->selectRaw('branch_id, SUM(paid_total) as total, COUNT(*) as cnt')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');

        // ── Orders today + active in flight ──
        $orders = Order::query()->withoutGlobalScopes()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('branch_id, COUNT(*) as today_cnt, '
                . 'SUM(CASE WHEN status IN ("approved","preparing","ready") THEN 1 ELSE 0 END) as active_cnt')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');

        // ── Table occupancy ──
        $tables = Table::query()->withoutGlobalScopes()
            ->where('active', true)
            ->selectRaw('branch_id, COUNT(*) as total, '
                . 'SUM(CASE WHEN status = "occupied" THEN 1 ELSE 0 END) as occupied')
            ->groupBy('branch_id')
            ->get()->keyBy('branch_id');

        return $branches->map(function (Branch $b) use ($sales, $orders, $tables) {
            $s   = $sales->get($b->id);
            $o   = $orders->get($b->id);
            $t   = $tables->get($b->id);

            $totalSales    = (float) ($s->total ?? 0);
            $paidInvoices  = (int)   ($s->cnt   ?? 0);
            $todayOrders   = (int)   ($o->today_cnt  ?? 0);
            $activeOrders  = (int)   ($o->active_cnt ?? 0);
            $occupied      = (int)   ($t->occupied   ?? 0);
            $totalTables   = (int)   ($t->total      ?? 0);

            return [
                'branch'          => $b,
                'sales'           => $totalSales,
                'paid_invoices'   => $paidInvoices,
                'today_orders'    => $todayOrders,
                'active_orders'   => $activeOrders,
                'occupied'        => $occupied,
                'total_tables'    => $totalTables,
                'avg_ticket'      => $paidInvoices > 0 ? $totalSales / $paidInvoices : 0,
                // "Needs attention" = active orders > active capacity, i.e. the
                // line is busier than the floor can keep up with. Per-branch
                // low-stock isn't available (ingredients are a global pool).
                'needs_attention' => $totalTables > 0 && $activeOrders > $occupied,
            ];
        })->values()->all();
    }

    /** Sum line — totals across every branch for the header strip. */
    #[Computed]
    public function totals(): array
    {
        $cards = $this->summaries;

        // Global low stock (ingredients are shared across branches, so this is
        // a company-wide number, not per-branch).
        $lowStock = (int) DB::table('ingredients')
            ->where('track_stock', true)
            ->whereColumn('current_stock', '<=', 'reorder_threshold')
            ->count();

        return [
            'sales'        => array_sum(array_column($cards, 'sales')),
            'orders'       => array_sum(array_column($cards, 'today_orders')),
            'active'       => array_sum(array_column($cards, 'active_orders')),
            'invoices'     => array_sum(array_column($cards, 'paid_invoices')),
            'occupied'     => array_sum(array_column($cards, 'occupied')),
            'total_tables' => array_sum(array_column($cards, 'total_tables')),
            'low_stock'    => $lowStock,
            'branches'     => count($cards),
        ];
    }
}
?>

<div class="po-wrap" wire:poll.visible.60s="refreshFromBroadcast">
    {{-- Header strip: totals across every branch --}}
    <div class="po-totals">
        <div class="po-total">
            <div class="po-total__label">إجمالي مبيعات اليوم</div>
            <div class="po-total__value">{{ number_format($this->totals['sales'], 2) }} <span class="po-currency">ر.ع</span></div>
            <div class="po-total__meta">{{ $this->totals['invoices'] }} فاتورة · {{ $this->totals['branches'] }} فرع نشط</div>
        </div>
        <div class="po-total">
            <div class="po-total__label">طلبات اليوم</div>
            <div class="po-total__value">{{ $this->totals['orders'] }}</div>
            <div class="po-total__meta">منها {{ $this->totals['active'] }} قيد التنفيذ</div>
        </div>
        <div class="po-total">
            <div class="po-total__label">طاولات مشغولة</div>
            <div class="po-total__value">{{ $this->totals['occupied'] }} <span class="po-of">/ {{ $this->totals['total_tables'] }}</span></div>
            <div class="po-total__meta">@if($this->totals['total_tables'] > 0) {{ round(($this->totals['occupied']/$this->totals['total_tables'])*100) }}% إشغال @else لا توجد طاولات @endif</div>
        </div>
        <div class="po-total {{ $this->totals['low_stock'] > 0 ? 'po-total--warn' : '' }}">
            <div class="po-total__label">تنبيهات المخزون</div>
            <div class="po-total__value">{{ $this->totals['low_stock'] }}</div>
            <div class="po-total__meta">صنف وصل حدّ إعادة الطلب</div>
        </div>
    </div>

    {{-- Per-branch cards --}}
    <div class="po-grid">
        @foreach($this->summaries as $card)
            @php $b = $card['branch']; $hue = ($b->id * 47) % 360; @endphp
            <article class="po-card {{ $card['needs_attention'] ? 'is-warn' : '' }}" style="--hue: {{ $hue }};">
                <header class="po-card__head">
                    <span class="po-card__avatar">{{ mb_substr($b->name, 0, 1, 'UTF-8') }}</span>
                    <div class="po-card__title">
                        <h3>{{ $b->name }}</h3>
                        @if($b->city)
                            <span class="po-card__city"><i class="bi bi-geo-alt"></i> {{ $b->city }}</span>
                        @endif
                    </div>
                    <span class="po-card__pulse" title="مباشر"></span>
                </header>

                <div class="po-card__metrics">
                    <div class="po-metric">
                        <div class="po-metric__value">{{ number_format($card['sales'], 0) }}</div>
                        <div class="po-metric__label">مبيعات اليوم (ر.ع)</div>
                    </div>
                    <div class="po-metric">
                        <div class="po-metric__value">{{ $card['today_orders'] }}</div>
                        <div class="po-metric__label">طلبات اليوم</div>
                    </div>
                    <div class="po-metric">
                        <div class="po-metric__value">{{ $card['active_orders'] }}</div>
                        <div class="po-metric__label">قيد التنفيذ</div>
                    </div>
                    <div class="po-metric">
                        <div class="po-metric__value">
                            {{ $card['occupied'] }}<span class="po-metric__of">/{{ $card['total_tables'] }}</span>
                        </div>
                        <div class="po-metric__label">طاولات مشغولة</div>
                    </div>
                    <div class="po-metric po-metric--span2">
                        <div class="po-metric__value">{{ number_format($card['avg_ticket'], 2) }} <span class="po-currency">ر.ع</span></div>
                        <div class="po-metric__label">متوسط الفاتورة</div>
                    </div>
                </div>

                @if($card['needs_attention'])
                    <div class="po-card__alert">
                        <i class="bi bi-lightning-charge-fill"></i>
                        ضغط في المطبخ — {{ $card['active_orders'] }} طلب قيد التنفيذ
                    </div>
                @else
                    <div class="po-card__alert po-card__alert--ok">
                        <i class="bi bi-check-circle-fill"></i>
                        التشغيل سلس
                    </div>
                @endif

                <footer class="po-card__foot">
                    <form action="{{ route('admin.branches.switch', $b) }}" method="POST" class="po-card__switch">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-box-arrow-in-right"></i>
                            دخول الفرع
                        </button>
                    </form>
                </footer>
            </article>
        @endforeach
    </div>
</div>

@once
<style>
    .po-wrap { display: flex; flex-direction: column; gap: 24px; }

    /* Totals strip */
    .po-totals {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
    }
    .po-total {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 18px 20px;
        box-shadow: 0 1px 2px rgba(15,23,42,.04);
        position: relative;
        overflow: hidden;
    }
    .po-total::before {
        content: '';
        position: absolute; inset: 0 0 auto 0;
        height: 3px;
        background: linear-gradient(90deg, rgb(var(--primary-rgb)), rgb(var(--accent-rgb)));
    }
    .po-total--warn::before { background: linear-gradient(90deg, #f59e0b, #ef4444); }
    .po-total__label { font-size: .78rem; font-weight: 600; color: #6b7280; margin-bottom: 6px; }
    .po-total__value {
        font-size: 1.85rem; font-weight: 800; color: #111827; line-height: 1.1;
    }
    .po-total__value .po-currency,
    .po-total__value .po-of {
        font-size: .9rem; font-weight: 600; color: #9ca3af;
    }
    .po-total__meta { margin-top: 6px; font-size: .76rem; color: #9ca3af; }

    /* Branch cards grid */
    .po-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 18px;
    }

    .po-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        box-shadow: 0 1px 3px rgba(15,23,42,.05);
        transition: transform .15s, box-shadow .15s;
        position: relative;
        overflow: hidden;
    }
    .po-card::before {
        content: '';
        position: absolute; inset: 0 0 auto 0;
        height: 3px;
        background: linear-gradient(135deg, hsl(var(--hue) 55% 45%), hsl(var(--hue) 60% 35%));
    }
    .po-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px -4px rgba(15,23,42,.12);
    }
    .po-card.is-warn::before {
        background: linear-gradient(90deg, #f59e0b, #ef4444);
    }

    .po-card__head {
        display: flex; align-items: center; gap: 12px;
    }
    .po-card__avatar {
        width: 40px; height: 40px;
        border-radius: 50%;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center; justify-content: center;
        background: linear-gradient(135deg, hsl(var(--hue) 50% 52%), hsl(var(--hue) 55% 38%));
        color: #fff;
        font-size: 1.05rem;
        font-weight: 800;
    }
    .po-card__title { flex: 1; min-width: 0; }
    .po-card__title h3 {
        font-size: 1rem; font-weight: 800; color: #111827; margin: 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .po-card__city {
        font-size: .72rem; color: #6b7280;
    }
    .po-card__pulse {
        width: 8px; height: 8px; border-radius: 50%;
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34,197,94,.18);
        animation: po-pulse 2s ease-in-out infinite;
    }
    @keyframes po-pulse {
        0%, 100% { box-shadow: 0 0 0 4px rgba(34,197,94,.18); }
        50%      { box-shadow: 0 0 0 7px rgba(34,197,94,.06); }
    }

    .po-card__metrics {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .po-metric {
        background: #f9fafb;
        border: 1px solid #f3f4f6;
        border-radius: 10px;
        padding: 10px 12px;
    }
    .po-metric--span2 { grid-column: span 2; }
    .po-metric__value {
        font-size: 1.25rem; font-weight: 800; color: #111827; line-height: 1.1;
    }
    .po-metric__value .po-metric__of,
    .po-metric__value .po-currency {
        font-size: .78rem; font-weight: 600; color: #9ca3af;
    }
    .po-metric__label {
        font-size: .7rem; color: #6b7280; margin-top: 4px;
    }

    .po-card__alert {
        display: flex; align-items: center; gap: 6px;
        padding: 8px 12px;
        background: #fff7ed;
        color: #b45309;
        border: 1px solid #fed7aa;
        border-radius: 8px;
        font-size: .82rem;
        font-weight: 600;
    }
    .po-card__alert--ok {
        background: #f0fdf4;
        color: #166534;
        border-color: #bbf7d0;
    }

    .po-card__foot {
        margin-top: auto;
    }
    .po-card__switch { margin: 0; }
    .po-card__switch button { font-weight: 700; }

    /* Mobile */
    @media (max-width: 640px) {
        .po-grid { grid-template-columns: 1fr; }
        .po-totals { grid-template-columns: 1fr 1fr; }
        .po-total__value { font-size: 1.5rem; }
    }
</style>
@endonce
