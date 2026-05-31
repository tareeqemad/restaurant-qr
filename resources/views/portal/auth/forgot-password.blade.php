@php
    $theme = \App\Support\ThemePalette::current();
    $siteName = \App\Helpers\Brand::name();
    $market = \App\Support\MarketProfile::class;
@endphp
<!DOCTYPE html>
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title>{{ __('ui.auth.forgot_title') }} · {{ $siteName }}</title>
    <link rel="icon" href="{{ \App\Helpers\Brand::faviconUrl() }}">
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">
    <style>
        :root {
            --primary: {{ $theme['primary'] }};
            --dark:    {{ $theme['dark'] }};
            --paper:   #ffffff;
            --bg:      #f7f8f5;
            --line:    #e5e7eb;
            --ink:     #1f2937;
            --muted:   #6b7280;
        }
        @include('partials.market-vars')
        *,*::before,*::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; min-height: 100%; }
        body {
            font-family: var(--market-font-family);
            background: linear-gradient(135deg, var(--bg) 0%, #fff 100%);
            color: var(--ink);
            display: flex; align-items: center; justify-content: center;
            padding: 1rem;
        }
        .card {
            background: var(--paper); width: 100%; max-width: 420px;
            border-radius: 18px; padding: 2rem 1.75rem;
            box-shadow: 0 12px 40px rgba(0,0,0,.08);
            border: 1px solid var(--line);
        }
        .brand { text-align: center; margin-bottom: 1.5rem; }
        .brand img { max-width: 84px; max-height: 84px; }
        h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 .35rem; color: var(--dark); text-align: center; }
        .lead { color: var(--muted); font-size: .92rem; text-align: center; margin-bottom: 1.5rem; line-height: 1.6; }
        label { display: block; font-weight: 700; font-size: .9rem; margin-bottom: .4rem; }
        .field { margin-bottom: 1rem; }
        .input {
            width: 100%; padding: .75rem .9rem;
            border: 1.5px solid var(--line); border-radius: 10px;
            font-size: 1rem; font-family: inherit;
        }
        .input:focus { outline: 0; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(0,0,0,.05); }
        .btn {
            width: 100%; padding: .85rem; background: var(--primary);
            color: #fff; border: 0; border-radius: 10px;
            font-weight: 800; font-size: 1rem; cursor: pointer;
        }
        .btn:hover { opacity: .92; }
        .alert {
            padding: .75rem .9rem; border-radius: 10px; font-size: .9rem;
            margin-bottom: 1rem; display: flex; align-items: start; gap: .55rem;
        }
        .alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .back {
            display: block; text-align: center; margin-top: 1.25rem;
            color: var(--muted); text-decoration: none; font-size: .9rem; font-weight: 700;
        }
        .back:hover { color: var(--primary); }
        .help { font-size: .82rem; color: var(--muted); margin-top: .4rem; line-height: 1.5; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <img src="{{ \App\Helpers\Brand::logoUrl() }}" alt="{{ $siteName }}">
        </div>

        <h1>{{ __('ui.auth.forgot_title') }}</h1>
        <p class="lead">{{ __('ui.auth.forgot_customer_lead') }}</p>

        @if(session('success'))
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <span>{{ session('success') }}</span>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form action="{{ route('portal.password.email') }}" method="POST" autocomplete="off">
            @csrf
            <div class="field">
                <label for="phone">{{ __('ui.auth.phone') }}</label>
                <input type="tel" id="phone" name="phone" class="input" dir="ltr"
                       placeholder="{{ __('ui.auth.phone_placeholder') }}"
                       value="{{ old('phone') }}" required autofocus>
                <div class="help">{{ __('ui.auth.forgot_customer_help') }}</div>
            </div>

            <button type="submit" class="btn">
                <i class="bi bi-send"></i> {{ __('ui.auth.send_new_password') }}
            </button>
        </form>

        <a href="{{ route('portal.login') }}" class="back">
            <i class="bi bi-arrow-right"></i> {{ __('ui.common.back_to_login') }}
        </a>
    </main>
</body>
</html>
