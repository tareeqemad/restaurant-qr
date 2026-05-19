@extends('layouts.admin')

@section('title', 'لوحة التحكم')

@section('content')
<x-admin.breadcrumb title="لوحة التحكم" icon="bi-speedometer2"
    subtitle="مركز قيادة اليوم: المال، التشغيل، المخزون، العملاء، وما يحتاج قراراً الآن"
    :home="false" />

@php
    // Stat-rail tiles follow the viewer's permissions: the money tiles
    // (net / sales) only render for users with financial access, and the
    // procurement tile only for users who can act on purchasing.
    $railStats = [];
    if ($can['financials']) {
        $railStats[] = [
            'label' => 'صافي اليوم',
            'value' => \App\Helpers\Money::format($stats['net_operating']),
            'icon'  => $stats['net_operating'] >= 0 ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow',
            'color' => $stats['net_operating'] >= 0 ? 'success' : 'danger',
            'link'  => route('admin.reports.end-of-day'),
        ];
        $railStats[] = [
            'label' => 'مبيعات اليوم',
            'value' => \App\Helpers\Money::format($stats['today_sales']),
            'icon'  => 'bi-cash-stack',
            'color' => 'primary',
            'link'  => route('admin.reports.sales'),
        ];
    }
    $railStats[] = [
        'label' => 'إجراءات مفتوحة',
        'value' => $stats['action_count'],
        'icon'  => $stats['critical_actions'] > 0 ? 'bi-exclamation-octagon-fill' : 'bi-list-check',
        'color' => $stats['critical_actions'] > 0 ? 'danger' : 'accent',
    ];
    $railStats[] = [
        'label' => 'طلبات نشطة',
        'value' => $stats['active_orders'],
        'icon'  => 'bi-lightning-charge-fill',
        'color' => 'warning',
        'link'  => route('admin.orders.index'),
    ];
    $railStats[] = [
        'label' => 'طاولات مشغولة',
        'value' => $stats['occupied_tables'].' / '.$stats['total_tables'],
        'icon'  => 'bi-grid-3x3-gap-fill',
        'color' => 'success',
        'link'  => route('admin.tables.index'),
    ];
    if ($can['procurement']) {
        $railStats[] = [
            'label' => 'مخزون يحتاج شراء',
            'value' => $stats['low_stock'],
            'icon'  => $stats['low_stock'] > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill',
            'color' => $stats['low_stock'] > 0 ? 'danger' : 'success',
            'link'  => route('admin.reports.reorder-suggestions'),
        ];
    }
@endphp
<x-admin.stat-rail :stats="$railStats" />

<x-admin.data-panel title="اختصارات تنفيذية" icon="bi-command">
    <div class="dashboard-quick-grid">
        @foreach($quickActions as $action)
            <a href="{{ $action['route'] }}" class="dashboard-quick dashboard-quick--{{ $action['color'] }}">
                <span class="dashboard-quick-icon"><i class="bi {{ $action['icon'] }}"></i></span>
                <span class="dashboard-quick-body">
                    <span class="dashboard-quick-label">{{ $action['label'] }}</span>
                    <span class="dashboard-quick-hint">{{ $action['hint'] }}</span>
                </span>
                <i class="bi bi-arrow-left-short dashboard-quick-arrow"></i>
            </a>
        @endforeach
    </div>
</x-admin.data-panel>

