@extends('layouts.admin')
@section('title', 'لوحة الطلبات')

@section('content')
<x-admin.breadcrumb title="لوحة الطلبات" icon="bi-kanban-fill"
    subtitle="عرض ذكي للطلبات حسب مرحلة العمل — لا فوضى، كل شي مكانه">
    <x-slot:actions>
        <a href="{{ route('admin.orders.list') }}" class="btn btn-light">
            <i class="bi bi-table"></i> عرض قائمة
        </a>
        @if($stats['pending'] > 0)
            <form method="POST" action="{{ route('admin.orders.bulk-approve') }}" class="d-inline"
                  onsubmit="return confirm('اعتماد كل {{ $stats['pending'] }} طلب بانتظار الموافقة؟');">
                @csrf
                @foreach($columns['pending'] as $p)
                    <input type="hidden" name="order_ids[]" value="{{ $p->id }}">
                @endforeach
                <button class="btn btn-success">
                    <i class="bi bi-check2-all"></i> اعتماد الكل ({{ $stats['pending'] }})
                </button>
            </form>
        @endif
    </x-slot:actions>
</x-admin.breadcrumb>

<x-admin.stat-rail :stats="[
    ['label' => 'بانتظار الموافقة',  'value' => $stats['pending'],    'icon' => 'bi-hourglass-split', 'color' => $stats['pending'] > 0 ? 'accent' : 'success'],
    ['label' => 'عاجل (>5 دقائق)',    'value' => $stats['urgent'],     'icon' => 'bi-alarm-fill',       'color' => $stats['urgent'] > 0 ? 'danger' : 'success'],
    ['label' => 'في المطبخ',          'value' => $stats['in_kitchen'], 'icon' => 'bi-fire',             'color' => 'primary'],
    ['label' => 'جاهز للتقديم',       'value' => $stats['ready'],      'icon' => 'bi-check-circle-fill','color' => 'success'],
]" />

@php
    $columnMeta = [
        'pending'    => ['title' => 'بانتظار الموافقة', 'icon' => 'bi-hourglass-split', 'color' => '#b91c1c', 'bg' => 'rgba(185,28,28,.04)', 'border' => '#b91c1c'],
        'in_kitchen' => ['title' => 'في المطبخ',         'icon' => 'bi-fire',           'color' => '#b8872a', 'bg' => 'rgba(184,135,42,.04)','border' => '#b8872a'],
        'ready'      => ['title' => 'جاهز للتقديم',      'icon' => 'bi-bell-fill',      'color' => '#1f4733', 'bg' => 'rgba(31,71,51,.04)',  'border' => '#1f4733'],
        'finished'   => ['title' => 'تم التسليم',        'icon' => 'bi-check-circle',   'color' => '#78716c', 'bg' => 'rgba(120,113,108,.04)','border' => '#78716c'],
    ];
@endphp

