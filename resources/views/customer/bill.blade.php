@extends('customer.layout')
@section('title', 'الفاتورة')

@push('styles')
<style>
.bill-wrap { max-width: 560px; margin: 1.5rem auto; padding: 0 1rem 6rem; }

/* Main bill card */
.bill-card {
    background: white;
    border-radius: 24px;
    box-shadow: 0 12px 40px rgba(31,71,51,.12), 0 0 0 1px rgba(184,135,42,.08);
    overflow: hidden;
    position: relative;
}
.bill-card::before {
    content: ''; position: absolute; top: 0; right: 0; left: 0; height: 4px;
    background: linear-gradient(90deg, var(--accent) 0%, var(--brand) 50%, var(--accent) 100%);
}

/* Bill header — decorative with logo + receipt number */
.bill-head {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    padding: 1.75rem 1.25rem 1.25rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.bill-head::before,
.bill-head::after {
    content: ''; position: absolute; border-radius: 50%;
    background: radial-gradient(circle, rgba(184,135,42,.25), transparent 70%);
    pointer-events: none;
}
.bill-head::before { width: 160px; height: 160px; top: -60px; right: -40px; }
.bill-head::after  { width: 140px; height: 140px; bottom: -60px; left: -40px; }

.bill-head-inner { position: relative; z-index: 1; }

.bill-icon {
    width: 62px; height: 62px;
    background: var(--accent);
    color: var(--brand-dark);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.75rem;
    margin-bottom: .5rem;
    box-shadow: 0 6px 20px rgba(0,0,0,.2), inset 0 -3px 6px rgba(0,0,0,.1);
}
.bill-title {
    font-size: 1.3rem;
    font-weight: 900;
    margin: .25rem 0 .2rem;
    letter-spacing: -.01em;
}
.bill-meta {
    font-size: .82rem;
    opacity: .9;
    display: flex;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.bill-meta .sep { opacity: .5; }
.bill-table-num {
    background: var(--accent);
    color: var(--brand-dark);
    padding: 2px 10px;
    border-radius: 99px;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

/* Orders */
.bill-orders { padding: 1rem 0 0; }
.bill-order-group {
    padding: 0 1.25rem;
    margin-bottom: 1rem;
}
.bill-order-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .6rem .8rem;
    background: linear-gradient(90deg, rgba(31,71,51,.05), transparent);
    border-right: 3px solid var(--accent);
    border-radius: 8px;
    margin-bottom: .5rem;
}
.bill-order-num {
    font-family: 'Courier New', monospace;
    font-weight: 800;
    color: var(--brand-dark);
    font-size: .85rem;
}
.bill-order-time { color: var(--muted); font-size: .75rem; }

.bill-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: .7rem .8rem;
    border-bottom: 1px dashed rgba(31,71,51,.08);
}
.bill-item:last-child { border-bottom: 0; }
.bill-item.cancelled { opacity: .4; text-decoration: line-through; }

.bill-item-body { flex: 1; min-width: 0; }
.bill-item-name {
    font-weight: 700;
    color: var(--brand-dark);
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.bill-item-qty-pill {
    background: rgba(184,135,42,.15);
    color: var(--accent-dark);
    padding: 1px 8px;
    border-radius: 99px;
    font-size: .72rem;
    font-weight: 900;
}
.bill-item-modifiers {
    margin-top: 4px;
    font-size: .78rem;
    color: var(--muted);
    line-height: 1.5;
}
.bill-item-modifiers i { color: var(--accent); margin-inline-end: 4px; }

.bill-item-price {
    font-weight: 800;
    color: var(--brand-dark);
    font-family: 'Courier New', monospace;
    white-space: nowrap;
    margin-inline-start: .5rem;
}

/* Totals */
.bill-totals {
    background: linear-gradient(180deg, transparent, rgba(184,135,42,.05));
    padding: 1rem 1.25rem 1.25rem;
    border-top: 2px dashed rgba(184,135,42,.3);
}
.bill-row {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: .92rem;
    color: var(--muted);
    font-weight: 600;
}
.bill-row span:last-child { color: var(--brand-dark); font-weight: 700; font-family: 'Courier New', monospace; }

.bill-grand {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: .9rem 1rem;
    margin-top: .75rem;
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    color: white;
    border-radius: 14px;
    box-shadow: 0 6px 20px rgba(31,71,51,.25);
}
.bill-grand-label {
    font-size: .9rem;
    font-weight: 700;
    opacity: .9;
}
.bill-grand-amount {
    font-size: 1.6rem;
    font-weight: 900;
    color: var(--accent);
    font-family: 'Courier New', monospace;
    letter-spacing: -.02em;
}

/* Waiter call-out */
.bill-note {
    margin-top: 1rem;
    padding: 1rem 1.1rem;
    background: linear-gradient(135deg, rgba(184,135,42,.08), rgba(31,71,51,.04));
    border: 1px solid rgba(184,135,42,.25);
    border-right: 4px solid var(--accent);
    border-radius: 14px;
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    color: var(--brand-dark);
    font-size: .88rem;
    line-height: 1.6;
}
.bill-note i {
    color: var(--accent);
    font-size: 1.2rem;
    flex-shrink: 0;
    margin-top: 1px;
}
.bill-note strong { color: var(--brand-dark); display: block; margin-bottom: 2px; }

.bill-status {
    margin: 1rem 0;
    padding: .95rem 1rem;
    border-radius: 14px;
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    font-size: .88rem;
    line-height: 1.6;
}
.bill-status i { font-size: 1.15rem; flex-shrink: 0; margin-top: 2px; }
.bill-status--waiting {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #7c2d12;
}
.bill-status--issued {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    color: #14532d;
}
.bill-request-form {
    margin-top: 1rem;
    display: grid;
    gap: .65rem;
}
.bill-request-form textarea {
    width: 100%;
    min-height: 72px;
    resize: vertical;
    border: 1px solid rgba(31,71,51,.14);
    border-radius: 12px;
    padding: .8rem .9rem;
    font-family: inherit;
    background: #fff;
}
.bill-request-form textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(184,135,42,.12);
}
.bill-request-btn {
    border: 0;
    border-radius: 14px;
    padding: .9rem 1rem;
    background: linear-gradient(135deg, var(--brand), var(--brand-dark));
    color: #fff;
    font-weight: 900;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    box-shadow: 0 10px 26px rgba(31,71,51,.24);
}
.bill-request-btn:disabled {
    opacity: .65;
    box-shadow: none;
}

/* Decorative receipt-bottom scallop */
.bill-scallop {
    height: 14px;
    background:
        radial-gradient(circle at 10px 0, transparent 8px, var(--bg) 9px),
        linear-gradient(180deg, white, white);
    background-size: 20px 100%;
    background-repeat: repeat-x;
    background-position: top;
}

/* Empty state */
.bill-empty {
    text-align: center;
    padding: 3rem 1.5rem;
}
.bill-empty-icon {
    width: 80px; height: 80px;
    background: rgba(184,135,42,.1);
    border: 2px dashed rgba(184,135,42,.3);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--accent);
    font-size: 2rem;
    margin-bottom: 1rem;
}
.bill-empty h4 { color: var(--brand-dark); font-weight: 900; margin-bottom: .5rem; }
.bill-empty p { color: var(--muted); margin: 0; }
</style>
@endpush

