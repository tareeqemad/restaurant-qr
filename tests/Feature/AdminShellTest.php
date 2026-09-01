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
use Illuminate\Support\Facades\Cache;
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
        $this->assertTrue($waiterLabels->contains(__('admin.nav.usage_guide')));
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

    public function test_desktop_nav_dropdown_is_viewport_anchored_below_its_parent_in_rtl(): void
    {
        $source = file_get_contents(resource_path('js/Components/AdminShell/NavItem.vue'));
        $componentsCss = file_get_contents(public_path('assets/dashtic/css/relax-components.css'));
        $brandCss = file_get_contents(public_path('assets/dashtic/css/relax-brand.css'));

        $this->assertStringContainsString('const open = ref(false)', $source);
        $this->assertStringNotContainsString('ref(Boolean(props.item.active))', $source);
        $this->assertStringContainsString('const positionDesktopMenu = (force = false) =>', $source);
        $this->assertStringContainsString('rect.right - menuWidth', $source);
        $this->assertStringContainsString("'--admin-nav-menu-left'", $source);
        $this->assertStringContainsString("'--admin-nav-menu-top'", $source);
        $this->assertStringContainsString('position: fixed !important;', $source);
        $this->assertStringContainsString('left: var(--admin-nav-menu-left) !important;', $source);
        $this->assertStringContainsString('right: auto !important;', $source);
        $this->assertStringContainsString("event.key === 'Escape'", $source);
        $this->assertStringContainsString("document.addEventListener('pointerdown', closeFromOutside)", $source);
        $this->assertStringContainsString('admin-nav-menu--wide', $source);
        $this->assertStringContainsString('const handleNavigate = () =>', $source);
        $this->assertStringContainsString('document.activeElement.blur()', $source);
        $this->assertStringContainsString('if (canHover && open.value)', $source);
        $this->assertStringContainsString('@navigate="handleNavigate"', $source);
        $this->assertStringContainsString('watch(() => page.url, closeMenu)', $source);
        $this->assertStringContainsString(":prefetch=\"item.active ? false : 'hover'\"", $source);
        $this->assertStringContainsString(':cache-for="30000"', $source);
        $this->assertSame(3, substr_count($source, 'class="nav-icon"'));
        $this->assertSame(3, substr_count($source, 'aria-hidden="true"'));
        $this->assertStringContainsString('<i :class="item.icon"></i>', $source);
        $this->assertStringContainsString('.side-menu__icon > i {', $componentsCss);
        $this->assertStringContainsString('.side-menu__icon > i::before {', $componentsCss);
        $this->assertStringContainsString('place-items: center !important;', $componentsCss);
        $this->assertStringNotContainsString('i.side-menu__icon::before', $componentsCss);
        $this->assertStringNotContainsString('has-sub:focus-within > .slide-menu', $source);
        $this->assertStringNotContainsString('has-sub:focus-within > .slide-menu', $componentsCss);
        $this->assertStringNotContainsString('has-sub:focus-within > .slide-menu', $brandCss);
        $this->assertStringContainsString('.slide.has-sub:not(.open) > .slide-menu', $source);
        $this->assertStringNotContainsString(':dir(ltr)', $source);
    }

    public function test_live_background_requests_yield_to_foreground_navigation(): void
    {
        $refresh = file_get_contents(resource_path('js/Composables/useLiveRefresh.js'));
        $bell = file_get_contents(resource_path('js/Components/AdminShell/NotificationsBell.vue'));
        $bootstrap = file_get_contents(resource_path('js/app-inertia.js'));

        $this->assertStringContainsString("router.on('start', pauseForNavigation)", $refresh);
        $this->assertStringContainsString("router.on('finish', resumeAfterNavigation)", $refresh);
        $this->assertStringContainsString('await onPing(reason, controller.signal)', $refresh);
        $this->assertStringContainsString('if (running)', $refresh);
        $this->assertStringContainsString('controller?.abort()', $refresh);

        $this->assertStringContainsString("stopStartListener = router.on('start'", $bell);
        $this->assertStringContainsString('refreshController?.abort()', $bell);
        $this->assertStringContainsString('navigationBusy', $bell);

        $this->assertStringContainsString("color: '#176b45'", $bootstrap);
        $this->assertStringContainsString('delay: 120', $bootstrap);
    }

    public function test_mobile_admin_navigation_is_an_accessible_drawer_owned_by_the_layout(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AdminLayout.vue'));
        $header = file_get_contents(resource_path('js/Components/AdminShell/AdminHeader.vue'));
        $sidebar = file_get_contents(resource_path('js/Components/AdminShell/AdminSidebar.vue'));
        $item = file_get_contents(resource_path('js/Components/AdminShell/NavItem.vue'));

        $this->assertStringContainsString('const sidebarOpen = ref(false)', $layout);
        $this->assertStringContainsString('@toggle-sidebar="toggleSidebar"', $layout);
        $this->assertStringContainsString('@close="closeSidebar"', $layout);
        $this->assertStringContainsString("event.key === 'Escape'", $layout);
        $this->assertStringContainsString('mobile-admin-nav-open', $layout);
        $this->assertStringNotContainsString('DemoTrialBanner', $layout);
        $this->assertStringNotContainsString('page.props.onboarding', $layout);

        $this->assertStringContainsString('aria-controls="sidebar"', $header);
        $this->assertStringContainsString(':aria-expanded="sidebarOpen"', $header);
        $this->assertStringContainsString('z-index: 1180;', $header);

        $this->assertStringContainsString('mobile-drawer__header', $sidebar);
        $this->assertStringContainsString('mobile-drawer__close', $sidebar);
        $this->assertStringContainsString('z-index: 1190 !important;', $sidebar);
        $this->assertStringContainsString('@navigate="emit(\'close\')"', $sidebar);

        $this->assertStringContainsString("const emit = defineEmits(['navigate'])", $item);
        $this->assertStringContainsString(':aria-expanded="open"', $item);
        $this->assertStringContainsString('@click="handleNavigate"', $item);
    }
}
