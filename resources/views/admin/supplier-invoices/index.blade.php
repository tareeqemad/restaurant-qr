@extends('layouts.admin')
@section('title', 'فواتير الموردين')

@php
    $scope = $filters['scope'] ?? 'all';
    $dateField = $filters['date_field'] ?? 'invoice_date';
    $hasFilters = filled($filters['search'] ?? null)
        || filled($filters['supplier_id'] ?? null)
        || filled($filters['from'] ?? null)
        || filled($filters['to'] ?? null)
        || $scope !== 'all'
        || $dateField !== 'invoice_date';

    $scopeOptions = [
        'all'            => ['label' => 'كل الفواتير', 'icon' => 'bi-layers', 'hint' => 'كل الحالات'],
        'open'           => ['label' => 'مفتوحة', 'icon' => 'bi-hourglass-split', 'hint' => 'غير مسددة بالكامل'],
        'overdue'        => ['label' => 'متأخرة', 'icon' => 'bi-alarm-fill', 'hint' => 'تجاوزت الاستحقاق'],
        'due_week'       => ['label' => 'مستحقة خلال 7 أيام', 'icon' => 'bi-calendar-event', 'hint' => 'قريبة الاستحقاق'],
        'this_month'     => ['label' => 'فواتير هذا الشهر', 'icon' => 'bi-calendar-month', 'hint' => 'حسب تاريخ الفاتورة'],
        'unpaid'         => ['label' => 'غير مدفوعة', 'icon' => 'bi-exclamation-circle', 'hint' => 'رصيد كامل'],
        'partially_paid' => ['label' => 'مدفوعة جزئياً', 'icon' => 'bi-pie-chart', 'hint' => 'لها دفعات'],
        'paid'           => ['label' => 'مدفوعة', 'icon' => 'bi-check-circle', 'hint' => 'مسددة بالكامل'],
        'cancelled'      => ['label' => 'ملغاة', 'icon' => 'bi-x-octagon', 'hint' => 'ملغاة'],
    ];

    $dateFieldOptions = [
        'invoice_date' => 'تاريخ الفاتورة',
        'due_date'     => 'تاريخ الاستحقاق',
        'created_at'   => 'تاريخ الإدخال',
    ];

@endphp

@section('content')
<x-admin.breadcrumb title="فواتير الموردين" icon="bi-file-earmark-text-fill"
    subtitle="تتبع المستحقات للموردين، تواريخ الاستحقاق، والرصيد المفتوح" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المستحقات (AP)', 'value' => \App\Helpers\Money::format($stats['total_ap']), 'icon' => 'bi-exclamation-circle-fill', 'color' => 'danger'],
    ['label' => 'فواتير متأخرة', 'value' => $stats['overdue'], 'icon' => 'bi-alarm-fill', 'color' => 'danger'],
    ['label' => 'مستحقة خلال 7 أيام', 'value' => $stats['due_this_week'], 'icon' => 'bi-calendar-event', 'color' => 'accent'],
    ['label' => 'مشتريات الشهر', 'value' => \App\Helpers\Money::format($stats['this_month']), 'icon' => 'bi-graph-up-arrow', 'color' => 'primary'],
]" />