@section('content')
@php
    $hasActiveInvoice = $invoice && ! in_array($invoice->status, ['cancelled'], true);
    $billRequested = $session->bill_requested_at && ! $hasActiveInvoice;
@endphp
<div class="bill-wrap">
    <div class="bill-card">
        {{-- Header --}}
        <div class="bill-head">
            <div class="bill-head-inner">
                <div class="bill-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <h3 class="bill-title">فاتورتك</h3>
                <div class="bill-meta">
                    @if(!empty($session?->table?->number))
                        <span class="bill-table-num">
                            <i class="bi bi-grid-3x3-gap-fill"></i>
                            طاولة {{ $session->table->number }}
                        </span>
                        <span class="sep">•</span>
                    @endif
                    <span>{{ now()->format('Y-m-d · H:i') }}</span>
                    @if($orders->isNotEmpty())
                        <span class="sep">•</span>
                        <span>{{ $orders->count() }} {{ $orders->count() === 1 ? 'طلب' : 'طلبات' }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if($hasActiveInvoice)
            <div class="bill-status bill-status--issued" style="margin-inline:1.25rem;">
                <i class="bi bi-check-circle-fill"></i>
                <div>
                    <strong>الفاتورة صدرت للكاشير</strong>
                    رقم الفاتورة {{ $invoice->number }}.
                    @if((float) $invoice->balance > 0)
                        المتبقي للدفع: <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>.
                    @else
                        تم تسجيل الدفع وسيتم إغلاق الجلسة قريباً.
                    @endif
                </div>
            </div>
        @elseif($billRequested)
            <div class="bill-status bill-status--waiting" style="margin-inline:1.25rem;">
                <i class="bi bi-hourglass-split"></i>
                <div>
                    <strong>طلب الفاتورة وصل للكاشير</strong>
                    تم الإرسال {{ $session->bill_requested_at->diffForHumans() }}. يرجى انتظار الجرسون أو الكاشير.
                </div>
            </div>
        @endif

        @if($orders->isEmpty())
            <div class="bill-empty">
                <div class="bill-empty-icon">
                    <i class="bi bi-receipt"></i>
                </div>
                <h4>لا توجد طلبات بعد</h4>
                <p>لم تقم بأي طلب على هذه الطاولة حتى الآن.</p>
            </div>
        @else
            {{-- Orders --}}
            <div class="bill-orders">
                @foreach($orders as $order)
                    <div class="bill-order-group">
                        <div class="bill-order-head">
                            <div class="bill-order-num">
                                <i class="bi bi-hash"></i>
                                {{ $order->number }}
                            </div>
                            <div class="bill-order-time">
                                {{ $order->created_at->diffForHumans() }}
                            </div>
                        </div>

                        @foreach($order->items as $it)
                            <div class="bill-item {{ $it->status === 'cancelled' ? 'cancelled' : '' }}">
                                <div class="bill-item-body">
                                    <div class="bill-item-name">
                                        <span class="bill-item-qty-pill">×{{ $it->quantity }}</span>
                                        {{ $it->name_snapshot }}
                                        @if($it->status === 'cancelled')
                                            <span style="color:var(--danger); font-size:.72rem; font-weight:800;">(ملغى)</span>
                                        @endif
                                    </div>
                                    @php
                                        $mods = $it->modifiers ?? collect();
                                    @endphp
                                    @if($mods->count())
                                        <div class="bill-item-modifiers">
                                            <i class="bi bi-plus-circle-fill"></i>
                                            {{ $mods->pluck('name_snapshot')->filter()->join(' • ') }}
                                        </div>
                                    @endif
                                    @if(!empty($it->notes))
                                        <div class="bill-item-modifiers">
                                            <i class="bi bi-chat-left-text-fill"></i>
                                            {{ $it->notes }}
                                        </div>
                                    @endif
                                </div>
                                <div class="bill-item-price">
                                    {{ \App\Helpers\Money::format($it->subtotal) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- Totals --}}
            <div class="bill-totals">
                <div class="bill-row">
                    <span>الفرعي</span>
                    <span>{{ \App\Helpers\Money::format($totals['subtotal']) }}</span>
                </div>
                @if(!empty($totals['tax']) && $totals['tax'] > 0)
                    <div class="bill-row">
                        <span>الضريبة</span>
                        <span>{{ \App\Helpers\Money::format($totals['tax']) }}</span>
                    </div>
                @endif
                @if(!empty($totals['service']) && $totals['service'] > 0)
                    <div class="bill-row">
                        <span>الخدمة</span>
                        <span>{{ \App\Helpers\Money::format($totals['service']) }}</span>
                    </div>
                @endif

                <div class="bill-grand">
                    <span class="bill-grand-label">الإجمالي</span>
                    <span class="bill-grand-amount">{{ \App\Helpers\Money::format($totals['total']) }}</span>
                </div>
            </div>
        @endif
    </div>

    @if($orders->isNotEmpty())
        <div class="bill-note">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <strong>للدفع والمغادرة</strong>
                اطلب من الجرسون استلام الفاتورة والدفع. شكراً لاختيارك {{ config('restaurant.name') }} ✨
            </div>
        </div>
    @endif
    @if(! $hasActiveInvoice)
        <form method="POST" action="{{ route('customer.bill.request') }}" class="bill-request-form">
            @csrf
            <textarea name="note" maxlength="500" placeholder="ملاحظة اختيارية للكاشير: طريقة دفع، استعجال، تقسيم الفاتورة...">{{ old('note', $session->bill_request_note) }}</textarea>
            <button class="bill-request-btn" {{ $billRequested ? 'disabled' : '' }}>
                <i class="bi {{ $billRequested ? 'bi-check2-circle' : 'bi-receipt-cutoff' }}"></i>
                {{ $billRequested ? 'تم طلب الفاتورة' : ($orders->isEmpty() ? 'طلب إنهاء الجلسة' : 'طلب الفاتورة من الكاشير') }}
            </button>
        </form>
    @endif
</div>
@endsection
