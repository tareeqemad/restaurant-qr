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
<br>
<table class="totals">
<tr><td class="lbl">الفرعي:</td><td class="val">{{ number_format($invoice->subtotal, 2) }}</td></tr>
@if($invoice->discount_total > 0)<tr><td class="lbl">خصم:</td><td class="val">-{{ number_format($invoice->discount_total, 2) }}</td></tr>@endif
@if($invoice->tax_total > 0)<tr><td class="lbl">الضريبة:</td><td class="val">{{ number_format($invoice->tax_total, 2) }}</td></tr>@endif
@if($invoice->service_total > 0)<tr><td class="lbl">الخدمة:</td><td class="val">{{ number_format($invoice->service_total, 2) }}</td></tr>@endif
@if($invoice->delivery_fee > 0)<tr><td class="lbl">رسوم التوصيل:</td><td class="val">{{ number_format($invoice->delivery_fee, 2) }}</td></tr>@endif
<tr class="grand"><td class="lbl">الإجمالي:</td><td class="val">{{ number_format($invoice->total, 2) }} {{ $currencySymbol }}</td></tr>
</table>
@if($receiptFooter)
<p class="center" style="margin-top:14px; color:#555;">{{ $receiptFooter }}</p>
@endif
</body></html>
