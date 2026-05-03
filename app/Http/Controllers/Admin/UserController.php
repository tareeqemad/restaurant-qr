<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
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
        return view('admin.users.create', [
            'roles'           => UserRole::options(),
            'stations'        => Station::where('active', true)->get(),
            'branches'        => Branch::where('is_active', true)->orderBy('display_order')->orderBy('name')->get(),
            'branchRoles'     => Role::orderBy('display_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $data = $this->validateData($request);
        $data['password'] = Hash::make($data['password']);

        $branchAssignments = $this->extractBranchAssignments($request);
        $user = User::create($data);
        $user->branches()->sync($branchAssignments);

        ActivityLog::log('user.created', "إنشاء مستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم إنشاء المستخدم');
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);
        return view('admin.users.edit', [
            'user'            => $user->load('branches'),
            'roles'           => UserRole::options(),
            'stations'        => Station::where('active', true)->get(),
            'branches'        => Branch::where('is_active', true)->orderBy('display_order')->orderBy('name')->get(),
            'branchRoles'     => Role::orderBy('display_order')->get(),
        ]);
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

        $branchAssignments = $this->extractBranchAssignments($request);
        $user->update($data);
        $user->branches()->sync($branchAssignments);

        ActivityLog::log('user.updated', "تعديل المستخدم {$user->name}", $user);
        return redirect()->route('admin.users.index')->with('success', 'تم تحديث المستخدم');
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
        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'name_en'              => ['nullable', 'string', 'max:255'],
            'username'             => ['required', 'string', 'max:64', Rule::unique('users')->ignore($id)],
            'email'                => ['nullable', 'email', Rule::unique('users')->ignore($id)],
            'phone'                => ['nullable', 'string', 'max:20'],
            'role'                 => ['required', Rule::in(array_keys(UserRole::options()))],
            'station_id'           => ['nullable', 'exists:stations,id'],
            'status'               => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'password'             => [$id ? 'nullable' : 'required', 'min:6', 'confirmed'],

            // Branch assignments — validated separately below to keep the user
            // create/update payload focused; pivot rows are written via sync().
            'branches'             => ['nullable', 'array'],
            'branches.*'           => ['integer', 'exists:branches,id'],
            'branch_roles'         => ['nullable', 'array'],
            'branch_roles.*'       => ['nullable', 'integer', 'exists:roles,id'],
            'primary_branch_id'    => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        // Strip pivot fields from the User::update payload — they don't belong
        // on the users table.
        unset($validated['branches'], $validated['branch_roles'], $validated['primary_branch_id']);

        return $validated;
    }

    /**
     * Build the sync() payload for `branch_user` from the request.
     *
     * Returns: [branch_id => ['role_id' => ?int, 'is_primary' => bool, 'joined_at' => Carbon]]
     */
    protected function extractBranchAssignments(Request $request): array
    {
        $selected   = $request->input('branches', []);
        $roleMap    = $request->input('branch_roles', []);
        $primaryId  = (int) $request->input('primary_branch_id', 0);

        $payload = [];
        foreach ((array) $selected as $branchId) {
            $bid = (int) $branchId;
            if ($bid <= 0) {
                continue;
            }

            $payload[$bid] = [
                'role_id'    => isset($roleMap[$bid]) && $roleMap[$bid] !== '' ? (int) $roleMap[$bid] : null,
                'is_primary' => $bid === $primaryId,
                'joined_at'  => now(),
            ];
        }

        // Guarantee exactly one primary assignment when there are any rows: if
        // the form didn't pick one, mark the first selected branch as primary.
        if ($payload && ! collect($payload)->contains('is_primary', true)) {
            $firstBid = array_key_first($payload);
            $payload[$firstBid]['is_primary'] = true;
        }

        return $payload;
    }
}
