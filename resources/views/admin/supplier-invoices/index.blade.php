@extends('layouts.admin')
@section('title', 'فواتير الموردين')

@section('content')
<x-admin.breadcrumb title="فواتير الموردين" icon="bi-file-earmark-text-fill"
    subtitle="تتبع ما نحنا مدينين فيه للموردين (Accounts Payable)" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المستحقات (AP)', 'value' => \App\Helpers\Money::format($stats['total_ap']),   'icon' => 'bi-exclamation-circle-fill','color' => 'danger'],
    ['label' => 'فواتير متأخرة',          'value' => $stats['overdue'],                              'icon' => 'bi-alarm-fill',            'color' => 'danger'],
    ['label' => 'مستحقة خلال 7 أيام',     'value' => $stats['due_this_week'],                        'icon' => 'bi-calendar-event',        'color' => 'accent'],
    ['label' => 'مشتريات الشهر',           'value' => \App\Helpers\Money::format($stats['this_month']),'icon' => 'bi-graph-up-arrow',      'color' => 'primary'],
]" />

<x-admin.data-panel title="قائمة فواتير الموردين" :count="$invoices->total()" icon="bi-file-earmark-text-fill">
    <x-slot:actions>
        <a href="{{ route('admin.supplier-invoices.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> فاتورة جديدة
        </a>
        @if(request()->hasAny(['search', 'status', 'supplier_id', 'overdue']))
            <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3"><input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 رقم الفاتورة"></div>
            <div class="col-md-3">
                <select name="supplier_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مورد...">
                    <option value="">كل الموردين</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(request('supplier_id')==$s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="unpaid"         @selected(request('status')==='unpaid')>غير مدفوعة</option>
                    <option value="partially_paid" @selected(request('status')==='partially_paid')>جزئية</option>
                    <option value="paid"           @selected(request('status')==='paid')>مدفوعة</option>
                    <option value="cancelled"      @selected(request('status')==='cancelled')>ملغاة</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="d-flex align-items-center gap-2 h-100">
                    <input type="checkbox" name="overdue" value="1" @checked(request('overdue'))>
                    متأخرة فقط
                </label>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>رقم الفاتورة</th>
                    <th>المورد</th>
                    <th>PO</th>
                    <th>الإجمالي</th>
                    <th>مدفوع</th>
                    <th>متبقي</th>
                    <th>الاستحقاق</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoices as $inv)
                    <tr class="{{ $inv->isOverdue() ? 'table-warning' : '' }}">
                        <td>
                            <a href="{{ route('admin.supplier-invoices.show', $inv) }}" class="fw-bold" style="color:var(--primary); text-decoration:none; font-family:'Courier New', monospace;">
                                {{ $inv->number }}
                            </a>
                            @if($inv->isOverdue())
                                <small class="d-block text-danger"><i class="bi bi-alarm"></i> متأخرة!</small>
                            @endif
                        </td>
                        <td>{{ $inv->supplier?->name ?? '—' }}</td>
                        <td>
                            @if($inv->purchaseOrder)
                                <a href="{{ route('admin.purchase-orders.show', $inv->purchaseOrder) }}" class="small" style="font-family:'Courier New', monospace;">
                                    {{ $inv->purchaseOrder->number }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($inv->total) }}</td>
                        <td class="text-success">{{ \App\Helpers\Money::format($inv->paid_total) }}</td>
                        <td class="fw-bold" style="color: {{ $inv->balance > 0 ? '#b91c1c' : 'var(--primary)' }};">
                            {{ \App\Helpers\Money::format($inv->balance) }}
                        </td>
                        <td class="small">
                            @if($inv->due_date)
                                {{ $inv->due_date->format('Y-m-d') }}
                                @if($inv->isOverdue())<br><small class="text-danger">متأخرة {{ $inv->due_date->diffInDays() }} يوم</small>@endif
                            @else — @endif
                        </td>
                        <td><span class="badge bg-{{ $inv->statusColor() }}">{{ $inv->statusLabel() }}</span></td>
                        <td>
                            <a href="{{ route('admin.supplier-invoices.show', $inv) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9">
                        <x-admin.empty-state
                            icon="bi-file-earmark-text"
                            title="ما في فواتير موردين بعد"
                            message="سجّل فواتير الموردين عند استلامها لتتبع المستحقات عليك.">
                            <x-slot:cta>
                                <a href="{{ route('admin.supplier-invoices.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> فاتورة جديدة
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $invoices->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
