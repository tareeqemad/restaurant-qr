<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StandalonePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'a', 'name' => 'A', 'is_active' => true]);
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->owner = $this->makeUser('owner_permissions', 'super_admin');
        $this->cashier = $this->makeUser('cashier_permissions', 'cashier');
    }

    public function test_owner_opens_one_vue_center_for_roles_and_user_exceptions(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->where('canManage', true)
                ->has('roles')
                ->has('users')
                ->where('editor.canCreate', true)
                ->where('editor.urls.create', route('admin.users.store'))
                ->has('users.0.account')
                ->has('tree')
                ->where('urls.users', route('admin.users.index'))
                ->has('tree.0.permissions.0.impactLabel'));
    }

    public function test_inline_user_editor_returns_to_the_same_permissions_tab(): void
    {
        $returnUrl = route('admin.permissions.index', ['tab' => 'users']);

        $this->actingAs($this->owner)
            ->from($returnUrl)
            ->post(route('admin.users.store'), [
                '_inline' => true,
                'name' => 'مستخدم داخل الصفحة',
                'username' => 'inline_user_editor',
                'role' => 'waiter',
                'status' => 'active',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
                'branches' => [$this->branch->id],
                'primary_branch_id' => $this->branch->id,
            ])
            ->assertRedirect($returnUrl)
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'username' => 'inline_user_editor',
            'role' => 'waiter',
        ]);
    }

    public function test_partner_can_review_but_only_super_admin_can_mutate(): void
    {
        $partner = $this->makeUser('partner_permissions', 'partner');
        $permission = Permission::where('name', 'payments.refund')->firstOrFail();

        $this->actingAs($partner)
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

        $this->actingAs($partner)
            ->put(route('admin.permissions.sync', $this->cashier), [
                'permissions' => [$permission->id],
            ])
            ->assertForbidden();
    }

    public function test_regular_admin_cannot_open_the_security_center(): void
    {
        $admin = $this->makeUser('admin_permissions', 'admin');

        $this->actingAs($admin)
            ->get(route('admin.permissions.index'))
            ->assertForbidden();
    }

    public function test_user_overrides_store_only_the_delta_from_the_role(): void
    {
        $roleIds = $this->cashier->rolePermissionIds();
        $extra = Permission::whereNotIn('id', $roleIds)->firstOrFail();
        $removed = Permission::whereIn('id', $roleIds)->firstOrFail();
        $effective = $roleIds->reject(fn ($id) => (int) $id === (int) $removed->id)
            ->push($extra->id)
            ->values()
            ->all();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.sync', $this->cashier), [
                'permissions' => $effective,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_permission', [
            'user_id' => $this->cashier->id,
            'permission_id' => $extra->id,
            'granted' => true,
        ]);
        $this->assertDatabaseHas('user_permission', [
            'user_id' => $this->cashier->id,
            'permission_id' => $removed->id,
            'granted' => false,
        ]);
        $this->assertSame(2, $this->cashier->fresh()->permissions()->count());
    }

    public function test_super_admin_can_update_an_operational_role_template(): void
    {
        $cashierRole = Role::global()->where('name', 'cashier')->firstOrFail();
        $only = Permission::where('name', 'payments.viewAny')->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.roles.sync', $cashierRole), [
                'permissions' => [$only->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$only->id],
            $cashierRole->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_role_permission_grant_and_removal_change_real_route_access_immediately(): void
    {
        $waiter = $this->makeUser('waiter_role_effect', 'waiter');
        $role = Role::global()->where('name', 'waiter')->firstOrFail();
        $permission = Permission::where('name', 'users.viewAny')->firstOrFail();
        $role->permissions()->sync([]);

        $this->actingAs($waiter)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.roles.sync', $role), [
                'permissions' => [$permission->id],
            ])
            ->assertRedirect();

        $this->actingAs($waiter->fresh())
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.roles.sync', $role), [
                'permissions' => [],
            ])
            ->assertRedirect();

        $this->actingAs($waiter->fresh())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_direct_user_exception_changes_real_route_access_without_changing_the_role(): void
    {
        $waiter = $this->makeUser('waiter_direct_effect', 'waiter');
        $role = Role::global()->where('name', 'waiter')->firstOrFail();
        $permission = Permission::where('name', 'users.viewAny')->firstOrFail();
        $role->permissions()->sync([]);

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.sync', $waiter), [
                'permissions' => [$permission->id],
            ])
            ->assertRedirect();

        $this->actingAs($waiter->fresh())
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.sync', $waiter), [
                'permissions' => [],
            ])
            ->assertRedirect();

        $this->actingAs($waiter->fresh())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_direct_revoke_wins_over_the_role_template_on_a_real_route(): void
    {
        $manager = $this->makeUser('manager_revoke_effect', 'manager');
        $role = Role::global()->where('name', 'manager')->firstOrFail();
        $permission = Permission::where('name', 'users.viewAny')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $this->actingAs($manager)
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.sync', $manager), [
                'permissions' => [],
            ])
            ->assertRedirect();

        $this->actingAs($manager->fresh())
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->assertDatabaseHas('user_permission', [
            'user_id' => $manager->id,
            'permission_id' => $permission->id,
            'granted' => false,
        ]);
    }

    public function test_current_account_and_final_super_admin_cannot_be_disabled_or_deleted(): void
    {
        $payload = [
            'name' => $this->owner->name,
            'username' => $this->owner->username,
            'role' => 'super_admin',
            'status' => 'suspended',
        ];

        $this->actingAs($this->owner)
            ->put(route('admin.users.update', $this->owner), $payload)
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $this->owner->fresh()->status);

        $this->actingAs($this->owner)
            ->patch(route('admin.users.toggle-status', $this->owner))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->delete(route('admin.users.destroy', $this->owner))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->owner->id, 'status' => 'active']);
    }

    public function test_partner_cannot_manage_a_super_admin_through_a_crafted_url(): void
    {
        $partner = $this->makeUser('partner_target_guard', 'partner');

        $this->actingAs($partner)
            ->get(route('admin.users.edit', $this->owner))
            ->assertForbidden();

        $this->actingAs($partner)
            ->patch(route('admin.users.toggle-status', $this->owner))
            ->assertForbidden();
    }

    public function test_staff_phone_is_normalized_and_legacy_variants_cannot_be_duplicated(): void
    {
        $base = [
            'name' => 'موظف جوال',
            'username' => 'mobile_staff_one',
            'phone' => '٠٥٩٩١٢٣٤٥٦',
            'role' => 'waiter',
            'status' => 'active',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'branches' => [$this->branch->id],
            'primary_branch_id' => $this->branch->id,
        ];

        $this->actingAs($this->owner)
            ->post(route('admin.users.store'), $base)
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'username' => 'mobile_staff_one',
            'phone' => '0599123456',
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.users.store'), [
                ...$base,
                'username' => 'mobile_staff_two',
                'phone' => '+970 599 123 456',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('users', ['username' => 'mobile_staff_two']);

        $this->actingAs($this->owner)
            ->post(route('admin.users.store'), [
                ...$base,
                'username' => 'mobile_staff_long',
                'phone' => '05926320261',
            ])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('users', ['username' => 'mobile_staff_long']);
    }

    public function test_owner_roles_remain_implicit_and_cannot_receive_overrides(): void
    {
        $permission = Permission::firstOrFail();
        $this->owner->permissions()->attach($permission->id, ['granted' => true]);

        $this->actingAs($this->owner)
            ->put(route('admin.permissions.sync', $this->owner), [
                'permissions' => [$permission->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(0, $this->owner->fresh()->permissions()->count());
    }

    public function test_old_roles_index_redirects_to_the_unified_center(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.roles.index'))
            ->assertRedirect(route('admin.permissions.index', ['tab' => 'roles']));
    }

    private function makeUser(string $username, string $role): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('password'),
            'status' => 'active',
            'role' => $role,
            'primary_branch_id' => $this->branch->id,
        ]);
        $user->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return $user;
    }
}
