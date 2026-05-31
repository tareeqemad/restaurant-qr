@php
    $market = \App\Support\MarketProfile::class;
    $theme = \App\Support\ThemePalette::current();
    $value = fn (string $key, mixed $fallback = null) => old($key, $defaults[$key] ?? $fallback);
    $checked = fn (string $key, mixed $fallback = false) => (bool) old($key, $defaults[$key] ?? $fallback);
@endphp
<!DOCTYPE html>
<html lang="{{ $market::lang() }}" dir="{{ $market::direction() }}" data-market="{{ $market::current() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ $theme['primary'] }}">
    <title>{{ __('setup.title') }}</title>
    <link href="{{ asset('assets/dashtic/icon-fonts/bootstrap-icons/icons/font/bootstrap-icons.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{{ $market::fontUrl() }}" rel="stylesheet">
    <style>
        :root {
            --primary: {{ $theme['primary'] }};
            --primary-dark: {{ $theme['dark'] }};
            --accent: {{ $theme['accent'] }};
            --bg: #f6f8f7;
            --panel: #ffffff;
            --ink: #17211d;
            --muted: #647067;
            --line: #dfe7e2;
            --soft: #edf4f0;
            --danger: #b42318;
            --danger-bg: #fff2f1;
            --success: #13795b;
            --shadow: 0 20px 60px rgba(18, 32, 26, .12);
        }
        @include('partials.market-vars')
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: var(--market-font-family);
            background:
                linear-gradient(180deg, rgba(255,255,255,.88), rgba(246,248,247,.94)),
                repeating-linear-gradient(135deg, rgba(22,76,55,.05) 0 1px, transparent 1px 18px);
            color: var(--ink);
            line-height: 1.5;
        }
        button, input, select, textarea { font: inherit; letter-spacing: 0; }
        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 32px 0 44px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 22px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--primary);
            color: #fff;
            display: grid;
            place-items: center;
            font-size: 1.35rem;
            flex: 0 0 auto;
        }
        .eyebrow {
            color: var(--primary);
            font-size: .82rem;
            font-weight: 800;
            margin: 0 0 2px;
        }
        .page-title {
            font-size: clamp(1.35rem, 2.4vw, 2rem);
            font-weight: 900;
            margin: 0;
            line-height: 1.18;
        }
        .security-note {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border: 1px solid rgba(19,121,91,.22);
            border-radius: 10px;
            background: rgba(19,121,91,.08);
            color: var(--success);
            font-size: .84rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .layout {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        .aside,
        .form-panel {
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(223,231,226,.9);
            box-shadow: var(--shadow);
        }
        .aside {
            position: sticky;
            top: 22px;
            border-radius: 16px;
            padding: 20px;
        }
        .intro {
            color: var(--muted);
            margin: 8px 0 18px;
            font-size: .94rem;
        }
        .steps {
            display: grid;
            gap: 8px;
            margin-top: 16px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--soft);
            color: var(--ink);
            font-weight: 800;
            font-size: .88rem;
        }
        .step i { color: var(--primary); }
        .form-panel {
            border-radius: 16px;
            overflow: hidden;
        }
        .form-header {
            padding: 22px 24px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(135deg, rgba(22,76,55,.09), rgba(185,120,24,.08));
        }
        .form-header h2 {
            margin: 0 0 6px;
            font-size: 1.25rem;
            font-weight: 900;
        }
        .form-header p {
            margin: 0;
            color: var(--muted);
            font-size: .94rem;
        }
        .setup-form {
            display: grid;
            gap: 0;
        }
        .section {
            padding: 24px;
            border-bottom: 1px solid var(--line);
        }
        .section:last-of-type { border-bottom: 0; }
        .section-head {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }
        .section-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(22,76,55,.11);
            color: var(--primary);
            display: grid;
            place-items: center;
            flex: 0 0 auto;
        }
        .section-title {
            margin: 0;
            font-weight: 900;
            font-size: 1.05rem;
        }
        .section-hint {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: .88rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .full { grid-column: 1 / -1; }
        .field label,
        .toggle-label {
            display: block;
            font-size: .82rem;
            font-weight: 800;
            color: #26342d;
            margin-bottom: 7px;
        }
        .control {
            width: 100%;
            min-height: 44px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
            color: var(--ink);
            outline: 0;
            transition: border-color .15s, box-shadow .15s;
        }
        textarea.control { min-height: 88px; resize: vertical; }
        .control:focus {
            border-color: rgba(22,76,55,.75);
            box-shadow: 0 0 0 4px rgba(22,76,55,.12);
        }
        .profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .profile-option {
            display: block;
            position: relative;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 16px 16px 16px 48px;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s, transform .15s;
            min-height: 120px;
        }
        [dir="rtl"] .profile-option { padding: 16px 48px 16px 16px; }
        .profile-option:hover { transform: translateY(-1px); border-color: rgba(22,76,55,.45); }
        .profile-option input {
            position: absolute;
            top: 18px;
            left: 16px;
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }
        [dir="rtl"] .profile-option input { left: auto; right: 16px; }
        .profile-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 900;
            margin-bottom: 6px;
        }
        .profile-desc {
            color: var(--muted);
            font-size: .86rem;
            margin: 0;
        }
        .profile-option:has(input:checked) {
            border-color: rgba(22,76,55,.85);
            box-shadow: 0 0 0 4px rgba(22,76,55,.12);
        }
        .profile-option--locked {
            cursor: default;
            min-height: auto;
            padding: 16px;
            border-color: rgba(22,76,55,.32);
            box-shadow: 0 0 0 4px rgba(22,76,55,.08);
        }
        .profile-option--locked:hover {
            transform: none;
        }
        .theme-preset-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .theme-preset {
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            border-radius: 10px;
            min-height: 38px;
            padding: 7px 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            cursor: pointer;
        }
        .theme-preset:hover {
            border-color: rgba(22,76,55,.45);
        }
        .preset-swatches {
            display: inline-flex;
            gap: 4px;
        }
        .preset-swatches span {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 1px solid rgba(0,0,0,.08);
        }
        .color-control {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
        }
        [dir="rtl"] .color-control { grid-template-columns: minmax(0, 1fr) 52px; }
        .color-control input[type="color"] {
            width: 52px;
            height: 44px;
            padding: 4px;
            cursor: pointer;
        }
        .color-code {
            color: var(--muted);
            font-weight: 800;
            direction: ltr;
            text-align: left;
        }
        .file-hint {
            margin-top: 7px;
            color: var(--muted);
            font-size: .78rem;
            font-weight: 700;
        }
        .toggle {
            min-height: 44px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
        }
        .toggle input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            flex: 0 0 auto;
        }
        .toggle span {
            font-weight: 800;
            color: #26342d;
            font-size: .88rem;
        }
        .input-unit {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
        }
        [dir="rtl"] .input-unit { grid-template-columns: auto minmax(0, 1fr); }
        .input-unit .control {
            border-start-end-radius: 0;
            border-end-end-radius: 0;
        }
        [dir="rtl"] .input-unit .control {
            border-radius: 0 10px 10px 0;
        }
        .unit {
            min-height: 44px;
            display: grid;
            place-items: center;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-inline-start: 0;
            border-radius: 0 10px 10px 0;
            background: var(--soft);
            color: var(--muted);
            font-weight: 800;
        }
        [dir="rtl"] .unit {
            border-inline-start: 1px solid var(--line);
            border-inline-end: 0;
            border-radius: 10px 0 0 10px;
        }
        .alert {
            margin: 18px 24px 0;
            padding: 12px 14px;
            border-radius: 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: .9rem;
            font-weight: 700;
        }
        .alert-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid rgba(180,35,24,.25);
        }
        .alert-warning {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid rgba(146,64,14,.22);
        }
        .alert ul { margin: 0; padding-inline-start: 18px; }
        .actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            padding: 20px 24px 24px;
            background: #fff;
        }
        .btn-primary {
            border: 0;
            border-radius: 11px;
            background: var(--primary);
            color: #fff;
            min-height: 46px;
            padding: 0 20px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(22,76,55,.22);
        }
        .btn-primary:disabled {
            opacity: .65;
            cursor: wait;
        }
        .btn-secondary {
            color: var(--muted);
            text-decoration: none;
            font-weight: 800;
        }
        .muted-note {
            margin: 0;
            color: var(--muted);
            font-size: .88rem;
        }
        [dir="ltr"] .ltr { direction: ltr; text-align: left; }
        [dir="rtl"] .ltr { direction: ltr; text-align: left; }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .aside { position: static; }
            .steps { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 700px) {
            .shell { width: min(100% - 20px, 1180px); padding-top: 18px; }
            .topbar { align-items: flex-start; flex-direction: column; }
            .security-note { white-space: normal; }
            .grid, .grid-3, .profile-grid { grid-template-columns: 1fr; }
            .section, .form-header, .actions { padding-inline: 16px; }
            .steps { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand">
                <div class="brand-mark"><i class="bi bi-sliders"></i></div>
                <div>
                    <p class="eyebrow">{{ __('setup.eyebrow') }}</p>
                    <h1 class="page-title">{{ __('setup.heading') }}</h1>
                </div>
            </div>
            <div class="security-note">
                <i class="bi bi-shield-check"></i>
                <span>{{ __('setup.secure_note') }}</span>
            </div>
        </header>

        <div class="layout">
            <aside class="aside">
                <p class="intro">{{ __('setup.intro') }}</p>
                <div class="steps">
                    <div class="step"><i class="bi bi-globe2"></i>{{ __('setup.steps.market') }}</div>
                    <div class="step"><i class="bi bi-shop"></i>{{ __('setup.steps.restaurant') }}</div>
                    <div class="step"><i class="bi bi-palette"></i>{{ __('setup.steps.branding') }}</div>
                    <div class="step"><i class="bi bi-diagram-3"></i>{{ __('setup.steps.branch') }}</div>
                    <div class="step"><i class="bi bi-person-badge"></i>{{ __('setup.steps.admin') }}</div>
                    <div class="step"><i class="bi bi-box-seam"></i>{{ __('setup.steps.operations') }}</div>
                    <div class="step"><i class="bi bi-cloud-check"></i>{{ __('setup.steps.connectivity') }}</div>
                </div>
            </aside>

            <section class="form-panel">
                <div class="form-header">
                    <h2>{{ __('setup.title') }}</h2>
                    <p>{{ __('setup.hints.market') }}</p>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($willResetDemoData)
                    <div class="alert alert-warning">
                        <i class="bi bi-database-exclamation"></i>
                        <div>
                            <strong>{{ __('setup.demo_reset.title') }}</strong>
                            <div>{{ __('setup.demo_reset.body') }}</div>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('setup.store') }}" class="setup-form" id="setupForm" enctype="multipart/form-data">
                    @csrf

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-globe2"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.market') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.market') }}</p>
                            </div>
                        </div>
                        <input type="hidden" name="market_profile" value="{{ $currentProfile }}">
                        <div class="profile-grid">
                            <div class="profile-option profile-option--locked">
                                <span class="profile-title">
                                    <i class="bi {{ $currentProfile === 'us' ? 'bi-flag' : 'bi-translate' }}"></i>
                                    {{ __('setup.profiles.'.$currentProfile.'.title') }}
                                </span>
                                <p class="profile-desc">{{ __('setup.profiles.'.$currentProfile.'.description') }}</p>
                                <p class="profile-desc">{{ __('setup.market_locked_note') }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-shop"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.restaurant') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.restaurant') }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <div class="field">
                                <label for="restaurant_name">{{ __('setup.fields.restaurant_name') }}</label>
                                <input class="control" id="restaurant_name" name="restaurant_name" maxlength="120"
                                    value="{{ $value('restaurant_name') }}" placeholder="{{ __('setup.placeholders.restaurant_name') }}" required>
                            </div>
                            <div class="field">
                                <label for="legal_name">{{ __('setup.fields.legal_name') }}</label>
                                <input class="control" id="legal_name" name="legal_name" maxlength="160"
                                    value="{{ $value('legal_name') }}" placeholder="{{ __('setup.placeholders.legal_name') }}">
                            </div>
                            <div class="field">
                                <label for="tax_number">{{ __('setup.fields.tax_number') }}</label>
                                <input class="control ltr" id="tax_number" name="tax_number" maxlength="80"
                                    value="{{ $value('tax_number') }}" placeholder="{{ __('setup.placeholders.tax_number') }}">
                            </div>
                            <div class="field">
                                <label for="currency_symbol">{{ __('setup.fields.currency_symbol') }}</label>
                                <input class="control" id="currency_symbol" name="currency_symbol" maxlength="10"
                                    value="{{ $value('currency_symbol') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="sales_currency">{{ __('setup.fields.sales_currency') }}</label>
                                <input class="control ltr" id="sales_currency" name="sales_currency" maxlength="3"
                                    value="{{ $value('sales_currency') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="accounting_base_currency">{{ __('setup.fields.accounting_base_currency') }}</label>
                                <input class="control ltr" id="accounting_base_currency" name="accounting_base_currency" maxlength="3"
                                    value="{{ $value('accounting_base_currency') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="accounting_currency_symbol">{{ __('setup.fields.accounting_currency_symbol') }}</label>
                                <input class="control" id="accounting_currency_symbol" name="accounting_currency_symbol" maxlength="10"
                                    value="{{ $value('accounting_currency_symbol') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="sales_to_accounting_rate">{{ __('setup.fields.sales_to_accounting_rate') }}</label>
                                <input class="control ltr" id="sales_to_accounting_rate" name="sales_to_accounting_rate" type="number" min="0.000001" max="999999" step="0.000001"
                                    value="{{ $value('sales_to_accounting_rate') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="fiscal_year_start_month">{{ __('setup.fields.fiscal_year_start_month') }}</label>
                                <select class="control" id="fiscal_year_start_month" name="fiscal_year_start_month">
                                    @for($month = 1; $month <= 12; $month++)
                                        <option value="{{ $month }}" @selected((int) $value('fiscal_year_start_month', 1) === $month)>
                                            {{ __('setup.months.'.$month) }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="field">
                                <label for="fiscal_year_start_day">{{ __('setup.fields.fiscal_year_start_day') }}</label>
                                <input class="control ltr" id="fiscal_year_start_day" name="fiscal_year_start_day" type="number" min="1" max="31" step="1"
                                    value="{{ $value('fiscal_year_start_day', 1) }}" required>
                            </div>
                            <div class="field full">
                                <label for="receipt_footer">{{ __('setup.fields.receipt_footer') }}</label>
                                <textarea class="control" id="receipt_footer" name="receipt_footer" maxlength="500"
                                    placeholder="{{ __('setup.placeholders.receipt_footer') }}">{{ $value('receipt_footer') }}</textarea>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-palette"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.branding') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.branding') }}</p>
                            </div>
                        </div>
                        <div class="theme-preset-grid">
                            <button type="button" class="theme-preset" data-theme-preset='{"theme_primary":"#164c37","theme_dark":"#0f2d22","theme_header":"#164c37","theme_accent":"#b97818","theme_menu":"#f7f8f5"}'>
                                <span class="preset-swatches"><span style="background:#164c37"></span><span style="background:#b97818"></span></span>
                                {{ __('setup.theme_presets.default') }}
                            </button>
                            <button type="button" class="theme-preset" data-theme-preset='{"theme_primary":"#0f766e","theme_dark":"#134e4a","theme_header":"#0f766e","theme_accent":"#d97706","theme_menu":"#f0fdfa"}'>
                                <span class="preset-swatches"><span style="background:#0f766e"></span><span style="background:#d97706"></span></span>
                                {{ __('setup.theme_presets.teal') }}
                            </button>
                            <button type="button" class="theme-preset" data-theme-preset='{"theme_primary":"#7f1d1d","theme_dark":"#450a0a","theme_header":"#7f1d1d","theme_accent":"#f59e0b","theme_menu":"#fff7ed"}'>
                                <span class="preset-swatches"><span style="background:#7f1d1d"></span><span style="background:#f59e0b"></span></span>
                                {{ __('setup.theme_presets.warm') }}
                            </button>
                            <button type="button" class="theme-preset" data-theme-preset='{"theme_primary":"#1d4ed8","theme_dark":"#0f172a","theme_header":"#1e40af","theme_accent":"#06b6d4","theme_menu":"#eff6ff"}'>
                                <span class="preset-swatches"><span style="background:#1d4ed8"></span><span style="background:#06b6d4"></span></span>
                                {{ __('setup.theme_presets.blue') }}
                            </button>
                        </div>
                        <div class="grid">
                            @foreach(['theme_primary', 'theme_dark', 'theme_header', 'theme_accent', 'theme_menu'] as $colorKey)
                                <div class="field">
                                    <label for="{{ $colorKey }}">{{ __('setup.fields.'.$colorKey) }}</label>
                                    <div class="color-control">
                                        <input class="control" id="{{ $colorKey }}" name="{{ $colorKey }}" type="color"
                                            value="{{ $value($colorKey) }}" required data-theme-color>
                                        <span class="color-code" data-color-code="{{ $colorKey }}">{{ $value($colorKey) }}</span>
                                    </div>
                                </div>
                            @endforeach
                            <div class="field">
                                <label for="theme_header_style">{{ __('setup.fields.theme_header_style') }}</label>
                                <select class="control" id="theme_header_style" name="theme_header_style">
                                    <option value="color" @selected($value('theme_header_style') === 'color')>{{ __('setup.options.theme_color') }}</option>
                                    <option value="light" @selected($value('theme_header_style') === 'light')>{{ __('setup.options.theme_light') }}</option>
                                    <option value="dark" @selected($value('theme_header_style') === 'dark')>{{ __('setup.options.theme_dark_style') }}</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="theme_menu_style">{{ __('setup.fields.theme_menu_style') }}</label>
                                <select class="control" id="theme_menu_style" name="theme_menu_style">
                                    <option value="brand" @selected($value('theme_menu_style') === 'brand')>{{ __('setup.options.theme_brand') }}</option>
                                    <option value="light" @selected($value('theme_menu_style') === 'light')>{{ __('setup.options.theme_light') }}</option>
                                    <option value="dark" @selected($value('theme_menu_style') === 'dark')>{{ __('setup.options.theme_dark_style') }}</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="brand_logo">{{ __('setup.fields.brand_logo') }}</label>
                                <input class="control" id="brand_logo" name="brand_logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                                <div class="file-hint">{{ __('setup.hints.brand_logo') }}</div>
                            </div>
                            <div class="field">
                                <label for="brand_favicon">{{ __('setup.fields.brand_favicon') }}</label>
                                <input class="control" id="brand_favicon" name="brand_favicon" type="file" accept="image/png,image/x-icon,image/webp,image/svg+xml">
                                <div class="file-hint">{{ __('setup.hints.brand_favicon') }}</div>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-receipt"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.billing') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.restaurant') }}</p>
                            </div>
                        </div>
                        <div class="grid-3">
                            <div class="field">
                                <span class="toggle-label">{{ __('setup.fields.tax_enabled') }}</span>
                                <input type="hidden" name="tax_enabled" value="0">
                                <label class="toggle">
                                    <input type="checkbox" name="tax_enabled" value="1" @checked($checked('tax_enabled'))>
                                    <span>{{ __('setup.fields.tax_enabled') }}</span>
                                </label>
                            </div>
                            <div class="field">
                                <label for="tax_rate">{{ __('setup.fields.tax_rate') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="tax_rate" name="tax_rate" type="number" min="0" max="100" step="0.01"
                                        value="{{ $value('tax_rate') }}" required data-profile-managed>
                                    <span class="unit">{{ __('setup.units.percent') }}</span>
                                </div>
                            </div>
                            <div class="field">
                                <label for="customer_tax_display">{{ __('setup.fields.customer_tax_display') }}</label>
                                <select class="control" id="customer_tax_display" name="customer_tax_display">
                                    <option value="exclusive" @selected($value('customer_tax_display', 'exclusive') === 'exclusive')>{{ __('setup.fields.tax_exclusive') }}</option>
                                    <option value="inclusive" @selected($value('customer_tax_display', 'exclusive') === 'inclusive')>{{ __('setup.fields.tax_inclusive') }}</option>
                                </select>
                            </div>
                            <div class="field">
                                <span class="toggle-label">{{ __('setup.fields.service_enabled') }}</span>
                                <input type="hidden" name="service_enabled" value="0">
                                <label class="toggle">
                                    <input type="checkbox" name="service_enabled" value="1" @checked($checked('service_enabled'))>
                                    <span>{{ __('setup.fields.service_enabled') }}</span>
                                </label>
                            </div>
                            <div class="field">
                                <label for="service_rate">{{ __('setup.fields.service_rate') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="service_rate" name="service_rate" type="number" min="0" max="100" step="0.01"
                                        value="{{ $value('service_rate') }}" required data-profile-managed>
                                    <span class="unit">{{ __('setup.units.percent') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-diagram-3"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.branch') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.branch') }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <div class="field">
                                <label for="branch_code">{{ __('setup.fields.branch_code') }}</label>
                                <input class="control ltr" id="branch_code" name="branch_code" maxlength="32"
                                    value="{{ $value('branch_code') }}" placeholder="{{ __('setup.placeholders.branch_code') }}" required>
                            </div>
                            <div class="field">
                                <label for="branch_name">{{ __('setup.fields.branch_name') }}</label>
                                <input class="control" id="branch_name" name="branch_name" maxlength="120"
                                    value="{{ $value('branch_name') }}" placeholder="{{ __('setup.placeholders.branch_name') }}" required data-profile-managed>
                            </div>
                            <div class="field">
                                <label for="branch_name_en">{{ __('setup.fields.branch_name_en') }}</label>
                                <input class="control ltr" id="branch_name_en" name="branch_name_en" maxlength="120"
                                    value="{{ $value('branch_name_en') }}">
                            </div>
                            <div class="field">
                                <label for="branch_phone">{{ __('setup.fields.branch_phone') }}</label>
                                <input class="control ltr" id="branch_phone" name="branch_phone" maxlength="50"
                                    value="{{ $value('branch_phone') }}" placeholder="{{ __('setup.placeholders.branch_phone') }}">
                            </div>
                            <div class="field">
                                <label for="branch_email">{{ __('setup.fields.branch_email') }}</label>
                                <input class="control ltr" id="branch_email" name="branch_email" type="email" maxlength="120"
                                    value="{{ $value('branch_email') }}" placeholder="{{ __('setup.placeholders.branch_email') }}">
                            </div>
                            <div class="field">
                                <label for="branch_city">{{ __('setup.fields.branch_city') }}</label>
                                <input class="control" id="branch_city" name="branch_city" maxlength="80"
                                    value="{{ $value('branch_city') }}" placeholder="{{ __('setup.placeholders.branch_city') }}">
                            </div>
                            <div class="field full">
                                <label for="branch_address">{{ __('setup.fields.branch_address') }}</label>
                                <textarea class="control" id="branch_address" name="branch_address" maxlength="500"
                                    placeholder="{{ __('setup.placeholders.branch_address') }}">{{ $value('branch_address') }}</textarea>
                            </div>
                            <div class="field">
                                <label for="delivery_estimated_minutes">{{ __('setup.fields.delivery_estimated_minutes') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="delivery_estimated_minutes" name="delivery_estimated_minutes" type="number" min="0" max="240"
                                        value="{{ $value('delivery_estimated_minutes') }}" required>
                                    <span class="unit">{{ __('setup.units.minutes') }}</span>
                                </div>
                            </div>
                            <div class="field">
                                <label for="delivery_fee">{{ __('setup.fields.delivery_fee') }}</label>
                                <input class="control ltr" id="delivery_fee" name="delivery_fee" type="number" min="0" max="9999" step="0.01"
                                    value="{{ $value('delivery_fee') }}" required>
                            </div>
                            <div class="field">
                                <label for="prep_buffer_minutes">{{ __('setup.fields.prep_buffer_minutes') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="prep_buffer_minutes" name="prep_buffer_minutes" type="number" min="0" max="180"
                                        value="{{ $value('prep_buffer_minutes') }}" required>
                                    <span class="unit">{{ __('setup.units.minutes') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-person-badge"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.admin') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.admin') }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <div class="field">
                                <label for="admin_name">{{ __('setup.fields.admin_name') }}</label>
                                <input class="control" id="admin_name" name="admin_name" maxlength="120"
                                    value="{{ old('admin_name') }}" placeholder="{{ __('setup.placeholders.admin_name') }}" required>
                            </div>
                            <div class="field">
                                <label for="admin_username">{{ __('setup.fields.admin_username') }}</label>
                                <input class="control ltr" id="admin_username" name="admin_username" maxlength="50"
                                    value="{{ old('admin_username', 'admin') }}" placeholder="{{ __('setup.placeholders.admin_username') }}" required>
                            </div>
                            <div class="field">
                                <label for="admin_email">{{ __('setup.fields.admin_email') }}</label>
                                <input class="control ltr" id="admin_email" name="admin_email" type="email" maxlength="120"
                                    value="{{ old('admin_email') }}" placeholder="{{ __('setup.placeholders.admin_email') }}">
                            </div>
                            <div class="field">
                                <label for="admin_phone">{{ __('setup.fields.admin_phone') }}</label>
                                <input class="control ltr" id="admin_phone" name="admin_phone" maxlength="50"
                                    value="{{ old('admin_phone') }}">
                            </div>
                            <div class="field">
                                <label for="admin_password">{{ __('setup.fields.admin_password') }}</label>
                                <input class="control ltr" id="admin_password" name="admin_password" type="password" autocomplete="new-password" required>
                            </div>
                            <div class="field">
                                <label for="admin_password_confirmation">{{ __('setup.fields.admin_password_confirmation') }}</label>
                                <input class="control ltr" id="admin_password_confirmation" name="admin_password_confirmation" type="password" autocomplete="new-password" required>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-box-seam"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.operations') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.operations') }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <div class="field">
                                <span class="toggle-label">{{ __('setup.fields.strict_stock') }}</span>
                                <input type="hidden" name="strict_stock" value="0">
                                <label class="toggle">
                                    <input type="checkbox" name="strict_stock" value="1" @checked($checked('strict_stock', true))>
                                    <span>{{ __('setup.fields.strict_stock') }}</span>
                                </label>
                            </div>
                            <div class="field">
                                <label for="inventory_deduction_stage">{{ __('setup.fields.inventory_deduction_stage') }}</label>
                                <select class="control" id="inventory_deduction_stage" name="inventory_deduction_stage">
                                    @foreach(['approve', 'preparing', 'ready', 'served'] as $stage)
                                        <option value="{{ $stage }}" @selected($value('inventory_deduction_stage') === $stage)>{{ __('setup.options.'.$stage) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="customer_cancel_window_seconds">{{ __('setup.fields.customer_cancel_window_seconds') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="customer_cancel_window_seconds" name="customer_cancel_window_seconds" type="number" min="0" max="900"
                                        value="{{ $value('customer_cancel_window_seconds') }}" required>
                                    <span class="unit">{{ __('setup.units.seconds') }}</span>
                                </div>
                            </div>
                            <div class="field">
                                <label for="session_ttl_minutes">{{ __('setup.fields.session_ttl_minutes') }}</label>
                                <div class="input-unit">
                                    <input class="control ltr" id="session_ttl_minutes" name="session_ttl_minutes" type="number" min="30" max="1440"
                                        value="{{ $value('session_ttl_minutes') }}" required>
                                    <span class="unit">{{ __('setup.units.minutes') }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section">
                        <div class="section-head">
                            <div class="section-icon"><i class="bi bi-cloud-check"></i></div>
                            <div>
                                <h3 class="section-title">{{ __('setup.sections.connectivity') }}</h3>
                                <p class="section-hint">{{ __('setup.hints.connectivity') }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <input type="hidden" name="sync_branch_uuid" value="{{ $value('sync_branch_uuid') }}">
                            <div class="field full">
                                <label for="menu_base_url">{{ __('setup.fields.menu_base_url') }}</label>
                                <input class="control ltr" id="menu_base_url" name="menu_base_url" maxlength="255"
                                    value="{{ $value('menu_base_url') }}" placeholder="{{ __('setup.placeholders.menu_base_url') }}">
                            </div>
                            <div class="field">
                                <span class="toggle-label">{{ __('setup.fields.sync_enabled') }}</span>
                                <input type="hidden" name="sync_enabled" value="0">
                                <label class="toggle">
                                    <input type="checkbox" name="sync_enabled" value="1" @checked($checked('sync_enabled'))>
                                    <span>{{ __('setup.fields.sync_enabled') }}</span>
                                </label>
                            </div>
                            <div class="field">
                                <label for="sync_role">{{ __('setup.fields.sync_role') }}</label>
                                <select class="control" id="sync_role" name="sync_role">
                                    @foreach(['standalone', 'branch', 'cloud'] as $role)
                                        <option value="{{ $role }}" @selected($value('sync_role') === $role)>{{ __('setup.options.'.$role) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="sync_cloud_url">{{ __('setup.fields.sync_cloud_url') }}</label>
                                <input class="control ltr" id="sync_cloud_url" name="sync_cloud_url" maxlength="255"
                                    value="{{ $value('sync_cloud_url') }}" placeholder="{{ __('setup.placeholders.sync_cloud_url') }}">
                            </div>
                            <div class="field">
                                <label for="sync_token">{{ __('setup.fields.sync_token') }}</label>
                                <input class="control ltr" id="sync_token" name="sync_token" maxlength="255"
                                    value="{{ $value('sync_token') }}" placeholder="{{ __('setup.placeholders.sync_token') }}">
                            </div>
                        </div>
                    </section>

                    <div class="actions">
                        <a href="{{ route('login') }}" class="btn-secondary">{{ __('setup.actions.go_login') }}</a>
                        <button class="btn-primary" type="submit" id="finishButton">
                            <i class="bi bi-check2-circle"></i>
                            <span data-label>{{ __('setup.actions.finish') }}</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </main>

    <script>
        const profileDefaults = {
            palestine: {
                currency_symbol: '₪',
                sales_currency: 'ILS',
                accounting_base_currency: 'ILS',
                accounting_currency_symbol: '₪',
                sales_to_accounting_rate: '1',
                tax_rate: '16',
                service_rate: '10',
                fiscal_year_start_month: '1',
                fiscal_year_start_day: '1',
                branch_name: @js(__('setup.defaults.branch_palestine')),
                branch_name_en: 'Main Branch'
            },
            us: {
                currency_symbol: '$',
                sales_currency: 'USD',
                accounting_base_currency: 'USD',
                accounting_currency_symbol: '$',
                sales_to_accounting_rate: '1',
                tax_rate: '0',
                service_rate: '0',
                fiscal_year_start_month: '1',
                fiscal_year_start_day: '1',
                branch_name: @js(__('setup.defaults.branch_us')),
                branch_name_en: 'Main Branch'
            }
        };

        document.querySelectorAll('[data-profile-managed]').forEach((field) => {
            field.addEventListener('input', () => field.dataset.touched = '1', { passive: true });
        });

        document.querySelectorAll('input[name="market_profile"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const defaults = profileDefaults[radio.value] || {};
                Object.entries(defaults).forEach(([id, nextValue]) => {
                    const field = document.getElementById(id);
                    if (field && field.dataset.touched !== '1') {
                        field.value = nextValue;
                    }
                });
            });
        });

        const themeCssVars = {
            theme_primary: '--primary',
            theme_dark: '--primary-dark',
            theme_accent: '--accent',
        };

        const updateColorCode = (input) => {
            const value = input.value || '';
            const code = document.querySelector(`[data-color-code="${input.name}"]`);
            if (code) code.textContent = value;
            if (themeCssVars[input.name]) {
                document.documentElement.style.setProperty(themeCssVars[input.name], value);
            }
        };

        document.querySelectorAll('[data-theme-color]').forEach((input) => {
            updateColorCode(input);
            input.addEventListener('input', () => updateColorCode(input));
        });

        document.querySelectorAll('[data-theme-preset]').forEach((button) => {
            button.addEventListener('click', () => {
                const preset = JSON.parse(button.dataset.themePreset || '{}');
                Object.entries(preset).forEach(([name, value]) => {
                    const input = document.querySelector(`[name="${name}"]`);
                    if (! input) return;
                    input.value = value;
                    updateColorCode(input);
                });
            });
        });

        document.getElementById('setupForm')?.addEventListener('submit', () => {
            const button = document.getElementById('finishButton');
            if (! button) return;
            button.disabled = true;
            button.querySelector('[data-label]').textContent = @js(__('setup.actions.saving'));
        });
    </script>
</body>
</html>
