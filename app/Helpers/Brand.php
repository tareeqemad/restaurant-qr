<?php

namespace App\Helpers;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Central accessor for brand assets (logo + favicon + default avatar).
 *
 * Every view reads through these helpers, so:
 *   - When a client uploads a custom logo from /admin/settings →
 *     it instantly shows in the admin header, sidebar, login page,
 *     customer topbar, browser tab, error pages, etc.
 *   - When NO custom logo is uploaded yet → public/default_logo.png
 *     is used as the standard restaurant placeholder.
 *
 * The monogram remains a last-resort inline data URI if the default file is
 * missing, so the UI never shows a broken image.
 */
class Brand
{
    /**
     * Single source of truth for the restaurant name everywhere it is
     * displayed (header, sidebar, SMS, PDF invoices, error pages…).
     *
     * Resolution chain:
     *   1. settings.site_name  (set from /admin/settings — admin overrideable)
     *   2. config('restaurant.name')  (env RESTAURANT_NAME, default 'مطعم QR')
     *
     * No further hardcoded fallback — the config layer already guarantees a
     * non-empty string via its own default. Centralising here means a brand
     * rename only needs to touch the settings row.
     */
    public static function name(): string
    {
        return (string) Setting::get('site_name', config('restaurant.name'));
    }

    /** URL of the uploaded brand logo, or the default restaurant logo. */
    public static function logoUrl(): string
    {
        if ($url = static::storedAssetUrl('brand_logo')) {
            return $url;
        }

        if (file_exists(public_path('default_logo.png'))) {
            return asset('default_logo.png').'?v='.filemtime(public_path('default_logo.png'));
        }

        return static::defaultMonogramDataUri();
    }

    /** URL of the uploaded favicon, or a dynamic monogram fallback. */
    public static function faviconUrl(): string
    {
        if ($url = static::storedAssetUrl('brand_favicon')) {
            return $url;
        }

        return static::defaultMonogramDataUri(square: true);
    }

    /** True if a custom logo has been uploaded (for preview UI). */
    public static function hasCustomLogo(): bool
    {
        return static::storedAssetUrl('brand_logo') !== null;
    }

    public static function hasCustomFavicon(): bool
    {
        return static::storedAssetUrl('brand_favicon') !== null;
    }

    protected static function storedAssetUrl(string $key): ?string
    {
        $path = trim((string) Setting::get($key, ''));
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
            return $path;
        }

        $normalized = ltrim($path, '/');
        $normalized = str_starts_with($normalized, 'storage/')
            ? substr($normalized, strlen('storage/'))
            : $normalized;

        $publicPath = public_path('storage/'.$normalized);
        if (file_exists($publicPath)) {
            return route('brand.asset', ['key' => $key, 'v' => filemtime($publicPath)]);
        }

        if (Storage::disk('public')->exists($normalized)) {
            return route('brand.asset', ['key' => $key, 'v' => Storage::disk('public')->lastModified($normalized)]);
        }

        return null;
    }

    /**
     * Inline-SVG data URI of a clean monogram. Pulls the first letter of
     * the configured site name and renders it on a forest-green disc with
     * a gold border — matches the rest of the brand palette without
     * hard-coding any specific restaurant name.
     *
     * `square` flips the aspect to fit favicon containers (browser tabs).
     */
    public static function defaultMonogramDataUri(bool $square = false): string
    {
        $name = static::name();
        $letter = mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1, 'UTF-8');
        // SVG attribute can't contain literal &, <, >, ", ' — escape defensively.
        $letterSafe = htmlspecialchars($letter, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $size = $square ? 64 : 200;
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$size} {$size}">
  <defs>
    <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#2d6e4a"/>
      <stop offset="100%" stop-color="#1f4733"/>
    </linearGradient>
  </defs>
  <circle cx="50%" cy="50%" r="46%" fill="url(#g)"/>
  <circle cx="50%" cy="50%" r="44%" fill="none" stroke="#d4a550" stroke-width="2"/>
  <text x="50%" y="50%"
        text-anchor="middle"
        dominant-baseline="central"
        font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, sans-serif"
        font-weight="800"
        font-size="56%"
        fill="#f1e4c2">{$letterSafe}</text>
</svg>
SVG;
        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }

    /**
     * Inline-SVG data URI of a neutral "person" silhouette. Used as the
     * default avatar when a user hasn't uploaded a profile photo. Inline so
     * no extra HTTP request, no broken-image icon if storage is misconfigured.
     */
    public static function defaultAvatarDataUri(): string
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
<rect width="100" height="100" fill="#e7e5e4"/>
<circle cx="50" cy="38" r="18" fill="#a8a29e"/>
<path d="M20 90 C20 68, 80 68, 80 90 Z" fill="#a8a29e"/>
</svg>
SVG;
        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }
}
