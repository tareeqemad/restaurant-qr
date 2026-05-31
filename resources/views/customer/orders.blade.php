@extends('customer.layout')
@section('title', __('ui.customer_order.my_orders'))
@section('content')
<div class="p-3">
    <h5 class="fw-bold mb-3"><i class="bi bi-receipt"></i> {{ __('ui.customer_order.my_orders') }}</h5>

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
                        {{ $it->quantity }}× {{ app()->getLocale() === 'en' && $it->name_en_snapshot ? $it->name_en_snapshot : $it->name_snapshot }}
                        @if($it->modifiers->count())
                            <small class="text-muted">
                                ({{ $it->modifiers->map(fn ($modifier) => app()->getLocale() === 'en' && $modifier->name_en_snapshot ? $modifier->name_en_snapshot : $modifier->name_snapshot)->join(', ') }})
                            </small>
                        @endif
                        <span class="float-start">{{ \App\Helpers\Money::format($it->subtotal) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="d-flex justify-content-between fw-bold">
                <span>{{ __('ui.customer_order.total') }}</span><span class="text-danger">{{ \App\Helpers\Money::format($order->total) }}</span>
            </div>

            @if($order->status === 'pending')
                <form action="{{ route('customer.orders.cancel', $order) }}" method="POST" class="mt-2" onsubmit="return confirm(@js(__('ui.customer_order.cancel_pending_confirm')))">@csrf
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-x-circle"></i> {{ __('ui.customer_order.cancel_order') }}</button>
                </form>
            @endif
        </div>
    @empty
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 4rem;"></i>
            <p class="mt-3">{{ __('ui.customer_order.no_orders_yet') }}</p>
            <a href="{{ route('customer.menu') }}" class="btn btn-danger">{{ __('ui.customer_order.start_ordering') }}</a>
        </div>
    @endforelse
</div>
<meta http-equiv="refresh" content="30">
@endsection
