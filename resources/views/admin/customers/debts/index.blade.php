@extends('layouts.admin')
@section('title', 'دفتر ديون الزبائن')

@section('content')
<x-admin.breadcrumb
    title="دفتر ديون الزبائن"
    icon="bi-wallet2"
    subtitle="كل زبون عليه رصيد مفتوح، مرتّب من الأكبر للأصغر" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي الديون',         'value' => number_format($stats['total_debt'], 2) . ' ش.إ', 'icon' => 'bi-cash-stack',        'color' => 'danger'],
    ['label' => 'زبائن عليهم دين',       'value' => $stats['customers_owing'],                       'icon' => 'bi-people-fill',       'color' => 'primary'],
    ['label' => 'فواتير مفتوحة',         'value' => $stats['open_invoices'],                         'icon' => 'bi-file-earmark-text', 'color' => 'accent'],
]" />

<x-admin.data-panel title="قائمة المديونين" :count="$rows->total()" icon="bi-wallet2">
    <x-slot:filters>
        <form class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">بحث (اسم أو هاتف)</label>
                <input type="text" name="search" value="{{ $search }}"
                       class="form-control" placeholder="اكتب اسم الزبون أو رقم هاتفه…">
            </div>
            <div class="col-md-3 text-center">
                <button class="btn btn-primary px-4 w-100"><i class="bi bi-funnel"></i> بحث</button>
            </div>
            @if($search)
                <div class="col-md-3">
                    <a href="{{ route('admin.customers.debts.index') }}" class="btn btn-light w-100">
                        <i class="bi bi-x-lg"></i> مسح
                    </a>
                </div>
            @endif
        </form>
    </x-slot:filters>

    @if($rows->count())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>الزبون</th>
                        <th class="text-center">عدد الفواتير</th>
                        <th class="text-end">إجمالي الدين</th>
                        <th class="text-center">أقدم دين</th>
                        <th class="text-center">آخر دين</th>
                        <th class="text-center">الحد الائتماني</th>
                        <th class="text-center" style="width:160px">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        @php
                            $customer = $customers[$row->customer_id] ?? null;
                            if (! $customer) continue;
                            $limit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
                            $debt  = (float) $row->debt_total;
                            $isOverLimit = $limit !== null && $debt > $limit + 0.01;
                        @endphp
                        <tr class="{{ $isOverLimit ? 'table-danger' : '' }}">
                            <td>
                                <div class="d-flex flex-column">
                                    <strong>{{ $customer->name }}</strong>
                                    <small class="text-muted" dir="ltr">{{ $customer->phone }}</small>
                                </div>
                            </td>
                            <td class="text-center"><span class="badge bg-secondary">{{ $row->invoice_count }}</span></td>
                            <td class="text-end fw-bold text-danger">{{ number_format($debt, 2) }} ش.إ</td>
                            <td class="text-center text-muted">
                                <small>{{ \Carbon\Carbon::parse($row->oldest_settled_at)->diffForHumans() }}</small>
                            </td>
                            <td class="text-center text-muted">
                                <small>{{ \Carbon\Carbon::parse($row->newest_settled_at)->diffForHumans() }}</small>
                            </td>
                            <td class="text-center">
                                @if($limit === null)
                                    <span class="text-muted">بدون حد</span>
                                @else
                                    <strong class="{{ $isOverLimit ? 'text-danger' : 'text-success' }}">
                                        {{ number_format($limit, 2) }}
                                    </strong>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.customers.debts.show', $customer) }}"
                                   class="btn btn-sm btn-primary">
                                    <i class="bi bi-wallet"></i> تفاصيل + تسديد
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rows->links() }}</div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
            <p class="mb-0">لا يوجد زبائن عليهم ديون مفتوحة الآن 🎉</p>
        </div>
    @endif
</x-admin.data-panel>
@endsection
