<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNav;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 0 (MIGRATION-PILOT.md §13): the Inertia admin shell. Covers the
 * gate page (activity logs on AdminLayout), the Dashtic root view, and —
 * most importantly — that AdminNav's gates stay server-side: a role only
 * ever receives the links its policies allow.
 */
class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'sh', 'name' => 'Shell', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'super_admin', 'label' => 'Super', 'is_system' => true]);
        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);

        $this->admin = User::create([
            'name' => 'Boss', 'username' => 'boss_shell', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
        $this->admin->branches()->attach($this->branch->id);

        $this->waiter = User::create([
            'name' => 'Waiter', 'username' => 'waiter_shell', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);
    }

    public function test_activity_logs_page_serves_the_inertia_shell(): void
    {
        ActivityLog::create([
            'event' => 'order.approved',
            'description' => 'approved #1',
            'causer_id' => $this->admin->id,
            'causer_type' => User::class,
            'ip_address' => '10.0.0.1',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ActivityLogs/Index')
                ->has('shell.nav')
                ->has('shell.user', fn (Assert $u) => $u->where('name', 'Boss')->etc())
                ->has('shell.urls.notifications.recent')
                ->has('shell.attendance.clockInUrl')
                ->has('logs.data', 1, fn (Assert $log) => $log
                    ->where('event', 'order.approved')
                    ->where('causer', 'Boss')
                    ->etc()));
    }

    public function test_admin_root_view_wears_the_dashtic_skin(): void
    {
        // AdminShell swaps the root view: the document must carry the
        // Dashtic stylesheet, unlike the bare POS root.
        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index'))
            ->assertSee('styles.min.css');

        $rootView = file_get_contents(resource_path('views/inertia-admin.blade.php'));
        $this->assertStringContainsString('asset($path)', $rootView);
        $this->assertStringContainsString("\$staticAsset('assets/dashtic/css/styles.min.css')", $rootView);
        $this->assertStringNotContainsString("route('optimized.asset'", $rootView);
    }

    public function test_event_filter_narrows_the_table(): void
    {
        ActivityLog::create(['event' => 'order.approved', 'description' => 'a', 'ip_address' => '1.1.1.1']);
        ActivityLog::create(['event' => 'user.login', 'description' => 'b', 'ip_address' => '1.1.1.1']);

        $this->actingAs($this->admin)
            ->get(route('admin.activity-logs.index', ['event' => 'login']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1, fn (Assert $log) => $log->where('event', 'user.login')->etc())
                ->where('filters.event', 'login'));
    }

    public function test_nav_is_policy_gated_per_role(): void
    {
        Cache::flush();

        $this->actingAs($this->admin);
        $adminLabels = collect(AdminNav::build())->pluck('label');
        $this->assertTrue($adminLabels->contains(__('admin.nav.system_admin')));
        $this->assertTrue($adminLabels->contains(__('admin.nav.accounting_center')));

        $this->actingAs($this->waiter);
        $waiterLabels = collect(AdminNav::build())->pluck('label');
        $this->assertTrue($waiterLabels->contains(__('admin.nav.dashboard')));
        // The guide is intentionally available from the user menu instead of
        // occupying the primary navigation for every operational role.
        $this->assertFalse($waiterLabels->contains(__('admin.nav.usage_guide')));
        $this->assertFalse($waiterLabels->contains(__('admin.nav.system_admin')));
        $this->assertFalse($waiterLabels->contains(__('admin.nav.inventory_purchasing')));
        $this->assertFalse($waiterLabels->contains(__('admin.nav.reports')));
    }

    public function test_open_attendance_badge_reaches_the_nav(): void
    {
        Attendance::create([
            'user_id' => $this->waiter->id,
            'branch_id' => $this->branch->id,
            'clock_in_at' => now()->subHour(),
        ]);
        Cache::flush(); // SidebarBadges caches 30s per branch

        $this->actingAs($this->admin);
        $ops = collect(AdminNav::build())->firstWhere('label', __('admin.nav.operations'));
        $this->assertNotNull($ops);

        $attendance = collect($ops['children'])->firstWhere('label', __('admin.nav.attendance'));
        $this->assertNotNull($attendance);
        $this->assertSame(1, $attendance['badge']['value']);
        $this->assertSame('success', $attendance['badge']['tone']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('admin.activity-logs.index'))->assertRedirect();
    }

    public function test_waiters_cannot_open_activity_logs(): void
    {
        $this->actingAs($this->waiter)
            ->get(route('admin.activity-logs.index'))
            ->assertForbidden();
    }

    public function test_authorized_navigation_leaves_resolve_to_get_routes(): void
    {
        foreach ([$this->admin, $this->waiter] as $user) {
            $this->actingAs($user);
            $leaves = [];
            $walk = function (array $items) use (&$walk, &$leaves): void {
                foreach ($items as $item) {
                    if (! empty($item['href'])) {
                        $leaves[] = $item['href'];
                    }
                    if (! empty($item['children'])) {
                        $walk($item['children']);
                    }
                }
            };
            $walk(AdminNav::build());
            $this->assertNotEmpty($leaves, $user->role.' must have a usable navigation destination.');

            foreach (array_unique($leaves) as $href) {
                $route = Route::getRoutes()->match(Request::create($href, 'GET'));
                $this->assertContains('GET', $route->methods(), $href);
                $this->assertNotNull($route->getName(), $href);
            }
        }
    }
}
