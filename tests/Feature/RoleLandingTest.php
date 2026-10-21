<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleLandingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'landing', 'name' => 'Landing', 'is_active' => true]);
        BranchContext::clear();
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function staff(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);
        $user = User::create(['name' => $role, 'username' => 'landing_'.$role, 'role' => $role,
            'status' => 'active', 'password' => bcrypt('landing-password'), 'primary_branch_id' => $this->branch->id]);
        $user->branches()->attach($this->branch->id, ['is_primary' => true]);

        return $user;
    }

    public static function defaults(): array
    {
        return [
            ['waiter', 'admin.tables.index'], ['cashier', 'admin.cashier.index'],
            ['accountant', 'admin.accounting.index'], ['manager', 'admin.dashboard'],
            ['admin', 'admin.dashboard'], ['super_admin', 'admin.dashboard'], ['partner', 'admin.dashboard'],
        ];
    }

    #[DataProvider('defaults')]
    public function test_staff_login_defaults_to_the_authorized_role_workspace(string $role, string $route): void
    {
        $user = $this->staff($role);
        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'landing-password'])
            ->assertRedirect(route($route));
        $this->assertAuthenticatedAs($user);
        $this->get(route('login'))->assertRedirect(route($route));
    }

    public function test_chef_and_bar_land_on_their_own_station_and_dashboard_exposes_it(): void
    {
        foreach (['chef' => 'kitchen', 'bartender' => 'bar'] as $role => $code) {
            $station = Station::create(['branch_id' => $this->branch->id, 'code' => $code, 'name' => $code, 'active' => true]);
            $user = $this->staff($role);
            $user->update(['station_id' => $station->id]);
            $this->actingAs($user)->get(route('login'))->assertRedirect(route('admin.station.show', $code));
            $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('stationActions.0.route', route('admin.station.show', $code)));
        }
    }

    public function test_station_selection_respects_current_membership_and_never_uses_another_branch_assignment(): void
    {
        $other = Branch::create(['code' => 'elsewhere', 'name' => 'Elsewhere', 'is_active' => true]);
        $foreignStation = Station::create(['branch_id' => $other->id, 'code' => 'private-station', 'name' => 'Private', 'active' => true]);
        $user = $this->staff('chef');
        $user->update(['station_id' => $foreignStation->id]);
        // A stale branch session must not turn an unrelated assignment into a destination.
        $this->actingAs($user)->withSession(['active_branch_id' => $other->id])->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('stationActions', 0));
    }

    public function test_inactive_station_is_not_a_landing_destination(): void
    {
        $station = Station::create(['branch_id' => $this->branch->id, 'code' => 'kitchen', 'name' => 'Closed', 'active' => false]);
        $user = $this->staff('chef');
        $user->update(['station_id' => $station->id]);
        $this->actingAs($user)->get(route('login'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_valid_selected_branch_keeps_its_assigned_station_instead_of_primary_branch(): void
    {
        $other = Branch::create(['code' => 'selected', 'name' => 'Selected', 'is_active' => true]);
        $station = Station::create(['branch_id' => $other->id, 'code' => 'pastry', 'name' => 'Pastry', 'active' => true]);
        $user = $this->staff('chef');
        $user->branches()->attach($other->id);
        $user->update(['station_id' => $station->id]);
        $this->actingAs($user)->withSession(['active_branch_id' => $other->id])->get(route('login'))
            ->assertRedirect(route('admin.station.show', 'pastry'));
    }

    public function test_explicitly_revoked_workspace_permission_falls_back_to_dashboard(): void
    {
        $user = $this->staff('cashier');
        $permission = Permission::where('name', 'payments.viewAny')->firstOrFail();
        $user->permissions()->attach($permission->id, ['granted' => false]);
        $this->actingAs($user->fresh())->get(route('login'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_valid_intended_url_takes_priority_over_role_default_in_full_inertia_visit(): void
    {
        $user = $this->staff('waiter');
        $intended = route('admin.profile.show');
        $this->withSession(['url.intended' => $intended])->withHeader('X-Inertia', 'true')
            ->post(route('login.store'), ['username' => $user->username, 'password' => 'landing-password'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', $intended);
        $this->assertFalse(session()->has('url.intended'));
    }
}
