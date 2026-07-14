@extends('customer.layout')
@section('title', __('ui.customer_order.cart_title'))
@section('content')
<div class="p-3">
    <h5 class="fw-bold mb-3"><i class="bi bi-cart3"></i> {{ __('ui.customer_order.cart_title') }}</h5>

    @if(empty($cart))
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cart-x" style="font-size: 4rem;"></i>
            <p class="mt-3">{{ __('ui.customer_menu.cart_empty') }}</p>
            <a href="{{ route('customer.menu') }}" class="btn btn-danger">{{ __('ui.customer_order.browse_menu') }}</a>
        </div>
    @else
        @php
            $total = collect($cart)->sum('subtotal');
            $taxEnabled = (bool) \App\Models\Setting::get('tax_enabled', config('restaurant.tax.enabled'));
            $taxRate = $taxEnabled ? (float) \App\Models\Setting::get('tax_rate', config('restaurant.tax.rate')) : 0.0;
            $serviceEnabled = (bool) \App\Models\Setting::get('service_enabled', config('restaurant.service_charge.enabled'));
            $serviceRate = $serviceEnabled ? (float) \App\Models\Setting::get('service_rate', config('restaurant.service_charge.rate')) : 0.0;
            $taxDisplayMode = $session->table?->branch?->customerTaxDisplayMode()
                ?? \App\Models\Setting::get('customer_tax_display', 'exclusive');
            $taxDisplayMode = in_array($taxDisplayMode, ['exclusive', 'inclusive'], true) ? $taxDisplayMode : 'exclusive';
            $taxTotal = $taxEnabled ? round($total * ($taxRate / 100), 2) : 0.0;
            $serviceTotal = $serviceEnabled ? round($total * ($serviceRate / 100), 2) : 0.0;
            $displayTotal = $taxDisplayMode === 'inclusive' ? $total + $taxTotal + $serviceTotal : $total;
            $displayLabel = $taxDisplayMode === 'inclusive' ? __('ui.customer_menu.tax_inclusive_total') : __('ui.customer_menu.tax_exclusive_total');
        @endphp
        @foreach($cart as $row)
            <div class="menu-card p-3 mb-2">
                <div class="d-flex gap-2">
                    <img src="{{ $row['image'] }}" width="72" height="72" class="rounded" style="object-fit:cover"
                         onerror="window.dishImgFallback && dishImgFallback(this)">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <strong>{{ $row['name'] }}</strong>
                            <span class="fw-bold text-danger">{{ \App\Helpers\Money::format($row['subtotal']) }}</span>
                        </div>
                        @if(! empty($row['modifiers']))
                            <small class="text-muted">{{ collect($row['modifiers'])->pluck('name')->join(', ') }}</small>
                        @endif
                        @if(! empty($row['notes']))<small class="d-block text-warning"><i class="bi bi-chat"></i> {{ $row['notes'] }}</small>@endif

                        <div class="d-flex gap-2 mt-2">
                            <form action="{{ route('customer.cart.update') }}" method="POST" class="d-inline">@csrf
                                <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                                <div class="input-group input-group-sm" style="width: 140px;">
                                    <button type="button" onclick="this.nextElementSibling.stepDown();this.form.submit()" class="btn btn-outline-secondary">-</button>
                                    <input name="quantity" type="number" value="{{ $row['quantity'] }}" min="1" class="form-control text-center">
                                    <button type="button" onclick="this.previousElementSibling.stepUp();this.form.submit()" class="btn btn-outline-secondary">+</button>
                                </div>
                            </form>
                            <form action="{{ route('customer.cart.remove') }}" method="POST" class="d-inline">@csrf
                                <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="card mt-3"><div class="card-body">
            <div class="d-flex justify-content-between fs-5 fw-bold mb-2">
                <span>{{ $displayLabel }}:</span>
                <span class="text-danger">{{ \App\Helpers\Money::format($displayTotal) }}</span>
            </div>
            <div class="small text-muted">
                <div>{{ __('ui.customer_menu.subtotal') }}: {{ \App\Helpers\Money::format($total) }}</div>
                @if($taxDisplayMode === 'inclusive' && $taxTotal > 0)
                    <div>{{ __('ui.customer_menu.tax') }} ({{ $taxRate }}%): {{ \App\Helpers\Money::format($taxTotal) }}</div>
                @endif
                @if($taxDisplayMode === 'inclusive' && $serviceTotal > 0)
                    <div>{{ __('ui.customer_menu.service') }} ({{ $serviceRate }}%): {{ \App\Helpers\Money::format($serviceTotal) }}</div>
                @endif
                @if($taxDisplayMode === 'exclusive' && ($taxTotal > 0 || $serviceTotal > 0))
                    <div>{{ __('ui.customer_order.tax_service_after_approval') }}</div>
                @endif
            </div>

            <form action="{{ route('customer.cart.submit') }}" method="POST" class="mt-3">@csrf
                <textarea name="notes" class="form-control mb-2" rows="2" placeholder="{{ __('ui.customer_order.general_notes_to_server') }}"></textarea>
                <button class="btn btn-danger btn-lg w-100"><i class="bi bi-send"></i> {{ __('ui.customer_order.send_order') }}</button>
            </form>
        </div></div>
    @endif
</div>
@endsection
