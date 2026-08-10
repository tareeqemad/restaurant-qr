@extends('layouts.admin')

@section('title', 'لوحة التحكم')

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/dashtic/css/dashboard-simple.css') }}?v={{ filemtime(public_path('assets/dashtic/css/dashboard-simple.css')) }}">
@endpush

@section('content')
@php
    $viewer = auth()->user();
    $viewerName = $viewer?->name ?: ($viewer?->username ?: 'أهلاً بك');

    $quick = collect($quickActions);
    $primaryQuick = $quick->take(4);
    $moreQuick = $quick->slice(4);

    $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
    $priorities = $actionCenter
        ->sortBy(fn ($item) => [
            $severityOrder[$item['severity']] ?? 3,
            -1 * (int) $item['count'],
        ])
        ->values();
    $primaryPriorities = $priorities->take(4);
    $morePriorities = $priorities->slice(4);

    $summaryStats = [];
    if($can['financials']) {
        $summaryStats[] = [
            'label' => 'مبيعات اليوم',
            'value' => \App\Helpers\Money::format($financialPulse['gross_sales']),
            'icon' => 'bi-cash-stack',
            'tone' => 'green',
            'href' => route('admin.reports.end-of-day'),
        ];
    } else {
        $summaryStats[] = [
            'label' => 'طلبات اليوم',
            'value' => $stats['today_orders'],
            'icon' => 'bi-receipt',
            'tone' => 'green',
            'href' => route('admin.orders.index'),
        ];
    }
    $summaryStats[] = [
        'label' => 'طلبات قيد العمل',
        'value' => $stats['active_orders'],
        'icon' => 'bi-lightning-charge-fill',
        'tone' => 'amber',
        'href' => route('admin.orders.index'),
    ];
    $summaryStats[] = [
        'label' => 'طلبات جاهزة',
        'value' => $dailyOps['ready_orders'],
        'icon' => 'bi-check2-circle',
        'tone' => $dailyOps['ready_orders'] > 0 ? 'blue' : 'muted',
        'href' => route('admin.orders.index', ['status' => 'ready']),
    ];
    $summaryStats[] = [
        'label' => 'الطاولات المشغولة',
        'value' => $stats['occupied_tables'].' / '.$stats['total_tables'],
        'icon' => 'bi-grid-3x3-gap-fill',
        'tone' => 'primary',
        'href' => route('admin.tables.index'),
    ];

    $hasManagementSummary = $can['procurement']
        || $can['customers']
        || $branchSnapshot->isNotEmpty();
@endphp

