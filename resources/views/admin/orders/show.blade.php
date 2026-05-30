@extends('layouts.admin')@section('title','طلب '.$order->number)
@section('content')
<x-admin.breadcrumb title="طلب {{ $order->number }}" icon="bi-receipt-cutoff"
    :crumbs="[['label' => 'الطلبات', 'url' => route('admin.orders.index')]]">
    <x-slot:actions>
        <span class="badge bg-{{ $order->statusColor() }} fs-6">{{ $order->statusLabel() }}</span>
    </x-slot:actions>
</x-admin.breadcrumb>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card"><div class="card-header d-flex justify-content-between">
            <div class="card-title fw-bold">{{ $order->number }}</div>
            <span class="badge bg-{{ $order->statusColor() }} fs-6">{{ $order->statusLabel() }}</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-4"><strong>الطاولة:</strong> {{ $order->tableLabel() }}</div>
                <div class="col-md-4"><strong>النوع:</strong>
                    @switch($order->order_type)@case('dine_in')جلوس@break @case('takeaway')تيك أواي@break @case('delivery')توصيل@break @endswitch
                </div>
                <div class="col-md-4"><strong>الوقت:</strong> {{ $order->created_at->format('Y-m-d H:i') }}</div>
                @if($order->approver)<div class="col-md-4 mt-2"><strong>اعتمده:</strong> {{ $order->approver->name }}</div>@endif
                @if($order->customer_notes)<div class="col-12 mt-2"><strong>ملاحظات الزبون:</strong> {{ $order->customer_notes }}</div>@endif
                @if($order->cancelled_reason)<div class="col-12 mt-2 text-danger"><strong>سبب الإلغاء:</strong> {{ $order->cancelled_reason }}</div>@endif
            </div>

            <table class="table align-middle">
                <thead class="bg-light"><tr><th>الصنف</th><th>الكمية</th><th>سعر</th><th>مجموع</th><th>المحطة</th><th>حالة</th><th></th></tr></thead>
                <tbody>
                @foreach($order->items as $it)
                    <tr class="{{ $it->status==='cancelled' ? 'table-secondary opacity-50' : '' }}">
                        <td><strong>{{ $it->name_snapshot }}</strong>
                            @if($it->modifiers->count())
                                <div class="small text-muted">
                                    @foreach($it->modifiers as $m)<span class="badge bg-light text-dark border me-1">{{ $m->name_snapshot }} @if($m->price_delta>0)+{{ $m->price_delta }}@endif</span>@endforeach
                                </div>
                            @endif
                            @if($it->notes)<div class="small text-warning"><i class="bi bi-chat"></i> {{ $it->notes }}</div>@endif
                        </td>
                        <td>{{ $it->quantity }}</td>
                        <td>{{ \App\Helpers\Money::format($it->unit_price + $it->modifiers_total) }}</td>
                        <td>{{ \App\Helpers\Money::format($it->subtotal) }}</td>
                        <td><span class="badge" style="background:{{ $it->station?->color ?? '#999' }}">{{ $it->station?->name ?? '—' }}</span></td>
                        <td><span class="badge bg-{{ $it->statusColor() }}">{{ $it->statusLabel() }}</span></td>
                        <td>
                            @if($it->status==='ready')
                                <form action="{{ route('admin.orders.items.serve', $it) }}" method="POST" class="d-inline">@csrf<button class="btn btn-sm btn-dark"><i class="bi bi-check-all"></i> قدّم</button></form>
                            @endif
                            @if(! in_array($it->status, ['cancelled', 'served']))
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelItem{{ $it->id }}"><i class="bi bi-x"></i></button>
                                <div class="modal fade" id="cancelItem{{ $it->id }}"><div class="modal-dialog"><div class="modal-content">
                                    <form action="{{ route('admin.orders.items.cancel', $it) }}" method="POST">@csrf
                                        <div class="modal-header"><h5>إلغاء: {{ $it->name_snapshot }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body"><label class="form-label">السبب *</label><textarea name="reason" class="form-control" required></textarea></div>
                                        <div class="modal-footer"><button class="btn btn-danger">تأكيد الإلغاء</button></div>
                                    </form>
                                </div></div></div>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <h5 class="fw-bold mb-3">الإجمالي</h5>
            <div class="d-flex justify-content-between mb-1"><span>المجموع الفرعي:</span><strong>{{ \App\Helpers\Money::format($order->subtotal) }}</strong></div>
            @if((float)$order->discount_total>0)<div class="d-flex justify-content-between mb-1"><span>الخصم:</span><strong class="text-success">-{{ \App\Helpers\Money::format($order->discount_total) }}</strong></div>@endif
            @if((float)$order->tax_total>0)<div class="d-flex justify-content-between mb-1"><span>الضريبة ({{ $order->tax_rate }}%):</span><strong>{{ \App\Helpers\Money::format($order->tax_total) }}</strong></div>@endif
            @if((float)$order->service_total>0)<div class="d-flex justify-content-between mb-1"><span>الخدمة ({{ $order->service_rate }}%):</span><strong>{{ \App\Helpers\Money::format($order->service_total) }}</strong></div>@endif
            <hr>
            <div class="d-flex justify-content-between"><strong>الإجمالي:</strong><strong class="text-primary fs-5">{{ \App\Helpers\Money::format($order->total) }}</strong></div>
        </div></div>

        <div class="card mt-3"><div class="card-body">
            @if($order->status==='pending')
                <form action="{{ route('admin.orders.approve', $order) }}" method="POST" class="d-grid mb-2">@csrf<button class="btn btn-success btn-lg"><i class="bi bi-check2-circle"></i> اعتماد الطلب</button></form>
            @endif
            @if($order->canCancelEntireOrder())
                <button class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#cancelOrder"><i class="bi bi-x-circle"></i> إلغاء الطلب بالكامل</button>
                <div class="modal fade" id="cancelOrder"><div class="modal-dialog"><div class="modal-content">
                    <form action="{{ route('admin.orders.cancel', $order) }}" method="POST">@csrf
                        <div class="modal-header"><h5>إلغاء الطلب {{ $order->number }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body"><label class="form-label">سبب الإلغاء *</label><textarea name="reason" class="form-control" required></textarea></div>
                        <div class="modal-footer"><button class="btn btn-danger">تأكيد الإلغاء</button></div>
                    </form>
                </div></div></div>
            @endif
        </div></div>
    </div>
</div>
@endsection
