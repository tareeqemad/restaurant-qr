<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->seed(\Database\Seeders\PermissionSeeder::class);
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
            ->assertSee('simple-dashboard', false)
            ->assertSee('ابدأ من هنا')
            ->assertSee('التشغيل الآن')
            ->assertSee('ملخص المال')
            ->assertSee('ملخصات الإدارة')
            ->assertSee('<details class="simple-dashboard__management">', false)
            ->assertDontSee('نبض المال')
            ->assertDontSee('تنبيهات العمليات');
    }

    public function test_waiter_dashboard_only_offers_work_the_waiter_can_open(): void
    {
        $response = $this->actingAs($this->user('dash_waiter', 'waiter'))
            ->get(route('admin.dashboard'))
            ->assertOk();

        $page = $response->getContent();
        $start = strpos($page, '<main class="simple-dashboard">');
        $end = strpos($page, '</main>', $start);
        $dashboard = substr($page, $start, $end - $start);

        $this->assertStringContainsString('الطاولات', $dashboard);
        $this->assertStringContainsString('طلبات الصالة', $dashboard);
        $this->assertStringContainsString('التشغيل الآن', $dashboard);
        $this->assertStringNotContainsString('الكاشير', $dashboard);
        $this->assertStringNotContainsString('أمر شراء', $dashboard);
        $this->assertStringNotContainsString('الحجوزات', $dashboard);
        $this->assertStringNotContainsString('ملخص المال', $dashboard);
        $this->assertStringNotContainsString('ملخصات الإدارة', $dashboard);
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
