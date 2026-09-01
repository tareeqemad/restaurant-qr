<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

/**
 * Branch-scoped moderation. The policy never gates *creation* — that's
 * the customer's domain (a different guard) and is enforced at the
 * customer review write layer (eligibility = a Completed reservation
 * the customer owns, with no existing review).
 */
class ReviewPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reviews.viewAny');
    }

    public function view(User $user, Review $review): bool
    {
        return $this->viewAny($user)
            && $this->inUserBranch($user, $review);
    }

    public function hide(User $user, Review $review): bool
    {
        return $user->hasPermission('reviews.hide')
            && $this->inUserBranch($user, $review);
    }

    public function unhide(User $user, Review $review): bool
    {
        return $user->hasPermission('reviews.unhide')
            && $this->inUserBranch($user, $review);
    }

    public function delete(User $user, Review $review): bool
    {
        // Hard delete reserved for admin (manager only hides). Hiding
        // preserves the audit trail; deleting destroys evidence.
        return $user->hasPermission('reviews.delete')
            && $this->inUserBranch($user, $review);
    }
}
