@extends('layouts.admin')
@section('title', 'أوامر الشراء')

@section('content')
<x-admin.breadcrumb title="أوامر الشراء" icon="bi-truck"
    subtitle="إدارة طلبات الشراء من الموردين واستلام البضاعة" />

<x-admin.stat-rail :stats="[
    ['label' => 'مسودات',         'value' => $stats['draft'],     'icon' => 'bi-file-earmark',          'color' => 'muted'],
    ['label' => 'مُرسلة',          'value' => $stats['sent'],      'icon' => 'bi-send-check-fill',      'color' => 'primary'],
    ['label' => 'مستلمة جزئياً',    'value' => $stats['partial'],   'icon' => 'bi-inboxes-fill',          'color' => 'accent'],
    ['label' => 'مشتريات الشهر',   'value' => \App\Helpers\Money::format($stats['this_month']), 'icon' => 'bi-cash-coin', 'color' => 'success'],
]" />

<x-admin.data-panel title="قائمة الأوامر" :count="$pos->total()" icon="bi-truck">
    <x-slot:actions>
        <a href="{{ route('admin.purchase-orders.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> أمر شراء جديد
        </a>
        @if(request()->hasAny(['status', 'supplier_id', 'search', 'from', 'to']))
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3">
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 رقم PO">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="draft"              @selected(request('status')==='draft')>مسودة</option>
                    <option value="sent"               @selected(request('status')==='sent')>مُرسل</option>
                    <option value="partially_received" @selected(request('status')==='partially_received')>مستلم جزئياً</option>
                    <option value="received"           @selected(request('status')==='received')>مستلم</option>
                    <option value="cancelled"          @selected(request('status')==='cancelled')>ملغي</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="supplier_id" class="form-select">
                    <option value="">كل الموردين</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}" @selected(request('supplier_id')==$s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>رقم PO</th>
                    <th>المورد</th>
                    <th>البنود</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th>تاريخ</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($pos as $po)
                    <tr>
                        <td>
                            <a href="{{ route('admin.purchase-orders.show', $po) }}" class="fw-bold" style="color:var(--primary); text-decoration:none; font-family:'Courier New', monospace;">
                                {{ $po->number }}
                            </a>
                        </td>
                        <td>{{ $po->supplier?->name ?? '—' }}</td>
                        <td><span class="badge bg-secondary">{{ $po->items->count() }} بند</span></td>
                        <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($po->total) }}</td>
                        <td><span class="badge bg-{{ $po->statusColor() }}">{{ $po->statusLabel() }}</span></td>
                        <td class="text-muted small">
                            {{ $po->created_at->format('Y-m-d') }}
                            @if($po->expected_at)
                                <div class="small"><i class="bi bi-calendar-check"></i> {{ $po->expected_at->format('Y-m-d') }}</div>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.purchase-orders.show', $po) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> تفاصيل
                            </a>
                            @if($po->isReceivable())
                                <a href="{{ route('admin.purchase-orders.receive-form', $po) }}" class="btn btn-sm btn-success" title="استلام البضاعة">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state
                            icon="bi-truck"
                            title="ما في أوامر شراء بعد"
                            message="أنشئ أول أمر شراء من مورد لاستلام بضاعة وتحديث المخزون تلقائياً.">
                            <x-slot:cta>
                                <a href="{{ route('admin.purchase-orders.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> أمر شراء جديد
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $pos->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
