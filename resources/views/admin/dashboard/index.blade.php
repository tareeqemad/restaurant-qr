@extends('layouts.admin')

@section('title', 'لوحة التحكم')

@section('content')
<x-admin.breadcrumb title="لوحة التحكم" icon="bi-speedometer2"
    subtitle="نظرة عامة على حالة المطعم اليوم"
    :home="false" />

{{-- Top KPI rail --}}
<x-admin.stat-rail :stats="[
    [
        'label' => 'بانتظار الموافقة',
        'value' => $stats['pending_orders'],
        'icon'  => 'bi-bell-fill',
        'color' => 'accent',
        'link'  => route('admin.orders.index', ['status' => 'pending']),
    ],
    [
        'label' => 'مبيعات اليوم',
        'value' => \App\Helpers\Money::format($stats['today_sales']),
        'icon'  => 'bi-cash-stack',
        'color' => 'primary',
    ],
    [
        'label' => 'طاولات مشغولة',
        'value' => $stats['occupied_tables'].' / '.$stats['total_tables'],
        'icon'  => 'bi-grid-3x3-gap-fill',
        'color' => 'success',
        'link'  => route('admin.tables.index'),
    ],
    [
        'label' => 'مخزون منخفض',
        'value' => $stats['low_stock'],
        'icon'  => $stats['low_stock'] > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill',
        'color' => $stats['low_stock'] > 0 ? 'danger' : 'success',
        'link'  => route('admin.ingredients.index'),
    ],
]" />

{{-- Operational alerts --}}
@if(!empty($alerts))
<x-admin.data-panel title="تنبيهات العمليات" :count="count($alerts)" icon="bi-bell-fill">
    <x-slot:actions>
        <span class="badge" style="background: rgba(var(--accent-rgb),.15); color: var(--accent-dark);">
            يحتاج انتباهك
        </span>
    </x-slot:actions>

    <div class="alerts-grid">
        @foreach($alerts as $alert)
            @php
                $colors = [
                    'critical' => ['bg' => 'rgba(185,28,28,.06)',  'border' => '#b91c1c', 'text' => '#991b1b', 'chip' => '#b91c1c'],
                    'warning'  => ['bg' => 'rgba(184,135,42,.08)', 'border' => '#b8872a', 'text' => '#8a6920', 'chip' => '#b8872a'],
                    'info'     => ['bg' => 'rgba(31,71,51,.06)',   'border' => '#1f4733', 'text' => 'var(--primary)', 'chip' => '#1f4733'],
                ][$alert['severity']] ?? ['bg' => '#f9fafb', 'border' => '#d1d5db', 'text' => '#57534e', 'chip' => '#6b7280'];
            @endphp
            <a href="{{ $alert['route'] }}" class="alert-card" style="background: {{ $colors['bg'] }}; border-right-color: {{ $colors['border'] }};">
                <div class="alert-card-icon" style="background: {{ $colors['chip'] }}; color: white;">
                    <i class="bi {{ $alert['icon'] }}"></i>
                </div>
                <div class="alert-card-body">
                    <div class="alert-card-title" style="color: {{ $colors['text'] }};">
                        {{ $alert['title'] }}
                        <span class="alert-card-count">{{ $alert['count'] }}</span>
                    </div>
                    <div class="alert-card-msg">{{ $alert['message'] }}</div>
                </div>
                <div class="alert-card-cta">
                    <span>{{ $alert['cta'] ?? 'عرض' }}</span>
                    <i class="bi bi-arrow-left"></i>
                </div>
            </a>
        @endforeach
    </div>
</x-admin.data-panel>
@endif