<div class="row g-3 mb-3">
    @if($can['financials'])
    <div class="col-xl-8">
        <x-admin.data-panel title="نبض المال" icon="bi-wallet2">
            <x-slot:actions>
                <a href="{{ route('admin.reports.profit-loss') }}" class="btn btn-light btn-sm">
                    <i class="bi bi-graph-up-arrow"></i> تفاصيل الصندوق
                </a>
            </x-slot:actions>

            <div class="dashboard-metric-grid dashboard-metric-grid--finance">
                <div class="dashboard-metric dashboard-metric--success">
                    <span>المبيعات</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['gross_sales']) }}</strong>
                </div>
                <div class="dashboard-metric dashboard-metric--primary">
                    <span>المدفوعات المقبوضة</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['payments']) }}</strong>
                </div>
                <div class="dashboard-metric dashboard-metric--danger">
                    <span>الاستردادات</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['refunds']) }}</strong>
                </div>
                <div class="dashboard-metric dashboard-metric--warning">
                    <span>المصروفات المعتمدة</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['expenses']) }}</strong>
                </div>
                <div class="dashboard-metric dashboard-metric--accent">
                    <span>متوسط الفاتورة</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['average_invoice']) }}</strong>
                </div>
                <div class="dashboard-metric dashboard-metric--muted">
                    <span>ذمم مفتوحة</span>
                    <strong>{{ \App\Helpers\Money::format($financialPulse['open_balance']) }}</strong>
                </div>
            </div>

            <div class="dashboard-split mt-3">
                <div>
                    <div class="dashboard-section-label">صافي التشغيل بعد الاستردادات والمصروفات</div>
                    <div class="dashboard-big-number {{ $financialPulse['net_operating'] < 0 ? 'text-danger' : 'text-success' }}">
                        {{ \App\Helpers\Money::format($financialPulse['net_operating']) }}
                    </div>
                </div>
                <div>
                    <div class="dashboard-section-label">طرق الدفع اليوم</div>
                    @if($financialPulse['payment_methods']->isEmpty())
                        <div class="text-muted small">لا توجد مدفوعات مسجلة اليوم.</div>
                    @else
                        <div class="dashboard-payment-list">
                            @foreach($financialPulse['payment_methods'] as $method)
                                <div class="dashboard-payment-row">
                                    <span>{{ $method['label'] }}</span>
                                    <strong>{{ \App\Helpers\Money::format($method['total']) }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </x-admin.data-panel>
    </div>
    @endif

    <div class="{{ $can['financials'] ? 'col-xl-4' : 'col-xl-12' }}">
        <x-admin.data-panel title="مركز الإجراءات" :count="$actionCenter->count()" icon="bi-exclamation-diamond-fill">
            @if($actionCenter->isEmpty())
                <x-admin.empty-state icon="bi-check2-circle" message="كل شيء هادئ حالياً" compact />
            @else
                <div class="dashboard-action-list">
                    @foreach($actionCenter as $item)
                        @php
                            $severityClass = [
                                'critical' => 'danger',
                                'warning' => 'warning',
                                'info' => 'info',
                            ][$item['severity']] ?? 'secondary';
                        @endphp
                        <a href="{{ $item['route'] }}" class="dashboard-action dashboard-action--{{ $item['severity'] }}">
                            <span class="dashboard-action-icon"><i class="bi {{ $item['icon'] }}"></i></span>
                            <span class="dashboard-action-body">
                                <span class="dashboard-action-title">
                                    {{ $item['title'] }}
                                    <span class="badge bg-{{ $severityClass }}">{{ $item['count'] }}</span>
                                </span>
                                <span class="dashboard-action-desc">{{ $item['description'] }}</span>
                            </span>
                            <i class="bi bi-arrow-left dashboard-action-arrow"></i>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

{{-- Operational alerts from the shared alert service. --}}
@if(!empty($alerts))
<x-admin.data-panel title="تنبيهات العمليات" :count="count($alerts)" icon="bi-bell-fill">
    <x-slot:actions>
        <span class="badge" style="background: rgba(var(--accent-rgb),.15); color: var(--accent-dark);">
            يحتاج انتباهك
        </span>
    </x-slot:actions>

    <div class="alerts-grid alerts-grid--dashboard">
        @foreach($alerts as $alert)
            <a href="{{ $alert['route'] }}" class="alert-card alert-card--{{ $alert['severity'] }}">
                <div class="alert-card-icon">
                    <i class="bi {{ $alert['icon'] }}"></i>
                </div>
                <div class="alert-card-body">
                    <div class="alert-card-title">
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

@php
    // Row layout adapts to which side panels the viewer is allowed to see —
    // so a waiter (no procurement / no customers) gets a full-width
    // "التشغيل اليومي" instead of one narrow column with dead space.
    $dashSideCount = ($can['procurement'] ? 1 : 0) + ($can['customers'] ? 1 : 0);
    $dashDailyOpsCol = $dashSideCount === 2 ? 'col-xl-4' : ($dashSideCount === 1 ? 'col-xl-6' : 'col-xl-12');
    $dashSideCol = $dashSideCount === 2 ? 'col-xl-4' : 'col-xl-6';
@endphp
<div class="row g-3 mb-3">
    <div class="{{ $dashDailyOpsCol }}">
        <x-admin.data-panel title="التشغيل اليومي" icon="bi-activity">
            <div class="dashboard-mini-grid">
                <a href="{{ route('admin.shifts.index') }}" class="dashboard-mini">
                    <span>ورديات مفتوحة</span>
                    <strong>{{ $dailyOps['open_shifts'] }}</strong>
                </a>
                <a href="{{ route('admin.attendance.index') }}" class="dashboard-mini">
                    <span>موظفون على الدوام</span>
                    <strong>{{ $dailyOps['open_attendance'] }}</strong>
                </a>
                <a href="{{ route('admin.orders.index') }}" class="dashboard-mini">
                    <span>طلبات جاهزة</span>
                    <strong>{{ $dailyOps['ready_orders'] }}</strong>
                </a>
                <a href="{{ route('admin.orders.index') }}" class="dashboard-mini dashboard-mini--danger">
                    <span>طلبات متأخرة</span>
                    <strong>{{ $dailyOps['delayed_orders'] }}</strong>
                </a>
                <a href="{{ route('admin.tables.index') }}" class="dashboard-mini">
                    <span>إشغال الطاولات</span>
                    <strong>{{ $dailyOps['table_utilization'] }}%</strong>
                </a>
                @if($can['expenses'])
                <a href="{{ route('admin.expenses.index', ['status' => 'pending_approval']) }}" class="dashboard-mini">
                    <span>مصروفات معلقة</span>
                    <strong>{{ $dailyOps['pending_expenses'] }}</strong>
                </a>
                @endif
            </div>

            <div class="dashboard-section-label mt-3">حالات طلبات اليوم</div>
            <div class="dashboard-status-list">
                @forelse($ordersByStatus as $status => $count)
                    @php $statusEnum = \App\Enums\OrderStatus::tryFrom($status); @endphp
                    <div class="dashboard-status-row">
                        <span>{{ $statusEnum?->label() ?? $status }}</span>
                        <strong>{{ $count }}</strong>
                    </div>
                @empty
                    <div class="text-muted small">لا توجد طلبات اليوم.</div>
                @endforelse
            </div>
        </x-admin.data-panel>
    </div>

    @if($can['procurement'])
    <div class="{{ $dashSideCol }}">
        <x-admin.data-panel title="المخزون والمشتريات" icon="bi-box-seam">
            <x-slot:actions>
                <a href="{{ route('admin.inventory.dashboard') }}" class="btn btn-light btn-sm">
                    <i class="bi bi-speedometer2"></i> لوحة المخزون
                </a>
            </x-slot:actions>

            <div class="dashboard-mini-grid">
                <a href="{{ route('admin.ingredients.index') }}" class="dashboard-mini dashboard-mini--danger">
                    <span>نافد</span>
                    <strong>{{ $inventoryProcurement['out_stock'] }}</strong>
                </a>
                <a href="{{ route('admin.ingredients.index', ['low_stock' => 1]) }}" class="dashboard-mini">
                    <span>منخفض</span>
                    <strong>{{ $inventoryProcurement['low_stock'] }}</strong>
                </a>
                <a href="{{ route('admin.batches.index', ['expiring' => 1]) }}" class="dashboard-mini">
                    <span>ينتهي قريباً</span>
                    <strong>{{ $inventoryProcurement['expiring_batches'] }}</strong>
                </a>
                <a href="{{ route('admin.purchase-orders.index') }}" class="dashboard-mini">
                    <span>أوامر مفتوحة</span>
                    <strong>{{ $inventoryProcurement['open_pos'] }}</strong>
                </a>
                <a href="{{ route('admin.supplier-invoices.index') }}" class="dashboard-mini dashboard-mini--warning">
                    <span>فواتير للمتابعة</span>
                    <strong>{{ $inventoryProcurement['supplier_invoice_queue'] }}</strong>
                </a>
                <a href="{{ route('admin.inventory.dashboard') }}" class="dashboard-mini">
                    <span>استلامات 7 أيام</span>
                    <strong>{{ $inventoryProcurement['receipts_7d'] }}</strong>
                </a>
            </div>

            <div class="dashboard-money-row mt-3">
                <span>ذمم موردين</span>
                <strong>{{ \App\Helpers\Money::format($inventoryProcurement['ap_due']) }}</strong>
            </div>
            <div class="dashboard-money-row dashboard-money-row--danger">
                <span>متأخر منها</span>
                <strong>{{ \App\Helpers\Money::format($inventoryProcurement['ap_overdue']) }}</strong>
            </div>
            <div class="dashboard-money-row dashboard-money-row--warning">
                <span>هدر آخر 7 أيام</span>
                <strong>{{ \App\Helpers\Money::format($inventoryProcurement['waste_7d']) }}</strong>
            </div>
        </x-admin.data-panel>
    </div>
    @endif

    @if($can['customers'])
    <div class="{{ $dashSideCol }}">
        <x-admin.data-panel title="العملاء والحجوزات" icon="bi-person-heart">
            <div class="dashboard-mini-grid">
                <a href="{{ route('admin.customers.index') }}" class="dashboard-mini">
                    <span>عملاء جدد اليوم</span>
                    <strong>{{ $customerPulse['new_today'] }}</strong>
                </a>
                <a href="{{ route('admin.customers.index') }}" class="dashboard-mini">
                    <span>جدد آخر 7 أيام</span>
                    <strong>{{ $customerPulse['new_7d'] }}</strong>
                </a>
                <a href="{{ route('admin.reservations.index') }}" class="dashboard-mini">
                    <span>حجوزات اليوم</span>
                    <strong>{{ $customerPulse['reservations_today'] }}</strong>
                </a>
                <a href="{{ route('admin.reservations.index', ['status' => 'pending']) }}" class="dashboard-mini dashboard-mini--warning">
                    <span>حجوزات معلقة</span>
                    <strong>{{ $customerPulse['reservations_pending'] }}</strong>
                </a>
                <a href="{{ route('admin.reviews.index') }}" class="dashboard-mini">
                    <span>متوسط التقييم</span>
                    <strong>{{ $customerPulse['average_rating'] ?: '—' }}</strong>
                </a>
                <a href="{{ route('admin.reviews.index', ['rating' => 2]) }}" class="dashboard-mini dashboard-mini--danger">
                    <span>تقييمات منخفضة</span>
                    <strong>{{ $customerPulse['low_reviews'] }}</strong>
                </a>
            </div>

            <div class="dashboard-section-label mt-3">آخر التقييمات</div>
            <div class="dashboard-review-list">
                @forelse($recentReviews as $review)
                    <a href="{{ route('admin.reviews.index', ['q' => $review->customer?->name]) }}" class="dashboard-review-row">
                        <span class="dashboard-rating">
                            @for($i = 1; $i <= 5; $i++)
                                <i class="bi {{ $i <= (int) $review->rating ? 'bi-star-fill' : 'bi-star' }}"></i>
                            @endfor
                        </span>
                        <span class="text-truncate">{{ $review->customer?->name ?? 'ضيف' }}</span>
                    </a>
                @empty
                    <div class="text-muted small">لا توجد تقييمات بعد.</div>
                @endforelse
            </div>
        </x-admin.data-panel>
    </div>
    @endif
</div>

{{-- Customer debt widget — surfaces only when there's actually open debt.
     Top-5 debtors by amount + summary row. Click-through goes to the
     ledger. Hidden when the restaurant has zero outstanding so the
     dashboard stays tidy on healthy days. --}}
@if(($customerDebtStats?->total_debt ?? 0) > 0.001)
    <x-admin.data-panel title="ديون الزبائن المفتوحة" icon="bi-wallet2"
                        :count="(int) ($customerDebtStats->customers_owing ?? 0)">
        <x-slot:actions>
            <a href="{{ route('admin.customers.debts.index') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-journal-text"></i> فتح دفتر الديون
            </a>
        </x-slot:actions>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="dashboard-mini dashboard-mini--danger">
                    <span>إجمالي الدين</span>
                    <strong>{{ \App\Helpers\Money::format((float) ($customerDebtStats->total_debt ?? 0)) }}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dashboard-mini">
                    <span>زبائن مديونون</span>
                    <strong>{{ (int) ($customerDebtStats->customers_owing ?? 0) }}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dashboard-mini">
                    <span>فواتير مفتوحة</span>
                    <strong>{{ (int) ($customerDebtStats->open_invoices ?? 0) }}</strong>
                </div>
            </div>
        </div>

        @if($topDebtors->isNotEmpty())
            <div class="dashboard-section-label">الأعلى ديناً</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الزبون</th>
                            <th class="text-end">الدين</th>
                            <th class="text-center">عدد الفواتير</th>
                            <th class="text-end">الحد</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topDebtors as $row)
                            @php
                                $limit = $row->customer->credit_limit !== null ? (float) $row->customer->credit_limit : null;
                                $over = $limit !== null && (float) $row->debt > $limit + 0.01;
                            @endphp
                            <tr class="{{ $over ? 'table-danger' : '' }}">
                                <td>
                                    <strong>{{ $row->customer->name }}</strong>
                                    <small class="text-muted d-block" dir="ltr">{{ $row->customer->phone }}</small>
                                </td>
                                <td class="text-end fw-bold text-danger">{{ \App\Helpers\Money::format((float) $row->debt) }}</td>
                                <td class="text-center"><span class="badge bg-secondary">{{ $row->invoice_count }}</span></td>
                                <td class="text-end">
                                    @if($limit === null)
                                        <small class="text-muted">—</small>
                                    @else
                                        <span class="{{ $over ? 'text-danger fw-bold' : '' }}">{{ \App\Helpers\Money::format($limit) }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('admin.customers.debts.show', $row->customer) }}"
                                       class="btn btn-sm btn-outline-primary">تفاصيل</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.data-panel>
