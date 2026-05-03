<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Support\BranchContext;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Roles list — branch-aware:
     *   - Super Admin sees every role (templates + every branch's instances).
     *   - Branch staff see global templates plus only their active branch's
     *     custom roles. The active branch comes from BranchContext (set by
     *     SetActiveBranch middleware).
     */
    public function index()
    {
        $this->authorize('viewAny', Role::class);

        $user      = auth()->user();
        $query     = Role::with(['permissions', 'branch'])->orderBy('display_order');

        // Owner-level users (SuperAdmin + Partner) see every role across the
        // company. Branch staff only see roles available in their active branch
        // (its own customizations + the global templates).
        if (! $user->isOwnerLevel()) {
            $branchId = BranchContext::current();
            if ($branchId) {
                $query->availableTo($branchId);
            } else {
                $query->global();
            }
        }

        $roles = $query->get();

        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        $this->authorize('create', Role::class);

        return view('admin.roles.create', [
            'permissions' => $this->permGrouped(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Role::class);

        $data = $this->valid($request);

        // Branch staff create branch-scoped roles automatically; only Super
        // Admin can create global templates (branch_id NULL).
        $data['branch_id'] = $this->resolveBranchId($request);

        $role = Role::create($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLog::log('role.created',
            "إنشاء دور «{$role->label}»" . ($role->branch_id ? ' (مخصَّص لفرع)' : ' (قالب عام)'),
            $role
        );

        return redirect()->route('admin.roles.index')->with('success', 'تم إنشاء الدور');
    }

    public function edit(Role $role)
    {
        $this->authorize('update', $role);

        return view('admin.roles.edit', [
            'role'        => $role,
            'permissions' => $this->permGrouped(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorize('update', $role);

        $data = $this->valid($request);
        // branch_id is immutable after creation — don't let admins move a role
        // between branches by editing the form.
        unset($data['branch_id']);

        $role->update($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        ActivityLog::log('role.updated', "تعديل دور «{$role->label}»", $role);

        return redirect()->route('admin.roles.index')->with('success', 'تم تحديث الدور');
    }

    public function destroy(Role $role)
    {
        $this->authorize('delete', $role);

        if ($role->is_system) {
            abort(403, 'لا يمكن حذف دور نظام');
        }

        $name = $role->label;
        $role->delete();

        ActivityLog::log('role.deleted', "حذف دور «{$name}»");

        return back()->with('success', 'تم حذف الدور');
    }

    /**
     * Clone a global role template into the current branch so it can be
     * customised without touching the template. Only available to non-super
     * admins (super admin has no need to clone — they edit the template).
     */
    public function clone(Role $role)
    {
        $this->authorize('create', Role::class);

        if (! $role->isGlobal()) {
            return back()->with('error', 'يمكنك استنساخ القوالب العامة فقط.');
        }

        $branchId = BranchContext::current();
        if (! $branchId) {
            return back()->with('error', 'لا يوجد فرع نشط للاستنساخ إليه.');
        }

        // Avoid silently producing duplicates.
        $existing = Role::forBranch($branchId)->where('name', $role->name)->first();
        if ($existing) {
            return redirect()
                ->route('admin.roles.edit', $existing)
                ->with('success', "هذا الدور موجود بالفعل في الفرع — تحرير النسخة المحلية.");
        }

        $clone = $role->replicate(['created_at', 'updated_at']);
        $clone->branch_id     = $branchId;
        $clone->is_system     = false;
        $clone->display_order = $role->display_order;
        $clone->save();

        $clone->permissions()->sync($role->permissions->pluck('id')->all());

        ActivityLog::log('role.cloned',
            "استنساخ قالب الدور «{$role->label}» إلى الفرع الحالي",
            $clone
        );

        return redirect()
            ->route('admin.roles.edit', $clone)
            ->with('success', "تم استنساخ «{$role->label}» — تستطيع تخصيص صلاحياته الآن.");
    }

    protected function permGrouped()
    {
        return Permission::orderBy('group')->orderBy('display_order')->get()->groupBy('group');
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'name'           => ['required', 'string'],
            'label'          => ['required', 'string'],
            'description'    => ['nullable', 'string'],
            'display_order'  => ['nullable', 'integer'],
            'permissions'    => ['array'],
            'permissions.*'  => ['exists:permissions,id'],
        ]);
    }

    /**
     * Pin newly-created roles to the right branch context:
     *   - Super Admin without an active branch  → null (global template).
     *   - Anyone else                           → the active branch.
     *
     * If a Super Admin is currently switched into a branch, new roles they
     * create from there go into that branch — matches their visible context.
     */
    protected function resolveBranchId(Request $request): ?int
    {
        $user     = $request->user();
        $branchId = BranchContext::current();

        // Only owner-level users can mint global role templates (branch_id NULL).
        if ($user->isOwnerLevel() && $request->boolean('as_template')) {
            return null;
        }

        return $branchId ?: null;
    }
}
