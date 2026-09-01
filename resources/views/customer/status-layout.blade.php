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
    <style>
        :root { --primary: {{ $theme['primary'] }}; --dark: {{ $theme['dark'] }}; --accent: {{ $theme['accent'] }}; }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; }
        body { display: grid; min-height: 100dvh; place-items: center; padding: 1rem; color: #1d2b22; background: radial-gradient(circle at 15% 12%, color-mix(in srgb, var(--primary) 13%, transparent), transparent 26rem), #f3f7f4; font-family: Tahoma, Arial, sans-serif; }
        .status-card { width: min(100%, 440px); padding: clamp(1.25rem, 6vw, 2rem); border: 1px solid #d9e4dd; border-radius: 24px; background: #fff; box-shadow: 0 28px 70px -48px #123f31; text-align: center; }
        .brand { display: flex; align-items: center; justify-content: center; gap: .55rem; margin-bottom: 1.5rem; }
        .brand span { display: grid; width: 46px; height: 46px; place-items: center; padding: .3rem; border: 1px solid #dfe8e2; border-radius: 13px; }
        .brand img { width: 100%; height: 100%; object-fit: contain; }
        .brand strong { font-size: .8rem; }
        .status-icon { display: grid; width: 74px; height: 74px; margin: 0 auto 1rem; place-items: center; border-radius: 22px; font-size: 1.8rem; }
        .status-icon.amber { color: #a2630d; background: #fff2d7; }
        .status-icon.red { color: #a6373f; background: #fff0f1; }
        h1 { margin: 0; color: var(--dark); font-size: clamp(1.2rem, 5vw, 1.55rem); line-height: 1.5; }
        .body { margin: .55rem 0 0; color: #718078; font-size: .76rem; line-height: 1.9; }
        .detail { display: flex; align-items: flex-start; gap: .55rem; margin-top: 1.15rem; padding: .8rem; border: 1px solid #dce6e0; border-radius: 13px; color: #3c5a49; background: #f7faf8; text-align: start; font-size: .66rem; line-height: 1.7; }
        .detail i { margin-top: .08rem; color: var(--primary); font-size: .9rem; }
        .footer { margin: 1.2rem 0 0; color: #929d96; font-size: .55rem; }
        @media (max-width: 480px) { body { align-items: end; padding: .75rem; } .status-card { margin-bottom: max(.25rem, env(safe-area-inset-bottom)); border-radius: 22px; } }
    </style>
</head>
<body>
    <main class="status-card" role="status">
        <div class="brand"><span><img src="{{ \App\Helpers\Brand::logoUrl() }}" alt=""></span><strong>{{ $brandName }}</strong></div>
        <span class="status-icon {{ $tone }}"><i class="bi {{ $icon }}"></i></span>
        <h1>@yield('heading')</h1>
        <p class="body">@yield('body')</p>
        <div class="detail"><i class="bi {{ $detailIcon ?? 'bi-info-circle' }}"></i><span>@yield('detail')</span></div>
        <p class="footer">@yield('footer', 'امسح رمز QR الموجود على الطاولة للوصول الآمن إلى المنيو.')</p>
    </main>
</body>
</html>
