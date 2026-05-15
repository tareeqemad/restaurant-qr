@php
    $theme = $theme ?? \App\Support\ThemePalette::current();
@endphp
:root {
    --primary: {{ $theme['primary'] }};
    --primary-rgb: {{ $theme['primary_rgb'] }};
    --primary-color: rgb(var(--primary-rgb));
    --primary-border: rgb(var(--primary-rgb));
    --primary005: rgba(var(--primary-rgb), 0.05);
    --primary01: rgba(var(--primary-rgb), 0.1);
    --primary02: rgba(var(--primary-rgb), 0.2);
    --primary03: rgba(var(--primary-rgb), 0.3);
    --primary04: rgba(var(--primary-rgb), 0.4);
    --primary05: rgba(var(--primary-rgb), 0.5);
    --primary06: rgba(var(--primary-rgb), 0.6);
    --primary07: rgba(var(--primary-rgb), 0.7);
    --primary08: rgba(var(--primary-rgb), 0.8);
    --primary09: rgba(var(--primary-rgb), 0.9);
    --dark: {{ $theme['dark'] }};
    --dark-rgb: {{ $theme['dark_rgb'] }};
    --header: {{ $theme['header'] }};
    --header-rgb: {{ $theme['header_rgb'] }};
    --accent: {{ $theme['accent'] }};
    --accent-rgb: {{ $theme['accent_rgb'] }};
    --accent-dark: {{ $theme['accent_dark'] }};
    --accent-soft: {{ $theme['accent_soft'] }};
    --menu: {{ $theme['menu'] }};
    --menu-rgb: {{ $theme['menu_rgb'] }};

    --green-primary: var(--primary);
    --green-dark: var(--dark);
    --green-soft: {{ $theme['primary_light'] }};
    --green-mint: {{ $theme['primary_soft'] }};
    --gold: var(--accent);

    --brand: var(--primary);
    --brand-dark: var(--dark);
    --brand-light: {{ $theme['primary_light'] }};
    --brand-soft: {{ $theme['primary_soft'] }};
    --gold-soft: var(--accent-soft);
    --success: var(--primary);
    --warning: var(--accent);
    --info: var(--primary);
    --brand-gradient: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
    --brand-gradient-y: linear-gradient(135deg, var(--brand) 0%, var(--accent) 100%);
    --gold-gradient: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
}

[data-relax-menu-style="brand"] {
    --menu-bg: {{ $theme['menu'] }};
    --menu-prime-color: rgb(var(--primary-rgb));
    --menu-border-color: rgba(var(--primary-rgb), 0.12);
}

[data-header-styles="color"] {
    --header-bg: {{ $theme['header'] }};
    --header-bg2: rgba(var(--header-rgb), 0.5);
    --header-prime-color: rgba(255, 255, 255, 0.88);
    --header-border-color: rgba(255, 255, 255, 0.14);
}
