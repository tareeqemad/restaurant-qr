<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>المراقبة المباشرة — {{ config('app.name') }}</title>

    {{-- Local icon fonts bundle (Bootstrap Icons + RemixIcons + Feather)
         — same file the admin shell uses, no external CDN. --}}
    <style>
        html { background: var(--bg, #f5f8f3); }
        body.lm-body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg, #f7faf5);
            color: var(--brand-dark, #09251b);
            font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .lm-wrap { min-height: 100vh; display: flex; flex-direction: column; }
        .lm-header {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 12px clamp(14px, 2vw, 28px);
            background: rgba(255,255,255,.94);
            border-bottom: 1px solid rgba(var(--brand-rgb, 20,83,61), .14);
        }
        .lm-header__meta { margin-inline-start: auto; display: flex; align-items: center; gap: 12px; }
        .lm-overview,
        .lm-grid { width: min(100% - 32px, 1740px); margin-inline: auto; display: grid; gap: 14px; }
        .lm-overview { margin-top: 18px; margin-bottom: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .lm-grid { grid-template-columns: repeat(var(--columns, 2), minmax(280px, 1fr)); }
        .lm-overview-card,
        .lm-col { background: rgba(255,255,255,.94); border: 1px solid rgba(var(--brand-rgb, 20,83,61), .14); border-radius: 20px; }
        .lm-overview-card { min-height: 118px; padding: 18px; }
        .lm-col { padding: 16px; }
        @media (max-width: 760px) {
            .lm-overview,
            .lm-grid { width: min(100% - 20px, 1740px); grid-template-columns: 1fr; }
            .lm-header { flex-wrap: wrap; }
        }
    </style>
    @include('partials.runtime-theme')
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/icons.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/dashtic/css/relax-live-monitor.css') }}?v={{ filemtime(public_path('assets/dashtic/css/relax-live-monitor.css')) }}">

    {{-- Vite (Echo + Reverb client) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="lm-body">

<livewire:admin.live-monitor />

@livewireScripts
</body>
</html>
