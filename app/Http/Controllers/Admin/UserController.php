<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Helpers\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        // Users live in a global table with a many-to-many `branch_user`
        // pivot, so BranchScope can't auto-filter them. We mirror its
        // behaviour manually: when a branch is pinned (via switcher or
        // primary-branch default), only show staff who belong to it.
        // Super admin without a pinned branch falls through to the global
        // view — same contract as BranchScope on operational tables.
        $branchId = BranchContext::current();

        $q = User::query()->with(['station', 'branches']);
        if ($branchId) {
            $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }

        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")
                    ->orWhere('username', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('phone', 'like', "%$s%");
            });
        }
        if ($role = $request->get('role')) {
            $q->where('role', $role);
        }
        if ($status = $request->get('status')) {
            $q->where('status', $status);
        }
        $users = $q->latest()->paginate(15)->withQueryString();

        // Stats reuse the same scope so the cards reflect what the table
        // actually shows.
        $statsBase = User::query();
        if ($branchId) {
            $statsBase->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
        }
        $stats = [
            'total'    => (clone $statsBase)->count(),
            'active'   => (clone $statsBase)->where('status', 'active')->count(),
            'admins'   => (clone $statsBase)->whereIn('role', ['super_admin', 'admin'])->count(),
            'inactive' => (clone $statsBase)->where('status', '!=', 'active')->count(),
        ];

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::options(),
            'stats' => $stats,
        ]);
    }

    public function create()
    {
        $this->authorize('create', User::class);
        return view('admin.users.create', array_merge(
            $this->branchFormData(),
            $this->permissionFormData(),
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $data = $this->validateData($request);
        $data['password'] = Hash::make($data['password']);

        // Server-side filter: drop any branch_id the creator can't manage,
        // even if it slipped through validation (defense in depth — the form
        // marks them disabled but a hand-crafted POST would bypass).
        $branchAssignments = $this->extractBranchAssignments(
            $request,
            existingAssignments: collect(),
            accessibleBranchIds: $this->accessibleBranchIdsForAssignment()
        );

        $user = User::create($data);
        $user->branches()->sync($branchAssignments);
        if (auth()->user()?->hasPermission('roles.update')) {
            $this->syncUserPermissions($user, $request);
        }

        ActivityLog::log('user.created', "إنشاء مستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم إنشاء المستخدم');
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);
        return view('admin.users.edit', array_merge(
            ['user' => $user->load('branches', 'permissions')],
            $this->branchFormData(),
            $this->permissionFormData($user),
        ));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $data = $this->validateData($request, $user->id);
        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // Preserve any existing assignments to branches the editor can't
        // see — otherwise a Gaza manager editing a user who's also
        // assigned to Khanyounis would silently strip the Khanyounis row.
        $branchAssignments = $this->extractBranchAssignments(
            $request,
            existingAssignments: $user->branches,
            accessibleBranchIds: $this->accessibleBranchIdsForAssignment()
        );

        $user->update($data);
        $user->branches()->sync($branchAssignments);
        // Sync the permission tree only when the editor can manage roles —
        // any other admin can see the form (for context) but its values
        // aren't applied. The view itself disables the checkboxes too.
        if (auth()->user()?->hasPermission('roles.update')) {
            $this->syncUserPermissions($user, $request);
        }

        ActivityLog::log('user.updated', "تعديل المستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم تحديث المستخدم');
    }

    /**
     * Build the permission-tree payload the user form needs:
     *   - `permissionTree`: the grouped collection rendered by the Blade
     *   - `rolePermIds` : ids the user's role grants by default (pre-checked
     *                     baseline; ticking a NEW one creates an EXTRA grant,
     *                     unticking one creates an explicit REVOKE)
     *   - `userPermIds` : ids the user actually has right now (effective)
     *   - `revokedIds`  : ids stripped from the role default (granted=false)
     *
     * For create() we only show the tree if the chosen role is "templated"
     * (anything other than owner-level). Owner-level users always have full
     * access — surfacing 119 checkboxes for them would just confuse the UX.
     */
    protected function permissionFormData(?User $user = null): array
    {
        $permissions = Permission::orderBy('group')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->groupBy('group');

        return [
            'permissionTree' => Permissions::tree($permissions),
            'rolePermIds'    => $user ? $user->rolePermissionIds()->all() : [],
            'userPermIds'    => $user ? ($user->effectivePermissionIds()?->all() ?? []) : [],
            'revokedIds'     => $user ? $user->revokedPermissionIds()->all() : [],
            'isOwnerLevel'   => $user?->isOwnerLevel() ?? false,
        ];
    }

    /**
     * Persist the user_permission deviations from the form.
     *
     * The DB rule: user_permission stores ONLY the difference between the
     * effective state and the role default. Two kinds of rows exist:
     *   granted=true  → an EXTRA permission this user has beyond their role
     *   granted=false → a permission the role grants but this user is
     *                   explicitly denied
     * Matching the role exactly (either both yes or both no) leaves the
     * table empty — that keeps audits short and re-seeding the role
     * automatically re-applies to everyone.
     *
     * The form posts:
     *   permissions[] = ids the user SHOULD have (checked boxes)
     * We diff that against the role's defaults to compute grants/revokes.
     */
    protected function syncUserPermissions(User $user, Request $request): void
    {
        // Owner-level users never carry per-user overrides — they bypass
        // the check anyway, so storing rows would just be noise.
        if ($user->isOwnerLevel()) {
            $user->permissions()->detach();
            return;
        }

        $request->validate([
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $checked = collect($request->input('permissions', []))->map(fn ($id) => (int) $id)->unique();
        $roleIds = $user->rolePermissionIds();

        $grants  = $checked->diff($roleIds);     // checked but not in role → extra grants
        $revokes = $roleIds->diff($checked);     // unchecked but in role  → explicit revokes

        $sync = [];
        foreach ($grants as $id)  $sync[$id] = ['granted' => true];
        foreach ($revokes as $id) $sync[$id] = ['granted' => false];

        $user->permissions()->sync($sync);

        if ($grants->isNotEmpty() || $revokes->isNotEmpty()) {
            ActivityLog::log(
                'user.permissions_changed',
                "تعديل صلاحيات {$user->name}: "
                .$grants->count()." منح إضافي، "
                .$revokes->count()." إلغاء من الدور",
                $user,
                ['granted' => $grants->all(), 'revoked' => $revokes->all()],
            );
        }
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        $user->delete();
        ActivityLog::log('user.deleted', "حذف المستخدم {$user->name}", $user);
        return back()->with('success', 'تم حذف المستخدم');
    }

    public function toggleStatus(User $user)
    {
        $this->authorize('update', $user);
        $user->update(['status' => $user->status === 'active' ? 'suspended' : 'active']);
        ActivityLog::log('user.status_changed', "تغيير حالة {$user->name} إلى {$user->status}", $user);
        return back()->with('success', 'تم تغيير حالة المستخدم');
    }

    protected function validateData(Request $request, ?int $id = null): array
    {
        // Privilege escalation guard — actor can ONLY assign roles their
        // hierarchy allows (see UserRole::grantableBy). Without this, a
        // branch manager could POST `role=super_admin` and immediately get
        // a fresh root account they fully control.
        $actor = auth()->user();
        $grantable = UserRole::grantableBy($actor);
        $submittedRole = $request->input('role');

        if ($submittedRole && ! in_array($submittedRole, $grantable, true)) {
            // Audit the attempt before throwing — same pattern we use for
            // out-of-scope branch assignments.
            ActivityLog::log(
                'user.role_escalation_attempt',
                "محاولة منح دور خارج صلاحية المُنشئ: {$submittedRole}",
                null,
                [
                    'attempted_role' => $submittedRole,
                    'allowed_roles'  => $grantable,
                    'actor_id'       => $actor?->id,
                    'actor_role'     => $actor?->role,
                    'target_user_id' => $id,
                ]
            );

            throw \Illuminate\Validation\ValidationException::withMessages([
                'role' => 'لا تملك صلاحية منح هذا الدور. اختر دوراً ضمن صلاحياتك.',
            ]);
        }

        // Branches are REQUIRED for everyone except owner-level roles
        // (super_admin / partner) who see all branches anyway. A regular
        // staffer with zero branches falls into a confusing "all branches"
        // mode that breaks BranchScope assumptions everywhere.
        $branchesRequiredForRole = $submittedRole
            && ! in_array($submittedRole, \App\Enums\UserRole::ownerRoles(), true);
        $branchesRule = $branchesRequiredForRole
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];

        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'name_en'              => ['nullable', 'string', 'max:255'],
            'username'             => ['required', 'string', 'max:64', Rule::unique('users')->ignore($id)],
            'email'                => ['nullable', 'email', Rule::unique('users')->ignore($id)],
            'phone'                => ['nullable', 'string', 'max:20'],
            // Restrict the dropdown values at validation time too — Rule::in
            // against $grantable instead of the full options() list.
            'role'                 => ['required', Rule::in($grantable)],
            'station_id'           => ['nullable', 'exists:stations,id'],
            'status'               => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'password'             => [$id ? 'nullable' : 'required', 'min:6', 'confirmed'],
            // Monthly meal allowance — null/blank means "no allowance"
            // (employee cannot place staff meals). 0 is also valid but
            // semantically equivalent to null for the dashboard checks.
            'monthly_meal_allowance' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            // Hard ceiling on TOTAL outstanding debt across all months.
            // Combined with `staff_meal_over_limit_policy` (settings) to
            // block/warn/require approval at order time. Null = no cap.
            'meal_debt_ceiling'      => ['nullable', 'numeric', 'min:0', 'max:999999'],

            // Branch assignments — required for non-owner roles (see above).
            // Pivot rows are written via sync() in extractBranchAssignments().
            // (`branch_roles` removed in May 2026 — per-branch role overrides
            // were dropped, only the global `users.role` is used now.)
            'branches'             => $branchesRule,
            'branches.*'           => ['integer', 'exists:branches,id'],
            'primary_branch_id'    => ['nullable', 'integer', 'exists:branches,id'],
        ], [
            // Arabic messages for the most user-visible failures.
            'role.required' => 'اختر الدور للمستخدم — لا يمكن ترك هذا الحقل فارغاً.',
            'role.in'       => 'لا تملك صلاحية منح هذا الدور. اختر دوراً ضمن صلاحياتك.',
            'name.required'     => 'اكتب اسم المستخدم.',
            'username.required' => 'اكتب اسم الدخول.',
            'username.unique'   => 'اسم الدخول مستخدم من قبل — اختر اسماً آخر.',
            'email.unique'      => 'البريد الإلكتروني مسجَّل لمستخدم آخر.',
            'password.required' => 'اكتب كلمة المرور.',
            'password.min'      => 'كلمة المرور يجب أن تكون 6 أحرف فأكثر.',
            'password.confirmed'=> 'تأكيد كلمة المرور لا يطابق كلمة المرور.',
            'status.required'   => 'اختر حالة المستخدم.',
            'branches.required' => 'اختر فرعاً واحداً على الأقل يعمل فيه هذا المستخدم.',
            'branches.array'    => 'اختر فرعاً واحداً على الأقل يعمل فيه هذا المستخدم.',
            'branches.min'      => 'اختر فرعاً واحداً على الأقل يعمل فيه هذا المستخدم.',
        ]);

        // Strip pivot fields from the User::update payload — they don't belong
        // on the users table.
        unset($validated['branches'], $validated['primary_branch_id']);

        return $validated;
    }

    /**
     * Build the sync() payload for `branch_user` from the request — with
     * a server-side scope filter so a non-owner can never assign someone
     * to a branch they themselves don't manage.
     *
     * The trick on UPDATE is to preserve the existing rows for branches
     * outside our scope: a Gaza manager editing a user who's ALSO in
     * Khanyounis must keep the Khanyounis assignment intact even though
     * they can't see it in the form. Otherwise sync() would wipe it.
     *
     * Returns: [branch_id => ['role_id' => ?int, 'is_primary' => bool, 'joined_at' => Carbon]]
     */
    protected function extractBranchAssignments(
        Request $request,
        \Illuminate\Support\Collection $existingAssignments,
        array $accessibleBranchIds,
    ): array {
        $selected   = $request->input('branches', []);
        $primaryId  = (int) $request->input('primary_branch_id', 0);

        // Limit anything WE write to branches we're allowed to manage.
        $accessible = array_map('intval', $accessibleBranchIds);

        $payload = [];

        // 1. Carry forward existing rows for branches OUTSIDE our scope.
        // We never had the right to touch them, so we leave them alone.
        foreach ($existingAssignments as $branch) {
            if (! in_array((int) $branch->id, $accessible, true)) {
                $payload[(int) $branch->id] = [
                    'is_primary' => (bool) $branch->pivot->is_primary,
                    'joined_at'  => $branch->pivot->joined_at ?? now(),
                ];
            }
        }

        // 2. Apply the form selections — but only for branches we can manage.
        // Track any bypass attempt for the audit log: the form marks
        // out-of-scope checkboxes `disabled`, but a hand-crafted POST
        // (curl, devtools removing the attribute) is the exact attack we
        // need to log so future incidents are explainable.
        $bypassed = [];
        foreach ((array) $selected as $branchId) {
            $bid = (int) $branchId;
            if ($bid <= 0) continue;
            if (! in_array($bid, $accessible, true)) {
                $bypassed[] = $bid;
                continue;   // silently drop anything out-of-scope
            }

            $payload[$bid] = [
                // Primary flag honoured only if the chosen branch is in scope.
                'is_primary' => $bid === $primaryId && in_array($primaryId, $accessible, true),
                'joined_at'  => now(),
            ];
        }

        // 3. Guarantee exactly one primary. If none picked, prefer one of
        // OUR assignments first (so a Gaza manager creating a user lands
        // them on Gaza by default), else fall back to whatever exists.
        if ($payload && ! collect($payload)->contains('is_primary', true)) {
            $ourBids = array_intersect(array_keys($payload), $accessible);
            $firstBid = $ourBids ? reset($ourBids) : array_key_first($payload);
            $payload[$firstBid]['is_primary'] = true;
        }

        // 4. Audit any bypass attempt — actor + which branches were dropped.
        // Goes to the activity log so SuperAdmin can review later.
        if ($bypassed) {
            ActivityLog::log(
                'user.branch_bypass_attempt',
                'محاولة إسناد فرع خارج نطاق الصلاحية: ' . implode(',', $bypassed),
                null,
                [
                    'attempted_branch_ids' => $bypassed,
                    'allowed_branch_ids'   => $accessible,
                    'actor_id'             => auth()->id(),
                    'actor_name'           => auth()->user()?->name,
                ]
            );
        }

        return $payload;
    }

    /**
     * The set of branch IDs the current user is allowed to assign other
     * users to. Owner-level (super_admin/partner) sees every active branch;
     * everyone else is restricted to their own member branches.
     *
     * @return int[]
     */
    protected function accessibleBranchIdsForAssignment(): array
    {
        $user = auth()->user();
        if ($user->isOwnerLevel()) {
            return Branch::where('is_active', true)->pluck('id')->all();
        }
        return $user->accessibleBranchIds();   // already filters to active branches
    }

    /**
     * Shared form data for create/edit: the FULL active-branches list (so
     * out-of-scope rows can still render disabled with a 🔒 hint), plus the
     * accessible-id allowlist the form uses to enable/disable each card,
     * plus the role + station catalogues.
     */
    protected function branchFormData(): array
    {
        $branches = Branch::where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $actor = auth()->user();

        return [
            // Only show roles the actor is allowed to grant. UI honours this;
            // the server `validateData()` re-validates as defense in depth.
            'roles'                => UserRole::grantableOptions($actor),
            'stations'             => Station::where('active', true)->get(),
            'branches'             => $branches,
            'accessibleBranchIds'  => $this->accessibleBranchIdsForAssignment(),
            // `branchRoles` removed — per-branch role overrides were
            // dropped in May 2026 (only the global `users.role` is used).
        ];
    }
}
