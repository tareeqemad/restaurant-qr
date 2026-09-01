<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Role;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 1 remainder (MIGRATION-PILOT.md §13): the section roster and the
 * waiter table picker on Inertia/Vue. Roster semantics come verbatim from
 * the retired ⚡section-assignments Volt component: immediate toggle
 * writes, copy-yesterday, clear-day with the carry-forward warning, and
 * the dedicated floor-assignment permission.
 */
class WaiterFloorVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected User $cashier;

    protected User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'wf', 'name' => 'WF', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);

        $this->manager = $this->staff('m1', 'manager');
        $this->cashier = $this->staff('c1', 'cashier');
        $this->waiter = $this->staff('w1', 'waiter');
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function staff(string $username, string $role): User
    {
        $u = User::create([
            'name' => $username, 'username' => $username, 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => $role,
        ]);
        $u->branches()->attach($this->branch->id);

        return $u;
    }

    protected function zone(string $label): Lookup
    {
        return Lookup::create([
            'branch_id' => null, 'group' => 'zones',
            'code' => Str::slug($label),
            'label' => $label, 'color' => '#166534', 'is_active' => true, 'display_order' => 1,
        ]);
    }

    protected function table(string $number, ?int $zoneId = null, string $status = 'available'): Table
    {
        return Table::create([
            'branch_id' => $this->branch->id, 'number' => $number,
            'capacity' => 4, 'status' => $status, 'active' => true,
            'zone_lookup_id' => $zoneId,
        ]);
    }

    // ── Section roster ───────────────────────────────────────────────────

    public function test_the_roster_page_shows_only_zones_that_have_tables(): void
    {
        $used = $this->zone('داخلي');
        $this->zone('مهجور'); // no tables → must not render
        $this->table('1', $used->id);
        $this->table('2', $used->id);

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SectionAssignments/Index')
                ->has('sections', 1, fn (Assert $s) => $s
                    ->where('label', 'داخلي')
                    ->where('tablesCount', 2)
                    ->etc())
                ->has('waiters', 1)
                ->where('waiters.0.id', $this->waiter->id)
                ->where('waiters.0.role', 'waiter')
                ->where('branchLocked', false)
                ->has('shell.nav'));
    }

    public function test_clocked_in_staff_sort_first_in_the_waiter_list(): void
    {
        $this->table('1', $this->zone('داخلي')->id);
        // 'a-early' sorts first by name, but w1 is clocked in and must lead.
        $this->staff('a-early', 'waiter');
        Attendance::create([
            'user_id' => $this->waiter->id,
            'branch_id' => $this->branch->id,
            'clock_in_at' => now()->subHour(),
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('waiters.0.name', 'w1')
                ->where('waiters.0.onShift', true));
    }

    public function test_toggle_assigns_then_unassigns_and_returns_the_roster(): void
    {
        $zone = $this->zone('تراس');
        $this->table('1', $zone->id);

        $payload = [
            'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id,
            'date' => now()->toDateString(),
        ];

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.toggle'), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath("roster.{$zone->id}.0", $this->waiter->id);

        $row = SectionAssignment::query()->first();
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame($this->manager->id, $row->created_by_user_id);

        // Second tap = unassign.
        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.toggle'), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, SectionAssignment::count());
    }

    public function test_one_waiter_can_cover_multiple_sections_on_the_same_day(): void
    {
        $inside = $this->zone('داخلي');
        $outside = $this->zone('خارجي');
        $this->table('1', $inside->id);
        $this->table('2', $outside->id);

        foreach ([$inside, $outside] as $zone) {
            $this->actingAs($this->manager)
                ->postJson(route('admin.section-assignments.toggle'), [
                    'zone_lookup_id' => $zone->id,
                    'user_id' => $this->waiter->id,
                    'date' => now()->toDateString(),
                ])
                ->assertOk();
        }

        $this->assertEqualsCanonicalizing(
            [$inside->id, $outside->id],
            SectionAssignment::zoneIdsFor($this->waiter->id),
        );

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where("roster.{$inside->id}.0", $this->waiter->id)
                ->where("roster.{$outside->id}.0", $this->waiter->id)
                ->where('waiters.0.roleLabel', 'جرسون'));
    }

    public function test_toggle_rejects_a_zone_or_waiter_outside_the_current_floor(): void
    {
        $used = $this->zone('داخلي');
        $unused = $this->zone('بلا طاولات');
        $this->table('1', $used->id);

        $otherBranch = Branch::create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $outsider = User::create([
            'name' => 'outsider', 'username' => 'outsider', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $otherBranch->id, 'role' => 'waiter',
        ]);
        $outsider->branches()->attach($otherBranch->id);

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.toggle'), [
                'zone_lookup_id' => $unused->id,
                'user_id' => $this->waiter->id,
                'date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['zone_lookup_id']);

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.toggle'), [
                'zone_lookup_id' => $used->id,
                'user_id' => $outsider->id,
                'date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.toggle'), [
                'zone_lookup_id' => $used->id,
                'user_id' => $this->manager->id,
                'date' => now()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertSame(0, SectionAssignment::count());
    }

    public function test_copy_previous_lifts_yesterdays_roster_idempotently(): void
    {
        $zone = $this->zone('داخلي');
        $this->table('1', $zone->id);
        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id, 'service_date' => now()->subDay()->toDateString(),
        ]);

        $payload = ['date' => now()->toDateString()];

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.copy'), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Copying twice must not duplicate (unique constraint + firstOrCreate).
        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.copy'), $payload)
            ->assertOk();

        $this->assertSame(1, SectionAssignment::forDate(now()->toDateString())->count());
    }

    public function test_copy_previous_with_an_empty_yesterday_refuses(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.copy'), ['date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_stale_non_waiter_assignments_never_drive_or_copy_the_live_roster(): void
    {
        $zone = $this->zone('داخلي');
        $this->table('1', $zone->id);
        SectionAssignment::create([
            'branch_id' => $this->branch->id,
            'zone_lookup_id' => $zone->id,
            'user_id' => $this->manager->id,
            'service_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull(SectionAssignment::effectiveDate());

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('roster', [])
                ->where('carried', null));

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.copy'), ['date' => now()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);
    }

    public function test_clear_day_wipes_and_warns_about_the_carry_forward(): void
    {
        $zone = $this->zone('داخلي');
        $this->table('1', $zone->id);
        // Yesterday's roster exists → after clearing today, carry-forward
        // silently resurrects it and the manager must be told.
        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id, 'service_date' => now()->subDay()->toDateString(),
        ]);
        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id, 'service_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->manager)
            ->postJson(route('admin.section-assignments.clear'), ['date' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('carried.date', now()->subDay()->toDateString());

        $this->assertSame(0, SectionAssignment::forDate(now()->toDateString())->count());
        $this->assertSame(1, SectionAssignment::forDate(now()->subDay()->toDateString())->count(),
            'clearing today must never touch other days');
    }

    public function test_the_carried_banner_appears_on_an_empty_today(): void
    {
        $zone = $this->zone('داخلي');
        $this->table('1', $zone->id);
        SectionAssignment::create([
            'branch_id' => $this->branch->id, 'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id, 'service_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('carried.date', now()->subDay()->toDateString())
                ->has('carried.label'));
    }

    public function test_manager_and_cashier_can_manage_the_roster_but_waiter_cannot(): void
    {
        $zone = $this->zone('داخلي');
        $this->table('1', $zone->id);

        $this->actingAs($this->manager)
            ->get(route('admin.section-assignments.index'))
            ->assertOk();

        $this->actingAs($this->cashier)
            ->get(route('admin.section-assignments.index'))
            ->assertOk();

        $this->actingAs($this->cashier)
            ->postJson(route('admin.section-assignments.toggle'), [
                'zone_lookup_id' => $zone->id,
                'user_id' => $this->waiter->id,
                'date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('section_assignments', [
            'zone_lookup_id' => $zone->id,
            'user_id' => $this->waiter->id,
            'created_by_user_id' => $this->cashier->id,
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.section-assignments.index'))
            ->assertForbidden();

        $this->actingAs($this->waiter)
            ->postJson(route('admin.section-assignments.toggle'), [
                'zone_lookup_id' => 1, 'user_id' => 1, 'date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    // ── One floor entry point ────────────────────────────────────────────

    public function test_the_retired_picker_redirects_to_the_tables_board(): void
    {
        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.index'))
            ->assertRedirect(route('admin.tables.index'));
    }

    public function test_picker_guests_are_redirected(): void
    {
        $this->get(route('admin.waiter-orders.index'))->assertRedirect();
    }
}
