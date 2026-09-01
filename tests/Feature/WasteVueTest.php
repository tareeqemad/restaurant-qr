<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Lookup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 4 (MIGRATION-PILOT.md §13): the waste form on Inertia/Vue.
 *
 * These tests pin the seed payload and re-assert that the single write
 * path still enforces its three
 * validation lanes (batch / location / global) and the manage gate.
 */
class WasteVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected User $chef;

    protected Ingredient $patty;

    protected Unit $gram;

    protected Unit $kilo;

    protected StorageLocation $kitchen;

    protected Lookup $expiredReason;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'ws', 'name' => 'WS', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        Role::create(['name' => 'chef', 'label' => 'Chef', 'is_system' => true]);

        $this->manager = User::create([
            'name' => 'M', 'username' => 'ws_manager', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'manager',
        ]);
        $this->manager->branches()->attach($this->branch->id);

        $this->chef = User::create([
            'name' => 'C', 'username' => 'ws_chef', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'chef',
        ]);
        $this->chef->branches()->attach($this->branch->id);

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->kilo = Unit::create([
            'code' => 'kg', 'name' => 'كيلو', 'unit_type' => 'weight',
            'factor_to_base' => 1000, 'is_base' => false,
        ]);
        // A volume unit must NEVER be offered for a weight ingredient.
        Unit::create([
            'code' => 'ml', 'name' => 'مل', 'unit_type' => 'volume',
            'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->kitchen = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'المطبخ',
            'is_default' => true, 'active' => true,
        ]);

        $this->patty = Ingredient::create([
            'name' => 'لحمة', 'base_unit_id' => $this->gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 2, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->kitchen->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);

        $this->expiredReason = Lookup::create([
            'branch_id' => null, 'group' => 'waste_reasons', 'code' => 'expired',
            'label' => 'انتهت الصلاحية', 'is_active' => true, 'display_order' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_the_form_seeds_ingredients_units_reasons_and_locations(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.waste.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Waste/Create')
                ->has('ingredients', 1, fn (Assert $i) => $i
                    ->where('name', 'لحمة')
                    ->where('unitCode', 'g')
                    ->where('baseUnitType', 'weight')
                    ->where('currentStock', 5000)
                    ->has('stocks')
                    ->etc())
                // Every unit ships (seeded ones included) — the CLIENT
                // filters to the ingredient's own unitType, so the payload
                // must carry that field on each row.
                ->has('units')
                ->has('units.0.unitType')
                // Reasons come from the lookups admin (seeded set + ours).
                ->has('reasons')
                ->has('reasons.0.code')
                ->has('locations', 1)
                ->has('submitToken')
                ->where('canManage', true)
                ->has('urls.store'));
    }

    public function test_a_chef_sees_the_form_read_only_and_cannot_write_off_stock(): void
    {
        // Opening is allowed (viewAny) but the payload says so…
        $this->actingAs($this->chef)
            ->get(route('admin.waste.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

        // …and the write path refuses regardless of what the client sends.
        $this->actingAs($this->chef)
            ->post(route('admin.waste.store'), [
                'ingredient_id' => $this->patty->id,
                'quantity' => 100,
                'unit_id' => $this->gram->id,
                'reason_lookup_id' => $this->expiredReason->id,
            ])
            ->assertForbidden();

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock);
    }

    public function test_waste_converts_the_entered_unit_before_deducting(): void
    {
        // 1 kg entered against a gram-based ingredient → 1000 g deducted.
        $this->actingAs($this->manager)
            ->post(route('admin.waste.store'), [
                'request_token' => (string) Str::ulid(),
                'ingredient_id' => $this->patty->id,
                'quantity' => 1,
                'unit_id' => $this->kilo->id,
                'storage_location_id' => $this->kitchen->id,
                'reason_lookup_id' => $this->expiredReason->id,
            ])
            ->assertRedirect(route('admin.waste.index'));

        $this->assertSame(4000.0, (float) $this->patty->fresh()->current_stock);
    }

    public function test_waste_beyond_the_location_stock_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.waste.store'), [
                'request_token' => (string) Str::ulid(),
                'ingredient_id' => $this->patty->id,
                'quantity' => 99,
                'unit_id' => $this->kilo->id,   // 99 kg = 99,000 g ≫ 5,000
                'storage_location_id' => $this->kitchen->id,
                'reason_lookup_id' => $this->expiredReason->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock);
    }

    public function test_batch_waste_decrements_that_batch(): void
    {
        $batch = IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->kitchen->id,
            'batch_number' => 'B-1',
            'initial_qty' => 1000,
            'remaining_qty' => 1000,
            'unit_cost' => 3,
            'received_date' => now()->subDays(5),
            'expiry_date' => now()->subDay(),   // already expired
        ]);

        $this->actingAs($this->manager)
            ->post(route('admin.waste.store'), [
                'request_token' => (string) Str::ulid(),
                'ingredient_id' => $this->patty->id,
                'batch_id' => $batch->id,
                'quantity' => 200,
                'unit_id' => $this->gram->id,
                'storage_location_id' => $this->kitchen->id,
                'reason_lookup_id' => $this->expiredReason->id,
            ])
            ->assertRedirect(route('admin.waste.index'));

        $this->assertSame(800.0, (float) $batch->fresh()->remaining_qty);
    }

    /** The selected batch and the explicit location must move together. */
    public function test_batch_waste_deducts_the_location_the_batch_lives_in(): void
    {
        $cellar = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'c', 'name' => 'القبو',
            'is_default' => false, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $cellar->id,
            'quantity' => 1000, 'reorder_threshold' => 0,
        ]);
        $batch = IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $cellar->id,     // NOT the default location
            'batch_number' => 'B-CELLAR',
            'initial_qty' => 1000, 'remaining_qty' => 1000, 'unit_cost' => 3,
            'received_date' => now()->subDays(3),
        ]);

        $this->actingAs($this->manager)
            ->post(route('admin.waste.store'), [
                'request_token' => (string) Str::ulid(),
                'ingredient_id' => $this->patty->id,
                'batch_id' => $batch->id,
                'storage_location_id' => $cellar->id,
                'quantity' => 100,
                'unit_id' => $this->gram->id,
                'reason_lookup_id' => $this->expiredReason->id,
            ])
            ->assertRedirect(route('admin.waste.index'));

        $this->assertSame(900.0, (float) $batch->fresh()->remaining_qty);

        // The cellar row lost the stock; the kitchen (default) row is intact.
        $this->assertSame(900.0, (float) IngredientStock::where('ingredient_id', $this->patty->id)
            ->where('storage_location_id', $cellar->id)->value('quantity'));
        $this->assertSame(5000.0, (float) IngredientStock::where('ingredient_id', $this->patty->id)
            ->where('storage_location_id', $this->kitchen->id)->value('quantity'));
    }

    public function test_the_batches_endpoint_is_gated(): void
    {
        // A logged-out caller can't enumerate lot numbers and unit costs
        // (the endpoint used to have no authorize() call at all).
        $this->getJson(route('admin.waste.batches', ['ingredient' => $this->patty->id]))
            ->assertUnauthorized();
    }

    public function test_repeating_the_same_submission_deducts_stock_once(): void
    {
        $payload = [
            'request_token' => (string) Str::ulid(),
            'ingredient_id' => $this->patty->id,
            'quantity' => 100,
            'unit_id' => $this->gram->id,
            'storage_location_id' => $this->kitchen->id,
            'reason_lookup_id' => $this->expiredReason->id,
        ];

        $this->actingAs($this->manager)
            ->post(route('admin.waste.store'), $payload)
            ->assertRedirect(route('admin.waste.index'));

        $this->post(route('admin.waste.store'), $payload)
            ->assertRedirect(route('admin.waste.index'));

        $this->assertSame(4900.0, (float) $this->patty->fresh()->current_stock);
        $this->assertSame(1, InventoryMovement::where('type', 'waste')->count());
    }

    public function test_inactive_ingredients_are_hidden_and_cannot_be_written_off(): void
    {
        $inactive = Ingredient::create([
            'name' => 'مكوّن متوقف',
            'base_unit_id' => $this->gram->id,
            'current_stock' => 100,
            'reorder_threshold' => 0,
            'cost_per_unit' => 1,
            'track_stock' => true,
            'active' => false,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.waste.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('ingredients', 1)
                ->where('ingredients.0.id', $this->patty->id));

        $this->post(route('admin.waste.store'), [
            'request_token' => (string) Str::ulid(),
            'ingredient_id' => $inactive->id,
            'quantity' => 10,
            'unit_id' => $this->gram->id,
            'storage_location_id' => $this->kitchen->id,
            'reason_lookup_id' => $this->expiredReason->id,
        ])->assertSessionHasErrors('ingredient_id');

        $this->getJson(route('admin.waste.batches', [
            'ingredient' => $inactive->id,
            'storage_location_id' => $this->kitchen->id,
        ]))->assertNotFound();

        $this->assertSame(0, InventoryMovement::where('ingredient_id', $inactive->id)->count());
    }

    public function test_the_units_payload_carries_the_conversion_factor(): void
    {
        // Without factorToBase the client can't mirror UnitConverter, which
        // is exactly how the retired screen previewed 1000× wrong costs.
        $page = $this->actingAs($this->manager)->get(route('admin.waste.create'));
        $units = collect($page->viewData('page')['props']['units']);
        $kg = $units->firstWhere('id', $this->kilo->id);

        $this->assertNotNull($kg);
        $this->assertSame(1000.0, (float) $kg['factorToBase']);
    }

    // ─── The waste LOG (index) ────────────────────────────────────────
    //
    // Everything the Blade computed in-template now ships as a prop: the
    // four KPIs, the per-reason share, the top-wasted ladder and the
    // reason badge (label/icon/colour) for BOTH the FK rows and the
    // legacy string ones.

    protected function wasteMovement(array $attrs = []): InventoryMovement
    {
        return InventoryMovement::create(array_merge([
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->kitchen->id,
            'type' => 'waste',
            'quantity' => 100,
            'unit_id' => $this->gram->id,
            'quantity_in_base' => 100,
            'unit_cost' => 2,
            'total_cost' => 200,
            'stock_before' => 5000,
            'stock_after' => 4900,
            'waste_reason' => 'expired',
            'waste_reason_lookup_id' => $this->expiredReason->id,
            'user_id' => $this->manager->id,
            'occurred_at' => now(),
        ], $attrs));
    }

    public function test_the_log_ships_rows_kpis_and_the_reason_breakdown(): void
    {
        $spill = Lookup::create([
            'branch_id' => null, 'group' => 'waste_reasons', 'code' => 'spillage',
            'label' => 'انسكاب', 'color' => '#dc2626', 'icon' => 'bi-droplet',
            'is_active' => true, 'display_order' => 2,
        ]);

        $this->wasteMovement();                                    // 200
        $this->wasteMovement([
            'waste_reason' => 'spillage',
            'waste_reason_lookup_id' => $spill->id,
            'quantity_in_base' => 300, 'total_cost' => 600,        // 600
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.waste.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Waste/Index')
                ->where('stats.count', 2)
                ->where('stats.totalCost', 800)
                ->where('stats.todayCount', 2)
                ->where('stats.todayCost', 800)
                // Ordered by cost desc — 600 (75%) before 200 (25%).
                ->has('byReason', 2)
                ->where('byReason.0.label', 'انسكاب')
                ->where('byReason.0.count', 1)
                ->where('byReason.0.totalCost', 600)
                ->where('byReason.0.pct', 75)
                ->where('byReason.0.pctLabel', '75.0')
                ->where('byReason.0.color', '#dc2626')
                ->where('byReason.0.icon', 'bi-droplet')
                ->where('byReason.1.pctLabel', '25.0')
                // Top-wasted: one ingredient, both events, 400 g total.
                ->has('topIngredients', 1)
                ->where('topIngredients.0.eventCount', 2)
                ->where('topIngredients.0.totalCost', 800)
                ->where('topIngredients.0.qtyDisplay', '400 غرام')
                // Rows are flattened — no raw models.
                ->has('movements.data', 2)
                ->where('movements.data.0.ingredientName', 'لحمة')
                ->where('movements.data.0.reason.label', 'انسكاب')
                ->where('movements.data.0.locationName', 'المطبخ')
                ->where('movements.data.0.qtyDisplay', '300 غرام')
                ->where('movements.data.0.userName', 'M')
                ->has('movements.links')
                ->where('currency.decimals', 2)
                ->has('urls.create'));
    }

    /**
     * A row written before the FK migration carries only the legacy string.
     * The badge must still resolve through the code-keyed lookup map —
     * that fallback was an @php block in the Blade.
     */
    public function test_a_legacy_string_row_still_resolves_its_reason_badge(): void
    {
        $this->wasteMovement(['waste_reason_lookup_id' => null]);

        $this->actingAs($this->manager)
            ->get(route('admin.waste.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.data.0.reason.label', 'انتهت الصلاحية')
                // Lookup with no colour configured → the PHP default.
                ->where('movements.data.0.reason.color', '#64748b')
                ->where('byReason.0.label', 'انتهت الصلاحية'));
    }

    public function test_the_reason_filter_narrows_the_rows_and_the_kpis_together(): void
    {
        $spill = Lookup::create([
            'branch_id' => null, 'group' => 'waste_reasons', 'code' => 'spillage',
            'label' => 'انسكاب', 'is_active' => true, 'display_order' => 2,
        ]);

        $this->wasteMovement();
        $this->wasteMovement([
            'waste_reason' => 'spillage', 'waste_reason_lookup_id' => $spill->id,
            'total_cost' => 600,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.waste.index', ['reason' => $spill->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('movements.data', 1)
                ->where('movements.data.0.reason.label', 'انسكاب')
                ->where('stats.count', 1)
                ->where('stats.totalCost', 600)
                // The top-wasted table is a raw query builder — it must obey
                // the same filter, not report the unfiltered total.
                ->where('topIngredients.0.totalCost', 600)
                ->where('filters.reason', (string) $spill->id));
    }

    /**
     * The top-wasted table is built with DB::table(), which does NOT inherit
     * BranchScope. Without the explicit branch filter every branch saw the
     * same cross-company numbers.
     */
    public function test_the_top_wasted_table_stays_inside_the_active_branch(): void
    {
        $other = Branch::create(['code' => 'ws2', 'name' => 'WS2', 'is_active' => true]);

        $this->wasteMovement();
        $this->wasteMovement(['branch_id' => $other->id, 'total_cost' => 5000]);

        $this->actingAs($this->manager)
            ->get(route('admin.waste.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('movements.data', 1)
                ->where('stats.totalCost', 200)
                ->has('topIngredients', 1)
                ->where('topIngredients.0.totalCost', 200));
    }

    public function test_the_lookups_gear_is_a_server_side_gate(): void
    {
        // A chef can read the log but cannot reach the lookups admin.
        $this->actingAs($this->chef)
            ->get(route('admin.waste.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manageLookups', false));

        $permission = Permission::firstOrCreate(
            ['name' => 'lookups.viewAny'],
            ['group' => 'lookups', 'label' => 'عرض الثوابت'],
        );
        $this->manager->permissions()->attach($permission->id, ['granted' => true]);

        $this->actingAs($this->manager->fresh())
            ->get(route('admin.waste.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manageLookups', true));
    }

    public function test_the_batches_endpoint_lists_expired_lots_for_write_off(): void
    {
        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->kitchen->id,
            'batch_number' => 'B-OLD',
            'initial_qty' => 500, 'remaining_qty' => 500, 'unit_cost' => 3,
            'received_date' => now()->subDays(10),
            'expiry_date' => now()->subDays(2),
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('admin.waste.batches', [
                'ingredient' => $this->patty->id,
                'storage_location_id' => $this->kitchen->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.is_expired', true);
    }
}