<div class="kanban-wrap">
    @foreach($columnMeta as $key => $meta)
        @php $items = $columns[$key]; @endphp
        <div class="kanban-col" style="background: {{ $meta['bg'] }}; border-top-color: {{ $meta['border'] }};">
            <div class="kanban-col-head" style="color: {{ $meta['color'] }};">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi {{ $meta['icon'] }}"></i>
                    <span class="fw-bold">{{ $meta['title'] }}</span>
                </div>
                <span class="kanban-col-count" style="background: {{ $meta['color'] }};">
                    {{ $items->count() }}
                </span>
            </div>

            <div class="kanban-col-body">
                @forelse($items as $order)
                    @php
                        $ageMin = (int) $order->created_at->diffInMinutes(now());
                        $urgent = $key === 'pending' && $ageMin > 5;
                        $critical = $key === 'pending' && $ageMin > 10;
                        $readyStale = $key === 'ready' && $ageMin > 10;
                    @endphp
                    <div class="kanban-card {{ $critical ? 'kanban-critical' : ($urgent ? 'kanban-urgent' : '') }} {{ $readyStale ? 'kanban-ready-stale' : '' }}">
                        <div class="kanban-card-head">
                            <div class="kanban-card-num">{{ $order->number }}</div>
                            @if($order->table)
                                <div class="kanban-table-chip"><i class="bi bi-grid-3x3-gap-fill"></i> {{ $order->table->number }}</div>
                            @endif
                        </div>

                        <div class="kanban-card-body">
                            <div class="kanban-card-items">
                                <i class="bi bi-bag-fill"></i>
                                {{ $order->items->count() }}
                                {{ $order->items->count() === 1 ? 'صنف' : 'أصناف' }}
                            </div>
                            <div class="kanban-card-total">
                                {{ \App\Helpers\Money::format($order->total) }}
                            </div>
                        </div>

                        {{-- Quick peek at items (first 2) --}}
                        <div class="kanban-card-preview">
                            @foreach($order->items->take(2) as $it)
                                <div class="kanban-item-line">
                                    <span>×{{ $it->quantity }}</span>
                                    <span class="text-truncate">{{ $it->name_snapshot }}</span>
                                </div>
                            @endforeach
                            @if($order->items->count() > 2)
                                <div class="kanban-item-line text-muted">
                                    <span>+</span>
                                    <span>{{ $order->items->count() - 2 }} أخرى</span>
                                </div>
                            @endif
                        </div>

                        <div class="kanban-card-meta">
                            <span class="kanban-time">
                                @if($critical)<i class="bi bi-alarm-fill text-danger"></i>@endif
                                @if($urgent && !$critical)<i class="bi bi-alarm" style="color:#b8872a;"></i>@endif
                                @if($readyStale)<i class="bi bi-exclamation-triangle text-danger"></i>@endif
                                منذ {{ $ageMin < 1 ? 'ثوانٍ' : $ageMin . ' د' }}
                            </span>
                            @if($order->customer_name ?? false)
                                <span class="small text-muted"><i class="bi bi-person"></i> {{ Str::limit($order->customer_name, 10) }}</span>
                            @endif
                        </div>

                        <div class="kanban-card-actions">
                            @if($key === 'pending')
                                <form method="POST" action="{{ route('admin.orders.approve', $order) }}" class="flex-grow-1">
                                    @csrf
                                    <button class="btn btn-success btn-sm w-100">
                                        <i class="bi bi-check2"></i> اعتماد
                                    </button>
                                </form>
                                <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-light btn-sm" title="تفاصيل">
                                    <i class="bi bi-eye"></i>
                                </a>
                            @elseif($key === 'in_kitchen')
                                <form method="POST" action="{{ route('admin.orders.transition', $order) }}" class="flex-grow-1">
                                    @csrf
                                    <input type="hidden" name="target" value="ready">
                                    <button class="btn btn-primary btn-sm w-100" title="علّم أن الطلب جاهز للتقديم">
                                        <i class="bi bi-bell"></i> جاهز
                                    </button>
                                </form>
                                <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-light btn-sm" title="تفاصيل">
                                    <i class="bi bi-eye"></i>
                                </a>
                            @elseif($key === 'ready')
                                <form method="POST" action="{{ route('admin.orders.transition', $order) }}" class="flex-grow-1">
                                    @csrf
                                    <input type="hidden" name="target" value="delivered">
                                    <button class="btn btn-primary btn-sm w-100">
                                        <i class="bi bi-truck"></i> تم التسليم
                                    </button>
                                </form>
                                <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-light btn-sm">
                                    <i class="bi bi-eye"></i>
                                </a>
                            @else
                                <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-light btn-sm w-100">
                                    <i class="bi bi-eye"></i> تفاصيل
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="kanban-empty">
                        <i class="bi {{ $meta['icon'] }}"></i>
                        <span>فارغ</span>
                    </div>
                @endforelse
            </div>
        </div>
    @endforeach
</div>

{{-- Live indicator pill --}}
<div class="orders-live-pill" id="ordersLivePill">
    <span class="live-dot"></span>
    <span class="live-label">مباشر</span>
    <span class="last-updated" id="ordersLastUpdated">الآن</span>
</div>

@push('styles')
<style>
.kanban-wrap {
    display: grid;
    grid-template-columns: repeat(4, minmax(260px, 1fr));
    gap: .85rem;
    overflow-x: auto;
    padding-bottom: 1rem;
}
@media (max-width: 991.98px) {
    .kanban-wrap { grid-template-columns: repeat(2, minmax(280px, 1fr)); }
}
@media (max-width: 575.98px) {
    .kanban-wrap { grid-template-columns: minmax(280px, 1fr); }
}

.kanban-col {
    background: white;
    border: 1px solid rgba(0,0,0,.05);
    border-top: 4px solid;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    min-height: 200px;
    max-height: calc(100vh - 320px);
}
.kanban-col-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .85rem 1rem;
    border-bottom: 1px solid rgba(0,0,0,.06);
    font-size: .95rem;
}
.kanban-col-count {
    color: white;
    padding: 2px 10px;
    border-radius: 99px;
    font-size: .78rem;
    font-weight: 900;
    min-width: 28px;
    text-align: center;
}
.kanban-col-body {
    padding: .6rem;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
.kanban-col-body::-webkit-scrollbar { width: 6px; }
.kanban-col-body::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 99px; }

