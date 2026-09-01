<!DOCTYPE html>
@php
    $market = \App\Support\MarketProfile::class;
    $theme = \App\Support\ThemePalette::current();
@endphp
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة {{ $invoice->number }}</title>
    <style>
        @page { margin: 20mm 17mm 18mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #243129; text-align: right; font-family: tajawal, sans-serif; font-size: 10px; line-height: 1.55; }
        .invoice-a4 { width: 100%; }
        .a4-head { width: 100%; margin-bottom: 18px; border-collapse: collapse; }
        .a4-head td { vertical-align: middle; }
        .brand-mark-cell { width: 48px; }
        .brand-mark {
            width: 42px;
            height: 42px;
            border: 1px solid {!! $theme['accent'] !!};
            border-radius: 12px;
            background: {!! $theme['primary'] !!};
            color: #fff;
            font-size: 20px;
            font-weight: 700;
            line-height: 42px;
            text-align: center;
        }
        .brand-copy h1 { margin: 0; color: {!! $theme['dark'] !!}; font-size: 20px; line-height: 1.35; }
        .brand-copy p { margin: 2px 0 0; color: #68756e; font-size: 8.5px; }
        .document-copy { width: 235px; text-align: start; }
        .document-copy h2 { margin: 0; color: {!! $theme['primary'] !!}; font-size: 19px; }
        .document-number { margin-top: 3px; color: #28352e; unicode-bidi: plaintext; font-family: tajawal, sans-serif; font-size: 9px; }
        .status-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 10px;
            border: 1px solid #bdcdc3;
            border-radius: 20px;
            background: #f5f8f6;
            color: #43564b;
            font-size: 8px;
            font-weight: 700;
        }
        .status-badge.is-paid { border-color: #88c19d; background: #edf8f1; color: #176238; }
        .status-badge.is-cancelled, .status-badge.is-unpaid_writeoff { border-color: #df9b9b; background: #fff2f2; color: #9d2525; }
        .accent-line { height: 3px; margin-bottom: 15px; background: {!! $theme['primary'] !!}; }
        .meta-table { width: 100%; margin-bottom: 17px; border-collapse: separate; border-spacing: 6px; }
        .meta-table td { width: 25%; padding: 9px 10px; border: 1px solid #dce5df; border-radius: 8px; background: #f8faf8; vertical-align: top; }
        .meta-table span { display: block; margin-bottom: 3px; color: #748078; font-size: 7.5px; }
        .meta-table strong { display: block; color: #25342b; font-size: 9px; }
        .section-title { margin: 0 0 7px; color: {!! $theme['dark'] !!}; font-size: 11px; font-weight: 700; }
        .items-table { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
        .items-table thead { display: table-header-group; }
        .items-table tr { page-break-inside: avoid; }
        .items-table th { padding: 8px 7px; border-bottom: 2px solid {!! $theme['primary'] !!}; background: {!! $theme['primary_soft'] !!}; color: {!! $theme['dark'] !!}; font-size: 8px; text-align: right; }
        .items-table td { padding: 8px 7px; border-bottom: 1px solid #e1e7e3; vertical-align: top; }
        .items-table th:first-child, .items-table td:first-child { width: 25px; text-align: center; color: #77837b; }
        .items-table th:not(:nth-child(2)), .items-table td:not(:nth-child(2)) { white-space: nowrap; }
        .items-table th:nth-child(n+3), .items-table td:nth-child(n+3) { text-align: left; }
        .item-name { color: #17251d; font-weight: 700; }
        .item-detail { display: block; margin-top: 2px; color: #748078; font-size: 7.5px; }
        .item-discount { color: #9a5c08; }
        .summary-layout { width: 100%; margin-top: 4px; border-collapse: collapse; page-break-inside: avoid; }
        .summary-layout td { vertical-align: top; }
        .summary-side { width: 51%; padding-left: 16px; }
        .summary-total { width: 49%; }
        .customer-card, .payment-card, .notice-card { margin-bottom: 10px; padding: 10px 11px; border: 1px solid #dde5e0; border-radius: 8px; background: #fbfcfb; }
        .customer-card strong, .payment-card strong, .notice-card strong { display: block; margin-bottom: 3px; color: {!! $theme['dark'] !!}; font-size: 9px; font-weight: 700; }
        .customer-card span, .notice-card span { color: #607067; font-size: 8px; }
        .totals-table, .payments-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 4px 0; }
        .totals-table td:last-child, .payments-table td:last-child { text-align: left; white-space: nowrap; font-weight: 700; }
        .totals-table .grand td { padding: 9px 0; border-top: 2px solid {!! $theme['primary'] !!}; border-bottom: 2px solid {!! $theme['primary'] !!}; color: {!! $theme['dark'] !!}; font-size: 13px; font-weight: 700; }
        .totals-table .balance td { color: #9a3412; font-weight: 700; }
        .payments-table td { padding: 4px 0; border-bottom: 1px solid #e2e7e4; font-size: 8px; }
        .notice-card.is-warning { border-color: #dcad67; background: #fffaf1; }
        .notice-card.is-danger { border-color: #dd9696; background: #fff4f4; color: #922626; }
        .unbilled { margin-top: 16px; padding: 11px; border: 1px solid #d99a47; border-radius: 8px; background: #fffaf2; page-break-inside: avoid; }
        .unbilled h3 { margin: 0 0 3px; color: #8a5309; font-size: 10px; }
        .unbilled p { margin: 0 0 8px; color: #765b37; font-size: 8px; }
        .invoice-footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #dce4df; text-align: center; color: #6d7971; font-size: 8px; }
        .invoice-footer strong { display: block; margin-bottom: 2px; color: {!! $theme['dark'] !!}; font-size: 10px; }
        bdi, .code-value { unicode-bidi: plaintext; }
        bdi { font-family: dejavusans, sans-serif; }
    </style>
</head>
<body>
    @include('admin.cashier._invoice-document', ['documentMode' => 'a4'])
</body>
</html>