@endif

@if($branchSnapshot->isNotEmpty())
<x-admin.data-panel title="نظرة المالك على الفروع" icon="bi-buildings" :count="$branchSnapshot->count()">
    <x-slot:actions>
        <a href="{{ route('admin.reports.branch-comparison') }}" class="btn btn-light btn-sm">
            <i class="bi bi-arrows-collapse"></i> مقارنة الفروع
        </a>
    </x-slot:actions>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>الفرع</th>
                    <th class="text-end">مبيعات اليوم</th>
                    <th class="text-center">فواتير</th>
                    <th class="text-center">طلبات اليوم</th>
                    <th class="text-center">نشطة</th>
                    <th class="text-center">حجوزات</th>
                    <th class="text-end">ذمم متأخرة</th>
                </tr>
            </thead>
            <tbody>
                @foreach($branchSnapshot as $row)
                    <tr>
                        <td>
                            <div class="fw-bold">{{ $row['branch']->name }}</div>
                            <div class="text-muted small">{{ $row['branch']->city ?: '—' }}</div>
                        </td>
                        <td class="text-end fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($row['sales']) }}</td>
                        <td class="text-center">{{ $row['invoices'] }}</td>
                        <td class="text-center">{{ $row['orders'] }}</td>
                        <td class="text-center">
                            <span class="badge bg-primary-transparent">{{ $row['active_orders'] }}</span>
                        </td>
                        <td class="text-center">{{ $row['reservations'] }}</td>
                        <td class="text-end {{ $row['overdue_ap'] > 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                            {{ \App\Helpers\Money::format($row['overdue_ap']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endif

