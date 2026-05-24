<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-user permission deviations from role default — the layer that
 * lets an admin grant a single waiter ONE extra capability (e.g.
 * approve discounts) without inventing a "senior waiter" role.
 *
 * Storage rule: user_permission only carries rows where the user's
 * effective state DIFFERS from their role. Matching the role default
 * = no row (keeps the table small and audits readable).
 */
class UserPermissionOverridesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'p', 'name' => 'P', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        // Seed roles + permissions exactly as the real seeder would.
        Role::firstOrCreate(['name' => 'admin'],   ['label' => 'Admin',   'is_system' => true]);
        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'],  ['label' => 'Waiter',  'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = $this->user('admin_u',   'admin');
        $this->staff = $this->user('waiter_u',  'waiter');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Effective permission resolver
    // ────────────────────────────────────────────────────────────────

    public function test_role_permissions_are_inherited_without_any_user_pivot_rows(): void
    {
        // Waiter role grants tables.viewAny — a fresh waiter inherits it.
        $this->assertTrue($this->staff->hasPermission('tables.viewAny'),
            'Role default must apply with no pivot rows touched.');
        $this->assertSame(0, $this->staff->permissions()->count(),
            'No pivot rows for a clean default user.');
    }

    public function test_extra_grant_adds_permission_outside_the_role(): void
    {
        $perm = Permission::where('name', 'staff_meals.close_month')->first();
        $this->assertNotNull($perm, 'staff_meals.close_month must be in the seeded catalog (the audit gap fix).');

        $this->assertFalse($this->staff->hasPermission('staff_meals.close_month'),
            'Waiter role does NOT grant close_month — sanity check.');

        // Grant explicitly. Row goes to user_permission with granted=true.
        $this->staff->permissions()->attach($perm->id, ['granted' => true]);

        $this->assertTrue($this->staff->fresh()->hasPermission('staff_meals.close_month'),
            'Extra grant must override the role default.');
    }

    public function test_explicit_revoke_blocks_role_default(): void
    {
        // Waiter has tables.viewAny via role — revoke it on this one user.
        $perm = Permission::where('name', 'tables.viewAny')->first();
        $this->staff->permissions()->attach($perm->id, ['granted' => false]);

        $this->assertFalse($this->staff->fresh()->hasPermission('tables.viewAny'),
            'Explicit revoke (granted=false) must beat the role default.');
    }

    public function test_effective_permission_ids_unions_role_and_grants_then_subtracts_revokes(): void
    {
        $closeMonth = Permission::where('name', 'staff_meals.close_month')->first();
        $tablesView = Permission::where('name', 'tables.viewAny')->first();

        $this->staff->permissions()->attach([
            $closeMonth->id => ['granted' => true],   // extra grant
            $tablesView->id => ['granted' => false],  // revoke a role default
        ]);

        $effective = $this->staff->fresh()->effectivePermissionIds();

        $this->assertTrue($effective->contains($closeMonth->id), 'Effective set must include extra grants.');
        $this->assertFalse($effective->contains($tablesView->id), 'Effective set must exclude explicit revokes.');
    }

    public function test_owner_level_user_returns_null_from_effective_ids(): void
    {
        $owner = $this->user('owner_u', 'super_admin');
        $this->assertNull($owner->effectivePermissionIds(),
            'Owner-level users bypass the per-permission check entirely — null signals "everything".');
    }

    // ────────────────────────────────────────────────────────────────
    // Controller sync — diff against role default
    // ────────────────────────────────────────────────────────────────

    public function test_admin_can_grant_extra_permission_via_user_form(): void
    {
        $closeMonth = Permission::where('name', 'staff_meals.close_month')->first();
        $waiterRole = Role::where('name', 'waiter')->first();
        $rolePermIds = $waiterRole->permissions->pluck('id')->all();

        // POST every role-default ID + the new extra one. The controller
        // should diff against role and only persist the extra grant.
        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->staff), [
                'name'              => $this->staff->name,
                'username'          => $this->staff->username,
                'role'              => $this->staff->role,
                'status'            => $this->staff->status,
                'branches'          => [$this->branch->id],
                'primary_branch_id' => $this->branch->id,
                'permissions'       => array_merge($rolePermIds, [$closeMonth->id]),
            ])
            ->assertRedirect();

        $row = $this->staff->permissions()->where('permissions.id', $closeMonth->id)->first();
        $this->assertNotNull($row, 'Extra grant must be persisted as a user_permission row.');
        $this->assertTrue((bool) $row->pivot->granted, 'New row carries granted=true.');

        // No noise: role defaults should NOT also be inserted.
        $this->assertSame(1, $this->staff->permissions()->count(),
            'Only the deviation gets a row — role defaults stay implicit.');
    }

    public function test_admin_can_revoke_role_permission_via_user_form(): void
    {
        $waiterRole = Role::where('name', 'waiter')->first();
        $rolePermIds = $waiterRole->permissions->pluck('id')->all();
        $tablesView = Permission::where('name', 'tables.viewAny')->first();

        // POST role defaults MINUS tables.viewAny → should write granted=false.
        $kept = array_values(array_diff($rolePermIds, [$tablesView->id]));

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->staff), [
                'name' => $this->staff->name, 'username' => $this->staff->username,
                'role' => $this->staff->role, 'status' => $this->staff->status,
                'branches' => [$this->branch->id], 'primary_branch_id' => $this->branch->id,
                'permissions' => $kept,
            ])
            ->assertRedirect();

        $row = $this->staff->permissions()->where('permissions.id', $tablesView->id)->first();
        $this->assertNotNull($row, 'Revoke must be persisted.');
        $this->assertFalse((bool) $row->pivot->granted, 'Revoke carries granted=false.');
    }

    public function test_clearing_overrides_back_to_role_default_removes_rows(): void
    {
        $closeMonth = Permission::where('name', 'staff_meals.close_month')->first();
        $this->staff->permissions()->attach($closeMonth->id, ['granted' => true]);
        $this->assertSame(1, $this->staff->permissions()->count());

        // POST exactly the role defaults — no extras, no missing → user_permission empties.
        $rolePermIds = Role::where('name', 'waiter')->first()->permissions->pluck('id')->all();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->staff), [
                'name' => $this->staff->name, 'username' => $this->staff->username,
                'role' => $this->staff->role, 'status' => $this->staff->status,
                'branches' => [$this->branch->id], 'primary_branch_id' => $this->branch->id,
                'permissions' => $rolePermIds,
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->staff->permissions()->count(),
            'Once the form matches the role exactly, all deviation rows are stripped.');
    }

    // ────────────────────────────────────────────────────────────────
    // Staff meals audit: the new permissions actually gate the controller
    // ────────────────────────────────────────────────────────────────

    public function test_staff_meal_permissions_are_in_the_catalog(): void
    {
        foreach (['viewAny', 'quick_consume', 'settle', 'waive', 'close_month'] as $action) {
            $this->assertNotNull(
                Permission::where('name', "staff_meals.{$action}")->first(),
                "Permission staff_meals.{$action} must be seeded — was missing before the audit."
            );
        }
    }

    public function test_cashier_inherits_quick_consume_but_not_close_month(): void
    {
        $cashier = $this->user('cashier_u', 'cashier');
        $this->assertTrue($cashier->hasPermission('staff_meals.quick_consume'),
            'Cashier role must grant quick_consume — they use it during shifts.');
        $this->assertFalse($cashier->hasPermission('staff_meals.close_month'),
            'Cashier must NOT inherit month-close — that stays a manager action.');
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function user(string $username, string $role): User
    {
        $u = User::create([
            'name'              => ucfirst($username),
            'username'          => $username,
            'password'          => bcrypt('x'),
            'status'            => 'active',
            'role'              => $role,
            'primary_branch_id' => $this->branch->id,
        ]);
        $u->branches()->attach($this->branch->id, ['is_primary' => true]);
        return $u;
    }
}