<main class="simple-dashboard">
    <header class="simple-dashboard__welcome">
        <div>
            <span class="simple-dashboard__eyebrow">ملخص اليوم</span>
            <h1>أهلاً، {{ $viewerName }}</h1>
            <p>ابدأ من المطلوب الآن، ثم افتح الشاشة التي تحتاجها فقط.</p>
        </div>
        <div class="simple-dashboard__health {{ $priorities->isNotEmpty() ? 'is-attention' : 'is-clear' }}">
            <i class="bi {{ $priorities->isNotEmpty() ? 'bi-exclamation-triangle-fill' : 'bi-check2-circle' }}"></i>
            <span>
                @if($priorities->isNotEmpty())
                    <strong>{{ $priorities->count() }}</strong>
                    أمور تحتاج متابعة
                @else
                    كل شيء تحت السيطرة
                @endif
            </span>
        </div>
    </header>

    @if($primaryPriorities->isNotEmpty())
        <section class="simple-dashboard__section" id="dashboard-actions">
            <div class="simple-dashboard__section-head">
                <div>
                    <span>الأولوية الأولى</span>
                    <h2>يحتاج انتباهك</h2>
                </div>
                <span class="simple-dashboard__count">{{ $priorities->count() }}</span>
            </div>

            <div class="simple-dashboard__attention-grid">
                @foreach($primaryPriorities as $item)
                    <a href="{{ $item['route'] }}"
                       class="simple-attention simple-attention--{{ $item['severity'] }}">
                        <span class="simple-attention__icon"><i class="bi {{ $item['icon'] }}"></i></span>
                        <span class="simple-attention__body">
                            <strong>{{ $item['title'] }}</strong>
                            <small>{{ $item['description'] }}</small>
                        </span>
                        <span class="simple-attention__number">{{ $item['count'] }}</span>
                        <i class="bi bi-arrow-left-short simple-attention__arrow"></i>
                    </a>
                @endforeach
            </div>

            @if($morePriorities->isNotEmpty())
                <details class="simple-dashboard__more">
                    <summary>
                        <i class="bi bi-chevron-down"></i>
                        {{ $morePriorities->count() }} تنبيهات أخرى
                    </summary>
                    <div class="simple-dashboard__attention-grid">
                        @foreach($morePriorities as $item)
                            <a href="{{ $item['route'] }}"
                               class="simple-attention simple-attention--{{ $item['severity'] }}">
                                <span class="simple-attention__icon"><i class="bi {{ $item['icon'] }}"></i></span>
                                <span class="simple-attention__body">
                                    <strong>{{ $item['title'] }}</strong>
                                    <small>{{ $item['description'] }}</small>
                                </span>
                                <span class="simple-attention__number">{{ $item['count'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>
    @endif

    <section class="simple-dashboard__section">
        <div class="simple-dashboard__section-head">
            <div>
                <span>وصول سريع</span>
                <h2>ابدأ من هنا</h2>
            </div>
        </div>

        <div class="simple-dashboard__quick-grid">
            @foreach($primaryQuick as $action)
                <a href="{{ $action['route'] }}" class="simple-quick simple-quick--{{ $action['color'] }}">
                    <span class="simple-quick__icon"><i class="bi {{ $action['icon'] }}"></i></span>
                    <span>
                        <strong>{{ $action['label'] }}</strong>
                        <small>{{ $action['hint'] }}</small>
                    </span>
                    <i class="bi bi-arrow-left-short"></i>
                </a>
            @endforeach
        </div>

        @if($moreQuick->isNotEmpty())
            <details class="simple-dashboard__more">
                <summary><i class="bi bi-grid"></i> كل الاختصارات</summary>
                <div class="simple-dashboard__quick-grid">
                    @foreach($moreQuick as $action)
                        <a href="{{ $action['route'] }}" class="simple-quick simple-quick--{{ $action['color'] }}">
                            <span class="simple-quick__icon"><i class="bi {{ $action['icon'] }}"></i></span>
                            <span>
                                <strong>{{ $action['label'] }}</strong>
                                <small>{{ $action['hint'] }}</small>
                            </span>
                            <i class="bi bi-arrow-left-short"></i>
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
    </section>

    <section class="simple-dashboard__stats" aria-label="ملخص الأرقام">
        @foreach($summaryStats as $stat)
            <a href="{{ $stat['href'] }}" class="simple-stat simple-stat--{{ $stat['tone'] }}">
                <span class="simple-stat__icon"><i class="bi {{ $stat['icon'] }}"></i></span>
                <span>
                    <small>{{ $stat['label'] }}</small>
                    <strong>{{ $stat['value'] }}</strong>
                </span>
            </a>
        @endforeach
    </section>

    <div class="simple-dashboard__main-grid {{ $can['financials'] ? '' : 'is-single' }}">
        <section class="simple-panel">
            <div class="simple-panel__head">
                <div>
                    <span>مباشر</span>
                    <h2>التشغيل الآن</h2>
                </div>
                @if($viewer?->hasPermission('tables.viewAny'))
                    <a href="{{ route('admin.tables.index') }}">فتح الطاولات <i class="bi bi-arrow-left"></i></a>
                @endif
            </div>

            <div class="simple-ops">
                @if($viewer?->hasPermission('orders.viewAny'))
                    <a href="{{ route('admin.orders.index', ['status' => 'ready']) }}">
                        <span><i class="bi bi-check2-circle"></i> جاهزة للتسليم</span>
                        <strong class="{{ $dailyOps['ready_orders'] > 0 ? 'is-good' : '' }}">{{ $dailyOps['ready_orders'] }}</strong>
                    </a>
                    <a href="{{ route('admin.orders.index') }}">
                        <span><i class="bi bi-stopwatch-fill"></i> طلبات متأخرة</span>
                        <strong class="{{ $dailyOps['delayed_orders'] > 0 ? 'is-danger' : '' }}">{{ $dailyOps['delayed_orders'] }}</strong>
                    </a>
                    <a href="{{ route('admin.orders.index', ['status' => 'pending']) }}">
                        <span><i class="bi bi-hourglass-split"></i> بانتظار الاعتماد</span>
                        <strong class="{{ $stats['pending_orders'] > 0 ? 'is-warning' : '' }}">{{ $stats['pending_orders'] }}</strong>
                    </a>
                @endif
                @if($viewer?->hasPermission('tables.viewAny'))
                    <a href="{{ route('admin.tables.index') }}">
                        <span><i class="bi bi-grid-3x3-gap-fill"></i> إشغال الصالة</span>
                        <strong>{{ $dailyOps['table_utilization'] }}%</strong>
                    </a>
                @endif
            </div>
        </section>

        @if($can['financials'])
            <section class="simple-panel">
                <div class="simple-panel__head">
                    <div>
                        <span>اليوم فقط</span>
                        <h2>ملخص المال</h2>
                    </div>
                    <a href="{{ route('admin.reports.end-of-day') }}">نهاية اليوم <i class="bi bi-arrow-left"></i></a>
                </div>

                <div class="simple-money">
                    <div>
                        <small>المبيعات</small>
                        <strong>{{ \App\Helpers\Money::format($financialPulse['gross_sales']) }}</strong>
                    </div>
                    <div>
                        <small>صافي التشغيل</small>
                        <strong class="{{ $financialPulse['net_operating'] < 0 ? 'is-danger' : 'is-good' }}">
                            {{ \App\Helpers\Money::format($financialPulse['net_operating']) }}
                        </strong>
                    </div>
                    <div>
                        <small>ذمم مفتوحة</small>
                        <strong>{{ \App\Helpers\Money::format($financialPulse['open_balance']) }}</strong>
                    </div>
                </div>
            </section>
        @endif
    </div>

    @if($hasManagementSummary)
        <details class="simple-dashboard__management">
            <summary>
                <span>
                    <i class="bi bi-briefcase-fill"></i>
                    <strong>ملخصات الإدارة</strong>
                    <small>المخزون والعملاء والفروع—افتحها عند الحاجة فقط</small>
                </span>
                <i class="bi bi-chevron-down"></i>
            </summary>

            <div class="simple-dashboard__management-grid">
                @if($can['procurement'])
                    <a href="{{ route('admin.inventory.dashboard') }}" class="simple-summary-card">
                        <span class="simple-summary-card__head"><i class="bi bi-box-seam"></i> المخزون والمشتريات</span>
                        <span><small>نافد</small><strong class="is-danger">{{ $inventoryProcurement['out_stock'] }}</strong></span>
                        <span><small>منخفض</small><strong>{{ $inventoryProcurement['low_stock'] }}</strong></span>
                        <span><small>ينتهي قريباً</small><strong>{{ $inventoryProcurement['expiring_batches'] }}</strong></span>
                    </a>
                @endif

                @if($can['customers'])
                    <a href="{{ route('admin.reservations.index') }}" class="simple-summary-card">
                        <span class="simple-summary-card__head"><i class="bi bi-person-heart"></i> العملاء والحجوزات</span>
                        <span><small>حجوزات اليوم</small><strong>{{ $customerPulse['reservations_today'] }}</strong></span>
                        <span><small>بانتظار التأكيد</small><strong>{{ $customerPulse['reservations_pending'] }}</strong></span>
                        <span><small>تقييمات منخفضة</small><strong>{{ $customerPulse['low_reviews'] }}</strong></span>
                    </a>
                @endif

                @if($branchSnapshot->isNotEmpty())
                    <div class="simple-summary-card simple-summary-card--branches">
                        <span class="simple-summary-card__head"><i class="bi bi-diagram-3-fill"></i> الفروع اليوم</span>
                        @foreach($branchSnapshot->take(4) as $row)
                            <span>
                                <small>{{ $row['branch']->name }}</small>
                                <strong>{{ \App\Helpers\Money::format($row['sales']) }}</strong>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @endif
</main>
@endsection
