<!DOCTYPE html>
@php
    $market = \App\Support\MarketProfile::class;
    $receiptFont = $market::isUs() ? 'Arial, sans-serif' : 'DejaVu Sans, sans-serif';
@endphp
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
<meta charset="UTF-8"><title>{{ $invoice->number }}</title>
<style>
body { font-family: {!! $receiptFont !!}; font-size: 12px; }
.center { text-align: center; }
h1 { margin: 0.5rem 0; font-size: 18px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
th { background: #f5f5f5; }
.totals td { border: 0; padding: 3px; }
.lbl { text-align: start; } .val { text-align: end; font-weight: bold; }
.grand { border-top: 2px solid #000; border-bottom: 2px solid #000; font-size: 14px; }
hr { border: 0; border-top: 1px dashed #333; }
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

    // Billed vs post-invoice items — identical logic to invoice-print (see
    // the WHY there): the invoice totals froze at issued_at, so later items
    // print under a separate «طلبات غير مفوترة» section unless a discount
    // resync already absorbed them into the totals.
    $issuedAt = $invoice->issued_at ?? $invoice->created_at;
    $activeItems = $invoiceOrders->flatMap(fn ($o) => $o->items)->filter(fn ($it) => $it->status !== 'cancelled')->values();
    $unbilledItems = $activeItems->filter(fn ($it) => $issuedAt && $it->created_at && $it->created_at->gt($issuedAt))->values();
    if ($unbilledItems->isNotEmpty()
        && abs($activeItems->sum(fn ($it) => (float) $it->subtotal) - (float) $invoice->subtotal) <= 0.011) {
        $unbilledItems = collect();
    }
    $billedItems = $activeItems->reject(fn ($it) => $unbilledItems->contains(fn ($u) => $u->id === $it->id))->values();

    $splitNotePrefix = 'دفعة جزء: ';
@endphp
<div class="center">
<h1>{{ $siteName }}</h1>
@if($legalName)<p>{{ $legalName }}</p>@endif
@if($taxNumber)<p>الرقم الضريبي: <span dir="ltr">{{ $taxNumber }}</span></p>@endif
<p>فاتورة ضريبية #{{ $invoice->number }} — {{ $invoice->issued_at?->format('Y-m-d H:i') }}</p>
<p>{{ $originLabel }}</p>
@if($invoice->customer_name || $invoice->customer_phone)
    <p>{{ $invoice->customer_name }} @if($invoice->customer_phone) - <span dir="ltr">{{ $invoice->customer_phone }}</span>@endif</p>
@endif
</div>
<hr>
<table>
<thead><tr><th>الصنف</th><th>كمية</th><th>سعر</th><th>إجمالي</th></tr></thead>
<tbody>
@foreach($billedItems as $it)
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
<br>
<table class="totals">
<tr><td class="lbl">الفرعي:</td><td class="val">{{ number_format($invoice->subtotal, 2) }}</td></tr>
@if($invoice->discount_total > 0)<tr><td class="lbl">خصم:</td><td class="val">-{{ number_format($invoice->discount_total, 2) }}</td></tr>@endif
@if($invoice->tax_total > 0)<tr><td class="lbl">الضريبة:</td><td class="val">{{ number_format($invoice->tax_total, 2) }}</td></tr>@endif
@if($invoice->service_total > 0)<tr><td class="lbl">الخدمة:</td><td class="val">{{ number_format($invoice->service_total, 2) }}</td></tr>@endif
@if($invoice->delivery_fee > 0)<tr><td class="lbl">رسوم التوصيل:</td><td class="val">{{ number_format($invoice->delivery_fee, 2) }}</td></tr>@endif
<tr class="grand"><td class="lbl">الإجمالي:</td><td class="val">{{ number_format($invoice->total, 2) }} {{ $currencySymbol }}</td></tr>
{{-- Money trail — mirrors invoice-print: debt/refund customers must see
     what was actually paid and what is still owed, not just the total. --}}
@if($invoice->payments->count() || (float) ($invoice->refunded_total ?? 0) > 0 || $invoice->settled_on_account_at)
<tr><td class="lbl">المدفوع:</td><td class="val">{{ number_format((float) $invoice->paid_total, 2) }}</td></tr>
@if((float) ($invoice->refunded_total ?? 0) > 0)<tr><td class="lbl">المسترد:</td><td class="val">−{{ number_format((float) $invoice->refunded_total, 2) }}</td></tr>@endif
<tr><td class="lbl">المتبقي:</td><td class="val">{{ number_format((float) $invoice->balance, 2) }}</td></tr>
@endif
</table>
@if($invoice->settled_on_account_at && (float) $invoice->balance > 0.001)
<p class="center" style="border:1px dashed #333; padding:4px;">
    المتبقي مُؤجَّل كدين على حساب الزبون بتاريخ {{ $invoice->settled_on_account_at->format('Y-m-d') }}
</p>
@endif

@if($unbilledItems->isNotEmpty())
<hr>
<p><strong>طلبات غير مفوترة</strong> <small>(أُضيفت بعد إصدار الفاتورة — غير مشمولة في الإجمالي أعلاه)</small></p>
<table>
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
@endif

@if($invoice->payments->count())
<hr>
<p><strong>الدفعات:</strong></p>
<table class="totals">
@foreach($invoice->payments as $p)
    @php
        $splitLabel = str_starts_with((string) $p->notes, $splitNotePrefix)
            ? trim(mb_substr($p->notes, mb_strlen($splitNotePrefix)))
            : null;
    @endphp
    <tr>
        <td class="lbl">{{ \App\Support\PaymentMethods::label($p->method) }}@if($splitLabel) ({{ $splitLabel }})@endif @if($p->reference)<span dir="ltr">[{{ $p->reference }}]</span>@endif</td>
        <td class="val">{{ number_format((float) $p->amount, 2) }}</td>
    </tr>
@endforeach
</table>
@endif
@if($receiptFooter)
<p class="center" style="margin-top:14px; color:#555;">{{ $receiptFooter }}</p>
@endif
</body></html>
