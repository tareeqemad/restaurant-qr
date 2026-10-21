<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\Station;
use App\Models\Table;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Default workspace only; destination controllers remain the authorization boundary. */
class RoleLanding
{
    public static function destination(User $user, Request $request): string
    {
        if ($user->isWaiter() && $user->can('viewAny', Table::class)) {
            return route('admin.tables.index');
        }
        if ($user->isCashier() && $user->can('viewAny', Payment::class)) {
            return route('admin.cashier.index');
        }
        if ($user->role === 'accountant' && $user->hasPermission('chart_of_accounts.viewAny')) {
            return route('admin.accounting.index');
        }
        if ($user->isChef() || $user->isBartender()) {
            $station = self::stations($user, self::loginBranchId($user, $request))->first();
            if ($station) {
                return route('admin.station.show', $station->code);
            }
        }

        return route('admin.dashboard');
    }

    /** No cross-branch or inactive station shortcuts; prefer the employee's assignment. */
    public static function stations(User $user, ?int $branchId): Collection
    {
        if (! $branchId || ! $user->belongsToBranch($branchId)) {
            return collect();
        }

        return BranchContext::forBranch($branchId, function () use ($user, $branchId) {
            return Station::where('branch_id', $branchId)->where('active', true)
                ->orderBy('display_order')->orderBy('id')->get()
                ->filter(fn (Station $station) => $user->canAccessStation($station->code))
                ->sortBy(fn (Station $station) => (int) $station->id === (int) $user->station_id ? 0 : 1)
                ->values();
        });
    }

    /** Match SetActiveBranch's session / primary pivot / first membership order. */
    private static function loginBranchId(User $user, Request $request): ?int
    {
        $selected = $request->session()->get('active_branch_id');
        if ($selected && ($branch = $user->branches()->where('branches.id', $selected)->first())) {
            return $branch->is_active ? (int) $selected : null;
        }
        $branch = $user->branches()->orderByDesc('branch_user.is_primary')->orderBy('branches.display_order')->first();

        return $branch?->is_active ? (int) $branch->id : null;
    }
}
