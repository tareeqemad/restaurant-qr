<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><title>{{ $invoice->number }}</title>
<style>
body { font-family: 'Tajawal', Arial, sans-serif; max-width: 380px; margin: 0 auto; padding: 1rem; font-size: 13px; }
.center { text-align: center; }
h1 { margin: .5rem 0; font-size: 1.2rem; }
table { width: 100%; border-collapse: collapse; margin: .5rem 0; }
th, td { padding: 4px 6px; }
.tbl-items th { border-bottom: 1px dashed #333; text-align: right; }
.tbl-items td { border-bottom: 1px dotted #ccc; }
.totals td { padding: 3px 0; }
.totals .lbl { text-align: right; }
.totals .val { text-align: left; font-weight: bold; }
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
        ? 'طاولة '.($invoice->tableSession?->table?->number ?? '—')
        : (($invoice->order?->order_type === 'delivery' ? 'دليفري' : 'استلام/سفري').' - '.($invoice->order?->sourceLabel() ?? 'طلب مباشر'));
    $siteName = \App\Helpers\Brand::name();
    $legalName = \App\Models\Setting::get('legal_name');
    $taxNumber = \App\Models\Setting::get('tax_number');
    $currencySymbol = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol'));
    $receiptFooter = \App\Models\Setting::get('receipt_footer', 'شكراً لزيارتكم');
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
@foreach($invoiceOrders as $order)
    @foreach($order->items as $it)
        @if($it->status !== 'cancelled')
        <tr>
            <td>{{ $it->name_snapshot }}
                @if($it->modifiers->count())<br><small>{{ $it->modifiers->pluck('name_snapshot')->join('، ') }}</small>@endif
            </td>
            <td>{{ $it->quantity }}</td>
            <td>{{ number_format($it->unit_price + $it->modifiers_total, 2) }}</td>
            <td>{{ number_format($it->subtotal, 2) }}</td>
        </tr>
        @endif
    @endforeach
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
</table>
@if($invoice->payments->count())
<hr>
<div>الدفعات:</div>
@foreach($invoice->payments as $p)
    <div>{{ $p->method }} — {{ number_format($p->amount, 2) }}</div>
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
