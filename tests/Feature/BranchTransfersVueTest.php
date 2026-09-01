<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferItem;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * التحويلات بين الفروع on Inertia/Vue.
 *
 * This screen group is unusual: BranchTransfer has NO BranchScope (a
 * transfer belongs to two branches at once), so the visibility rules are
 * hand-written in the controller. Those rules are the thing worth pinning:
 *
 *  1. index() narrows to "my branch is one of the two legs" for anyone who
 *     is not owner-level; owner-level sees every transfer.
 *  2. show() 404s a manager whose branch is neither leg — without it, any
 *     transfer id is a direct-URL peek at another branch's stock moves.
 *  3. create/send/receive/cancel are owner-level ONLY. Every one of those
 *     now ships as a boolean prop, so a red test here means the Vue page
 *     could paint a button the controller would 403.
 *
 * Money and quantity strings are formatted in PHP (Qty::format /
 * number_format) exactly as the retired Blade did — asserted literally so
 * a future "let the client format it" refactor fails loudly.
 *
 * Assertions are on props (Inertia\Testing), never on markup.
 */
class BranchTransfersVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $north;
    protected Branch $south;
    protected Branch $east;

    protected User $owner;
    protected User $manager;

    protected Ingredient $patty;
    protected StorageLocation $northKitchen;
    protected StorageLocation $southStore;

    protected int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->north = Branch::create(['code' => 'bt-n', 'name' => 'الفرع الشمالي', 'is_active' => true, 'display_order' => 1]);
        $this->south = Branch::create(['code' => 'bt-s', 'name' => 'الفرع الجنوبي', 'is_active' => true, 'display_order' => 2]);
        $this->east = Branch::create(['code' => 'bt-e', 'name' => 'الفرع الشرقي', 'is_active' => true, 'display_order' => 3]);

        BranchContext::set($this->north->id);

        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->owner = $this->staff('super_admin', 'bt_owner', $this->north);
        $this->manager = $this->staff('manager', 'bt_manager', $this->north);

        $gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->northKitchen = StorageLocation::create([
            'branch_id' => $this->north->id, 'code' => 'n-k', 'name' => 'مطبخ الشمالي',
            'is_default' => true, 'active' => true, 'display_order' => 1,
        ]);
        $this->southStore = StorageLocation::create([
            'branch_id' => $this->south->id, 'code' => 's-st', 'name' => 'مخزن الجنوبي',
            'is_default' => true, 'active' => true, 'display_order' => 1,
        ]);

        $this->patty = Ingredient::create([
            'name' => 'لحمة', 'base_unit_id' => $gram->id, 'current_stock' => 100,
            'reorder_threshold' => 7, 'cost_per_unit' => 5, 'track_stock' => true, 'active' => true,
        ]);

        IngredientStock::create([
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->northKitchen->id,
            'quantity' => 100, 'reorder_threshold' => 12,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** `users` has no branch_id — membership is primary_branch_id + the pivot. */
    protected function staff(string $role, string $username, Branch ...$branches): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $branches[0]->id,
            'role' => $role,
        ]);

        foreach ($branches as $branch) {
            $user->branches()->attach($branch->id, ['is_primary' => $branch->id === $branches[0]->id]);
        }

        return $user;
    }

    protected function transfer(Branch $from, Branch $to, string $status = 'draft', array $attrs = []): BranchTransfer
    {
        $this->seq++;

        return BranchTransfer::create(array_merge([
            'number' => sprintf('BT-20260811-%04d', $this->seq),
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'status' => $status,
            'created_by_user_id' => $this->owner->id,
        ], $attrs));
    }

    protected function line(BranchTransfer $transfer, float $qty, float $cost, array $attrs = []): BranchTransferItem
    {
        return BranchTransferItem::create(array_merge([
            'branch_transfer_id' => $transfer->id,
            'ingredient_id' => $this->patty->id,
            'quantity_base' => $qty,
            'unit_cost' => $cost,
        ], $attrs));
    }

    // ── index ────────────────────────────────────────────────────────

    public function test_the_index_ships_flattened_rows_with_a_server_side_item_count(): void
    {
        $t = $this->transfer($this->north, $this->south);
        $this->line($t, 2, 5);
        $this->line($t, 3, 5);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BranchTransfers/Index')
                ->where('transfers.total', 1)
                ->where('transfers.data.0.number', $t->number)
                ->where('transfers.data.0.fromBranchName', 'الفرع الشمالي')
                ->where('transfers.data.0.toBranchName', 'الفرع الجنوبي')
                // withCount('items') — the Blade ran a COUNT query per row.
                ->where('transfers.data.0.itemsCount', 2)
                ->where('transfers.data.0.status', 'draft')
                ->where('transfers.data.0.statusLabel', 'مسودة')
                ->where('transfers.data.0.statusColor', 'secondary')
                ->where('transfers.data.0.sentAgo', null)
                ->where('transfers.data.0.url', route('admin.branch-transfers.show', $t))
                ->where('stats.draft', 1)
                ->where('stats.in_transit', 0)
                ->where('stats.this_month', 1)
                ->where('can.create', true)
                ->has('urls.create')
            );
    }

    public function test_a_branch_level_user_sees_only_transfers_their_branch_is_a_leg_of_and_cannot_create(): void
    {
        $mine = $this->transfer($this->north, $this->south);
        $this->transfer($this->south, $this->east);   // neither leg is north

        $this->actingAs($this->manager)
            ->get(route('admin.branch-transfers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BranchTransfers/Index')
                ->where('transfers.total', 1)
                ->where('transfers.data.0.number', $mine->number)
                ->where('can.create', false)
            );
    }

    public function test_the_status_filter_and_the_number_search_narrow_the_list(): void
    {
        $draft = $this->transfer($this->north, $this->south, 'draft');
        $moving = $this->transfer($this->north, $this->south, 'in_transit', ['sent_at' => now()->subHour()]);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.index', ['status' => 'in_transit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfers.total', 1)
                ->where('transfers.data.0.number', $moving->number)
                ->where('transfers.data.0.statusLabel', 'في الطريق')
                ->where('transfers.data.0.statusColor', 'warning')
                // sent_at renders as a relative string, computed server-side.
                ->where('transfers.data.0.sentAgo', fn ($v) => is_string($v) && $v !== '')
                ->where('filters.status', 'in_transit')
            );

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.index', ['search' => substr($draft->number, -4)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfers.total', 1)
                ->where('transfers.data.0.number', $draft->number)
                ->where('filters.search', substr($draft->number, -4))
            );
    }

    // ── show ─────────────────────────────────────────────────────────

    public function test_the_show_page_formats_every_figure_server_side(): void
    {
        $t = $this->transfer($this->north, $this->south);
        $this->line($t, 2.5, 3.2, ['from_location_id' => $this->northKitchen->id]);
        $this->line($t, 1000, 2);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.show', $t))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BranchTransfers/Show')
                ->where('transfer.number', $t->number)
                ->where('transfer.statusLabel', 'مسودة')
                ->where('transfer.fromBranchName', 'الفرع الشمالي')
                ->where('transfer.creatorName', 'bt_owner')
                ->has('items', 2)
                ->where('items.0.ingredientName', 'لحمة')
                ->where('items.0.quantity', '2.5')         // Qty::format
                ->where('items.0.unitCode', 'g')
                ->where('items.0.fromLocationName', 'مطبخ الشمالي')
                ->where('items.0.toLocationName', null)
                ->where('items.0.unitCost', '3.2')         // Qty::format
                ->where('items.0.lineCost', '8.00')        // 2.5 × 3.2, number_format(…, 2)
                ->where('items.1.lineCost', '2,000.00')    // thousands separator preserved
                ->where('totalCost', '2,008.00')
                ->has('currency.symbol')
            );
    }

    public function test_show_gates_every_mutation_behind_owner_level(): void
    {
        $draft = $this->transfer($this->north, $this->south, 'draft');
        $this->line($draft, 1, 1);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.send', true)
                ->where('can.receive', false)
                ->where('can.cancel', true)
                ->where('readOnlyNotice', false)
            );

        // Same transfer, branch-level user on one of the legs: read-only.
        $this->actingAs($this->manager)
            ->get(route('admin.branch-transfers.show', $draft))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.send', false)
                ->where('can.receive', false)
                ->where('can.cancel', false)
                ->where('readOnlyNotice', true)
            );
    }

    public function test_show_offers_receive_and_the_reversal_warning_only_while_in_transit(): void
    {
        $moving = $this->transfer($this->north, $this->south, 'in_transit', [
            'sent_at' => now()->subHour(),
            'sent_by_user_id' => $this->owner->id,
        ]);
        $this->line($moving, 4, 2.5);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.show', $moving))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfer.isInTransit', true)
                ->where('transfer.senderName', 'bt_owner')
                ->where('can.send', false)
                ->where('can.receive', true)
                ->where('can.cancel', true)
            );

        $done = $this->transfer($this->north, $this->south, 'received', [
            'received_at' => now(), 'received_by_user_id' => $this->owner->id,
        ]);
        $this->line($done, 1, 1);

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.show', $done))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfer.isInTransit', false)
                ->where('can.send', false)
                ->where('can.receive', false)
                ->where('can.cancel', false)
                ->where('readOnlyNotice', false)
            );
    }

    public function test_show_404s_for_a_manager_whose_branch_is_neither_leg(): void
    {
        $foreign = $this->transfer($this->south, $this->east);
        $this->line($foreign, 1, 1);

        $this->actingAs($this->manager)
            ->get(route('admin.branch-transfers.show', $foreign))
            ->assertNotFound();
    }

    // ── create ───────────────────────────────────────────────────────

    public function test_create_is_owner_level_only(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.branch-transfers.create'))
            ->assertForbidden();
    }

    public function test_create_seeds_the_picker_with_stock_thresholds_and_costs(): void
    {
        $this->actingAs($this->owner)
            // "view all branches" leaves BranchContext unbound, which is the
            // only mode where the location lookup spans both branches.
            ->withSession(['view_all_branches' => true])
            ->get(route('admin.branch-transfers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BranchTransfers/Create')
                ->has('branches', 3)
                ->where('branches.0.name', 'الفرع الشمالي')
                ->has('ingredients', 1)
                ->where('ingredients.0.id', $this->patty->id)
                ->where('ingredients.0.unit_code', 'g')
                ->where('ingredients.0.global_threshold', 7)
                ->where('ingredients.0.global_cost', 5)
                // id-keyed maps must serialize as JS OBJECTS, never arrays —
                // otherwise map[id] silently reads the wrong slot.
                ->where('locationsByBranch.'.$this->north->id.'.0.id', $this->northKitchen->id)
                ->where('locationsByBranch.'.$this->north->id.'.0.is_default', true)
                ->where('locationsByBranch.'.$this->south->id.'.0.id', $this->southStore->id)
                ->where('hasAnyLocation', true)
                ->where('stockByBranch.'.$this->north->id.'.'.$this->patty->id, 100)
                ->where('stockByLocation.'.$this->northKitchen->id.'.'.$this->patty->id, 100)
                // per-location threshold (12), not the ingredient's global (7)
                ->where('thresholdByBranchSum.'.$this->north->id.'.'.$this->patty->id, 12)
                ->where('thresholdByLocation.'.$this->northKitchen->id.'.'.$this->patty->id, 12)
                // no active batches → costByBranch stays empty and the client
                // falls back to the ingredient's global cost.
                ->where('costByBranch', [])
                ->has('currency.symbol')
                ->where('old.lines', [])
                ->where('urls.store', route('admin.branch-transfers.store'))
            );
    }

    public function test_create_rehydrates_the_picker_from_flashed_input_after_a_failed_store(): void
    {
        // `different:from_branch_id` fails → redirect back with old input.
        $this->actingAs($this->owner)
            ->from(route('admin.branch-transfers.create'))
            ->post(route('admin.branch-transfers.store'), [
                'from_branch_id' => $this->north->id,
                'to_branch_id' => $this->north->id,
                'notes' => 'نقل عاجل',
                'lines' => [[
                    'ingredient_id' => $this->patty->id,
                    'quantity_base' => 9.5,
                    'from_location_id' => $this->northKitchen->id,
                    'to_location_id' => $this->southStore->id,
                ]],
            ])
            ->assertRedirect(route('admin.branch-transfers.create'));

        $this->assertSame(0, BranchTransfer::count());

        $this->actingAs($this->owner)
            ->get(route('admin.branch-transfers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('old.from_branch_id', $this->north->id)
                ->where('old.to_branch_id', $this->north->id)
                ->where('old.notes', 'نقل عاجل')
                ->has('old.lines', 1)
                ->where('old.lines.0.ingredient_id', $this->patty->id)
                ->where('old.lines.0.quantity_base', 9.5)
                ->where('old.lines.0.from_location_id', $this->northKitchen->id)
                ->where('old.lines.0.to_location_id', $this->southStore->id)
            );
    }

    public function test_store_accepts_the_payload_shape_the_picker_posts(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.branch-transfers.store'), [
                'from_branch_id' => $this->north->id,
                'to_branch_id' => $this->south->id,
                'notes' => null,
                'lines' => [[
                    'ingredient_id' => $this->patty->id,
                    'quantity_base' => 12.5,
                    'from_location_id' => $this->northKitchen->id,
                    'to_location_id' => $this->southStore->id,
                    'notes' => null,
                ]],
            ])
            ->assertRedirect();

        $created = BranchTransfer::firstOrFail();
        $this->assertSame('draft', $created->status);
        $this->assertSame($this->north->id, (int) $created->from_branch_id);
        $this->assertSame($this->south->id, (int) $created->to_branch_id);
        $this->assertCount(1, $created->items);
        $this->assertEquals(12.5, (float) $created->items->first()->quantity_base);
        $this->assertSame($this->southStore->id, (int) $created->items->first()->to_location_id);
    }

    public function test_store_is_owner_level_only(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.branch-transfers.store'), [
                'from_branch_id' => $this->north->id,
                'to_branch_id' => $this->south->id,
                'lines' => [['ingredient_id' => $this->patty->id, 'quantity_base' => 1]],
            ])
            ->assertForbidden();

        $this->assertSame(0, BranchTransfer::count());
    }
}