/* Card */
.kanban-card {
    background: white;
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 12px;
    padding: .75rem .85rem;
    transition: box-shadow .15s, transform .15s;
    position: relative;
}
.kanban-card:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,.08);
    transform: translateY(-1px);
}

/* Urgency states */
.kanban-urgent {
    border-color: #b8872a;
    background: linear-gradient(90deg, rgba(184,135,42,.05), white);
    box-shadow: inset 3px 0 0 #b8872a;
}
.kanban-critical {
    border-color: #b91c1c;
    background: linear-gradient(90deg, rgba(185,28,28,.08), white);
    box-shadow: inset 4px 0 0 #b91c1c;
    animation: kanban-pulse 2s ease-in-out infinite;
}
@keyframes kanban-pulse {
    0%, 100% { box-shadow: inset 4px 0 0 #b91c1c, 0 0 0 0 rgba(185,28,28,.3); }
    50%      { box-shadow: inset 4px 0 0 #b91c1c, 0 0 0 6px rgba(185,28,28,0); }
}
.kanban-ready-stale {
    border-color: #b91c1c;
    background: linear-gradient(90deg, rgba(185,28,28,.06), white);
    box-shadow: inset 3px 0 0 #b91c1c;
}

.kanban-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}
.kanban-card-num {
    font-family: 'Courier New', monospace;
    font-weight: 900;
    font-size: .82rem;
    color: var(--primary);
}
.kanban-table-chip {
    background: rgba(var(--accent-rgb),.15);
    color: #8a6920;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: .72rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.kanban-card-body {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 6px;
}
.kanban-card-items {
    font-size: .75rem;
    color: #78716c;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.kanban-card-total {
    font-weight: 900;
    font-size: 1rem;
    color: var(--primary);
    font-variant-numeric: tabular-nums;
}

.kanban-card-preview {
    background: rgba(var(--primary-rgb),.03);
    border-radius: 8px;
    padding: 6px 8px;
    margin-bottom: 8px;
    font-size: .75rem;
}
.kanban-item-line {
    display: flex;
    gap: 6px;
    padding: 2px 0;
}
.kanban-item-line > span:first-child {
    color: var(--accent);
    font-weight: 800;
    min-width: 22px;
}

.kanban-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .7rem;
    color: #78716c;
    margin-bottom: 8px;
}
.kanban-time {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-weight: 700;
}

.kanban-card-actions {
    display: flex;
    gap: 4px;
}
.kanban-card-actions .btn { font-size: .75rem; padding: .3rem .5rem; }

.kanban-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 2rem 1rem;
    color: #a8a29e;
    font-size: .85rem;
    font-weight: 600;
}
.kanban-empty i { font-size: 1.8rem; opacity: .4; }

/* Live indicator */
.orders-live-pill {
    position: fixed;
    bottom: 1rem;
    inset-inline-start: 50%;
    transform: translateX(50%);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    background: white;
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 99px;
    box-shadow: 0 4px 18px rgba(0,0,0,.1);
    font-size: .75rem;
    font-weight: 700;
    color: #57534e;
    z-index: 100;
}
.orders-live-pill .live-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #10b981;
    position: relative;
}
.orders-live-pill .live-dot::before {
    content: '';
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    background: rgba(16,185,129,.35);
    animation: live-pulse 1.6s ease-out infinite;
}
@keyframes live-pulse {
    0%   { transform: scale(.8); opacity: .8; }
    100% { transform: scale(2);  opacity: 0; }
}
.orders-live-pill .last-updated {
    font-weight: 600;
    color: #a8a29e;
    font-variant-numeric: tabular-nums;
}
</style>
@endpush

@push('scripts')
<script>
// Live indicator + auto-refresh every 20s (Echo will push real-time anyway)
(function () {
    const loadedAt = Date.now();
    const lastUpd = document.getElementById('ordersLastUpdated');

    function tick() {
        if (!lastUpd) return;
        const s = Math.floor((Date.now() - loadedAt) / 1000);
        lastUpd.textContent = s < 5 ? 'الآن' : (s < 60 ? 'منذ '+s+' ث' : 'منذ '+Math.floor(s/60)+' د');
    }
    tick();
    setInterval(tick, 5000);

    // Soft auto-refresh every 20 seconds (websocket pushes will interrupt this via reloads from onNewOrder)
    setTimeout(() => location.reload(), 20000);
})();
</script>
@endpush
@endsection
