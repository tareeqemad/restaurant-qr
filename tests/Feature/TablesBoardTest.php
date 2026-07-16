<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Lookup;
use App\Models\Role;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tables triage board (Livewire component `admin.tables-board`).
 *
 * Locks the two scope/precedence bugs an adversarial review caught after the
 * v3 redesign — both invisible when clicking through as an admin, which is
 * exactly why they need tests rather than a manual pass.
 */
class TablesBoardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $waiter;
    protected User $otherWaiter;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'tb', 'name' => 'TB', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'is_system' => true]);

        $this->waiter = $this->staff('w1', 'waiter');
        $this->otherWaiter = $this->staff('w2', 'waiter');
        $this->admin = $this->staff('a1', 'admin');
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

    protected function table(string $number): Table
    {
        return Table::create([
            'branch_id' => $this->branch->id, 'number' => $number,
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
    }

    protected function openSession(Table $t, ?int $waiterId, ?\Carbon\Carbon $openedAt = null): TableSession
    {
        return TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $t->id,
            'token' => 'tb-'.uniqid(), 'status' => 'active',
            'opened_at' => $openedAt ?? now(),
            'last_activity_at' => $openedAt ?? now(),
            'assigned_waiter_id' => $waiterId,
            'cover_count' => 1,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, array> */
    protected function rowsFor(User $as, ?string $view = null)
    {
        $c = Livewire::actingAs($as)->test('admin.tables-board');

        if ($view !== null) {
            $c->call('setView', $view);
        }

        return $c->instance()->rows;
    }

    protected function zone(string $label): Lookup
    {
        return Lookup::create([
            'branch_id' => null,   // zones are a GLOBAL dictionary
            'group' => 'zones', 'code' => \Illuminate\Support\Str::slug($label),
            'label' => $label, 'color' => '#166534', 'is_active' => true, 'display_order' => 1,
        ]);
    }

    protected ?\App\Models\MenuItem $menu = null;

    /** Minimal sellable item — built through the real chain, once. */
    protected function menuItem(): \App\Models\MenuItem
    {
        if ($this->menu) {
            return $this->menu;
        }

        $storage = \App\Models\StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $station = \App\Models\Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);
        $category = \App\Models\Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $station->id, 'active' => true,
        ]);

        return $this->menu = \App\Models\MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
    }

    /**
     * A table whose food is sitting on the pass going cold.
     *
     * Built through OrderService rather than raw inserts so the fixture can't
     * drift from what a real ticket looks like, then fast-forwarded to Ready.
     *
     * @return array{0: Table, 1: TableSession, 2: \App\Models\Order}
     */
    protected function readyOrder(): array
    {
        $table = $this->table('40');
        $table->update(['status' => 'occupied']);
        $session = $this->openSession($table, $this->waiter->id);

        $order = app(\App\Services\OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $this->menuItem()->id, 'quantity' => 1, 'modifier_ids' => []],
        ], $this->waiter->id);

        $order->items()->update([
            'status' => \App\Enums\OrderItemStatus::Ready->value,
            'ready_at' => now()->subMinutes(6),
        ]);
        $order->update([
            'status' => \App\Enums\OrderStatus::Ready->value,
            'ready_at' => now()->subMinutes(6),
        ]);

        return [$table, $session, $order->fresh()];
    }

    protected function roster(Lookup $zone, User $waiter, $date = null): SectionAssignment
    {
        return SectionAssignment::create([
            'branch_id' => $this->branch->id,
            'zone_lookup_id' => $zone->id,
            'user_id' => $waiter->id,
            'service_date' => $date ?? now()->toDateString(),
        ]);
    }

    /**
     * No roster → no fake "قسمي". The claim-based fallback resolves to almost
     * the entire floor (36 of 40 on a real one), so defaulting an unrostered
     * waiter into it would label a tab "قسمي 36" — a lie that leaves them as
     * drowned as before. Open honestly on the whole floor and say why.
     */
    public function test_an_unrostered_waiter_opens_on_the_whole_floor_and_is_told_why(): void
    {
        $t = $this->table('7');
        $this->openSession($t, null);

        $board = Livewire::actingAs($this->waiter)->test('admin.tables-board');

        $this->assertSame('all', $board->get('view'));
        $this->assertFalse($board->instance()->showsMineTab, 'no roster → no قسمي tab');
        $this->assertTrue($board->instance()->needsRosterNudge, 'tell them nobody rostered them');
        $this->assertContains('7', $board->instance()->rows->map(fn ($r) => $r['table']->number)->all());
    }

    /**
     * THE QR DEADLOCK, in the rostered world: a customer QR session is created
     * with NO assigned_waiter_id (MenuController) and only gets one when a
     * waiter APPROVES an order (OrderService). Ownership by section means the
     * pending order is visible to the section's waiter immediately — nobody has
     * to already own it in order to be allowed to see it.
     */
    public function test_a_rostered_waiter_sees_an_unclaimed_qr_session_in_their_section(): void
    {
        $terrace = $this->zone('التراس');
        $t = $this->table('7');
        $t->update(['zone_lookup_id' => $terrace->id]);
        $this->openSession($t, null);   // customer scanned the QR — no waiter yet
        $this->roster($terrace, $this->waiter);

        $board = Livewire::actingAs($this->waiter)->test('admin.tables-board');

        $this->assertSame('mine', $board->get('view'), 'a rostered waiter opens on their section');
        $this->assertContains('7', $board->instance()->rows->map(fn ($r) => $r['table']->number)->all());
    }

    /** The un-rostered fallback still filters out another waiter's claimed table. */
    public function test_mine_scope_drops_another_waiters_claimed_table(): void
    {
        $mine = $this->table('1');
        $this->openSession($mine, $this->waiter->id);

        $theirs = $this->table('2');
        $this->openSession($theirs, $this->otherWaiter->id);

        $numbers = $this->rowsFor($this->waiter, 'mine')->map(fn ($r) => $r['table']->number)->all();

        $this->assertContains('1', $numbers);
        $this->assertNotContains('2', $numbers);
    }

    /** A free table stays visible in "mine" — the waiter has to be able to seat it. */
    public function test_mine_scope_keeps_free_tables_seatable(): void
    {
        $free = Table::create([
            'branch_id' => $this->branch->id, 'number' => '9',
            'capacity' => 2, 'status' => 'available', 'active' => true,
        ]);

        $rows = $this->rowsFor($this->waiter, 'mine');

        $this->assertContains('9', $rows->map(fn ($r) => $r['table']->number)->all());
        $this->assertSame('available', $rows->firstWhere('table.id', $free->id)['tile_state']);
    }

    /**
     * PRECEDENCE: an abandoned session has zero orders, so the no_order rule
     * (activeCount === 0 && open >= 5min) is a permanent superset of the
     * idle-close case. If no_order is checked first it masks idle forever and
     * the close-session form — the whole point — never renders.
     */
    public function test_abandoned_idle_session_offers_close_instead_of_take_order(): void
    {
        $t = $this->table('3');
        // Idle well past the attention threshold (default 75m), zero orders.
        $this->openSession($t, $this->admin->id, now()->subHours(4));

        $row = $this->rowsFor($this->admin)->firstWhere('table.id', $t->id);

        $this->assertSame('idle', $row['triage']['type']);
        $this->assertTrue($row['triage']['action']['close'] ?? false, 'an admin should get the close action');
        $this->assertSame('stale', $row['tile_state'], 'a table in the feed must be marked on the map too');
    }

    /** A table seated only a few minutes still gets "take the order", not "close". */
    public function test_recently_seated_table_with_no_order_is_asked_for_an_order(): void
    {
        $t = $this->table('4');
        $this->openSession($t, $this->waiter->id, now()->subMinutes(20));

        $row = $this->rowsFor($this->waiter)->firstWhere('table.id', $t->id);

        $this->assertSame('no_order', $row['triage']['type']);
        $this->assertSame('attention', $row['tile_state']);
    }

    /**
     * Closing authorizes TablePolicy::update (admin|manager only). The idle card
     * has no other action, so an ungated close form is a guaranteed 403 for the
     * board's primary audience — give waiters something they can actually do.
     */
    public function test_waiter_gets_a_usable_action_on_an_idle_card_not_a_403_close(): void
    {
        $t = $this->table('5');
        $this->openSession($t, $this->waiter->id, now()->subHours(4));

        $row = $this->rowsFor($this->waiter)->firstWhere('table.id', $t->id);

        $this->assertSame('idle', $row['triage']['type']);
        $this->assertArrayNotHasKey('close', $row['triage']['action']);
        $this->assertArrayHasKey('url', $row['triage']['action']);
        $this->assertFalse($row['perms']['update'], 'waiters cannot update tables');
    }

    /**
     * THE POINT OF THE ROSTER: once a manager says "Ahmad has the terrace
     * tonight", the whole section is his — free tables included, and regardless
     * of who happened to approve an order first. Ownership becomes a plan
     * rather than a race.
     */
    public function test_a_rostered_waiter_owns_their_whole_section(): void
    {
        $terrace = $this->zone('التراس');
        $indoor = $this->zone('الداخلي');

        $t1 = $this->table('20'); $t1->update(['zone_lookup_id' => $terrace->id, 'status' => 'available']);
        $t2 = $this->table('21'); $t2->update(['zone_lookup_id' => $terrace->id]);
        $inside = $this->table('30'); $inside->update(['zone_lookup_id' => $indoor->id]);

        // Someone ELSE claimed a party on my section's table — still mine.
        $this->openSession($t2, $this->otherWaiter->id);
        // And the indoor table is claimed by them too — not mine.
        $this->openSession($inside, $this->otherWaiter->id);

        $this->roster($terrace, $this->waiter);

        $numbers = $this->rowsFor($this->waiter)->map(fn ($r) => $r['table']->number)->all();

        $this->assertContains('20', $numbers, 'a free table in my section is mine to seat');
        $this->assertContains('21', $numbers, "my section's tables are mine even if someone else claimed the party");
        $this->assertNotContains('30', $numbers, 'another section is not mine');
    }

    /** A table I'm actually serving outside my section still shows — I'm on it. */
    public function test_rostered_waiter_still_sees_a_party_they_serve_elsewhere(): void
    {
        $terrace = $this->zone('التراس');
        $indoor = $this->zone('الداخلي');

        $mySection = $this->table('20');
        $mySection->update(['zone_lookup_id' => $terrace->id]);

        $elsewhere = $this->table('31');
        $elsewhere->update(['zone_lookup_id' => $indoor->id]);
        $this->openSession($elsewhere, $this->waiter->id);

        $this->roster($terrace, $this->waiter);

        $this->assertContains('31', $this->rowsFor($this->waiter)->map(fn ($r) => $r['table']->number)->all());
    }

    /** Yesterday's roster must not follow a waiter into today. */
    public function test_a_stale_roster_from_yesterday_does_not_grant_ownership(): void
    {
        $terrace = $this->zone('التراس');
        $t = $this->table('20');
        $t->update(['zone_lookup_id' => $terrace->id, 'status' => 'available']);

        $this->roster($terrace, $this->waiter, now()->subDay()->toDateString());

        // No roster for TODAY → falls back to claim-based ownership, under which
        // a free table is still visible, so assert on the mechanism instead.
        $this->assertSame([], SectionAssignment::zoneIdsFor($this->waiter->id));
    }

    /**
     * With no roster, an explicit "قسمي" still falls back to claim ownership —
     * the old behaviour, kept so a small floor that never opens the roster
     * screen is unaffected. Unclaimed sessions must stay visible there too.
     */
    public function test_board_falls_back_to_claim_ownership_when_nobody_is_rostered(): void
    {
        $t = $this->table('7');
        $this->openSession($t, null);   // unclaimed QR session

        $this->assertContains('7', $this->rowsFor($this->waiter, 'mine')->map(fn ($r) => $r['table']->number)->all());
    }

    /** A section tab narrows both the feed and the map to that section alone. */
    public function test_a_section_tab_shows_only_that_sections_tables(): void
    {
        $terrace = $this->zone('التراس');
        $indoor = $this->zone('الداخلي');

        $out = $this->table('20'); $out->update(['zone_lookup_id' => $terrace->id]);
        $in = $this->table('30'); $in->update(['zone_lookup_id' => $indoor->id]);

        $numbers = Livewire::actingAs($this->admin)
            ->test('admin.tables-board')
            ->call('setView', (string) $terrace->id)
            ->instance()->rows->map(fn ($r) => $r['table']->number)->all();

        $this->assertSame(['20'], $numbers);
    }

    /** Managers open on the whole floor, not on a section they don't serve. */
    public function test_a_manager_opens_on_the_whole_floor(): void
    {
        $this->assertSame('all', Livewire::actingAs($this->admin)->test('admin.tables-board')->get('view'));
    }

    /**
     * A raised hand outranks everything — including cold food. The guest is
     * watching the room right now to see whether anyone comes.
     */
    public function test_a_help_request_tops_the_feed(): void
    {
        $t = $this->table('16');
        $s = $this->openSession($t, $this->waiter->id, now()->subMinutes(30));
        $s->update(['help_requested_at' => now()->subMinutes(3), 'help_request_note' => 'ناقص ملح']);

        $row = $this->rowsFor($this->waiter)->firstWhere('table.id', $t->id);

        $this->assertSame('help', $row['triage']['type']);
        $this->assertSame('urgent', $row['tile_state']);
        $this->assertStringContainsString('ناقص ملح', $row['triage']['label']);

        $feed = Livewire::actingAs($this->waiter)->test('admin.tables-board')->instance()->triage;
        $this->assertSame('help', $feed->first()['triage']['type'], 'a raised hand sorts to the very top');
    }

    /** "I went" clears the hand and records who answered. */
    public function test_acknowledging_help_clears_it_and_records_who_went(): void
    {
        $t = $this->table('17');
        $s = $this->openSession($t, $this->waiter->id);
        $s->update(['help_requested_at' => now()->subMinutes(2)]);

        Livewire::actingAs($this->waiter)
            ->test('admin.tables-board')
            ->call('ackHelp', $s->id);

        $s->refresh();
        $this->assertNull($s->help_requested_at);
        $this->assertSame($this->waiter->id, $s->help_ack_by_user_id);
    }

    /**
     * The waiter's #1 job, done without leaving the floor.
     *
     * "سلّم" used to link to the branch-wide order list with no table filter —
     * a red card shouting "table 12's food is going cold" whose only action was
     * "go hunt for table 12 in a list of everything". Serving in place is the
     * whole point of a triage board.
     */
    public function test_serving_a_table_from_the_board_marks_its_ready_food_delivered(): void
    {
        [$table, $session, $order] = $this->readyOrder();

        Livewire::actingAs($this->waiter)
            ->test('admin.tables-board')
            ->call('serveTable', $session->id);

        $this->assertSame(
            \App\Enums\OrderItemStatus::Served->value,
            $order->items()->first()->fresh()->status,
            'the ready line is now delivered'
        );
    }

    /** Food still cooking must never be signed off as delivered. */
    public function test_serving_leaves_items_that_are_still_cooking_alone(): void
    {
        [$table, $session, $order] = $this->readyOrder();

        $cooking = $order->items()->first();
        $cooking->update(['status' => \App\Enums\OrderItemStatus::Preparing->value]);

        Livewire::actingAs($this->waiter)
            ->test('admin.tables-board')
            ->call('serveTable', $session->id);

        $this->assertSame(
            \App\Enums\OrderItemStatus::Preparing->value,
            $cooking->fresh()->status,
            'a half-ready ticket is not delivered'
        );
    }

    /** A cashier can see this board but cannot hand food to a guest. */
    public function test_a_cashier_is_not_offered_the_serve_button(): void
    {
        [$table, $session] = $this->readyOrder();

        $cashier = $this->staff('c1', 'cashier');
        $row = $this->rowsFor($cashier)->firstWhere('table.id', $table->id);

        $this->assertSame('food_ready', $row['triage']['type']);
        $this->assertArrayNotHasKey('serve', $row['triage']['action'], 'no button that would 403 in their hand');
        $this->assertStringContainsString('table_id=', $row['triage']['action']['url'], 'the fallback carries the table');
    }

    /** A table nobody wiped down surfaces as its own tier with a "cleaned" action. */
    public function test_a_dirty_table_asks_to_be_cleaned(): void
    {
        $t = $this->table('12');
        $t->update(['status' => 'available', 'needs_cleaning_since' => now()->subMinutes(9)]);

        $row = $this->rowsFor($this->waiter)->firstWhere('table.id', $t->id);

        $this->assertSame('cleaning', $row['triage']['type']);
        $this->assertSame('cleaning', $row['tile_state']);
        $this->assertTrue($row['triage']['action']['clean'] ?? false);
    }

    /** A live guest always outranks a dirty table — cold food costs you this seating. */
    public function test_a_seated_party_outranks_bussing(): void
    {
        $t = $this->table('13');
        $t->update(['needs_cleaning_since' => now()->subHour()]);
        $this->openSession($t, $this->waiter->id, now()->subMinutes(30));

        $row = $this->rowsFor($this->waiter)->firstWhere('table.id', $t->id);

        $this->assertSame('no_order', $row['triage']['type'], 'the party in front of you wins');
    }

    /**
     * THE SAFETY VALVE: without this, a floor that ignores bussing would strand
     * every table in "needs cleaning" forever. Seating a new party clears it,
     * whatever path opened the session.
     */
    public function test_seating_a_new_party_clears_the_bussing_debt(): void
    {
        $t = $this->table('14');
        $t->update(['needs_cleaning_since' => now()->subHour()]);

        $this->openSession($t, null);

        $this->assertNull($t->fresh()->needs_cleaning_since);
    }

    /**
     * Moving a party leaves used plates behind. Transfer is rejected once an
     * invoice exists, so the party still has live orders — they have been
     * eating at the source table and physically got up. The `created` valve
     * can't cover this: a transfer UPDATEs table_id, it never creates a
     * session, so both sides have to be handled explicitly.
     */
    public function test_transferring_a_party_dirties_the_old_table_and_cleans_the_new_one(): void
    {
        $source = $this->table('20');
        $target = $this->table('21');
        $target->update(['status' => 'available', 'needs_cleaning_since' => now()->subHour()]);

        $this->openSession($source, $this->waiter->id);
        $source->update(['status' => 'occupied']);

        app(\App\Services\TableSessionTransferService::class)
            ->transfer($source->fresh(), $target->fresh(), $this->admin->id);

        $this->assertNotNull($source->fresh()->needs_cleaning_since, 'the vacated table has dirty plates on it');
        $this->assertNull($target->fresh()->needs_cleaning_since, 'the table that just took a party is not dirty');
    }

    /** Wiping a table down is floor work — a waiter must not be 403'd for it. */
    public function test_a_waiter_can_mark_a_table_clean(): void
    {
        $t = $this->table('15');
        $t->update(['status' => 'available', 'needs_cleaning_since' => now()->subMinutes(5)]);

        $this->actingAs($this->waiter)
            ->post(route('admin.tables.mark-clean', $t))
            ->assertRedirect();

        $this->assertNull($t->fresh()->needs_cleaning_since);
    }

    /**
     * The ⋯ menu renders per feed card AND per map tile. Its gates run
     * TablePolicy → BasePolicy::inUserBranch → User::belongsToBranch, which is
     * an un-memoized `branches()->exists()` query for anyone who isn't
     * owner-level. Owners short-circuit to true with NO query — which is
     * precisely why this never showed up while clicking around as an admin, and
     * why the guard below acts as a WAITER. Gates are now resolved once per
     * branch; if that hoist is ever undone this blows up long before a real
     * 40-table floor feels it.
     */
    public function test_the_board_does_not_run_permission_queries_per_tile_for_a_waiter(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->openSession($this->table((string) $i), $this->waiter->id);
        }

        // Warm auth/session lookups so we measure the board, not the login.
        $probe = Livewire::actingAs($this->waiter);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $probe->test('admin.tables-board');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 12 tables × 2 render sites × 3 gates = 72 extra queries before the
        // hoist. The board's own reads are a handful of eager-loaded ones.
        $this->assertLessThan(
            30,
            $queries,
            "The tables board ran {$queries} queries for 12 tables — the per-tile permission storm is back."
        );
    }
}
