<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

/**
 * Cashier shift policy.
 *
 * - viewAny: anyone with cash-handling responsibility (cashier+) — they
 *   need to see their own shifts to clock cash in/out.
 * - create: same — opening one's own shift is a cashier-day-1 action.
 * - close: own shift OR admin/manager (the controller already has this
 *   check inline as `abort_unless`; we mirror it here so future Livewire
 *   surfaces have a consistent gate).
 */
class ShiftPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('shifts.viewAny');
    }

    public function view(User $user, Shift $shift): bool
    {
        // Own shift always visible; admins/managers see any.
        return $shift->user_id === $user->id
            || $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('shifts.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'cashier'])
            || $user->hasPermission('shifts.create');
    }

    public function close(User $user, Shift $shift): bool
    {
        return $shift->user_id === $user->id
            || $user->hasAnyRole(['admin', 'manager'])
            || $user->hasPermission('shifts.close');
    }
}
