<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Station;
use App\Models\User;

class UserEditor
{
    public static function catalogue(User $actor): array
    {
        $accessibleIds = $actor->isOwnerLevel()
            ? Branch::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : array_map('intval', $actor->accessibleBranchIds());

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return [
            'roles' => collect(UserRole::grantableOptions($actor))
                ->map(fn (string $label, string $value) => [
                    'value' => $value,
                    'label' => $label,
                ])->values()->all(),
            'ownerRoles' => UserRole::ownerRoles(),
            'stations' => Station::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Station $station) => [
                    'id' => (int) $station->id,
                    'name' => $station->name,
                ])->values()->all(),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => (int) $branch->id,
                'name' => $branch->localizedName(),
                'code' => $branch->code,
                'city' => $branch->city,
                'accessible' => in_array((int) $branch->id, $accessibleIds, true),
            ])->values()->all(),
            'defaultBranchId' => in_array((int) BranchContext::current(), $accessibleIds, true)
                ? (int) BranchContext::current()
                : ($accessibleIds[0] ?? null),
            'canCreate' => (bool) $actor->can('create', User::class),
            'canManagePermissions' => $actor->isSuperAdmin(),
            'urls' => [
                'create' => route('admin.users.store'),
                'permissions' => $actor->isSuperAdmin()
                    ? route('admin.permissions.index', ['tab' => 'users'])
                    : null,
                'createBranch' => $actor->can('create', Branch::class)
                    ? route('admin.branches.create')
                    : null,
            ],
            '_accessibleBranchIds' => $accessibleIds,
        ];
    }

    public static function account(User $user, User $actor, array $catalogue, ?int $activeSuperAdmins = null): array
    {
        $accessibleIds = array_map('intval', $catalogue['_accessibleBranchIds'] ?? []);
        $assignedIds = $user->branches->pluck('id')->map(fn ($id) => (int) $id);
        $primary = $user->branches->first(fn (Branch $branch) => (bool) $branch->pivot?->is_primary);
        $isSelf = (int) $actor->id === (int) $user->id;
        $lastActiveSuperAdmin = $user->role === UserRole::SuperAdmin->value
            && $user->status === 'active'
            && ($activeSuperAdmins ?? User::query()->where('role', UserRole::SuperAdmin->value)->where('status', 'active')->count()) === 1;
        $targetManageable = $isSelf || in_array($user->role, UserRole::grantableBy($actor), true);

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email ?? '',
            'phone' => $user->phone ?? '',
            'role' => $user->role,
            'roleLabel' => $user->role_label,
            'status' => $user->status,
            'stationId' => $user->station_id ? (int) $user->station_id : null,
            'branchIds' => $assignedIds->filter(fn (int $id) => in_array($id, $accessibleIds, true))->values()->all(),
            'lockedBranchIds' => $assignedIds->reject(fn (int $id) => in_array($id, $accessibleIds, true))->values()->all(),
            'primaryBranchId' => $primary && in_array((int) $primary->id, $accessibleIds, true)
                ? (int) $primary->id
                : null,
            'lockedPrimaryBranchId' => $primary && ! in_array((int) $primary->id, $accessibleIds, true)
                ? (int) $primary->id
                : null,
            'canUpdate' => $targetManageable && (bool) $actor->can('update', $user),
            'guard' => [
                'lockRole' => $isSelf || $lastActiveSuperAdmin,
                'lockStatus' => $isSelf || $lastActiveSuperAdmin,
                'message' => $isSelf
                    ? 'يمكنك تعديل بياناتك، لكن لا يمكنك تغيير دور حسابك الحالي أو إيقافه.'
                    : ($lastActiveSuperAdmin
                        ? 'هذا آخر مدير نظام فعّال؛ أضف مدير نظام آخر قبل تغيير دوره أو إيقافه.'
                        : ''),
            ],
            'urls' => [
                'submit' => route('admin.users.update', $user),
                'permissions' => $actor->isSuperAdmin() && ! $user->isOwnerLevel()
                    ? route('admin.permissions.index', ['tab' => 'users', 'user' => $user->id])
                    : null,
            ],
        ];
    }

    public static function exposeCatalogue(array $catalogue): array
    {
        unset($catalogue['_accessibleBranchIds']);

        return $catalogue;
    }
}
