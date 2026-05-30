<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;

/**
 * Standalone "إدارة الصلاحيات" page.
 *
 * The user-edit form already embeds the permission tree inline, but
 * that's awkward when an admin wants to:
 *   - quickly compare permissions across users without re-loading each form
 *   - grant/revoke without going through edit → scroll → save the whole user
 *   - jump straight from sidebar to a permission task
 *
 * So this controller serves a dedicated page:
 *   GET  /admin/permissions          → user picker + optional tree
 *   GET  /admin/permissions?user=ID  → picker + that user's tree
 *   PUT  /admin/permissions/{user}   → persists deviations only
 *
 * The page reuses the same partial (admin.permissions._tree) that the
 * user-edit form uses, so behaviour stays identical.
 */
class PermissionManagementController extends Controller
{
    /**
     * Show the user picker + (optionally) one user's permission tree.
     *
     * We gate on `roles.update` to match the inline tree's gating —
     * granting/revoking perms is itself a high-privilege action, so we
     * don't want a regular admin landing here.
     */
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('roles.update'), 403);

        // Branch-scope the user list to whoever the editor can manage,
        // mirroring UserController::index() — a Gaza manager shouldn't
        // see Khanyounis staff in this picker either.
        $branchId = BranchContext::current();
        $usersQuery = User::query()
            ->with(['branches', 'station'])
            ->orderBy('name');

        if ($branchId) {
            $usersQuery->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        $users = $usersQuery->get();

        // Resolve the selected user (if any) from ?user=ID. We bound it
        // to the visible list so a deep-link to a user outside the
        // current branch scope is silently dropped.
        $selectedUser = null;
        if ($userId = $request->integer('user')) {
            $selectedUser = $users->firstWhere('id', $userId);
        }

        $treeData = $selectedUser ? $this->buildTreeData($selectedUser) : [];

        return view('admin.permissions.index', array_merge([
            'users'        => $users,
            'selectedUser' => $selectedUser,
        ], $treeData));
    }

    /**
     * Persist the form's grant/revoke selections.
     *
     * Logic identical to UserController::syncUserPermissions() — DUPLICATED
     * intentionally so this page can evolve independently (e.g. add a
     * "reason" field, bulk operations) without polluting the user form.
     */
    public function sync(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('roles.update'), 403);

        // Owner-level users bypass the permission system entirely. We
        // detach any stray rows (in case a previous role assignment
        // left them behind) and bounce back with no further work.
        if ($user->isOwnerLevel()) {
            $user->permissions()->detach();
            return redirect()->route('admin.permissions.index', ['user' => $user->id])
                ->with('info', 'هذا المستخدم بدور صاحب صلاحيات شاملة — لا يحتاج استثناءات.');
        }

        $request->validate([
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $checked = collect($request->input('permissions', []))->map(fn ($id) => (int) $id)->unique();
        $roleIds = $user->rolePermissionIds();

        // Two flavours of pivot row, mirroring the role-vs-effective diff:
        //   granted=true   → checked AND not in the role → extra grant
        //   granted=false  → unchecked AND in the role   → explicit revoke
        // Rows matching the role default are NOT persisted (the table only
        // stores the delta) — so syncing role + 0 deviations = empty pivot.
        $grants  = $checked->diff($roleIds);
        $revokes = $roleIds->diff($checked);

        $sync = [];
        foreach ($grants as $id)  $sync[$id] = ['granted' => true];
        foreach ($revokes as $id) $sync[$id] = ['granted' => false];

        $user->permissions()->sync($sync);

        if ($grants->isNotEmpty() || $revokes->isNotEmpty()) {
            ActivityLog::log(
                'user.permissions_changed',
                "تعديل صلاحيات {$user->name} من صفحة إدارة الصلاحيات: "
                .$grants->count()." منح إضافي، "
                .$revokes->count()." إلغاء من الدور",
                $user,
                ['granted' => $grants->all(), 'revoked' => $revokes->all(), 'via' => 'permissions_page'],
            );
        }

        return redirect()->route('admin.permissions.index', ['user' => $user->id])
            ->with('success', "تم تحديث صلاحيات {$user->name} بنجاح.");
    }

    /**
     * Build the same payload the user-edit form expects, so the
     * partial sees identical inputs from both pages.
     */
    protected function buildTreeData(User $user): array
    {
        $permissions = Permission::orderBy('group')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->groupBy('group');

        return [
            'user'           => $user,
            'permissionTree' => Permissions::tree($permissions),
            'rolePermIds'    => $user->rolePermissionIds()->all(),
            'userPermIds'    => $user->effectivePermissionIds()?->all() ?? [],
            'isOwnerLevel'   => $user->isOwnerLevel(),
        ];
    }
}
