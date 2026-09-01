<?php

namespace App\Support;

use App\Models\Setting;

class ThemePalette
{
    public static function current(): array
    {
        $defaults = config('restaurant.theme', []);

        $primaryDefault = $defaults['primary'] ?? '#1f6b50';
        $darkDefault = $defaults['dark'] ?? '#123f31';
        $headerDefault = $defaults['header'] ?? $primaryDefault;
        $accentDefault = $defaults['accent'] ?? '#b97818';
        $menuDefault = $defaults['menu'] ?? '#f7f8f5';

        $primary = self::normalizeHex(Setting::get('theme_primary', $primaryDefault), $primaryDefault);
        $dark = self::normalizeHex(Setting::get('theme_dark', $darkDefault), $darkDefault);
        $header = self::normalizeHex(Setting::get('theme_header', $headerDefault), $headerDefault);
        $accent = self::normalizeHex(Setting::get('theme_accent', $accentDefault), $accentDefault);
        $menu = self::normalizeHex(Setting::get('theme_menu', $menuDefault), $menuDefault);

        $headerStyle = Setting::get('theme_header_style', $defaults['header_style'] ?? 'color');
        $headerStyle = in_array($headerStyle, ['light', 'dark', 'color'], true) ? $headerStyle : 'color';

        $menuStyle = Setting::get('theme_menu_style', $defaults['menu_style'] ?? 'brand');
        $menuStyle = in_array($menuStyle, ['brand', 'light', 'dark'], true) ? $menuStyle : 'brand';

        return [
            'primary' => $primary,
            'dark' => $dark,
            'header' => $header,
            'accent' => $accent,
            'menu' => $menu,
            'primary_rgb' => self::hexToRgb($primary, '31, 107, 80'),
            'dark_rgb' => self::hexToRgb($dark, '18, 63, 49'),
            'header_rgb' => self::hexToRgb($header, '31, 107, 80'),
            'accent_rgb' => self::hexToRgb($accent, '185, 120, 24'),
            'menu_rgb' => self::hexToRgb($menu, '247, 248, 245'),
            'primary_light' => self::mix($primary, '#ffffff', 0.24),
            'primary_soft' => self::mix($primary, '#ffffff', 0.86),
            'accent_dark' => self::darken($accent),
            'accent_soft' => self::mix($accent, '#ffffff', 0.82),
            'header_style' => $headerStyle,
            'menu_style' => $menuStyle,
            'dashtic_menu_style' => $menuStyle === 'brand' ? 'light' : $menuStyle,
        ];
    }

    public static function normalizeHex($color, string $fallback): string
    {
        $hex = ltrim((string) $color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return strlen($hex) === 6 && ctype_xdigit($hex) ? '#'.strtolower($hex) : $fallback;
    }

    public static function hexToRgb(string $color, string $fallback): string
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return $fallback;
        }

        return hexdec(substr($hex, 0, 2)).', '.hexdec(substr($hex, 2, 2)).', '.hexdec(substr($hex, 4, 2));
    }

    public static function darken(string $color, float $factor = 0.68): string
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '#805113';
        }

        return sprintf(
            '#%02x%02x%02x',
            self::clampChannel(hexdec(substr($hex, 0, 2)) * $factor),
            self::clampChannel(hexdec(substr($hex, 2, 2)) * $factor),
            self::clampChannel(hexdec(substr($hex, 4, 2)) * $factor),
        );
    }

    public static function mix(string $color, string $with, float $amount): string
    {
        $color = ltrim(self::normalizeHex($color, '#000000'), '#');
        $with = ltrim(self::normalizeHex($with, '#ffffff'), '#');
        $amount = max(0, min(1, $amount));

        $channel = function (int $offset) use ($color, $with, $amount): int {
            $from = hexdec(substr($color, $offset, 2));
            $to = hexdec(substr($with, $offset, 2));

            return self::clampChannel($from + (($to - $from) * $amount));
        };

        return sprintf('#%02x%02x%02x', $channel(0), $channel(2), $channel(4));
    }

    private static function clampChannel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }
}
