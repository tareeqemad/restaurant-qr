@php
    $theme = \App\Support\ThemePalette::current();
    $brandName = \App\Helpers\Brand::name();
    $tone = $tone ?? 'amber';
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title>{{ $pageTitle }} · {{ $brandName }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ \App\Support\MarketProfile::fontUrl() }}" rel="stylesheet"><style>
        :root { --primary: {{ $theme['primary'] }}; --dark:#25343A; --accent:#B78338; }
        *{box-sizing:border-box}html,body{min-height:100%;margin:0}body{min-height:100dvh;display:grid;place-items:center;padding:24px 16px;background:#F7F3ED;color:#25343A;font-family:Cairo,Tahoma,Arial,sans-serif}.status-card{width:min(100%,500px);padding:0;border:1px solid #DFD7CB;border-radius:12px;background:#FFFDFA;overflow:hidden;text-align:start}.brand{display:flex;align-items:center;gap:12px;margin:0;padding:22px 26px;border-bottom:1px solid #DFD7CB}.brand span{display:grid;width:44px;height:44px;place-items:center}.brand img{width:100%;height:100%;object-fit:contain}.brand strong{font-size:18px}.status-content{padding:28px 26px}.status-icon{display:grid;width:46px;height:46px;place-items:center;border-radius:8px;margin-bottom:20px;font-size:23px}.status-icon.amber{color:#7F5525;background:#F8F0E2}.status-icon.red{color:#A3422B;background:#F3E3D8}h1{margin:0;font-size:26px;line-height:1.5;color:var(--dark)}.body{margin:12px 0 22px;color:#626B6D;font-size:15px;line-height:1.8}.detail{display:flex;align-items:flex-start;gap:10px;padding:16px;border-inline-start:3px solid var(--primary);background:#F7F3ED;color:#25343A;font-size:15px;line-height:1.7}.detail i{color:var(--primary);margin-top:3px}.footer{font-size:14px;color:#626B6D;line-height:1.7;border-top:1px solid #DFD7CB;padding:18px 26px;margin:0;background:#F7F3ED}@media(max-width:500px){.status-content{padding:24px 20px}.brand,.footer{padding-inline:20px}h1{font-size:24px}}
    </style>
</head>
<body>
    <main class="status-card" role="status">
        <div class="brand"><span><img src="{{ \App\Helpers\Brand::logoUrl() }}" alt=""></span><strong>{{ $brandName }}</strong></div>
        <section class="status-content"><span class="status-icon {{ $tone }}"><i class="bi {{ $icon }}"></i></span>
        <h1>@yield('heading')</h1>
        <p class="body">@yield('body')</p>
        <div class="detail"><i class="bi {{ $detailIcon ?? 'bi-info-circle' }}"></i><span>@yield('detail')</span></div>
        </section><p class="footer">@yield('footer', 'امسح رمز QR الموجود على الطاولة للوصول الآمن إلى المنيو.')</p>
    </main>
</body>
</html>
