<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\BranchContext;
use App\Support\LiveRefreshPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveRefreshPulseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::forget('broadcast:unavailable');
        BranchContext::clear();

        parent::tearDown();
    }

    /**
     * Staff of a given branch. `users` has no branch_id column in this schema:
     * membership is primary_branch_id + the branches() pivot, and the role is
     * a plain column backed by a Role row.
     */
    protected function staffMember(Branch $branch, string $role, string $username): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $branch->id,
            'role' => $role,
        ]);

        $user->branches()->attach($branch->id);

        return $user;
    }

    public function test_operational_event_touches_global_and_branch_pulses_even_when_broadcast_is_unavailable(): void
    {
        $branch = Branch::create([
            'code' => 'pulse-test',
            'name' => 'Pulse Test',
            'is_active' => true,
        ]);

        BranchContext::set($branch->id);

        $table = Table::create([
            'number' => 'P-1',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]);

        $beforeGlobal = LiveRefreshPulse::version();
        $beforeBranch = LiveRefreshPulse::version($branch->id);

        Cache::forever('broadcast:unavailable', true);
        SafeBroadcast::dispatch(new TableStatusChanged($table, 'occupied'));

        $afterGlobal = LiveRefreshPulse::version();
        $afterBranch = LiveRefreshPulse::version($branch->id);

        $this->assertNotSame($beforeGlobal, $afterGlobal);
        $this->assertNotSame($beforeBranch, $afterBranch);
        $this->assertSame($afterGlobal, $afterBranch);
    }

    public function test_polling_mode_skips_the_unused_broadcast_pipeline(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([TableStatusChanged::class]);

        $table = new Table();
        $table->forceFill(['branch_id' => 93]);

        SafeBroadcast::dispatch(new TableStatusChanged($table, 'available'));

        Event::assertNotDispatched(TableStatusChanged::class);
        $this->assertNotSame('0', LiveRefreshPulse::version(93));
    }

    /**
     * Every board polls the same way now: a cheap pulse endpoint the client
     * compares against the version it was rendered with. This pins the
     * SERVER half of the contract — the
     * endpoint reports the global version, and an operational event moves it.
     */
    public function test_heavy_staff_boards_use_visible_pulse_polling(): void
    {
        $branch = Branch::create([
            'code' => 'monitor-pulse',
            'name' => 'Monitor Pulse',
            'is_active' => true,
        ]);

        BranchContext::set($branch->id);

        $owner = $this->staffMember($branch, 'admin', 'monitor_boss');

        $before = $this->actingAs($owner)
            ->getJson(route('admin.partner.live-monitor.pulse'))
            ->assertOk()
            ->json('version');

        $this->assertSame(LiveRefreshPulse::version(), $before);

        $table = Table::create([
            'number' => 'MP-1',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]);

        SafeBroadcast::dispatch(new TableStatusChanged($table, 'occupied'));

        $after = $this->actingAs($owner)
            ->getJson(route('admin.partner.live-monitor.pulse'))
            ->assertOk()
            ->json('version');

        $this->assertNotSame($before, $after, 'the monitor pulse must move when the floor does');
    }

    public function test_live_monitor_pulse_is_management_only(): void
    {
        $branch = Branch::create([
            'code' => 'monitor-guard',
            'name' => 'Monitor Guard',
            'is_active' => true,
        ]);

        BranchContext::set($branch->id);

        $waiter = $this->staffMember($branch, 'waiter', 'monitor_waiter');

        $this->actingAs($waiter)
            ->getJson(route('admin.partner.live-monitor.pulse'))
            ->assertForbidden();
    }

    public function test_customer_session_has_an_isolated_change_pulse(): void
    {
        $order = new Order();
        $order->forceFill([
            'branch_id' => 77,
            'table_session_id' => 8801,
        ]);

        $beforeOtherSession = LiveRefreshPulse::sessionVersion(8802);

        LiveRefreshPulse::touch(new OrderCreated($order));

        $this->assertNotSame('0', LiveRefreshPulse::sessionVersion(8801));
        $this->assertSame($beforeOtherSession, LiveRefreshPulse::sessionVersion(8802));
    }

    /**
     * The Vue tracker polls GET /track/pulse and compares versions
     * client-side. This pins the
     * server half of that contract: an event for THIS session changes the
     * version the endpoint reports; other sessions stay untouched.
     */
    public function test_customer_tracker_pulse_endpoint_wakes_for_its_session(): void
    {
        $branch = Branch::create([
            'code' => 'customer-pulse',
            'name' => 'Customer Pulse',
            'is_active' => true,
        ]);

        BranchContext::set($branch->id);

        $table = Table::create([
            'number' => 'CP-1',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);

        $session = TableSession::create([
            'table_id' => $table->id,
            'status' => 'active',
            'cover_count' => 2,
        ]);

        $before = $this->getJson(route('customer.track.pulse', ['session' => $session->token]))
            ->assertOk()
            ->json('version');

        $order = new Order();
        $order->forceFill([
            'branch_id' => $branch->id,
            'table_session_id' => $session->id,
        ]);
        LiveRefreshPulse::touch(new OrderCreated($order));

        $after = $this->getJson(route('customer.track.pulse', ['session' => $session->token]))
            ->json('version');

        $this->assertNotSame($before, $after, 'a session event must change the reported pulse version');
        $this->assertSame(LiveRefreshPulse::sessionVersion($session->id), $after);
    }
}
