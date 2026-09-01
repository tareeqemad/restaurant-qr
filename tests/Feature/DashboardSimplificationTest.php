<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardSimplificationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'dash',
            'name' => 'Dashboard Branch',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_admin_dashboard_is_task_first_and_keeps_management_details_folded(): void
    {
        $this->actingAs($this->user('dash_admin', 'admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard/Index')
                ->where('viewerName', 'dash_admin')
                ->where('can.financials', true)
                ->where('can.inventory', true)
                ->has('stats')
                ->has('financialPulse')
                ->has('dailyOps')
                ->has('inventoryProcurement')
                ->has('customerPulse')
                ->has('quickActions', 10)
                ->has('urls'));
    }

    public function test_waiter_dashboard_only_offers_work_the_waiter_can_open(): void
    {
        $response = $this->actingAs($this->user('dash_waiter', 'waiter'))
            ->get(route('admin.dashboard'))
            ->assertOk();

        $response->assertInertia(function (Assert $page) {
            $labels = collect($page->toArray()['props']['quickActions'])->pluck('label');

            $page->component('Admin/Dashboard/Index')
                ->where('can.financials', false)
                ->where('can.procurement', false)
                ->where('can.inventory', false)
                ->where('can.customers', false);

            $this->assertTrue($labels->contains('الطاولات'));
            $this->assertTrue($labels->contains('طلبات الصالة'));
            $this->assertFalse($labels->contains('الكاشير'));
            $this->assertFalse($labels->contains('أمر شراء'));
            $this->assertFalse($labels->contains('الحجوزات'));
        });
    }

    public function test_inventory_alerts_are_folded_into_one_compact_summary(): void
    {
        $unit = Unit::create([
            'code' => 'g', 'name' => 'Gram', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        Ingredient::create([
            'name' => 'طماطم اختبار', 'base_unit_id' => $unit->id,
            'current_stock' => 0, 'reorder_threshold' => 5,
            'track_stock' => true, 'active' => true,
        ]);

        $this->actingAs($this->user('dash_inventory_admin', 'admin'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard/Index')
                ->where('inventoryProcurement.out_stock', 1)
                ->has('inventoryAlerts', 1)
                ->where('inventoryAlerts.0.name', 'طماطم اختبار')
                ->where('inventoryAlerts.0.kind', 'out')
                ->where('inventoryAlerts.0.severity', 'danger'));
    }

    protected function user(string $username, string $role): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $this->branch->id,
            'role' => $role,
        ]);
        $user->branches()->attach($this->branch->id);

        return $user;
    }
}