{{-- Sales trend sparkline + hour heatmap --}}
<div class="row g-3 mb-3">
    @if($can['financials'])
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
                <div class="d-flex align-items-end gap-4 mb-3 flex-wrap">
                    <div>
                        <div class="text-muted small fw-bold">المجموع</div>
                        <div style="font-size:1.7rem; font-weight:900; color:var(--primary); letter-spacing:0;">
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
                            $linePath = 'M '.implode(' L ', array_map(fn($p) => $p[0].' '.$p[1], $points));
                            $areaPath = $linePath.' L '.end($points)[0].' '.($h-30).' L '.$points[0][0].' '.($h-30).' Z';
                        @endphp

                        <defs>
                            <linearGradient id="sparkFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="rgb(var(--primary-rgb))" stop-opacity=".25"/>
                                <stop offset="100%" stop-color="rgb(var(--primary-rgb))" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="sparkLine" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%" stop-color="var(--accent)"/>
                                <stop offset="100%" stop-color="var(--primary)"/>
                            </linearGradient>
                        </defs>

                        @foreach([0.25, 0.5, 0.75] as $r)
                            <line x1="{{ $gap }}" y1="{{ $h - 30 - $r * ($h - 60) }}" x2="{{ $w - $gap }}" y2="{{ $h - 30 - $r * ($h - 60) }}" stroke="rgba(31,71,51,.08)" stroke-dasharray="3 4"/>
                        @endforeach

                        <path d="{{ $areaPath }}" fill="url(#sparkFill)"/>
                        <path d="{{ $linePath }}" fill="none" stroke="url(#sparkLine)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>

                        @foreach($points as $p)
                            <g>
                                <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="6" fill="#fff" stroke="var(--accent)" stroke-width="2.5"/>
                                <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="2" fill="var(--accent)"/>
                            </g>
                        @endforeach

                        @foreach($trend as $i => $d)
                            @php $x = $gap + ($n > 1 ? ($i / ($n - 1)) * $usableW : $usableW / 2); @endphp
                            <text x="{{ $x }}" y="{{ $h - 8 }}" font-size="13" text-anchor="middle" fill="#78716c" font-weight="700">{{ $d['label'] }}</text>
                        @endforeach
                    </svg>

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

    @endif

    <div class="{{ $can['financials'] ? 'col-xl-4' : 'col-xl-12' }}">
        <x-admin.data-panel title="أوقات الذروة" icon="bi-clock-fill">
            @php $maxHourly = max($hourly->pluck('count')->toArray()) ?: 1; @endphp
            <div class="p-3">
                <p class="text-muted small mb-3">عدد الطلبات حسب الساعة اليوم</p>
                <div class="heatmap-grid">
                    @foreach($hourly as $h)
                        @php $intensity = $h['count'] === 0 ? 0 : min(1, $h['count'] / $maxHourly); @endphp
                        <div class="heatmap-cell"
                            style="--i: {{ $intensity }};"
                            title="الساعة {{ str_pad($h['hour'],2,'0',STR_PAD_LEFT) }}:00 - {{ $h['count'] }} طلب">
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

