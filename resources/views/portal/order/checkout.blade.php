@extends('portal.layout')
@section('title', 'إتمام الطلب')

@section('content')
<div class="pf-section">
    <a href="{{ route('portal.order.menu', $branch) }}" class="poc-back">
        <i class="bi bi-arrow-right"></i> عودة للقائمة
    </a>
    <h1 class="pf-h1">إتمام الطلب من «{{ $branch->name }}»</h1>

    <div class="poc-grid">
        <form action="{{ route('portal.order.place', $branch) }}" method="POST" class="poc-form">
            @csrf

            {{-- Type picker --}}
            <fieldset class="poc-fieldset">
                <legend>نوع الطلب</legend>
                <div class="poc-types" id="poc-types">
                    <label class="poc-type {{ old('order_type', 'takeaway') === 'takeaway' ? 'is-active' : '' }}">
                        <input type="radio" name="order_type" value="takeaway"
                               {{ old('order_type', 'takeaway') === 'takeaway' ? 'checked' : '' }} hidden>
                        <i class="bi bi-bag-fill"></i>
                        <div>
                            <strong>أستلم بنفسي</strong>
                            <span>أنت تأتي للفرع وتستلم</span>
                        </div>
                    </label>
                    <label class="poc-type {{ old('order_type') === 'delivery' ? 'is-active' : '' }}">
                        <input type="radio" name="order_type" value="delivery"
                               {{ old('order_type') === 'delivery' ? 'checked' : '' }} hidden>
                        <i class="bi bi-truck"></i>
                        <div>
                            <strong>توصيل</strong>
                            <span>المطعم يوصل لعنوانك</span>
                        </div>
                    </label>
                </div>
                @error('order_type') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            </fieldset>

            {{-- Delivery address (shown only for delivery — toggled by vanilla JS, no Alpine dependency) --}}
            <fieldset class="poc-fieldset" id="poc-address"
                      style="{{ old('order_type') === 'delivery' ? '' : 'display:none;' }}">
                <legend>عنوان التوصيل</legend>
                <textarea name="delivery_address" rows="3" class="form-control"
                          placeholder="الحي، الشارع، رقم البناية، أي علامة مميزة...">{{ old('delivery_address') }}</textarea>
                @error('delivery_address') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            </fieldset>

            {{-- Optional schedule --}}
            <fieldset class="poc-fieldset">
                <legend>وقت التحضير (اختياري)</legend>
                <input type="datetime-local" name="scheduled_for" class="form-control"
                       value="{{ old('scheduled_for') }}"
                       min="{{ now()->addMinutes(15)->format('Y-m-d\TH:i') }}">
                <small class="text-muted">اتركه فارغاً ليبدأ التحضير فوراً.</small>
                @error('scheduled_for') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            </fieldset>

            {{-- Notes --}}
            <fieldset class="poc-fieldset">
                <legend>ملاحظات للمطعم (اختياري)</legend>
                <textarea name="customer_notes" rows="2" class="form-control"
                          placeholder="بدون بصل، حار قليل، ...">{{ old('customer_notes') }}</textarea>
                @error('customer_notes') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
            </fieldset>

            {{-- Customer card (read-only — they're already logged in) --}}
            <div class="poc-customer">
                <i class="bi bi-person-check-fill"></i>
                <div>
                    <strong>{{ $customer->name }}</strong>
                    <span dir="ltr">{{ $customer->phone }}</span>
                </div>
            </div>

            <button type="submit" class="poc-submit">
                <i class="bi bi-check-circle-fill"></i>
                إرسال الطلب — {{ number_format($cartTotal, 2) }} ر.ع
            </button>
            <p class="poc-paynote">
                <i class="bi bi-info-circle"></i>
                الدفع نقداً عند الاستلام / التوصيل.
            </p>
        </form>

        {{-- Order summary --}}
        <aside class="poc-summary">
            <h3>ملخص الطلب</h3>
            @foreach($cart as $row)
                <div class="poc-summary__row">
                    <span class="poc-summary__qty">{{ $row['quantity'] }}×</span>
                    <span class="poc-summary__name">{{ $row['name'] }}</span>
                    <span class="poc-summary__price">{{ number_format($row['subtotal'], 2) }}</span>
                </div>
                @if(! empty($row['notes']))
                    <div class="poc-summary__note">— {{ $row['notes'] }}</div>
                @endif
            @endforeach
            <hr>
            @php
                $deliveryFee = $branch->deliveryFee();
                $isDelivery  = old('order_type') === 'delivery';
                $totalShown  = $cartTotal + ($isDelivery ? $deliveryFee : 0);
            @endphp
            <div class="poc-summary__row">
                <span></span>
                <span class="poc-summary__name">الفرعي</span>
                <span class="poc-summary__price">{{ number_format($cartTotal, 2) }}</span>
            </div>
            {{-- Delivery fee row — toggled by the same vanilla JS that
                 toggles the address fieldset. --}}
            <div class="poc-summary__row" id="poc-fee-row"
                 style="{{ $isDelivery ? '' : 'display:none;' }}">
                <span></span>
                <span class="poc-summary__name">رسوم التوصيل</span>
                <span class="poc-summary__price">{{ number_format($deliveryFee, 2) }}</span>
            </div>
            <div class="poc-summary__total">
                <span>الإجمالي</span>
                <strong>
                    <span id="poc-total-display">{{ number_format($totalShown, 2) }}</span>
                    ر.ع
                </strong>
            </div>
            <span id="poc-subtotal" data-value="{{ $cartTotal }}" hidden></span>
            <span id="poc-delivery" data-value="{{ $deliveryFee }}" hidden></span>
        </aside>
    </div>
</div>

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    .poc-back { display:inline-block; color:#6b7280; text-decoration:none; margin-bottom:12px; font-size:.88rem; }
    .poc-back:hover { color: var(--green-primary); }

    .poc-grid { display:grid; grid-template-columns: 1fr 320px; gap:18px; align-items:start; }
    @media (max-width:900px) { .poc-grid { grid-template-columns: 1fr; } }

    .poc-form { display:flex; flex-direction:column; gap:14px; }
    .poc-fieldset { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; }
    .poc-fieldset legend { float:none; width:auto; padding:0 6px; font-size:.85rem; font-weight:700; color:var(--green-dark); }

    .poc-types { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .poc-type { cursor:pointer; padding:14px 12px; display:flex; align-items:center; gap:10px;
                border:2px solid #e5e7eb; border-radius:10px; transition:border-color .15s, background .15s; }
    .poc-type:hover { border-color: rgba(46,125,50,.4); }
    .poc-type.is-active { border-color: var(--green-primary); background: #f0fdf4; }
    .poc-type i { font-size:1.6rem; color: var(--green-primary); }
    .poc-type strong { display:block; font-size:.92rem; color: var(--ink); }
    .poc-type span { display:block; font-size:.74rem; color:#6b7280; }

    .poc-customer { display:flex; align-items:center; gap:10px; padding:12px;
                    background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; }
    .poc-customer i { color: var(--green-primary); font-size:1.4rem; }
    .poc-customer strong { display:block; color:var(--ink); }
    .poc-customer span { display:block; font-size:.78rem; color:#6b7280; }

    .poc-submit { display:flex; align-items:center; justify-content:center; gap:10px;
                  padding:14px; border:0; border-radius:12px; cursor:pointer;
                  background:var(--green-primary); color:#fff; font-weight:800; font-size:1rem;
                  box-shadow:0 4px 12px -3px rgba(46,125,50,.4); }
    .poc-submit:hover { background:var(--green-dark); }
    .poc-paynote { text-align:center; font-size:.78rem; color:#6b7280; margin:0; }

    .poc-summary { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; position:sticky; top:80px; }
    .poc-summary h3 { font-size:.95rem; font-weight:800; margin-bottom:10px; color:var(--ink); }
    .poc-summary__row { display:flex; gap:8px; padding:6px 0; font-size:.85rem; align-items:baseline; }
    .poc-summary__qty { font-weight:700; color:var(--green-primary); min-width:24px; }
    .poc-summary__name { flex:1; min-width:0; }
    .poc-summary__price { font-weight:700; color:var(--ink); font-variant-numeric:tabular-nums; }
    .poc-summary__note { font-size:.74rem; color:#6b7280; padding-inline-start:32px; padding-bottom:4px; }
    .poc-summary__total { display:flex; justify-content:space-between; font-weight:800; font-size:1rem; color:var(--green-dark); padding-top:6px; }
</style>
@endpush

@push('scripts')
<script>
    // Toggle the address fieldset + active class as the user picks a type.
    // Plain DOM — no Alpine on the portal layout, no extra dependency.
    (function () {
        const types = document.getElementById('poc-types');
        const addr  = document.getElementById('poc-address');
        if (!types || !addr) return;

        const feeRow      = document.getElementById('poc-fee-row');
        const totalEl     = document.getElementById('poc-total-display');
        const subtotalNum = parseFloat(document.getElementById('poc-subtotal')?.dataset.value || '0');
        const deliveryNum = parseFloat(document.getElementById('poc-delivery')?.dataset.value || '0');

        const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        types.addEventListener('change', (e) => {
            if (e.target.name !== 'order_type') return;
            const isDelivery = e.target.value === 'delivery';

            addr.style.display = isDelivery ? '' : 'none';
            if (feeRow) feeRow.style.display = isDelivery ? '' : 'none';
            if (totalEl) totalEl.textContent = fmt(subtotalNum + (isDelivery ? deliveryNum : 0));

            // Mark active card
            types.querySelectorAll('.poc-type').forEach(el => el.classList.remove('is-active'));
            e.target.closest('.poc-type')?.classList.add('is-active');
        });
    })();
</script>
@endpush
@endsection
