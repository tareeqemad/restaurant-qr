<!DOCTYPE html>
@php
    $market = \App\Support\MarketProfile::class;
    $theme = \App\Support\ThemePalette::current();
@endphp
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إيصال {{ $invoice->number }}</title>
    <link rel="stylesheet" href="{{ $market::fontUrl() }}">
    <style>
        * { box-sizing: border-box; }
        @page { size: 80mm 297mm; margin: 3mm; }
        html, body { margin: 0; padding: 0; }
        body {
            background: #edf2ef;
            color: #17231d;
            font-family: {!! $market::fontFamily() !!};
            font-size: 12px;
            line-height: 1.55;
        }
        .print-toolbar {
            width: min(72mm, calc(100% - 24px));
            margin: 16px auto 10px;
            display: flex;
            gap: 8px;
        }
        .print-toolbar button {
            min-height: 44px;
            border: 1px solid #ced9d2;
            border-radius: 10px;
            padding: 0 16px;
            background: #fff;
            color: #24372d;
            cursor: pointer;
            font: 800 13px/1 {!! $market::fontFamily() !!};
        }
        .print-toolbar .primary {
            flex: 1;
            border-color: {!! $theme['primary'] !!};
            background: {!! $theme['primary'] !!};
            color: #fff;
        }
        .invoice-paper {
            width: min(72mm, calc(100% - 24px));
            min-height: 110mm;
            margin: 0 auto 24px;
            padding: 5mm 4mm;
            background: #fff;
            border-top: 4px solid {!! $theme['primary'] !!};
            box-shadow: 0 18px 46px rgba(15, 45, 34, .13);
        }
        .receipt-head { text-align: center; padding-bottom: 9px; }
        .receipt-mark {
            width: 38px;
            height: 38px;
            margin: 0 auto 6px;
            border: 1px solid {!! $theme['accent'] !!};
            border-radius: 12px;
            background: {!! $theme['primary'] !!};
            color: #fff;
            font-size: 19px;
            font-weight: 900;
            line-height: 38px;
        }
        .receipt-head h1 { margin: 0; color: {!! $theme['dark'] !!}; font-size: 18px; line-height: 1.3; }
        .receipt-head p { margin: 2px 0 0; color: #68766f; font-size: 10px; }
        .receipt-title { margin: 9px 0 3px; font-size: 13px; font-weight: 900; }
        .receipt-number { unicode-bidi: plaintext; font: 800 11px/1.4 Arial, sans-serif; letter-spacing: .25px; }
        .status-badge {
            display: inline-block;
            margin-top: 5px;
            padding: 2px 8px;
            border: 1px solid #cbd8d0;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 800;
        }
        .status-badge.is-paid { border-color: #8bc4a0; color: #126332; }
        .status-badge.is-cancelled, .status-badge.is-unpaid_writeoff { border-color: #e5a4a4; color: #a21f1f; }
        .receipt-rule { border: 0; border-top: 1px dashed #9da9a2; margin: 8px 0; }
        .receipt-meta { width: 100%; border-collapse: collapse; }
        .receipt-meta td { padding: 2px 0; vertical-align: top; }
        .receipt-meta td:first-child { width: 33%; color: #6c7871; }
        .receipt-meta td:last-child { text-align: end; font-weight: 700; }
        .section-label { margin: 0 0 5px; font-size: 10px; color: #5f6e65; font-weight: 900; }
        .items-table, .totals-table, .payments-table { width: 100%; border-collapse: collapse; }
        .items-table { table-layout: fixed; }
        .items-table th {
            padding: 5px 2px;
            border-top: 1px solid #26362d;
            border-bottom: 1px solid #26362d;
            color: #26362d;
            font-size: 9px;
            text-align: start;
        }
        .items-table td { padding: 6px 2px; border-bottom: 1px dotted #c9d1cc; vertical-align: top; }
        .items-table th:not(:first-child), .items-table td:not(:first-child) { text-align: end; white-space: nowrap; }
        .items-table th:first-child, .items-table td:first-child { width: 42%; }
        .items-table th:nth-child(2), .items-table td:nth-child(2) { width: 12%; }
        .items-table th:nth-child(3), .items-table td:nth-child(3) { width: 22%; }
        .items-table th:nth-child(4), .items-table td:nth-child(4) { width: 24%; }
        .item-name { font-weight: 800; }
        .item-detail { display: block; margin-top: 1px; color: #6a766f; font-size: 9px; line-height: 1.4; }
        .item-discount { color: #9b5b05; }
        .totals-table td { padding: 2px 0; }
        .totals-table td:last-child { text-align: end; white-space: nowrap; font-weight: 800; }
        .totals-table .grand td {
            padding: 7px 0;
            border-top: 2px solid #1c2b23;
            border-bottom: 2px solid #1c2b23;
            color: {!! $theme['dark'] !!};
            font-size: 14px;
            font-weight: 900;
        }
        .totals-table .balance td { color: #9a3412; font-weight: 900; }
        .receipt-alert { margin: 8px 0; padding: 7px; border: 1px dashed #bf7622; background: #fffaf0; text-align: center; font-size: 9px; }
        .receipt-alert.is-danger { border-color: #bd3f3f; background: #fff5f5; color: #8f1f1f; text-align: start; }
        .payments-table td { padding: 3px 0; border-bottom: 1px dotted #ccd4cf; vertical-align: top; }
        .payments-table td:last-child { text-align: end; white-space: nowrap; font-weight: 800; }
        .receipt-footer { margin-top: 12px; text-align: center; color: #5e6b63; font-size: 9px; }
        .receipt-footer strong { display: block; color: {!! $theme['dark'] !!}; font-size: 11px; }
        bdi, .code-value { unicode-bidi: plaintext; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .invoice-paper { width: 100%; min-height: 0; margin: 0; padding: 0; border-top: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <button type="button" class="primary" data-print-document>طباعة الإيصال</button>
        <button type="button" data-close-document>إغلاق</button>
    </div>
    <div class="invoice-paper">
        @include('admin.cashier._invoice-document', ['documentMode' => 'receipt'])
    </div>
    <script>
        window.addEventListener('load', () => window.print(), { once: true });
        document.querySelector('[data-print-document]')?.addEventListener('click', () => window.print());
        document.querySelector('[data-close-document]')?.addEventListener('click', () => window.close());
    </script>
</body>
</html>
