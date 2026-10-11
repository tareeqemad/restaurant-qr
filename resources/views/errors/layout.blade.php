@php
    $market = \App\Support\MarketProfile::class;
    $brandName = \App\Helpers\Brand::name();
    $isAdminContext = auth()->check() || request()->is('admin') || request()->is('admin/*');
    $homeUrl = $isAdminContext ? url('/admin') : url('/');
    $homeLabel = $isAdminContext ? 'لوحة التحكم' : 'الصفحة الرئيسية';
    $theme = \App\Support\ThemePalette::current();
    $requestReference = request()->attributes->get('request_reference')
        ?: substr(hash('sha256', request()->method().'|'.request()->fullUrl().'|'.microtime(true)), 0, 12);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f7f3ed">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') — @yield('title', 'تعذر فتح الصفحة') | {{ $brandName }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}" >
    <link href="{{ $market::fontUrl() }}" rel="stylesheet"><style>
        :root{--primary:{{ $theme['primary'] }};--ink:#25343A;--muted:#626B6D;--line:#DFD7CB;--surface:#FFFDFA;--accent:@yield('accent', $theme['primary']);}
        @include('partials.market-vars')
        *{box-sizing:border-box}html,body{margin:0;min-height:100%}body{min-height:100dvh;display:grid;grid-template-rows:auto 1fr auto;gap:24px;padding:24px;background:#F7F3ED;color:var(--ink);font-family:var(--market-font-family,Cairo,Tahoma,Arial,sans-serif)}.site-head,.error-wrap,.site-foot{width:min(960px,100%);margin-inline:auto}.site-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:20px;border-bottom:1px solid var(--line)}.brand{display:flex;gap:12px;align-items:center;color:inherit;text-decoration:none}.brand img{width:44px;height:44px;object-fit:contain}.brand strong{display:block;font-size:18px}.brand small{display:block;margin-top:4px;color:var(--muted);font-size:14px}.context-pill{font-size:14px;color:var(--muted)}.error-wrap{display:grid;place-items:center}.error-card{width:min(100%,580px);border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface)}.error-side{display:flex;align-items:center;gap:14px;padding:20px 26px;border-bottom:1px solid var(--line);background:#F3E3D8}.error-illustration{display:grid;place-items:center;width:38px;height:38px;color:var(--primary)}.error-illustration svg{width:32px;height:32px}.error-code{font-size:25px;font-weight:700;color:var(--primary);font-variant-numeric:tabular-nums}.error-content{padding:28px 26px}.eyebrow{display:block;color:var(--muted);font-size:14px;margin-bottom:10px}.error-headline{font-size:28px;line-height:1.5;margin:0;font-weight:700}.error-message{font-size:15px;line-height:1.85;color:var(--muted);margin:14px 0 26px}.error-actions{display:flex;gap:12px;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:22px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;border:1px solid var(--line);border-radius:8px;padding:10px 18px;font:inherit;font-size:15px;font-weight:700;text-decoration:none;cursor:pointer;background:transparent;color:var(--ink)}.btn svg{width:18px;height:18px}.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn-ghost{background:var(--surface)}.btn:focus-visible{outline:3px solid var(--primary);outline-offset:3px}.site-foot{font-size:14px;color:var(--muted);text-align:center;line-height:1.7;padding-top:18px;border-top:1px solid var(--line)}@media(max-width:560px){body{padding:16px}.brand small,.context-pill{display:none}.error-content{padding:24px 20px}.error-side{padding:18px 20px}.error-headline{font-size:25px}.error-actions{display:grid;grid-template-columns:1fr}.btn{width:100%}}
    </style>
    @include('partials.runtime-theme')
</head>
<body>
    <header class="site-head">
        <a href="{{ $homeUrl }}" class="brand">
            <img src="{{ \App\Helpers\Brand::logoUrl() }}" alt="">
            <span><strong>{{ $brandName }}</strong><small>نظام إدارة المطعم</small></span>
        </a>
        <span class="context-pill">{{ $isAdminContext ? 'منطقة الإدارة' : 'خدمة الزبائن' }}</span>
    </header>

    <main class="error-wrap">
        <article class="error-card" role="alert" aria-labelledby="error-headline">
            <aside class="error-side" aria-hidden="true">
                <div class="error-illustration">@yield('illustration')</div>
                <div class="error-code">@yield('code')</div>
            </aside>
            <section class="error-content">
                <span class="eyebrow">تعذر إكمال الطلب</span>
                <h1 id="error-headline" class="error-headline">@yield('headline')</h1>
                <p class="error-message">@yield('message')</p>
                <div class="error-actions">@yield('actions')</div>
            </section>
        </article>
    </main>

    <footer class="site-foot">إذا تكررت المشكلة، أخبر موظف المطعم بالرمز: {{ $requestReference }}</footer>
    <script>
        document.querySelectorAll('[data-error-back]').forEach((button) => {
            button.addEventListener('click', () => window.history.length > 1 ? window.history.back() : window.location.assign(@json($homeUrl)));
        });
        document.querySelectorAll('[data-error-reload]').forEach((button) => {
            button.addEventListener('click', () => window.location.reload());
        });
    </script>
</body>
</html>
