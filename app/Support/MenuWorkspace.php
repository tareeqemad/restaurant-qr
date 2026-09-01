<?php

namespace App\Support;

use App\Models\MenuItem;

/**
 * One navigation contract for every menu-management screen.
 *
 * Keeping it on the server means Blade-free Inertia pages, deep links and
 * permission-gated admin navigation all agree on where the manager is.
 */
class MenuWorkspace
{
    public static function navigation(): array
    {
        $user = auth()->user();
        $canManageMenu = (bool) $user?->can('viewAny', MenuItem::class);
        $canViewPromotions = $canManageMenu && (bool) $user?->hasPermission('promotions.viewAny');
        $canManageStations = (bool) $user?->hasAnyRole(['super_admin', 'admin']);

        return array_values(array_filter([
            $canManageMenu ? static::link('items', 'الأصناف', 'bi-egg-fried', 'admin.menu-items.index', 'admin.menu-items.*') : null,
            $canManageMenu ? static::link('categories', 'الأقسام', 'bi-grid-fill', 'admin.categories.index', 'admin.categories.*') : null,
            $canManageMenu ? static::link('modifiers', 'الإضافات', 'bi-sliders2', 'admin.modifiers.index', 'admin.modifiers.*') : null,
            $canManageMenu ? static::link('allergens', 'الحساسية', 'bi-shield-exclamation', 'admin.allergens.index', 'admin.allergens.*') : null,
            $canViewPromotions ? static::link('promotions', 'العروض', 'bi-tag-fill', 'admin.promotions.index', 'admin.promotions.*') : null,
            $canManageStations ? static::link('stations', 'محطات التحضير', 'bi-fire', 'admin.stations.index', 'admin.stations.*') : null,
        ]));
    }

    protected static function link(
        string $key,
        string $label,
        string $icon,
        string $route,
        string $pattern,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'href' => route($route),
            'active' => request()->routeIs($pattern),
        ];
    }
}
