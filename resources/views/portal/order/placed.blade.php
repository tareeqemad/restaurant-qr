@extends('portal.layout')
@section('title', __('portal.order.placed_title', ['number' => $order->number]))

@section('content')
<div class="pf-section pf-section--centered pop-success">
    <div class="pop-icon">
        <i class="bi bi-check-circle-fill"></i>
    </div>
    <h1 class="pf-h1">{{ __('portal.order.placed_heading') }}</h1>
    <p class="pf-sub">{{ __('portal.order.order_number') }} <strong>{{ $order->number }}</strong></p>

    <div class="pop-card">
        <div class="pop-row">
            <span>{{ __('portal.order.branch') }}</span>
            <strong>{{ $order->branch->localizedName() }}</strong>
        </div>
        <div class="pop-row">
            <span>{{ __('portal.order.order_type') }}</span>
            <strong>
                @if($order->order_type === 'takeaway')
                    <i class="bi bi-bag-fill"></i> {{ __('portal.order.pickup_from_branch') }}
                @else
                    <i class="bi bi-truck"></i> {{ __('portal.order.delivery') }}
                @endif
            </strong>
        </div>
        @if($order->delivery_address)
            <div class="pop-row pop-row--col">
                <span>{{ __('portal.order.delivery_address') }}</span>
                <strong>{{ $order->delivery_address }}</strong>
            </div>
        @endif
        @if($order->scheduled_for)
            <div class="pop-row">
                <span>{{ __('portal.order.scheduled_time') }}</span>
                <strong>{{ $order->scheduled_for->translatedFormat('d M H:i') }}</strong>
            </div>
        @endif

        {{-- ETA snapshot stamped at creation. For delivery we show the
             arrival time; for takeaway, the ready-for-pickup time. --}}
        @if($order->order_type === 'delivery' && $order->estimated_delivered_at)
            <div class="pop-row pop-eta">
                <span><i class="bi bi-truck"></i> {{ __('portal.order.estimated_arrival') }}</span>
                <strong>
                    {{ $order->estimated_delivered_at->translatedFormat('H:i') }}
                    <small class="text-muted">({{ __('portal.order.minutes', ['count' => ceil($order->estimated_delivered_at->diffInMinutes(now(), absolute: true))]) }})</small>
                </strong>
            </div>
        @elseif($order->estimated_ready_at)
            <div class="pop-row pop-eta">
                <span><i class="bi bi-clock-fill"></i> {{ __('portal.order.ready_for_pickup') }}</span>
                <strong>
                    {{ $order->estimated_ready_at->translatedFormat('H:i') }}
                    <small class="text-muted">({{ __('portal.order.minutes', ['count' => ceil($order->estimated_ready_at->diffInMinutes(now(), absolute: true))]) }})</small>
                </strong>
            </div>
        @endif

        <div class="pop-row">
            <span>{{ __('portal.order.status') }}</span>
            <strong class="pop-status pop-status--{{ $order->status }}">
                {{ $order->statusLabel() }}
            </strong>
        </div>

        <hr>

        @foreach($order->items as $it)
            <div class="pop-item">
                <span class="pop-item__qty">{{ (int) $it->quantity }}×</span>
                <span class="pop-item__name">{{ $it->name_snapshot }}</span>
                <span class="pop-item__sub">{{ number_format($it->subtotal, 2) }}</span>
            </div>
        @endforeach

        <hr>
        @if((float) $order->delivery_fee > 0)
            <div class="pop-row pop-row--mini">
                <span>{{ __('portal.order.subtotal_tax') }}</span>
                <span>{{ number_format((float) $order->total - (float) $order->delivery_fee, 2) }}</span>
            </div>
            <div class="pop-row pop-row--mini">
                <span><i class="bi bi-truck"></i> {{ __('portal.order.delivery_fee') }}</span>
                <span>{{ number_format($order->delivery_fee, 2) }}</span>
            </div>
        @endif
        <div class="pop-total">
            <span>{{ __('portal.order.total') }}</span>
            <strong>{{ number_format($order->total, 2) }} {{ config('restaurant.currency_symbol', '₪') }}</strong>
        </div>
        <div class="pop-paynote">
            <i class="bi bi-cash-coin"></i>
            {{ __('portal.order.cash_on_delivery') }}
        </div>
    </div>

    @if($order->status === \App\Enums\OrderStatus::Pending->value)
        <div class="d-flex gap-2 mt-4 flex-wrap justify-content-center">
            <a href="{{ route('portal.order.edit', $order) }}" class="pf-btn pf-btn--light">
                <i class="bi bi-pencil-square"></i> {{ __('portal.order.edit_order') }}
            </a>
            <form action="{{ route('portal.order.cancel', $order) }}" method="POST"
                  onsubmit="return confirm(@js(__('portal.order.cancel_confirm')));" class="d-inline">
                @csrf
                <button type="submit" class="pf-btn pf-btn--danger">
                    <i class="bi bi-x-octagon"></i> {{ __('portal.order.cancel_order') }}
                </button>
            </form>
        </div>
        <p class="text-muted text-center mt-2 small">
            <i class="bi bi-info-circle"></i>
            {{ __('portal.order.edit_until_approved') }}
        </p>
    @else
        <p class="text-muted text-center mt-3 small">
            <i class="bi bi-lock-fill"></i>
            {{ __('portal.order.locked_after_approved') }}
        </p>
    @endif

    <div class="d-flex gap-2 mt-3 justify-content-center flex-wrap">
        <a href="{{ route('portal.order.history') }}" class="pf-btn pf-btn--light">
            <i class="bi bi-clock-history"></i> {{ __('portal.order.order_history') }}
        </a>
        <a href="{{ route('portal.order.branches') }}" class="pf-btn pf-btn--primary">
            <i class="bi bi-bag-plus"></i> {{ __('portal.order.new_order') }}
        </a>
    </div>
