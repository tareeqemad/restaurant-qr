<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StaffSetupService
{
    /**
     * The exact untouched accounts created by DemoSeeder. The technical
     * `admin` account is deliberately absent: it may be the only way into a
     * fresh installation and must never be removed by the cleanup shortcut.
     */
    private const DEMO_ACCOUNTS = [
        ['username' => 'partner', 'name' => 'الشريك', 'phone' => '0791111111'],
        ['username' => 'manager', 'name' => 'المدير العام', 'phone' => '0790000001'],
        ['username' => 'waiter1', 'name' => 'أحمد الجرسون', 'phone' => '0790000002'],
        ['username' => 'waiter2', 'name' => 'محمد الجرسون', 'phone' => '0790000003'],
        ['username' => 'chef1', 'name' => 'يوسف الشيف', 'phone' => '0790000004'],
        ['username' => 'bar1', 'name' => 'خالد البارمان', 'phone' => '0790000005'],
        ['username' => 'cashier1', 'name' => 'سارة الكاشير', 'phone' => '0790000006'],
    ];

    /** @return array<int,array<string,mixed>> */
    public function roleCards(User $actor): array
    {
        $allowed = array_values(array_diff(
            UserRole::grantableBy($actor),
            [UserRole::SuperAdmin->value],
        ));

        return collect(UserRole::cases())
            ->filter(fn (UserRole $role) => in_array($role->value, $allowed, true))
            ->map(function (UserRole $role) use ($actor) {
                $users = User::query()->where('role', $role->value);
                if (! $actor->isOwnerLevel()) {
                    $branchIds = $actor->accessibleBranchIds();
                    $users->whereHas('branches', fn ($query) => $query->whereIn('branches.id', $branchIds));
                }

                return [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'description' => $this->roleDescription($role),
                    'icon' => $this->roleIcon($role),
                    'activeCount' => (clone $users)->where('status', 'active')->count(),
                    'requiresBranch' => ! in_array($role->value, UserRole::ownerRoles(), true),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int,array{id:int,name:string}> */
    public function branchesFor(User $actor): array
    {
        return Branch::query()
            ->active()
            ->when(! $actor->isOwnerLevel(), fn ($query) => $query->whereIn('id', $actor->accessibleBranchIds()))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->localizedName(),
            ])
            ->values()
            ->all();
    }

    /**
     * Only returns rows that still match the seeder identity byte-for-byte
     * and have never logged in. A renamed, re-phoned or used account is real
     * operational data and is intentionally protected from bulk cleanup.
     */
    public function demoCandidates(User $actor): Collection
    {
        $allowedRoles = UserRole::grantableBy($actor);

        return User::query()
            ->where('id', '!=', $actor->id)
            ->whereNull('last_login_at')
            ->whereIn('role', $allowedRoles)
            ->where(function ($query) {
                foreach (self::DEMO_ACCOUNTS as $account) {
                    $query->orWhere(function ($candidate) use ($account) {
                        $candidate
                            ->where('username', $account['username'])
                            ->where('name', $account['name'])
                            ->where('phone', $account['phone']);
                    });
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{name:string,phone:?string,role:string,branch_id:?int}  $data
     * @return array{user:User,password:string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $role = UserRole::from($data['role']);
            $branchId = in_array($role->value, UserRole::ownerRoles(), true)
                ? null
                : (int) $data['branch_id'];
            $password = $this->makePassword();
            $stationId = $this->stationIdFor($role, $branchId);

            $user = User::create([
                'name' => $data['name'],
                'username' => $this->nextUsername($role),
                'phone' => $data['phone'] ?: null,
                'role' => $role->value,
                'station_id' => $stationId,
                'status' => 'active',
                'password' => $password,
            ]);

            if ($branchId) {
                $user->branches()->attach($branchId, [
                    'is_primary' => true,
                    'joined_at' => now(),
                ]);
            }

            ActivityLog::log(
                'user.quick_created',
                "إنشاء حساب {$user->name} من تجهيز فريق العمل",
                $user,
                ['role' => $role->value, 'branch_id' => $branchId],
            );

            return ['user' => $user, 'password' => $password];
        });
    }

    /** @param Collection<int,User> $users */
    public function removeDemoAccounts(Collection $users): int
    {
        return DB::transaction(function () use ($users) {
            $removed = 0;
            foreach ($users as $user) {
                $user->update(['status' => 'inactive']);
                $user->delete();
                $removed++;

                ActivityLog::log(
                    'user.demo_removed',
                    "إزالة الحساب التجريبي {$user->name}",
                    $user,
                    ['username' => $user->username, 'role' => $user->role],
                );
            }

            return $removed;
        });
    }

    private function nextUsername(UserRole $role): string
    {
        $prefix = match ($role) {
            UserRole::Partner => 'owner',
            UserRole::Admin => 'admin',
            UserRole::Manager => 'manager',
            UserRole::Accountant => 'accountant',
            UserRole::Waiter => 'waiter',
            UserRole::Chef => 'kitchen',
            UserRole::Bartender => 'bar',
            UserRole::Cashier => 'cashier',
            UserRole::SuperAdmin => 'system',
        };
        $number = User::withTrashed()->where('role', $role->value)->count() + 1;

        do {
            $username = sprintf('%s-%03d', $prefix, $number++);
        } while (User::withTrashed()->where('username', $username)->exists());

        return $username;
    }

    private function makePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return collect(range(1, 8))
            ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');
    }

    private function stationIdFor(UserRole $role, ?int $branchId): ?int
    {
        $code = match ($role) {
            UserRole::Chef => 'kitchen',
            UserRole::Bartender => 'bar',
            default => null,
        };

        if (! $code || ! $branchId) {
            return null;
        }

        return Station::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->where('active', true)
            ->value('id');
    }

    private function roleDescription(UserRole $role): string
    {
        return match ($role) {
            UserRole::Partner => 'مالك يتابع كل الفروع والنتائج.',
            UserRole::Admin => 'إدارة تشغيلية شاملة للمطعم.',
            UserRole::Manager => 'إدارة الموظفين وتشغيل فرع محدد.',
            UserRole::Accountant => 'الحسابات والقيود والتقارير المالية.',
            UserRole::Waiter => 'الطاولات واعتماد الطلبات وتسليمها.',
            UserRole::Chef => 'استلام وتحضير طلبات المطبخ.',
            UserRole::Bartender => 'استلام وتحضير طلبات البار.',
            UserRole::Cashier => 'التحصيل والفواتير والإقفال اليومي.',
            UserRole::SuperAdmin => 'الإدارة التقنية للنظام.',
        };
    }

    private function roleIcon(UserRole $role): string
    {
        return match ($role) {
            UserRole::Partner => 'bi-gem',
            UserRole::Admin => 'bi-person-workspace',
            UserRole::Manager => 'bi-person-badge',
            UserRole::Accountant => 'bi-calculator',
            UserRole::Waiter => 'bi-person-badge',
            UserRole::Chef => 'bi-egg-fried',
            UserRole::Bartender => 'bi-cup-straw',
            UserRole::Cashier => 'bi-receipt-cutoff',
            UserRole::SuperAdmin => 'bi-shield-lock',
        };
    }
}
