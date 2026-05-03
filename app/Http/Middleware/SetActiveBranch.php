<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the user's active branch and binds it to the runtime context
 * so BranchScope can filter operational queries.
 *
 * Resolution order:
 *   1. Session value (set by the Branch Switcher UI for users with access
 *      to multiple branches, e.g. owners or regional managers).
 *   2. The user's primary branch (pivot.is_primary = true).
 *
 * Membership is verified before binding — a stale session entry pointing
 * at a branch the user no longer belongs to is dropped silently.
 *
 * Owner-level users (SuperAdmin + Partner): given a default branch for
 * scoped UIs (so dropdowns and "current branch" labels work), but
 * BranchScope ignores filtering for them. They can also enter "view all"
 * mode via the switcher, which leaves BranchContext unbound entirely.
 */
class SetActiveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $session = $request->session();

        // Owner-level "all branches" mode — explicit opt-in via the switcher.
        // Don't bind a branch context, so BranchScope becomes a no-op and
        // listings span every branch. Regular staff can never enter this
        // mode (BranchSwitchController guards it), but we belt-and-suspenders
        // re-check the role here too.
        if ($user->isOwnerLevel() && $session->get('view_all_branches')) {
            return $next($request);
        }

        $branchId = $session->get('active_branch_id');

        // Drop a stale session value if the user no longer has access.
        // Owner-level users can switch into any active branch, so skip the
        // membership check for them.
        if ($branchId && ! $user->isOwnerLevel()
            && ! $user->branches()->where('branches.id', $branchId)->exists()) {
            $session->forget('active_branch_id');
            $branchId = null;
        }

        if (! $branchId) {
            $primary  = $user->branches()->wherePivot('is_primary', true)->first();
            $branchId = $primary?->id;

            // No primary set and the user has at least one branch — fall back
            // to the first one alphabetically by display_order.
            if (! $branchId && $user->branches()->exists()) {
                $branchId = $user->branches()->orderBy('branches.display_order')->first()?->id;
            }

            // Owner-level user without explicit assignments — pick the first
            // active branch so scoped UIs have something to work with.
            if (! $branchId && $user->isOwnerLevel()) {
                $branchId = Branch::active()->orderBy('display_order')->value('id');
            }

            if ($branchId) {
                $session->put('active_branch_id', $branchId);
            }
        }

        if ($branchId) {
            BranchContext::set((int) $branchId);
        }

        return $next($request);
    }
}
