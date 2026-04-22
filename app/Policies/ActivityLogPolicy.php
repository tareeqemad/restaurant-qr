<?php

namespace App\Policies;

use App\Models\User;

class ActivityLogPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