</div>

@push('styles')
<style>
    .pop-success { text-align:center; padding-top:30px; }
    .pop-icon { font-size:4rem; color:#16a34a; line-height:1; margin-bottom:12px;
                animation: pop-pop .5s ease; }
    @keyframes pop-pop { from { transform:scale(0.5); opacity:0; } to { transform:scale(1); opacity:1; } }

    .pop-card {
        max-width:520px; margin:24px auto 0; padding:18px;
        background:#fff; border:1px solid #e5e7eb; border-radius:14px;
        text-align:start;
    }
    .pop-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:.92rem; }
    .pop-row:last-of-type { border-bottom:0; }
    .pop-row--col { flex-direction:column; gap:4px; }
    .pop-row--mini { font-size:.82rem; padding:4px 0; }
    .pop-row span { color:#6b7280; }
    .pop-eta { background: #ecfeff; padding-inline: 10px; border-radius:6px; }
    .pop-eta strong { color: #0e7490; }
    .pop-status { color:#d97706; }
    .pop-status--approved, .pop-status--preparing, .pop-status--ready, .pop-status--delivered { color:#1e40af; }
    .pop-status--completed { color:#166534; }
    .pop-status--cancelled { color:#991b1b; }

    .pop-item { display:flex; gap:10px; padding:5px 0; font-size:.88rem; }
    .pop-item__qty { font-weight:700; color:var(--green-primary); min-width:24px; }
    .pop-item__name { flex:1; }
    .pop-item__sub { font-weight:700; color:var(--ink); font-variant-numeric:tabular-nums; }

    .pop-total { display:flex; justify-content:space-between; font-weight:800; color:var(--green-dark); padding:6px 0; }
    .pop-paynote { display:inline-flex; align-items:center; gap:6px; padding:8px 12px;
                   margin-top:10px; background:#fef3c7; color:#92400e; border-radius:8px; font-size:.84rem; }
</style>
@endpush
@endsection
