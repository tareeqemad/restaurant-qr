@php
    $theme = $theme ?? \App\Support\ThemePalette::current();
@endphp

<style data-relax-runtime-theme>
    @include('partials.theme-vars', ['theme' => $theme])

    :root {
        --brand-rgb: var(--primary-rgb);
        --brand-dark-rgb: var(--dark-rgb);
        --forest: var(--primary);
        --forest-deep: var(--dark);
        --forest-dark: var(--dark);
        --gold-light: rgba(var(--accent-rgb), 0.58);
        --cream: var(--menu);
        --bg: var(--menu);
        --border: rgba(var(--primary-rgb), 0.14);
        --line: rgba(var(--primary-rgb), 0.14);
    }
</style>
