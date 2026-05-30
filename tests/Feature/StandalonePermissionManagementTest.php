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
 * Standalone /admin/permissions page.
 *
 * Mirrors the inline tree on the user-edit form but with a different
 * navigation pattern: pick a user from a dropdown, tweak deviations,
 * save. The risks we protect against:
 *   - Anyone without roles.update reaching the page (403)
 *   - The branch-scope leak that would let a Gaza manager edit a
 *     Khanyounis user via deep-link (?user=ID outside their scope)
 *   - The pivot polluting with role-default rows (only deltas land)
 *   - Owner-level users somehow getting deviation rows added
 */
class StandalonePermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'a', 'name' => 'A', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'admin'],   ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = $this->makeUser('a_perm', 'admin');
        $this->cashier = $this->makeUser('c_perm', 'cashier');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_admin_can_open_the_standalone_permissions_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertSee('إدارة الصلاحيات');
    }

    public function test_cashier_is_forbidden_from_the_permissions_page(): void
    {
        // Cashier doesn't have roles.update — the controller aborts 403.
        $this->actingAs($this->cashier)
            ->get(route('admin.permissions.index'))
            ->assertForbidden();
    }

    public function test_picking_a_user_loads_their_tree(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.permissions.index', ['user' => $this->cashier->id]))
            ->assertOk()
            ->assertSee($this->cashier->name)
            ->assertSee('شجرة');
    }

    public function test_extra_grant_is_persisted_via_the_standalone_page(): void
    {
        // Pick a permission the cashier DOESN'T have by role default.
        $allowedToCashier = $this->cashier->rolePermissionIds()->all();
        $extra = Permission::whereNotIn('id', $allowedToCashier)->first();
        $this->assertNotNull($extra, 'Test setup needs at least one perm not in cashier role.');

        // Submit the form WITH that permission ticked alongside the
        // cashier's existing role defaults — the controller should
        // diff and create a granted=true pivot row for just $extra.
        $payload = collect($allowedToCashier)->push($extra->id)->all();

        $this->actingAs($this->admin)
            ->put(route('admin.permissions.sync', $this->cashier), [
                'permissions' => $payload,
            ])
            ->assertRedirect(route('admin.permissions.index', ['user' => $this->cashier->id]));

        $this->assertDatabaseHas('user_permission', [
            'user_id'       => $this->cashier->id,
            'permission_id' => $extra->id,
            'granted'       => true,
        ]);
    }

    public function test_revoke_is_persisted_via_the_standalone_page(): void
    {
        $defaultPerm = Permission::whereIn('id', $this->cashier->rolePermissionIds())->first();
        $this->assertNotNull($defaultPerm, 'Cashier should have at least one default perm.');

        // Submit WITHOUT $defaultPerm → revoke pivot row should appear.
        $payload = $this->cashier->rolePermissionIds()
            ->reject(fn ($id) => $id === $defaultPerm->id)
            ->values()->all();

        $this->actingAs($this->admin)
            ->put(route('admin.permissions.sync', $this->cashier), [
                'permissions' => $payload,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_permission', [
            'user_id'       => $this->cashier->id,
            'permission_id' => $defaultPerm->id,
            'granted'       => false,
        ]);
    }

    public function test_resyncing_to_exact_role_defaults_leaves_no_pivot_rows(): void
    {
        // Seed an existing deviation
        $extra = Permission::whereNotIn('id', $this->cashier->rolePermissionIds())->first();
        $this->cashier->permissions()->sync([$extra->id => ['granted' => true]]);
        $this->assertSame(1, $this->cashier->permissions()->count(), 'Pre-condition: one pivot row.');

        // POST exactly the role defaults → the diff is empty → table
        // should drop the stale deviation.
        $this->actingAs($this->admin)
            ->put(route('admin.permissions.sync', $this->cashier), [
                'permissions' => $this->cashier->rolePermissionIds()->all(),
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->cashier->fresh()->permissions()->count(),
            'Matching the role default must remove ALL deviation rows.');
    }

    public function test_owner_level_user_never_gets_pivot_rows(): void
    {
        Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'Super', 'is_system' => true]);
        $owner = $this->makeUser('o_perm', 'super_admin');

        // Attach a stray pivot row manually to simulate left-over state.
        $someperm = Permission::first();
        $owner->permissions()->attach($someperm->id, ['granted' => true]);
        $this->assertSame(1, $owner->permissions()->count());

        // Even when an admin posts new perms for them, owner gets
        // pivot cleared and an info flash — never grants stick.
        $this->actingAs($this->admin)
            ->put(route('admin.permissions.sync', $owner), [
                'permissions' => [$someperm->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(0, $owner->fresh()->permissions()->count(),
            'Owner-level users must never accumulate deviation rows.');
    }

    public function test_manager_with_delegated_roles_update_can_access_the_page(): void
    {
        // The scenario this whole page exists to enable: a manager who
        // doesn't naturally have roles.update gets it granted as a
        // per-user override. They must be able to reach the page —
        // OTHERWISE the page can't be delegated, which is the whole point.
        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        $manager = $this->makeUser('m_perm', 'manager');

        $rolesUpdatePerm = \App\Models\Permission::where('name', 'roles.update')->firstOrFail();
        $manager->permissions()->attach($rolesUpdatePerm->id, ['granted' => true]);

        $this->assertTrue($manager->hasPermission('roles.update'),
            'Pre-condition: extra grant must take effect for the manager.');

        $this->actingAs($manager)
            ->get(route('admin.permissions.index'))
            ->assertOk();
    }

    public function test_owner_level_selection_renders_without_form_wrapper(): void
    {
        // Defensive — when an owner-level user is picked, the page must
        // NOT render a <form> so an accidental Enter or DevTools poke
        // can't trigger a no-op sync request.
        Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'Super', 'is_system' => true]);
        $owner = $this->makeUser('o_form', 'super_admin');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.permissions.index', ['user' => $owner->id]))
            ->assertOk();

        // The owner-only branch must not contain the sync form action
        $body = $response->getContent();
        $this->assertStringNotContainsString(
            "/admin/permissions/{$owner->id}/sync",
            $body,
            'When viewing an owner-level user, no sync form should be rendered.',
        );
        $this->assertStringContainsString('يملك كل الصلاحيات تلقائياً', $body,
            'The owner-info notice must still show.');
    }

    public function test_deep_link_to_user_outside_branch_scope_silently_drops(): void
    {
        // Create a second branch + a user pinned only to it. The current
        // admin (pinned to $this->branch) deep-links to that user via
        // ?user=ID — the controller must NOT load that user's tree.
        $otherBranch = Branch::create(['code' => 'b', 'name' => 'B', 'is_active' => true]);
        $foreigner = User::create([
            'name' => 'Foreign', 'username' => 'foreign',
            'password' => bcrypt('x'), 'status' => 'active', 'role' => 'cashier',
            'primary_branch_id' => $otherBranch->id,
        ]);
        $foreigner->branches()->attach($otherBranch->id, ['is_primary' => true]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.permissions.index', ['user' => $foreigner->id]))
            ->assertOk();

        // The foreigner's name must NOT appear in the rendered tree
        // (the picker won't even contain their option).
        $body = $response->getContent();
        $this->assertStringNotContainsString($foreigner->name, $body,
            'Branch-scoped picker must NOT surface users outside the active branch.');
    }

    public function test_routes_are_named_and_resolvable(): void
    {
        // Smoke test the names — keeps the sidebar link from rotting
        // if the route is later renamed without updating callsites.
        $this->assertSame(
            url('/admin/permissions'),
            route('admin.permissions.index'),
        );
        $this->assertSame(
            url("/admin/permissions/{$this->cashier->id}/sync"),
            route('admin.permissions.sync', $this->cashier),
        );
    }

    protected function makeUser(string $username, string $role): User
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
