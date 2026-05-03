<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

/**
 * Endpoint behind the in-header branch switcher.
 *
 * Modes:
 *   - Specific branch:  session.active_branch_id = N, view_all_branches = false
 *     → BranchScope filters listings to that branch.
 *   - All branches:     session.view_all_branches = true, active_branch_id forgotten
 *     → SetActiveBranch middleware does NOT bind a branch, BranchScope is a no-op,
 *     and admin listings see every branch's data. Owner-level only (SuperAdmin
 *     + Partner).
 *
 * Authorisation:
 *   - Specific branch: any user who is a member of that branch (owner-level
 *     users can pick any active one).
 *   - All branches: owner-level (SuperAdmin + Partner) only.
 *
 * Inactive branches are rejected with a friendly flash error.
 */
class BranchSwitchController extends Controller
{
    public function switch(Request $request, Branch $branch)
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->belongsToBranch($branch->id)) {
            abort(403, 'لا تملك صلاحية الوصول لهذا الفرع.');
        }

        if (! $branch->is_active) {
            return back()->with('error', "الفرع «{$branch->name}» معطَّل حالياً.");
        }

        $session = $request->session();
        $session->put('active_branch_id', $branch->id);
        $session->forget('view_all_branches');   // exit any prior "all" mode

        return back()->with('success', "تم التبديل إلى فرع «{$branch->name}»");
    }

    public function switchAll(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->isOwnerLevel(), 403,
            'وضع «كل الفروع» متاح للملاك فقط.');

        $session = $request->session();
        $session->put('view_all_branches', true);
        $session->forget('active_branch_id');

        return back()->with('success', 'تم التبديل إلى عرض كل الفروع.');
    }
}
