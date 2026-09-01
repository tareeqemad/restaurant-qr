<?php

namespace App\Services\Authorization;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermissionAssignmentService
{
    public function syncRole(Role $role, array $permissionIds, User $actor): array
    {
        if (! $actor->isSuperAdmin()) {
            abort(403);
        }

        if (! $role->isGlobal() || in_array($role->name, ['super_admin', 'partner'], true)) {
            throw ValidationException::withMessages([
                'role' => 'هذا الدور شامل بطبيعته ولا يقبل تخصيص الصلاحيات.',
            ]);
        }

        return DB::transaction(function () use ($role, $permissionIds, $actor) {
            $before = $role->permissions()->pluck('permissions.id');
            $checked = $this->validIds($permissionIds);

            $role->permissions()->sync($checked->all());

            $added = $checked->diff($before)->values();
            $removed = $before->diff($checked)->values();

            ActivityLog::log(
                'role.permissions_changed',
                "تحديث صلاحيات دور {$role->label}: {$added->count()} إضافة، {$removed->count()} إزالة",
                $role,
                [
                    'actor_id' => $actor->id,
                    'added_permission_ids' => $added->all(),
                    'removed_permission_ids' => $removed->all(),
                ],
            );

            return ['added' => $added->count(), 'removed' => $removed->count()];
        });
    }

    public function syncUser(User $user, array $permissionIds, User $actor, string $source): array
    {
        if (! $actor->isSuperAdmin()) {
            abort(403);
        }

        if ($user->isOwnerLevel()) {
            $user->permissions()->detach();

            return ['granted' => 0, 'revoked' => 0, 'owner' => true];
        }

        return DB::transaction(function () use ($user, $permissionIds, $actor, $source) {
            $checked = $this->validIds($permissionIds);
            $roleIds = $user->rolePermissionIds();
            $grants = $checked->diff($roleIds)->values();
            $revokes = $roleIds->diff($checked)->values();

            $sync = [];
            foreach ($grants as $id) {
                $sync[$id] = ['granted' => true];
            }
            foreach ($revokes as $id) {
                $sync[$id] = ['granted' => false];
            }

            $user->permissions()->sync($sync);

            ActivityLog::log(
                'user.permissions_changed',
                "تحديث استثناءات {$user->name}: {$grants->count()} منح، {$revokes->count()} سحب",
                $user,
                [
                    'actor_id' => $actor->id,
                    'source' => $source,
                    'granted_permission_ids' => $grants->all(),
                    'revoked_permission_ids' => $revokes->all(),
                ],
            );

            return ['granted' => $grants->count(), 'revoked' => $revokes->count(), 'owner' => false];
        });
    }

    private function validIds(array $permissionIds): Collection
    {
        $ids = collect($permissionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $existing = Permission::query()->whereKey($ids)->pluck('id');

        if ($existing->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'permissions' => 'تتضمن القائمة صلاحية غير موجودة. حدّث الصفحة وأعد المحاولة.',
            ]);
        }

        return $existing;
    }
}
