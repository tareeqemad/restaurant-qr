<?php

namespace App\Support;

use App\Helpers\Brand;
use App\Models\Branch;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entry point for every Inertia ADMIN page (Wave 0).
 *
 * AdminShell::render() swaps the root view to inertia-admin.blade.php
 * (the Dashtic-skinned document) and attaches the `shell` prop that
 * AdminLayout.vue feeds on: brand, the policy-gated nav from AdminNav,
 * and the header payloads (branch switcher / attendance / bell URLs).
 *
 * Full-viewport screens (waiter POS) keep calling Inertia::render()
 * directly on the bare root — they have no chrome to feed.
 */
class AdminShell
{
    public static function render(string $component, array $props = []): Response
    {
        Inertia::setRootView('inertia-admin');

        return Inertia::render($component, $props + [
            'shell' => fn () => static::props(),
        ]);
    }

    protected static function props(): array
    {
        $u = auth()->user();

        return [
            'brand' => [
                'name' => Brand::name(),
                'logo' => Brand::logoUrl(),
            ],
            'user' => $u ? [
                'name' => $u->name,
                'roleLabel' => $u->role_label ?? $u->role,
                'avatar' => $u->avatar_url ?: Brand::defaultAvatarDataUri(),
            ] : null,
            'nav' => AdminNav::build(),
            'workspace' => InventoryWorkspace::payload(),
            'branch' => static::branchSwitcher($u),
            'attendance' => static::attendance($u),
            'urls' => [
                'dashboard' => route('admin.dashboard'),
                'profile' => route('admin.profile.show'),
                'logout' => route('logout'),
                'notifications' => [
                    'recent' => route('admin.notifications.recent'),
                    'readAll' => route('admin.notifications.read-all'),
                    'base' => url('admin/notifications'),
                    'index' => route('admin.notifications.index'),
                ],
            ],
        ];
    }

    /** Mirrors admin/partials/branch_switcher.blade.php's data decisions. */
    protected static function branchSwitcher($u): ?array
    {
        if (! $u) {
            return null;
        }

        $available = $u->isOwnerLevel()
            ? Branch::where('is_active', true)->orderBy('display_order')->orderBy('name')->get()
            : $u->branches()->where('is_active', true)->orderBy('branches.display_order')->orderBy('branches.name')->get();

        if ($available->isEmpty()) {
            return null;
        }

        $isAllMode = $u->isOwnerLevel() && session('view_all_branches');
        $activeId = $isAllMode ? null : (session('active_branch_id') ?? BranchContext::current());
        $active = $activeId ? ($available->firstWhere('id', $activeId) ?? $available->first()) : null;

        return [
            'single' => $available->count() === 1 && ! $u->isOwnerLevel(),
            'allMode' => (bool) $isAllMode,
            'activeName' => $active?->localizedName(),
            'activeHue' => $active ? ($active->id * 47) % 360 : null,
            'canAll' => $u->isOwnerLevel(),
            'allUrl' => $u->isOwnerLevel() ? route('admin.branches.switch.all') : null,
            'manageUrl' => $u->can('viewAny', Branch::class) ? route('admin.branches.index') : null,
            'branches' => $available->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->localizedName(),
                // Suppress city when the branch name already mentions it.
                'city' => ($b->city && mb_stripos($b->name, $b->city) === false) ? $b->city : null,
                'hue' => ($b->id * 47) % 360,
                'active' => ! $isAllMode && $active && $b->id === $active->id,
                'switchUrl' => route('admin.branches.switch', $b),
            ])->values()->all(),
        ];
    }

    /** Mirrors admin/partials/attendance_widget.blade.php. */
    protected static function attendance($u): ?array
    {
        if (! $u) {
            return null;
        }

        $open = $u->openAttendance();

        return [
            'open' => $open ? [
                'branch' => $open->branch?->localizedName(),
                'since' => $open->clock_in_at->toIso8601String(),
                'breakMinutes' => (int) $open->break_minutes,
                'label' => $open->durationLabel(),
            ] : null,
            'clockInUrl' => route('admin.attendance.clock-in'),
            'clockOutUrl' => route('admin.attendance.clock-out'),
        ];
    }
}
