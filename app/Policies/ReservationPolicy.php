<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

/**
 * Branch-scoped: a manager only manages reservations of their own branch.
 *
 * `viewAny` is open to admin / manager / waiter / cashier roles — the
 * BranchScope already filters out other branches from the listing, and
 * per-row methods then enforce the branch via inUserBranch().
 *
 * Customers go through their own portal — they do NOT pass through this
 * policy. The portal controller's authorization is "customer_id owns row".
 */
class ReservationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reservations.viewAny');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $reservation);
    }

    public function confirm(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.confirm')
            && $this->inUserBranch($user, $reservation);
    }

    public function seat(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.seat')
            && $this->inUserBranch($user, $reservation);
    }

    public function complete(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.complete')
            && $this->inUserBranch($user, $reservation);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.cancel')
            && $this->inUserBranch($user, $reservation);
    }

    public function noShow(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.no_show')
            && $this->inUserBranch($user, $reservation);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission('reservations.update')
            && $this->inUserBranch($user, $reservation);
    }
}
