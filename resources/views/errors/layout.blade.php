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
    <meta name="theme-color" content="#f4f7f5">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') — @yield('title', 'تعذر فتح الصفحة') | {{ $brandName }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}" type="image/x-icon">
    <style>
        :root {
            --primary: {{ $theme['primary'] }};
            --primary-soft: #e9f5f0;
            --ink: #16231c;
            --muted: #6c7972;
            --line: #dfe7e2;
            --surface: #ffffff;
            --accent: @yield('accent', $theme['primary']);
        }
        @include('partials.market-vars')
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            min-height: 100dvh;
            padding: 1rem;
            display: grid;
            grid-template-rows: auto 1fr auto;
            color: var(--ink);
            background:
                radial-gradient(circle at 90% 0%, rgba(15, 71, 49, .07), transparent 28rem),
                radial-gradient(circle at 0% 100%, rgba(186, 135, 39, .06), transparent 25rem),
                #f4f7f5;
            font-family: var(--market-font-family, "Segoe UI", Tahoma, Arial, sans-serif);
        }
        .site-head, .error-wrap, .site-foot { width: min(960px, 100%); margin-inline: auto; }
        .site-head { min-height: 58px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .brand { display: inline-flex; align-items: center; gap: .65rem; color: inherit; text-decoration: none; }
        .brand img { width: 38px; height: 38px; object-fit: contain; border-radius: 12px; background: #fff; border: 1px solid var(--line); padding: .25rem; }
        .brand strong, .brand small { display: block; }
        .brand strong { font-size: .9rem; font-weight: 900; }
        .brand small { margin-top: .08rem; color: var(--muted); font-size: .66rem; }
        .context-pill { min-height: 34px; display: inline-flex; align-items: center; padding: 0 .72rem; border: 1px solid var(--line); border-radius: 999px; background: rgba(255,255,255,.75); color: var(--muted); font-size: .68rem; font-weight: 800; }
        .error-wrap { display: grid; place-items: center; padding-block: 2rem; }
        .error-card { width: min(680px, 100%); overflow: hidden; position: relative; display: grid; grid-template-columns: 130px 1fr; border: 1px solid var(--line); border-radius: 26px; background: rgba(255,255,255,.94); box-shadow: 0 28px 80px -55px rgba(19, 57, 36, .55); }
        .error-side { padding: 1.5rem 1rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .65rem; background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 8%, white), #f8faf8); border-inline-end: 1px solid var(--line); }
        .error-illustration { width: 64px; height: 64px; border-radius: 20px; display: grid; place-items: center; background: #fff; color: var(--accent); border: 1px solid color-mix(in srgb, var(--accent) 18%, white); box-shadow: 0 12px 28px -20px var(--accent); }
        .error-illustration svg { width: 32px; height: 32px; }
        .error-code { color: var(--accent); font-size: 1.45rem; font-weight: 950; letter-spacing: .06em; font-variant-numeric: tabular-nums; }
        .error-content { padding: clamp(1.45rem, 5vw, 2.35rem); }
        .eyebrow { display: inline-flex; align-items: center; gap: .35rem; margin-bottom: .65rem; color: var(--accent); font-size: .68rem; font-weight: 850; }
        .eyebrow::before { content: ''; width: 16px; height: 2px; border-radius: 2px; background: currentColor; opacity: .55; }
        .error-headline { margin: 0; color: var(--ink); font-size: clamp(1.25rem, 3.5vw, 1.75rem); font-weight: 950; letter-spacing: -.025em; }
        .error-message { max-width: 32rem; margin: .55rem 0 1.35rem; color: var(--muted); font-size: .86rem; line-height: 1.75; }
        .error-actions { display: flex; flex-wrap: wrap; gap: .55rem; }
        .btn { min-height: 44px; border: 1px solid transparent; border-radius: 13px; padding: 0 1rem; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; color: inherit; background: none; font: inherit; font-size: .76rem; font-weight: 850; text-decoration: none; cursor: pointer; transition: transform .15s ease, background .15s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }
        .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 10px 24px -16px var(--primary); }
        .btn-ghost { border-color: var(--line); background: #f8faf8; color: #4f5e55; }
        .site-foot { min-height: 46px; display: flex; align-items: center; justify-content: center; color: #89958e; font-size: .65rem; text-align: center; }
        @media (max-width: 560px) {
            body { padding: .7rem; }
            .brand small, .context-pill { display: none; }
            .error-wrap { padding-block: 1rem; }
            .error-card { grid-template-columns: 1fr; border-radius: 22px; }
            .error-side { min-height: 116px; flex-direction: row; border-inline-end: 0; border-bottom: 1px solid var(--line); }
            .error-content { text-align: center; }
            .eyebrow { justify-content: center; }
            .error-message { margin-inline: auto; }
            .error-actions { display: grid; grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) { .btn { transition: none; } }
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
