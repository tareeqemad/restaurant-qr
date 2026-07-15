<!DOCTYPE html>
@php
    $market = \App\Support\MarketProfile::class;
    $receiptFont = $market::isUs() ? 'Arial, sans-serif' : 'Tajawal, Arial, sans-serif';
@endphp
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
<meta charset="UTF-8"><title>{{ $invoice->number }}</title>
<style>
body { font-family: {!! $receiptFont !!}; max-width: 380px; margin: 0 auto; padding: 1rem; font-size: 13px; }
.center { text-align: center; }
h1 { margin: .5rem 0; font-size: 1.2rem; }
table { width: 100%; border-collapse: collapse; margin: .5rem 0; }
th, td { padding: 4px 6px; }
.tbl-items th { border-bottom: 1px dashed #333; text-align: start; }
.tbl-items td { border-bottom: 1px dotted #ccc; }
.totals td { padding: 3px 0; }
.totals .lbl { text-align: start; }
.totals .val { text-align: end; font-weight: bold; }
.grand { border-top: 2px solid #000; border-bottom: 2px solid #000; padding: .5rem 0 !important; font-size: 1.1rem; }
hr { border: 0; border-top: 1px dashed #333; margin: .5rem 0; }
.footer { text-align: center; margin-top: 1rem; color: #666; font-size: 11px; }
@media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head><body>
@php
    $invoiceOrders = $invoice->tableSession
        ? $invoice->tableSession->orders
        : collect([$invoice->order])->filter();
    $originLabel = $invoice->tableSession
        ? 'طاولة '.$invoice->tableLabel()
        : (($invoice->order?->order_type === 'delivery' ? 'دليفري' : 'استلام/سفري').' - '.($invoice->order?->sourceLabel() ?? 'طلب مباشر'));
    $siteName = \App\Helpers\Brand::name();
    $legalName = \App\Models\Setting::get('legal_name');
    $taxNumber = \App\Models\Setting::get('tax_number');
    $currencySymbol = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol'));
    $receiptFooter = \App\Models\Setting::get('receipt_footer', 'شكراً لزيارتكم');

    // Same qty format as the KDS/cashier screens: «×2» whole, «×1.5» fractional.
    $fmtQty = function ($qty): string {
        $qty = (float) $qty;
        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    };

    // ─── Which items does the invoice snapshot actually cover? ─────────
    // issueInvoice froze the totals at issued_at and nothing recomputes
    // them when an order/item lands later (only a discount resync does).
    // Printing late items inside the billed list next to the OLD totals
    // is a lie — so they go under a separate «طلبات غير مفوترة» section.
    // Self-heal: if all items reconcile with the invoice subtotal, a
    // later resync absorbed them, and everything prints as billed —
    // mirroring exactly what the screen totals say, never inventing.
    $issuedAt = $invoice->issued_at ?? $invoice->created_at;
    $activeItems = $invoiceOrders->flatMap(fn ($o) => $o->items)->filter(fn ($it) => $it->status !== 'cancelled')->values();
    $unbilledItems = $activeItems->filter(fn ($it) => $issuedAt && $it->created_at && $it->created_at->gt($issuedAt))->values();
    if ($unbilledItems->isNotEmpty()
        && abs($activeItems->sum(fn ($it) => (float) $it->subtotal) - (float) $invoice->subtotal) <= 0.011) {
        $unbilledItems = collect();
    }
    $billedItems = $activeItems->reject(fn ($it) => $unbilledItems->contains(fn ($u) => $u->id === $it->id))->values();

    // Split-payment label lives in the payment notes («دفعة جزء: …»).
    $splitNotePrefix = 'دفعة جزء: ';
@endphp
<div class="center">
    <h1>{{ $siteName }}</h1>
    @if($legalName)<div>{{ $legalName }}</div>@endif
    @if($taxNumber)<div>الرقم الضريبي: <span dir="ltr">{{ $taxNumber }}</span></div>@endif
    <div>فاتورة ضريبية</div>
    <div><strong>{{ $invoice->number }}</strong></div>
    <div>{{ $invoice->issued_at?->format('Y-m-d H:i') }}</div>
    <div>{{ $originLabel }}</div>
    @if($invoice->customer_name || $invoice->customer_phone)
        <div>{{ $invoice->customer_name }} @if($invoice->customer_phone) - <span dir="ltr">{{ $invoice->customer_phone }}</span>@endif</div>
    @endif
</div>
<hr>
<table class="tbl-items">
<thead><tr><th>الصنف</th><th>كم</th><th>سعر</th><th>إجمالي</th></tr></thead>
<tbody>
@foreach($billedItems as $it)
        <tr>
            <td>{{ $it->name_snapshot }}
                @if($it->modifiers->count())<br><small>{{ $it->modifiers->pluck('name_snapshot')->join('، ') }}</small>@endif
                @if($it->wasDiscounted())
                    <br><small style="color:#b91c1c;">
                        🏷️ خصم: −{{ number_format($it->discountSavings(), 2) }}
                        (كان {{ number_format((float) $it->unit_price_original, 2) }})
                    </small>
                @endif
            </td>
            <td>×{{ $fmtQty($it->quantity) }}</td>
            <td>{{ number_format($it->unit_price + $it->modifiers_total, 2) }}</td>
            <td>{{ number_format($it->subtotal, 2) }}</td>
        </tr>
@endforeach
</tbody></table>
<hr>
<table class="totals">
<tr><td class="lbl">المجموع الفرعي:</td><td class="val">{{ number_format($invoice->subtotal, 2) }}</td></tr>
@if($invoice->discount_total > 0)<tr><td class="lbl">خصم:</td><td class="val">-{{ number_format($invoice->discount_total, 2) }}</td></tr>@endif
@if($invoice->tax_total > 0)<tr><td class="lbl">الضريبة:</td><td class="val">{{ number_format($invoice->tax_total, 2) }}</td></tr>@endif
@if($invoice->service_total > 0)<tr><td class="lbl">الخدمة:</td><td class="val">{{ number_format($invoice->service_total, 2) }}</td></tr>@endif
@if($invoice->delivery_fee > 0)<tr><td class="lbl">رسوم التوصيل:</td><td class="val">{{ number_format($invoice->delivery_fee, 2) }}</td></tr>@endif
@if($invoice->tip > 0)<tr><td class="lbl">إكرامية:</td><td class="val">{{ number_format($invoice->tip, 2) }}</td></tr>@endif
<tr class="grand"><td class="lbl">الإجمالي:</td><td class="val">{{ number_format($invoice->total, 2) }} {{ $currencySymbol }}</td></tr>
{{-- Money trail — a debt or refund customer walking off with a paper that
     shows only «الإجمالي» thinks they're square. Only printed once money
     actually moved (a plain pre-payment bill stays uncluttered). --}}
@if($invoice->payments->count() || (float) ($invoice->refunded_total ?? 0) > 0 || $invoice->settled_on_account_at)
<tr><td class="lbl">المدفوع:</td><td class="val">{{ number_format((float) $invoice->paid_total, 2) }}</td></tr>
@if((float) ($invoice->refunded_total ?? 0) > 0)<tr><td class="lbl">المسترد:</td><td class="val">−{{ number_format((float) $invoice->refunded_total, 2) }}</td></tr>@endif
<tr><td class="lbl">المتبقي:</td><td class="val">{{ number_format((float) $invoice->balance, 2) }}</td></tr>
@endif
</table>
@if($invoice->settled_on_account_at && (float) $invoice->balance > 0.001)
<div class="center" style="border:1px dashed #333; padding:4px; margin:.4rem 0;">
    المتبقي مُؤجَّل كدين على حساب الزبون بتاريخ {{ $invoice->settled_on_account_at->format('Y-m-d') }}
</div>
@endif

{{-- Items keyed in AFTER the invoice was issued — the totals above don't
     include them, so they must never sit silently in the billed list. --}}
@if($unbilledItems->isNotEmpty())
<hr>
<div><strong>طلبات غير مفوترة</strong> <small>(أُضيفت بعد إصدار الفاتورة)</small></div>
<table class="tbl-items">
<tbody>
@foreach($unbilledItems as $it)
        <tr>
            <td>{{ $it->name_snapshot }}
                @if($it->modifiers->count())<br><small>{{ $it->modifiers->pluck('name_snapshot')->join('، ') }}</small>@endif
            </td>
            <td>×{{ $fmtQty($it->quantity) }}</td>
            <td>{{ number_format($it->unit_price + $it->modifiers_total, 2) }}</td>
            <td>{{ number_format($it->subtotal, 2) }}</td>
        </tr>
@endforeach
</tbody></table>
<div><small>غير مشمولة في الإجمالي أعلاه — تُحصَّل على حدة.</small></div>
@endif
@if($invoice->payments->count())
<hr>
<div>الدفعات:</div>
@foreach($invoice->payments as $p)
    @php
        $splitLabel = str_starts_with((string) $p->notes, $splitNotePrefix)
            ? trim(mb_substr($p->notes, mb_strlen($splitNotePrefix)))
            : null;
    @endphp
    <div>
        {{ \App\Support\PaymentMethods::label($p->method) }}@if($splitLabel) ({{ $splitLabel }})@endif
        — {{ number_format($p->amount, 2) }}@if($p->reference) <span dir="ltr">[{{ $p->reference }}]</span>@endif
    </div>
@endforeach
@endif
@if($receiptFooter)
<div class="footer">{{ $receiptFooter }}</div>
@endif
<div class="no-print center" style="margin-top:1rem">
<button onclick="window.print()" style="padding:.5rem 1rem; background:#b91c1c; color:white; border:0; border-radius:4px;">طباعة</button>
</div>
<script>window.onload = () => window.print();</script>
</body></html>
