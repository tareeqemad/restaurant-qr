{{--
  Shared layout for all error pages (404, 403, 500, 419, 503, 429).
  Standalone — does NOT extend admin.blade.php, so errors render even if the
  auth system, sidebar, or menu settings fail.

  Sections a child page defines:
    @section('title')        Browser tab title (after the error code)
    @section('code')          Big "404" / "500" / etc. in Cinzel
    @section('headline')      Short one-line headline
    @section('message')       Longer explanation
    @section('illustration')  Inline SVG specific to this error
    @section('actions')       Button row (at least one CTA)
    @section('accent')        CSS accent color for this specific error
                               (defaults to gold — override with a valid CSS color)
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#122d1e">
    <title>@yield('title', 'خطأ') — {{ config('restaurant.name', 'Relax') }}</title>

    <link rel="icon" href="{{ asset('assets/dashtic/images/brand-logos/favicon.ico') }}" type="image/x-icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --forest:      #1f4733;
            --forest-deep: #122d1e;
            --forest-dark: #0d2317;
            --gold:        #d4a550;
            --gold-light:  #e8c775;
            --gold-dark:   #a37a2b;
            --cream:       #faf5eb;
            --accent:      @yield('accent', '#d4a550');
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; padding: 0; }

        body {
            font-family: 'Tajawal', Arial, sans-serif;
            min-height: 100vh;
            background:
                linear-gradient(135deg, rgba(13,35,23,.92) 0%, rgba(18,45,30,.85) 50%, rgba(31,71,51,.92) 100%),
                url("{{ asset('assets/brand/pattern.svg') }}"),
                linear-gradient(135deg, #0d2317 0%, #1f4733 100%);
            background-size: cover, 240px 240px, cover;
            background-repeat: no-repeat, repeat, no-repeat;
            background-attachment: fixed;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        /* Decorative glow blobs */
        body::before,
        body::after {
            content: '';
            position: fixed;
            width: 480px;
            height: 480px;
            border-radius: 50%;
            filter: blur(80px);
            opacity: .25;
            pointer-events: none;
            z-index: 0;
        }
        body::before {
            background: radial-gradient(circle, var(--accent), transparent 70%);
            top: -180px;
            inset-inline-end: -180px;
        }
        body::after {
            background: radial-gradient(circle, var(--gold-light), transparent 70%);
            bottom: -180px;
            inset-inline-start: -180px;
        }

        /* Card */
        .error-card {
            position: relative;
            z-index: 1;
            max-width: 560px;
            width: 100%;
            text-align: center;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(212,165,80,.25);
            border-radius: 24px;
            padding: 3rem 2rem 2.5rem;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 24px 60px rgba(0,0,0,.35);
        }

        /* Gold top stripe */
        .error-card::before {
            content: '';
            position: absolute;
            top: 0; left: 20%; right: 20%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            border-radius: 0 0 999px 999px;
        }

        /* Illustration */
        .error-illustration {
            width: 140px;
            height: 140px;
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(212,165,80,.15), rgba(212,165,80,.05));
            border: 1px solid rgba(212,165,80,.3);
            border-radius: 28px;
            position: relative;
        }
        .error-illustration svg {
            width: 80px;
            height: 80px;
            color: var(--accent);
        }
        .error-illustration::after {
            content: '';
            position: absolute;
            inset: -6px;
            border: 1px dashed rgba(212,165,80,.2);
            border-radius: 32px;
            pointer-events: none;
        }

        /* Big Code (404, 500, ...) */
        .error-code {
            font-family: 'Cinzel', serif;
            font-size: clamp(3.5rem, 10vw, 5.5rem);
            font-weight: 700;
            letter-spacing: .08em;
            line-height: 1;
            background: linear-gradient(180deg, var(--gold-light) 0%, var(--accent) 50%, var(--gold-dark) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin: .25rem 0 .5rem;
            text-shadow: 0 4px 24px rgba(212,165,80,.2);
        }

        /* Headline + message */
        .error-headline {
            font-size: 1.5rem;
            font-weight: 900;
            margin: .5rem 0;
            color: #fff;
            letter-spacing: -.01em;
        }
        .error-message {
            font-size: 1rem;
            font-weight: 500;
            margin: 0 0 1.75rem;
            color: rgba(255,255,255,.78);
            line-height: 1.65;
        }

        /* Actions row */
        .error-actions {
            display: flex;
            gap: .75rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: .7rem 1.4rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: .95rem;
            font-family: inherit;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--gold-dark));
            color: #fff;
            box-shadow: 0 6px 20px rgba(212,165,80,.35);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(212,165,80,.5);
        }
        .btn-ghost {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.9);
            border: 1px solid rgba(255,255,255,.18);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,.14);
            transform: translateY(-2px);
        }
        .btn svg { width: 16px; height: 16px; }

        /* Brand footer */
        .error-footer {
            position: relative;
            z-index: 1;
            margin-top: 2rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .4rem;
            opacity: .55;
            transition: opacity .2s;
        }
        .error-footer:hover { opacity: .85; }
        .error-footer img {
            width: 36px;
            height: 36px;
            filter: drop-shadow(0 4px 10px rgba(212,165,80,.3));
        }
        .error-footer .brand-name {
            font-family: 'Cinzel', serif;
            font-weight: 600;
            font-size: 1.05rem;
            letter-spacing: .12em;
            color: var(--accent);
        }
        .error-footer .brand-tag {
            font-size: .72rem;
            color: rgba(255,255,255,.5);
            letter-spacing: .05em;
        }

        @media (max-width: 480px) {
            .error-card { padding: 2rem 1.25rem 1.75rem; border-radius: 20px; }
            .error-illustration { width: 110px; height: 110px; border-radius: 22px; }
            .error-illustration svg { width: 60px; height: 60px; }
            .error-headline { font-size: 1.2rem; }
            .error-message { font-size: .92rem; }
            .error-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-illustration">
            @yield('illustration')
        </div>
        <div class="error-code">@yield('code')</div>
        <h1 class="error-headline">@yield('headline')</h1>
        <p class="error-message">@yield('message')</p>
        <div class="error-actions">
            @yield('actions')
        </div>
    </div>

    <div class="error-footer">
        <img src="{{ asset('assets/brand/relax-monogram.svg') }}" alt="Relax">
        <span class="brand-name">RELAX</span>
        <span class="brand-tag">نظام إدارة المطعم</span>
    </div>
</body>
</html>