<div class="row g-3">
    <div class="{{ $can['financials'] ? 'col-xl-7' : 'col-xl-12' }}">
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
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
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

    @if($can['financials'])
    <div class="col-xl-5">
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
    @endif
</div>

@if($can['procurement'] || $can['financials'])
<div class="row g-3 mt-0">
    @if($can['procurement'])
    <div class="{{ $can['financials'] ? 'col-xl-6' : 'col-xl-12' }}">
        <x-admin.data-panel title="آخر الاستلامات" icon="bi-clipboard2-check" :count="$recentReceipts->count()">
            <div class="dashboard-feed-list">
                @forelse($recentReceipts as $receipt)
                    <a href="{{ $receipt->purchaseOrder ? route('admin.purchase-orders.show', $receipt->purchaseOrder) : route('admin.inventory.dashboard') }}" class="dashboard-feed-row">
                        <span class="dashboard-feed-icon"><i class="bi bi-truck"></i></span>
                        <span class="dashboard-feed-body">
                            <strong>{{ $receipt->number }}</strong>
                            <small>{{ $receipt->supplier?->name ?? 'مورد غير محدد' }} - {{ $receipt->received_at?->diffForHumans() }}</small>
                        </span>
                    </a>
                @empty
                    <x-admin.empty-state icon="bi-clipboard2-check" message="لا توجد استلامات حديثة" compact />
                @endforelse
            </div>
        </x-admin.data-panel>
    </div>
    @endif

    @if($can['financials'])
    <div class="{{ $can['procurement'] ? 'col-xl-6' : 'col-xl-12' }}">
        <x-admin.data-panel title="روابط تحليل سريعة" icon="bi-compass-fill">
            <div class="dashboard-link-grid">
                <a href="{{ route('admin.reports.end-of-day') }}"><i class="bi bi-calendar-check-fill"></i> نهاية اليوم</a>
                <a href="{{ route('admin.reports.menu-engineering') }}"><i class="bi bi-diagram-3-fill"></i> هندسة المنيو</a>
                <a href="{{ route('admin.reports.stock-valuation') }}"><i class="bi bi-cash-stack"></i> تقييم المخزون</a>
                <a href="{{ route('admin.reports.sales-by-platform') }}"><i class="bi bi-bag-check-fill"></i> قنوات البيع</a>
                <a href="{{ route('admin.reports.reorder-suggestions') }}"><i class="bi bi-cart-plus-fill"></i> اقتراحات الشراء</a>
                <a href="{{ route('admin.reports.shifts') }}"><i class="bi bi-clock-history"></i> الورديات</a>
            </div>
        </x-admin.data-panel>
    </div>
    @endif