{{-- Sales trend sparkline + hour heatmap --}}
<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <x-admin.data-panel title="مبيعات آخر 7 أيام" icon="bi-graph-up-arrow">
            <x-slot:actions>
                <a href="{{ route('admin.reports.sales') }}" class="btn btn-light btn-sm">
                    <i class="bi bi-bar-chart-line"></i> تقرير مفصّل
                </a>
            </x-slot:actions>

            @php
                $values   = $trend->pluck('value')->toArray();
                $maxValue = max($values) ?: 1;
                $total7d  = array_sum($values);
                $avg7d    = $total7d / max(count($values), 1);
            @endphp

            <div class="p-4">
                {{-- Summary row --}}
                <div class="d-flex align-items-end gap-4 mb-3 flex-wrap">
                    <div>
                        <div class="text-muted small fw-bold">المجموع</div>
                        <div style="font-size:1.7rem; font-weight:900; color:var(--primary); letter-spacing:-.02em;">
                            {{ \App\Helpers\Money::format($total7d) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-muted small fw-bold">المعدّل اليومي</div>
                        <div style="font-size:1.1rem; font-weight:800; color:#8a6920;">
                            {{ \App\Helpers\Money::format($avg7d) }}
                        </div>
                    </div>
                </div>

                {{-- Sparkline (SVG line + area + dots) --}}
                <div class="sparkline-wrap">
                    <svg viewBox="0 0 700 200" preserveAspectRatio="none" class="sparkline-svg">
                        @php
                            $w = 700; $h = 200; $n = count($values); $gap = 40;
                            $usableW = $w - $gap * 2;
                            $points = [];
                            foreach ($values as $i => $v) {
                                $x = $gap + ($n > 1 ? ($i / ($n - 1)) * $usableW : $usableW / 2);
                                $y = $h - 30 - ($v / $maxValue) * ($h - 60);
                                $points[] = [$x, $y, $v];
                            }
                            // Build path — smooth line
                            $linePath = 'M '.implode(' L ', array_map(fn($p) => $p[0].' '.$p[1], $points));
                            $areaPath = $linePath.' L '.end($points)[0].' '.($h-30).' L '.$points[0][0].' '.($h-30).' Z';
                        @endphp

                        <defs>
                            <linearGradient id="sparkFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%"   stop-color="rgb(var(--primary-rgb))" stop-opacity=".25"/>
                                <stop offset="100%" stop-color="rgb(var(--primary-rgb))" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="sparkLine" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%"   stop-color="var(--accent)"/>
                                <stop offset="100%" stop-color="var(--primary)"/>
                            </linearGradient>
                        </defs>

                        {{-- grid lines --}}
                        @foreach([0.25, 0.5, 0.75] as $r)
                            <line x1="{{ $gap }}" y1="{{ $h - 30 - $r * ($h - 60) }}" x2="{{ $w - $gap }}" y2="{{ $h - 30 - $r * ($h - 60) }}" stroke="rgba(31,71,51,.08)" stroke-dasharray="3 4"/>
                        @endforeach

                        {{-- area under line --}}
                        <path d="{{ $areaPath }}" fill="url(#sparkFill)"/>

                        {{-- line --}}
                        <path d="{{ $linePath }}" fill="none" stroke="url(#sparkLine)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>

                        {{-- dots --}}
                        @foreach($points as $p)
                            <g>
                                <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="6" fill="#fff" stroke="var(--accent)" stroke-width="2.5"/>
                                <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="2" fill="var(--accent)"/>
                            </g>
                        @endforeach

                        {{-- x-axis day labels --}}
                        @foreach($trend as $i => $d)
                            @php
                                $x = $gap + ($n > 1 ? ($i / ($n - 1)) * $usableW : $usableW / 2);
                            @endphp
                            <text x="{{ $x }}" y="{{ $h - 8 }}" font-size="13" text-anchor="middle" fill="#78716c" font-weight="700">{{ $d['label'] }}</text>
                        @endforeach
                    </svg>

                    {{-- Hover markers (HTML, since SVG text is fixed-size) --}}
                    <div class="sparkline-values">
                        @foreach($trend as $i => $d)
                            <div class="spark-point" style="--x: {{ ($i / max(count($values)-1,1)) * 100 }}%;">
                                <span class="spark-tooltip">{{ \App\Helpers\Money::format($d['value']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-4">
        <x-admin.data-panel title="أوقات الذروة" icon="bi-clock-fill">
            @php
                $maxHourly = max($hourly->pluck('count')->toArray()) ?: 1;
            @endphp
            <div class="p-3">
                <p class="text-muted small mb-3">عدد الطلبات حسب الساعة اليوم</p>
                <div class="heatmap-grid">
                    @foreach($hourly as $h)
                        @php
                            $intensity = $h['count'] === 0 ? 0 : min(1, $h['count'] / $maxHourly);
                        @endphp
                        <div class="heatmap-cell"
                            style="--i: {{ $intensity }};"
                            title="الساعة {{ str_pad($h['hour'],2,'0',STR_PAD_LEFT) }}:00 — {{ $h['count'] }} طلب">
                            <span class="heatmap-hour">{{ str_pad($h['hour'],2,'0',STR_PAD_LEFT) }}</span>
                            @if($h['count'] > 0)
                                <span class="heatmap-count">{{ $h['count'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="heatmap-legend">
                    <span class="text-muted small">أقل</span>
                    <div class="heatmap-scale">
                        @foreach([0, 0.25, 0.5, 0.75, 1] as $v)
                            <span class="heatmap-swatch" style="--i: {{ $v }};"></span>
                        @endforeach
                    </div>
                    <span class="text-muted small">أكثر</span>
                </div>
            </div>
        </x-admin.data-panel>
    </div>
</div>

{{-- Recent orders + top items --}}
<div class="row g-3">
    <div class="col-xl-8">
        <x-admin.data-panel title="آخر الطلبات" :count="$recentOrders->count()" icon="bi-receipt-cutoff">
            <x-slot:actions>
                <a href="{{ route('admin.orders.index') }}" class="btn btn-light btn-sm">
                    عرض الكل <i class="bi bi-arrow-left"></i>
                </a>
            </x-slot:actions>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الرقم</th>
                            <th>الطاولة</th>
                            <th>الأصناف</th>
                            <th>الإجمالي</th>
                            <th>الحالة</th>
                            <th>الوقت</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentOrders as $order)
                            <tr>
                                <td class="fw-bold">{{ $order->number }}</td>
                                <td>
                                    @if($order->table)
                                        <span class="badge" style="background: rgba(var(--accent-rgb),.15); color: var(--accent); font-weight: 700;">{{ $order->table->number }}</span>
                                    @else — @endif
                                </td>
                                <td>{{ $order->items->count() }}</td>
                                <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($order->total) }}</td>
                                <td><span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span></td>
                                <td class="text-muted small">{{ $order->created_at->diffForHumans() }}</td>
                                <td>
                                    <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-sm btn-light">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7">
                                <x-admin.empty-state icon="bi-receipt" message="لا توجد طلبات بعد" compact />
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-4">
        <x-admin.data-panel title="الأكثر مبيعاً" icon="bi-trophy-fill">
            <x-slot:actions>
                <span class="text-muted small">آخر 7 أيام</span>
            </x-slot:actions>

            <div class="p-0">
                @forelse($topItems as $idx => $item)
                    <div class="top-item-row">
                        <div class="d-flex align-items-center gap-3 flex-grow-1">
                            <span class="top-item-rank {{ $idx === 0 ? 'first' : '' }}">{{ $idx + 1 }}</span>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-bold text-truncate">{{ $item->name_snapshot }}</div>
                                <div class="text-muted small"><i class="bi bi-bag"></i> {{ (int) $item->qty }} قطعة</div>
                            </div>
                        </div>
                        <div class="fw-bold small" style="color: var(--primary);">
                            {{ \App\Helpers\Money::format($item->total) }}
                        </div>
                    </div>
                @empty
                    <x-admin.empty-state icon="bi-bar-chart" message="لا توجد بيانات كافية بعد" compact />
                @endforelse
            </div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
