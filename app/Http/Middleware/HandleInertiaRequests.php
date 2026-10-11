<?php

namespace App\Http\Middleware;

use App\Helpers\Brand;
use App\Models\Setting;
use App\Support\BranchContext;
use App\Support\ThemePalette;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shared props for every Inertia page (MIGRATION-PILOT.md — Claude lane).
 *
 * Only what EVERY page needs lives here: identity, branch, theme, flash.
 * Page-specific data goes through each route's Inertia::render() props —
 * the §6 contract — so a page's data needs stay visible in one place.
 */
class HandleInertiaRequests extends Middleware
{
    /** @var string Root Blade view — resources/views/inertia.blade.php. */
    protected $rootView = 'inertia';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                // Closure: evaluated per-response, so a mid-session logout
                // never serves a stale user snapshot.
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'role' => $request->user()->role,
                ] : null,
            ],
            'branchId' => fn () => BranchContext::current(),
            'theme' => fn () => ThemePalette::current(),
            'restaurantBrand' => fn () => [
                'name' => Brand::name(),
                'logo' => Brand::logoUrl(),
                'demoNotice' => Setting::get('demo_dataset') === 'dar-al-sarw-v1'
                    ? 'نسخة تجريبية لمطعم وهمي · الأسعار للتوضيح'
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                // Customer flows flash `info` (duplicate submit, carry notes).
                'info' => fn () => $request->session()->get('info'),
            ],
        ]);
    }
}