</div>
@endif
@endsection

@push('styles')
<style>
    .dashboard-quick-grid,
    .dashboard-metric-grid,
    .dashboard-mini-grid,
    .dashboard-link-grid {
        display: grid;
        gap: .75rem;
    }

    .dashboard-quick-grid {
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    }

    .dashboard-quick {
        display: flex;
        align-items: center;
        gap: .75rem;
        min-height: 72px;
        padding: .85rem .95rem;
        border: 1px solid rgba(var(--primary-rgb), .1);
        border-radius: 8px;
        background: #fff;
        color: #1f2937;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .dashboard-quick:hover {
        transform: translateY(-2px);
        border-color: rgba(var(--primary-rgb), .2);
        box-shadow: 0 10px 24px rgba(15, 45, 34, .08);
        color: var(--primary);
    }

    .dashboard-quick-icon,
    .dashboard-action-icon,
    .dashboard-feed-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 38px;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        background: rgba(var(--primary-rgb), .08);
        color: var(--primary);
        font-size: 1.05rem;
    }

    .dashboard-quick-body,
    .dashboard-action-body,
    .dashboard-feed-body {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: .15rem;
    }

    .dashboard-quick-label,
    .dashboard-action-title {
        font-weight: 900;
        color: #1f2937;
    }

    .dashboard-quick-hint,
    .dashboard-action-desc,
    .dashboard-feed-body small {
        color: #6b7280;
        font-size: .78rem;
        line-height: 1.35;
    }

    .dashboard-quick-arrow,
    .dashboard-action-arrow {
        margin-inline-start: auto;
        color: #94a3b8;
    }

    .dashboard-metric-grid--finance {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .dashboard-metric,
    .dashboard-mini {
        border: 1px solid rgba(15, 45, 34, .08);
        border-radius: 8px;
        background: linear-gradient(180deg, #fff, rgba(248, 250, 252, .72));
        padding: .85rem;
        min-height: 72px;
    }

    .dashboard-metric span,
    .dashboard-mini span,
    .dashboard-section-label {
        display: block;
        color: #6b7280;
        font-size: .78rem;
        font-weight: 800;
    }

    .dashboard-metric strong,
    .dashboard-mini strong {
        display: block;
        margin-top: .25rem;
        color: var(--primary);
        font-size: 1.05rem;
        font-weight: 900;
        letter-spacing: 0;
    }

    .dashboard-metric--success strong,
    .dashboard-mini--success strong { color: #16a34a; }
    .dashboard-metric--danger strong,
    .dashboard-mini--danger strong { color: #dc2626; }
    .dashboard-metric--warning strong,
    .dashboard-mini--warning strong { color: #b45309; }
    .dashboard-metric--accent strong { color: var(--accent-dark); }
    .dashboard-metric--muted strong { color: #475569; }

    .dashboard-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        align-items: start;
    }

    .dashboard-big-number {
        font-size: 1.55rem;
        font-weight: 900;
        letter-spacing: 0;
    }

    .dashboard-payment-list,
    .dashboard-status-list,
    .dashboard-review-list,
    .dashboard-feed-list,
    .dashboard-action-list {
        display: grid;
        gap: .55rem;
    }

    .dashboard-payment-row,
    .dashboard-status-row,
    .dashboard-money-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .55rem .65rem;
        border-radius: 8px;
        background: rgba(248, 250, 252, .9);
    }

    .dashboard-payment-row strong,
    .dashboard-status-row strong,
    .dashboard-money-row strong {
        color: var(--primary);
        font-weight: 900;
    }

    .dashboard-money-row--danger strong { color: #dc2626; }
    .dashboard-money-row--warning strong { color: #b45309; }

    .dashboard-action,
    .dashboard-review-row,
    .dashboard-feed-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .75rem;
        border: 1px solid rgba(15, 45, 34, .08);
        border-radius: 8px;
        background: #fff;
        color: #1f2937;
    }

    .dashboard-action:hover,
    .dashboard-review-row:hover,
    .dashboard-feed-row:hover {
        color: var(--primary);
        border-color: rgba(var(--primary-rgb), .2);
        background: rgba(var(--primary-rgb), .035);
    }

    .dashboard-action--critical .dashboard-action-icon {
        background: rgba(220, 38, 38, .1);
        color: #dc2626;
    }

    .dashboard-action--warning .dashboard-action-icon {
        background: rgba(180, 83, 9, .12);
        color: #b45309;
    }

    .dashboard-action--info .dashboard-action-icon {
        background: rgba(37, 99, 235, .1);
        color: #2563eb;
    }

    .dashboard-mini-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-mini {
        color: #1f2937;
    }

    .dashboard-mini:hover {
        color: var(--primary);
        border-color: rgba(var(--primary-rgb), .2);
    }

    .dashboard-rating {
        color: #d97706;
        white-space: nowrap;
        font-size: .86rem;
    }

    .dashboard-link-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-link-grid a {
        display: flex;
        align-items: center;
        gap: .55rem;
        min-height: 48px;
        padding: .7rem .8rem;
        border-radius: 8px;
        background: rgba(var(--primary-rgb), .045);
        color: var(--primary);
        font-weight: 800;
    }

    .dashboard-link-grid a:hover {
        background: rgba(var(--primary-rgb), .09);
    }

    @media (max-width: 991.98px) {
        .dashboard-metric-grid--finance,
        .dashboard-split,
        .dashboard-link-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 575.98px) {
        .dashboard-mini-grid,
        .dashboard-quick-grid {
            grid-template-columns: 1fr;
        }
    }
</style>
@endpush
