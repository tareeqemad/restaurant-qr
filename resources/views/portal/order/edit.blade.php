@extends('portal.layout')
@section('title', 'تعديل طلب '.$order->number)

@section('content')
<div class="pf-section">
    <a href="{{ route('portal.order.placed', $order) }}" class="poc-back">
        <i class="bi bi-arrow-right"></i> عودة لتفاصيل الطلب
    </a>
    <h1 class="pf-h1">تعديل الطلب {{ $order->number }}</h1>
    <p class="pf-sub">
        <i class="bi bi-info-circle"></i>
        يمكنك تغيير الكمية أو حذف صنف.
        لإضافة أصناف جديدة، أنشئ طلباً جديداً من نفس الفرع.
    </p>

    <form action="{{ route('portal.order.update', $order) }}" method="POST" class="poe-form">
        @csrf

        <div class="poe-items">
            @foreach($order->items as $item)
                <div class="poe-item">
                    <div class="poe-item__main">
                        <strong>{{ $item->name_snapshot }}</strong>
                        @if($item->modifiers->isNotEmpty())
                            <small class="text-muted d-block">
                                {{ $item->modifiers->pluck('name_snapshot')->join('، ') }}
                            </small>
                        @endif
                        <span class="poe-item__price">
                            {{ number_format((float) $item->unit_price + (float) $item->modifiers_total, 2) }} {{ config('restaurant.currency_symbol', '₪') }} / للقطعة
                        </span>
                    </div>
                    <div class="poe-item__qty">
                        <label class="visually-hidden" for="qty-{{ $item->id }}">الكمية</label>
                        <input type="number" id="qty-{{ $item->id }}"
                               name="items[{{ $item->id }}][qty]"
                               class="form-control"
                               value="{{ (int) $item->quantity }}"
                               min="0" max="50">
                        <small class="poe-item__hint">0 = حذف</small>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="poe-actions">
            <button type="submit" class="poc-submit">
                <i class="bi bi-check-circle-fill"></i> حفظ التعديلات
            </button>
            <button type="button" class="pf-btn pf-btn--light"
                    onclick="if(confirm('إلغاء الطلب بالكامل؟')) document.getElementById('cancel-form').submit();">
                <i class="bi bi-x-octagon"></i> إلغاء الطلب بالكامل
            </button>
        </div>
    </form>

    <form id="cancel-form" action="{{ route('portal.order.cancel', $order) }}" method="POST" class="d-none">
        @csrf
    </form>
</div>

@push('styles')
<style>
    .poe-items { display:flex; flex-direction:column; gap:8px; margin-bottom:18px; }
    .poe-item {
        display:grid; grid-template-columns: 1fr 110px; gap:14px;
        padding:14px; background:#fff; border:1px solid #e5e7eb; border-radius:12px;
    }
    .poe-item__main strong { display:block; color:var(--ink); }
    .poe-item__price { display:block; font-size:.78rem; color:var(--green-primary); margin-top:4px; }
    .poe-item__qty input { text-align:center; font-weight:700; }
    .poe-item__hint { display:block; text-align:center; font-size:.7rem; color:#9ca3af; margin-top:2px; }
    .poe-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .poe-actions .poc-submit { flex:1; }
</style>
@endpush
@endsection
