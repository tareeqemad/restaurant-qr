@extends('customer.layout')
@section('title','طلباتي')
@section('content')
<div class="p-3">
    <h5 class="fw-bold mb-3"><i class="bi bi-receipt"></i> طلباتي</h5>

    @forelse($orders as $order)
        <div class="menu-card p-3 mb-2">
            <div class="d-flex justify-content-between mb-2">
                <strong>{{ $order->number }}</strong>
                <span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span>
            </div>
            <small class="text-muted d-block">{{ $order->created_at->diffForHumans() }}</small>

            <ul class="list-unstyled mt-2 mb-2">
                @foreach($order->items as $it)
                    <li class="{{ $it->status==='cancelled' ? 'text-muted text-decoration-line-through' : '' }}">
                        <i class="bi bi-{{ match($it->status){'served'=>'check-all text-success','ready'=>'check-circle text-success','preparing'=>'fire text-warning','cancelled'=>'x-circle text-danger', default=>'hourglass-split text-muted' } }}"></i>
                        {{ $it->quantity }}× {{ $it->name_snapshot }}
                        @if($it->modifiers->count())<small class="text-muted">({{ $it->modifiers->pluck('name_snapshot')->join('، ') }})</small>@endif
                        <span class="float-start">{{ \App\Helpers\Money::format($it->subtotal) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="d-flex justify-content-between fw-bold">
                <span>الإجمالي:</span><span class="text-danger">{{ \App\Helpers\Money::format($order->total) }}</span>
            </div>

            @if($order->status === 'pending')
                <form action="{{ route('customer.orders.cancel', $order) }}" method="POST" class="mt-2" onsubmit="return confirm('إلغاء الطلب قبل موافقة الجرسون؟')">@csrf
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle"></i> إلغاء الطلب</button>
                </form>
            @endif
        </div>
    @empty
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 4rem;"></i>
            <p class="mt-3">لا طلبات بعد</p>
            <a href="{{ route('customer.menu') }}" class="btn btn-danger">ابدأ بالطلب</a>
        </div>
    @endforelse
</div>
<meta http-equiv="refresh" content="30">
@endsection
