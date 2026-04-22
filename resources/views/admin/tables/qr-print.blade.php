<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>QR طاولة {{ $table->number }}</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 2rem; text-align: center; }
        .qr-card { border: 3px dashed #b91c1c; padding: 2rem; border-radius: 16px; max-width: 420px; margin: 0 auto; }
        .qr-card svg { width: 100%; height: auto; max-width: 360px; }
        h1 { color: #b91c1c; }
        h2 { font-size: 1.3rem; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="qr-card">
        <h1>{{ config('restaurant.name') }}</h1>
        <h2>طاولة رقم {{ $table->number }}</h2>
        <p>امسح الرمز لبدء الطلب</p>
        <div>{!! $svg !!}</div>
        <small class="text-muted">{{ $table->qrUrl() }}</small>
    </div>
    <button onclick="window.print()" class="no-print" style="margin-top:1rem; padding:0.5rem 1.5rem; background:#b91c1c; color:white; border:0; border-radius:8px; cursor:pointer;">طباعة</button>
</body>
</html>
