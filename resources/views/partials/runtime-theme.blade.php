@php
    $settings = \App\Models\Setting::class;
    $themeDefaults = config('restaurant.theme', []);

    $normalizeHex = function ($color, string $fallback): string {
        $hex = ltrim((string) $color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 && ctype_xdigit($hex) ? '#'.strtolower($hex) : $fallback;
    };

    $hexToRgb = function (string $color, string $fallback): string {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            return hexdec(substr($hex, 0, 2)).', '.hexdec(substr($hex, 2, 2)).', '.hexdec(substr($hex, 4, 2));
        }

        return $fallback;
    };

    $scaleHex = function (string $color, float $factor, string $fallback): string {
        $hex = ltrim($color, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return $fallback;
        }

        $channel = function (string $value) use ($factor): int {
            return max(0, min(255, (int) round(hexdec($value) * $factor)));
        };

        return sprintf(
            '#%02x%02x%02x',
            $channel(substr($hex, 0, 2)),
            $channel(substr($hex, 2, 2)),
            $channel(substr($hex, 4, 2))
        );
    };

    $primaryDefault = $themeDefaults['primary'] ?? '#164c37';
    $darkDefault = $themeDefaults['dark'] ?? '#0f2d22';
    $accentDefault = $themeDefaults['accent'] ?? '#b97818';
    $menuDefault = $themeDefaults['menu'] ?? '#f7f8f5';

    $primaryColor = $normalizeHex($settings::get('theme_primary', $primaryDefault), $primaryDefault);
    $darkColor = $normalizeHex($settings::get('theme_dark', $darkDefault), $darkDefault);
    $accentColor = $normalizeHex($settings::get('theme_accent', $accentDefault), $accentDefault);
    $menuColor = $normalizeHex($settings::get('theme_menu', $menuDefault), $menuDefault);

    $primaryRgb = $hexToRgb($primaryColor, '22, 76, 55');
    $darkRgb = $hexToRgb($darkColor, '15, 45, 34');
    $accentRgb = $hexToRgb($accentColor, '185, 120, 24');
    $accentDark = $scaleHex($accentColor, 0.72, '#8a6920');
@endphp

<style data-relax-runtime-theme>
    :root {
        --brand: {{ $primaryColor }};
        --brand-rgb: {{ $primaryRgb }};
        --brand-dark: {{ $darkColor }};
        --brand-dark-rgb: {{ $darkRgb }};
        --brand-light: rgba({{ $primaryRgb }}, 0.78);
        --brand-soft: rgba({{ $primaryRgb }}, 0.12);
        --forest: {{ $primaryColor }};
        --forest-deep: {{ $darkColor }};
        --forest-dark: {{ $scaleHex($darkColor, 0.74, '#0d2317') }};
        --green-primary: {{ $primaryColor }};
        --green-dark: {{ $darkColor }};
        --green-soft: rgba({{ $primaryRgb }}, 0.78);
        --green-mint: rgba({{ $primaryRgb }}, 0.16);
        --primary: {{ $primaryColor }};
        --primary-rgb: {{ $primaryRgb }};
        --accent: {{ $accentColor }};
        --accent-rgb: {{ $accentRgb }};
        --accent-dark: {{ $accentDark }};
        --accent-soft: rgba({{ $accentRgb }}, 0.16);
        --gold: {{ $accentColor }};
        --gold-light: rgba({{ $accentRgb }}, 0.58);
        --gold-soft: rgba({{ $accentRgb }}, 0.14);
        --cream: {{ $menuColor }};
        --bg: {{ $menuColor }};
        --border: rgba({{ $primaryRgb }}, 0.14);
        --line: rgba({{ $primaryRgb }}, 0.14);
        --success: {{ $primaryColor }};
        --warning: {{ $accentColor }};
        --info: {{ $primaryColor }};
        --brand-gradient: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
        --brand-gradient-y: linear-gradient(135deg, var(--brand) 0%, var(--accent) 100%);
        --gold-gradient: linear-gradient(135deg, rgba({{ $accentRgb }}, 0.78) 0%, var(--accent) 100%);
    }
</style>
