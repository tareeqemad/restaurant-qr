<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end branch-isolation guarantees.
 *
 * Each test verifies a *specific* leakage vector that the multi-branch
 * design has to defend against:
 *
 *   1. Direct ID tampering in URLs        → policies refuse cross-branch.
 *   2. Listings & dashboards               → BranchScope filters by branch.
 *   3. Branch switcher abuse                → 403 when user not a member.
 *   4. Super-admin bypass                   → unrestricted across branches.
 *
 * The fixture deliberately mints rows directly via the query builder where
 * the model has many required attributes — we're testing isolation, not
 * model factories.
 */
class BranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashier;          // member of branchA only
    protected User $superAdmin;       // bypasses every check

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::create([
            'code'      => 'a-test',
            'name'      => 'Branch A',
            'is_active' => true,
        ]);

        $this->branchB = Branch::create([
            'code'      => 'b-test',
            'name'      => 'Branch B',
            'is_active' => true,
        ]);

        $this->cashier = User::create([
            'name'     => 'Test Cashier',
            'username' => 'cashier_test',
            'role'     => 'cashier',
            'status'   => 'active',
            'password' => bcrypt('test'),
        ]);
        $this->cashier->branches()->attach($this->branchA->id, [
            'is_primary' => true,
            'joined_at'  => now(),
        ]);

        $this->superAdmin = User::create([
            'name'     => 'Test Super',
            'username' => 'super_test',
            'role'     => 'super_admin',
            'status'   => 'active',
            'password' => bcrypt('test'),
        ]);
    }

    /**
     * Helper: build an unsaved Order pointing at a given branch.
     * Sets branch_id directly because it's intentionally not in $fillable —
     * the BelongsToBranch trait fills it via the creating event in
     * production code; tests bypass that path with a stub instance.
     */
    protected function fakeOrder(int $branchId, int $id = 1): Order
    {
        $order = new Order();
        $order->id        = $id;
        $order->branch_id = $branchId;
        $order->status    = 'pending';

        return $order;
    }

    protected function fakeTable(int $branchId, int $id = 1): Table
    {
        $table = new Table(['number' => '1', 'capacity' => 4]);
        $table->id        = $id;
        $table->branch_id = $branchId;

        return $table;
    }

    // ─── 1. ID tampering — policies must reject cross-branch lookups ────

    public function test_branch_user_cannot_view_order_from_another_branch(): void
    {
        $foreign = $this->fakeOrder($this->branchB->id);

        $this->assertFalse(
            $this->cashier->can('view', $foreign),
            'Cashier in branch A leaked into branch B order.'
        );
    }

    public function test_branch_user_can_view_order_from_their_own_branch(): void
    {
        $own = $this->fakeOrder($this->branchA->id);

        $this->assertTrue(
            $this->cashier->can('view', $own),
            'Cashier denied access to their own branch order.'
        );
    }

    public function test_branch_user_cannot_update_table_from_another_branch(): void
    {
        // Make cashier a manager so role-check passes — branch check is the
        // only thing that should still deny.
        $this->cashier->update(['role' => 'manager']);
        $foreignTable = $this->fakeTable($this->branchB->id);

        $this->assertFalse(
            $this->cashier->can('update', $foreignTable),
            'Manager updated a table belonging to a different branch.'
        );
    }

    public function test_branch_user_cannot_delete_order_from_another_branch(): void
    {
        $this->cashier->update(['role' => 'manager']);
        $foreign = $this->fakeOrder($this->branchB->id);

        $this->assertFalse($this->cashier->can('delete', $foreign));
    }

    // ─── 2. BranchScope — listings filter automatically ─────────────────

    public function test_branch_scope_filters_orders_by_active_branch(): void
    {
        // 3 orders: 2 in A, 1 in B
        $base = [
            'order_type' => 'dine_in',
            'status'     => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('orders')->insert([
            ['number' => 'TEST-A1', 'branch_id' => $this->branchA->id] + $base,
            ['number' => 'TEST-A2', 'branch_id' => $this->branchA->id] + $base,
            ['number' => 'TEST-B1', 'branch_id' => $this->branchB->id] + $base,
        ]);

        // Authenticated as a regular branch user (no super-admin bypass).
        $this->actingAs($this->cashier);

        BranchContext::set($this->branchA->id);
        $this->assertSame(2, Order::count(), 'BranchScope did not isolate to branch A.');

        BranchContext::set($this->branchB->id);
        $this->assertSame(1, Order::count(),
            'BranchScope did not isolate to branch B.');
        // The "should never reach branch B context" guarantee is enforced at
        // the middleware/switcher layer, tested separately below — the scope
        // itself only honors whatever context is bound.
    }

    /**
     * The Super Admin is no longer treated as "always sees all" by
     * BranchScope — once they switch into a branch (the in-header
     * switcher writes the choice to the session), they get the same
     * filtered view a branch user gets, so they can manage that branch
     * without sibling-branch data leaking onto the screen.
     *
     * The cross-branch global view is now explicit:
     *   - No active branch bound → see everything (the global dashboard).
     *   - BranchContext::unscoped() callback → see everything inside it.
     */
    public function test_super_admin_respects_active_branch_context(): void
    {
        DB::table('orders')->insert([
            ['number' => 'SUP-A1', 'branch_id' => $this->branchA->id, 'order_type' => 'dine_in', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['number' => 'SUP-A2', 'branch_id' => $this->branchA->id, 'order_type' => 'dine_in', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['number' => 'SUP-B',  'branch_id' => $this->branchB->id, 'order_type' => 'dine_in', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->superAdmin);

        BranchContext::set($this->branchA->id);
        $this->assertSame(2, Order::count(),
            'Super admin pinned to branch A leaked into branch B.');

        BranchContext::set($this->branchB->id);
        $this->assertSame(1, Order::count(),
            'Super admin pinned to branch B leaked into branch A.');

        BranchContext::clear();
        $this->assertSame(3, Order::count(),
            'Super admin with no active branch should see the global view.');
    }

    public function test_branch_context_unscoped_returns_all_rows(): void
    {
        DB::table('orders')->insert([
            ['number' => 'UNS-A', 'branch_id' => $this->branchA->id, 'order_type' => 'dine_in', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['number' => 'UNS-B', 'branch_id' => $this->branchB->id, 'order_type' => 'dine_in', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);

        BranchContext::set($this->branchA->id);
        $cross = BranchContext::unscoped(fn () => Order::count());

        $this->assertSame(2, $cross, 'BranchContext::unscoped did not bypass the scope.');
    }

    // ─── 3. Switcher endpoint — must reject foreign branches ────────────

    public function test_branch_switcher_rejects_branches_user_does_not_belong_to(): void
    {
        $this->actingAs($this->cashier);

        $response = $this->post(route('admin.branches.switch', $this->branchB));

        $response->assertForbidden();
    }

    public function test_branch_switcher_accepts_branch_user_belongs_to(): void
    {
        $this->actingAs($this->cashier);

        $response = $this->post(route('admin.branches.switch', $this->branchA));

        $response->assertRedirect();
        $this->assertSame($this->branchA->id, session('active_branch_id'));
    }

    public function test_super_admin_can_switch_to_any_active_branch(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->post(route('admin.branches.switch', $this->branchB));

        $response->assertRedirect();
        $this->assertSame($this->branchB->id, session('active_branch_id'));
    }

    public function test_branch_switcher_rejects_inactive_branch(): void
    {
        $this->branchB->update(['is_active' => false]);
        $this->actingAs($this->superAdmin);

        $this->post(route('admin.branches.switch', $this->branchB))
            ->assertRedirect();

        // SetActiveBranch middleware seeds a sensible default for super
        // admin (the first active branch) before the controller rejects;
        // the assertion is therefore "switch did NOT take effect" rather
        // than "session is empty".
        $this->assertNotSame($this->branchB->id, session('active_branch_id'),
            'Inactive branch was set as active despite the rejection.');
    }

    // ─── 4. BranchPolicy — Super Admin only on management UI ────────────

    public function test_branch_policy_denies_branches_admin_to_non_super_admin(): void
    {
        $this->cashier->update(['role' => 'admin']);
        $this->assertFalse($this->cashier->can('viewAny', Branch::class),
            'Even Admin role gained Super-Admin-only branch management.');
    }

    public function test_branch_policy_allows_super_admin_full_access(): void
    {
        $this->assertTrue($this->superAdmin->can('viewAny', Branch::class));
        $this->assertTrue($this->superAdmin->can('create', Branch::class));
        $this->assertTrue($this->superAdmin->can('update', $this->branchA));
        $this->assertTrue($this->superAdmin->can('delete', $this->branchA));
    }

    // ─── 5. Super-Admin "all branches" mode ─────────────────────────────

    public function test_super_admin_can_enter_all_branches_mode(): void
    {
        $this->actingAs($this->superAdmin)
            ->post('/admin/branches/switch/all')
            ->assertRedirect();

        $this->assertTrue(session('view_all_branches'));
        $this->assertNull(session('active_branch_id'));
    }

    public function test_non_super_admin_cannot_enter_all_branches_mode(): void
    {
        $this->actingAs($this->cashier)
            ->post('/admin/branches/switch/all')
            ->assertForbidden();

        $this->assertFalse((bool) session('view_all_branches'));
    }

    public function test_switching_to_specific_branch_clears_all_mode(): void
    {
        // Activate all-mode first
        $this->actingAs($this->superAdmin)
            ->post('/admin/branches/switch/all');
        $this->assertTrue(session('view_all_branches'));

        // Then switch to a specific branch
        $this->post(route('admin.branches.switch', $this->branchA))
            ->assertRedirect();

        $this->assertFalse((bool) session('view_all_branches'),
            'Switching to a specific branch must clear the all-mode flag.');
        $this->assertSame($this->branchA->id, session('active_branch_id'));
    }

    // ─── 6. Users index filters by branch membership ───────────────────

    public function test_users_index_only_shows_branch_members(): void
    {
        // managerA is in branchA via setUp(). Add a user to branchB to confirm
        // they're filtered out when context is branchA.
        $userB = User::create([
            'name'     => 'B Staff',
            'username' => 'staff_b',
            'role'     => 'cashier',
            'status'   => 'active',
            'password' => bcrypt('test'),
        ]);
        $userB->branches()->attach($this->branchB->id, ['is_primary' => true, 'joined_at' => now()]);

        // Use the manager (non-super admin) so super-admin bypass doesn't
        // mask the filter. ManagerA is a member of branchA only.
        $managerA = User::create([
            'name' => 'Mgr A', 'username' => 'mgr_a',
            'role' => 'manager', 'status' => 'active',
            'password' => bcrypt('test'),
        ]);
        $managerA->branches()->attach($this->branchA->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->actingAs($managerA);
        BranchContext::set($this->branchA->id);

        $response = $this->get('/admin/users');
        $response->assertStatus(200);
        $response->assertDontSee('staff_b');     // branch B member must not appear
        $response->assertSee($managerA->username); // their own row should still appear
    }

    // ─── 7. Suspended user kill-switch (BasePolicy::before) ────────────

    public function test_suspended_branch_user_loses_all_access(): void
    {
        $this->cashier->update(['status' => 'suspended']);
        $own = $this->fakeOrder($this->branchA->id);

        $this->assertFalse($this->cashier->can('view', $own),
            'Suspended user kept policy access to their own branch.');
    }
}
