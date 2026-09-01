<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Helpers\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\PermissionAssignmentService;
use App\Support\AdminShell;
use App\Support\UserEditor;
use Illuminate\Http\Request;

class PermissionManagementController extends Controller
{
    public function __construct(private PermissionAssignmentService $assignments) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->isOwnerLevel(), 403);

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $roleNames = array_keys(UserRole::options());
        $roles = Role::query()
            ->global()
            ->whereIn('name', $roleNames)
            ->with('permissions:id,name')
            ->orderBy('display_order')
            ->get();

        $memberCounts = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');
        $activeMemberCounts = User::query()
            ->where('status', 'active')
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $rolePermissionIds = $roles->mapWithKeys(fn (Role $role) => [
            $role->name => $role->permissions->pluck('id')->map(fn ($id) => (int) $id)->values(),
        ]);
        $editorCatalogue = UserEditor::catalogue($actor);
        $activeSuperAdmins = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('status', 'active')
            ->count();

        $users = User::query()
            ->with(['branches:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($rolePermissionIds, $actor, $editorCatalogue, $activeSuperAdmins) {
                $base = collect($rolePermissionIds->get($user->role, []));
                $granted = $user->permissions
                    ->filter(fn (Permission $permission) => (bool) $permission->pivot->granted)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values();
                $revoked = $user->permissions
                    ->reject(fn (Permission $permission) => (bool) $permission->pivot->granted)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values();

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'roleLabel' => $user->role_label,
                    'status' => $user->status,
                    'lastLoginAt' => $user->last_login_at?->diffForHumans(),
                    'branches' => $user->branches->pluck('name')->values()->all(),
                    'owner' => $user->isOwnerLevel(),
                    'rolePermissionIds' => $base->all(),
                    'effectivePermissionIds' => $user->isOwnerLevel()
                        ? []
                        : $base->merge($granted)->unique()->diff($revoked)->values()->all(),
                    'grantedPermissionIds' => $granted->all(),
                    'revokedPermissionIds' => $revoked->all(),
                    'syncUrl' => route('admin.permissions.sync', $user),
                    'editUrl' => route('admin.users.edit', $user),
                    'account' => UserEditor::account($user, $actor, $editorCatalogue, $activeSuperAdmins),
                ];
            })
            ->values();

        return AdminShell::render('Admin/Permissions/Index', [
            'tree' => Permissions::tree($permissions->groupBy('group')),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => (int) $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'members' => (int) ($memberCounts[$role->name] ?? 0),
                'activeMembers' => (int) ($activeMemberCounts[$role->name] ?? 0),
                'locked' => in_array($role->name, ['super_admin', 'partner'], true),
                'permissionIds' => $rolePermissionIds->get($role->name, collect())->all(),
                'syncUrl' => route('admin.permissions.roles.sync', $role),
            ])->values(),
            'users' => $users,
            'canManage' => $actor->isSuperAdmin(),
            'initialTab' => $request->string('tab')->toString() === 'users' ? 'users' : 'roles',
            'focus' => [
                'role' => $request->integer('role') ?: null,
                'user' => $request->integer('user') ?: null,
            ],
            'urls' => [
                'users' => route('admin.users.index'),
            ],
            'rules' => [
                'cashier' => [
                    'title' => 'الكاشير يصحّح عمله فوراً',
                    'items' => [
                        'إلغاء فاتورة لم يدخل عليها أي تحصيل.',
                        'إلغاء دفعة سجّلها بنفسه في نفس اليوم فقط.',
                        'لا يرد أموالاً ولا يشطب ديناً ولا يلغي دفعة موظف آخر.',
                    ],
                ],
                'accountant' => [
                    'title' => 'المحاسب يراجع ويصحّح الأثر المالي',
                    'items' => [
                        'يرى التحصيلات ويعكس أي دفعة موثقة بسبب.',
                        'ينفذ الاسترداد وشطب الديون والتصحيح المحاسبي.',
                        'لا يجمع دفعات جديدة افتراضياً؛ يمكن منحه التحصيل إذا كان هو نفسه الكاشير.',
                    ],
                ],
            ],
            'editor' => UserEditor::exposeCatalogue($editorCatalogue),
        ]);
    }

    public function syncRole(Request $request, Role $role)
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['integer'],
        ]);

        $result = $this->assignments->syncRole($role, $data['permissions'], $request->user());
        $members = User::query()->where('role', $role->name)->count();

        return back()->with(
            'success',
            "تم تحديث دور {$role->label}: {$result['added']} منح و{$result['removed']} سحب. الأثر مطبّق الآن على {$members} موظف."
        );
    }

    public function sync(Request $request, User $user)
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['integer'],
        ]);

        $result = $this->assignments->syncUser(
            $user,
            $data['permissions'],
            $request->user(),
            'permissions_center',
        );

        if ($result['owner']) {
            return back()->with('info', 'دور المالك شامل تلقائياً ولا يحتاج استثناءات.');
        }

        return back()->with(
            'success',
            "تم تحديث {$user->name}: {$result['granted']} منح إضافي و{$result['revoked']} صلاحية مسحوبة."
        );
    }
}