<x-admin.data-panel title="سجل فواتير الموردين" :count="$invoices->total()" icon="bi-file-earmark-text-fill">
    <x-slot:actions>
        <a href="{{ route('admin.supplier-invoices.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> فاتورة جديدة
        </a>
        @if($hasFilters)
            <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="si-query" method="GET" action="{{ route('admin.supplier-invoices.index') }}">
            <div class="si-query-head">
                <div>
                    <span class="si-query-kicker"><i class="bi bi-funnel"></i> محددات الاستعلام</span>
                    <strong>{{ $scopeOptions[$scope]['label'] ?? 'كل الفواتير' }}</strong>
                </div>
                <span class="si-query-date">
                    {{ $dateFieldOptions[$dateField] ?? 'تاريخ الفاتورة' }}
                    @if(!empty($filters['from']) || !empty($filters['to']))
                        · {{ $filters['from'] ?? 'البداية' }} إلى {{ $filters['to'] ?? 'اليوم' }}
                    @endif
                </span>
            </div>

            <div class="si-filter-grid">
                <label class="si-field si-field--search">
                    <span>بحث</span>
                    <input name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                           placeholder="رقم الفاتورة أو رقم PO">
                </label>

                <label class="si-field si-field--supplier">
                    <span>المورد</span>
                    <select name="supplier_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مورد...">
                        <option value="">كل الموردين</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="si-field">
                    <span>الحالة / الاستحقاق</span>
                    <select name="scope" class="form-select">
                        @foreach($scopeOptions as $key => $option)
                            <option value="{{ $key }}" @selected($scope === $key)>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="si-field">
                    <span>احتساب الفترة حسب</span>
                    <select name="date_field" class="form-select">
                        @foreach($dateFieldOptions as $key => $label)
                            <option value="{{ $key }}" @selected($dateField === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="si-field">
                    <span>من تاريخ</span>
                    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                </label>

                <label class="si-field">
                    <span>إلى تاريخ</span>
                    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                </label>
            </div>

            <div class="si-query-actions">
                <button class="btn btn-primary px-5">
                    <i class="bi bi-search"></i> استعلام
                </button>
                @if($hasFilters)
                    <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light">
                        <i class="bi bi-arrow-counterclockwise"></i> إعادة ضبط
                    </a>
                @endif
            </div>
        </form>
    </x-slot:filters>

    <div class="si-filter-summary mb-3">
        <div>
            <span>نتائج الاستعلام</span>
            <strong>{{ number_format($filteredStats['count']) }}</strong>
        </div>
        <div>
            <span>إجمالي الفواتير</span>
            <strong>{{ \App\Helpers\Money::format($filteredStats['total']) }}</strong>
        </div>
        <div>
            <span>المدفوع</span>
            <strong class="text-success">{{ \App\Helpers\Money::format($filteredStats['paid']) }}</strong>
        </div>
        <div>
            <span>المتبقي</span>
            <strong class="{{ $filteredStats['balance'] > 0 ? 'text-danger' : 'text-success' }}">
                {{ \App\Helpers\Money::format($filteredStats['balance']) }}
            </strong>
        </div>
        <div>
            <span>متأخرة ضمن النتائج</span>
            <strong class="{{ $filteredStats['overdue'] > 0 ? 'text-danger' : 'text-success' }}">
                {{ number_format($filteredStats['overdue']) }}
            </strong>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle si-table">
            <thead class="bg-light">
                <tr>
                    <th>الفاتورة</th>
                    <th>المورد / أمر الشراء</th>
                    <th>المبالغ</th>
                    <th>التواريخ</th>
                    <th>الحالة</th>
                    <th class="text-end">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoices as $invoice)
                    @php $isOverdue = $invoice->isOverdue(); @endphp
                    <tr class="{{ $isOverdue ? 'si-row--overdue' : '' }}">
                        <td>
                            <a href="{{ route('admin.supplier-invoices.show', $invoice) }}" class="si-number">
                                {{ $invoice->number }}
                            </a>
                            @if($invoice->attachment_path)
                                <span class="badge bg-info-transparent ms-1" title="مرفق">
                                    <i class="bi bi-paperclip"></i>
                                </span>
                            @endif
                        </td>
                        <td>
                            <div class="fw-bold">{{ $invoice->supplier?->name ?? '—' }}</div>
                            @if($invoice->purchaseOrder)
                                <a href="{{ route('admin.purchase-orders.show', $invoice->purchaseOrder) }}" class="si-po">
                                    <i class="bi bi-truck"></i> {{ $invoice->purchaseOrder->number }}
                                </a>
                            @else
                                <small class="text-muted">بدون أمر شراء</small>
                            @endif
                        </td>
                        <td>
                            <div class="si-money-stack">
                                <span><small>الإجمالي</small><strong>{{ \App\Helpers\Money::format($invoice->total) }}</strong></span>
                                <span><small>مدفوع</small><strong class="text-success">{{ \App\Helpers\Money::format($invoice->paid_total) }}</strong></span>
                                <span><small>متبقي</small><strong class="{{ $invoice->balance > 0 ? 'text-danger' : 'text-success' }}">{{ \App\Helpers\Money::format($invoice->balance) }}</strong></span>
                            </div>
                        </td>
                        <td>
                            <div class="si-date-stack">
                                <span><i class="bi bi-receipt"></i> {{ $invoice->invoice_date?->format('Y-m-d') ?? '—' }}</span>
                                <span class="{{ $isOverdue ? 'text-danger fw-bold' : '' }}">
                                    <i class="bi bi-calendar-event"></i>
                                    {{ $invoice->due_date?->format('Y-m-d') ?? 'لا يوجد استحقاق' }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span>
                            @if($isOverdue)
                                <small class="d-block text-danger mt-1">
                                    <i class="bi bi-alarm"></i>
                                    متأخرة {{ $invoice->due_date->diffInDays(today()) }} يوم
                                </small>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.supplier-invoices.show', $invoice) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> تفاصيل
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-admin.empty-state
                                icon="bi-file-earmark-text"
                                title="لا توجد فواتير مطابقة"
                                message="عدّل محددات الاستعلام أو سجّل فاتورة مورد جديدة عند الاستلام.">
                                <x-slot:cta>
                                    @if($hasFilters)
                                        <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light">
                                            <i class="bi bi-x-circle"></i> مسح الفلاتر
                                        </a>
                                    @endif
                                    <a href="{{ route('admin.supplier-invoices.create') }}" class="btn btn-primary">
                                        <i class="bi bi-plus-lg"></i> فاتورة جديدة
                                    </a>
                                </x-slot:cta>
                            </x-admin.empty-state>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
</x-admin.data-panel>

@push('styles')
<style>
    .si-query {
        display: grid;
        gap: .85rem;
    }

    .si-query-head,
    .si-query-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .6rem;
    }

    .si-query-head {
        justify-content: space-between;
    }

    .si-query-head strong {
        display: block;
        color: var(--relax-heading, #10251d);
        font-size: .98rem;
    }

    .si-query-kicker,
    .si-query-date {
        color: #66746f;
        font-size: .78rem;
        font-weight: 800;
    }

    .si-filter-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: .75rem;
    }

    .si-field {
        display: grid;
        gap: .28rem;
        margin: 0;
    }

    .si-field > span {
        color: #53645d;
        font-size: .76rem;
        font-weight: 900;
    }

    .si-query .form-control,
    .si-query .form-select {
        min-height: 38px;
        padding-block: .42rem;
        font-size: .86rem;
    }

    .si-query .choices {
        margin-bottom: 0;
    }

    .si-query .choices__inner {
        min-height: 38px !important;
        padding-block: .22rem !important;
        font-size: .86rem !important;
    }

    .si-query .choices[data-type*="select-one"] .choices__inner {
        padding-bottom: .22rem !important;
    }

    .si-query .choices__list--single {
        padding-block: .16rem !important;
    }

    .si-query .choices__list--dropdown,
    .si-query .choices__list[aria-expanded] {
        border-radius: 8px !important;
    }

    .si-query .choices__list--dropdown .choices__input,
    .si-query .choices__list[aria-expanded] .choices__input {
        min-height: 34px;
        padding: .38rem .55rem;
        font-size: .84rem;
    }

    .si-query .choices__list--dropdown .choices__item,
    .si-query .choices__list[aria-expanded] .choices__item {
        padding: .45rem .65rem !important;
        font-size: .84rem !important;
        line-height: 1.35 !important;
    }

    .si-query-actions {
        justify-content: center;
    }

    .si-filter-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: .65rem;
    }

    .si-filter-summary > div {
        border: 1px solid var(--relax-line, #dde6df);
        border-radius: 8px;
        padding: .72rem .85rem;
        background: #fff;
    }

    .si-filter-summary span {
        display: block;
        color: #67756f;
        font-size: .72rem;
        font-weight: 900;
    }

    .si-filter-summary strong {
        display: block;
        margin-top: .18rem;
        color: var(--relax-heading, #10251d);
        font-size: 1rem;
    }

    .si-number,
    .si-po {
        color: rgb(var(--primary-rgb));
        font-weight: 950;
        text-decoration: none;
    }

    .si-number {
        font-family: "Courier New", monospace;
    }

    .si-money-stack,
    .si-date-stack {
        display: grid;
        gap: .28rem;
    }

    .si-money-stack span {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        min-width: 12rem;
    }

    .si-money-stack small {
        color: #73817b;
        font-weight: 800;
    }

    .si-row--overdue {
        --bs-table-bg: rgba(var(--danger-rgb), .06);
    }

    @media (max-width: 1199.98px) {
        .si-filter-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .si-filter-summary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 767.98px) {
        .si-filter-grid {
            grid-template-columns: 1fr;
        }

        .si-filter-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .si-money-stack span {
            min-width: 0;
        }
    }
</style>
@endpush
@endsection
