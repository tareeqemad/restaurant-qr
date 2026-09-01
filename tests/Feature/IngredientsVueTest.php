<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\IngredientUnit;
use App\Models\InventoryMovement;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * المكونات on Inertia/Vue — index, stock card (كرت الصنف), create and edit.
 *
 * What these pin, in order of how easily it would rot:
 *
 *  1. Every figure the retired Blades computed in-template — usable-vs-book
 *     stock, the blended cross-branch cost, per-location chips, the "low"
 *     predicate, the 30-day burn, the composite formula's line costs — now
 *     ships as a prop. If any of them drifts back into a template, the
 *     numbers start disagreeing with the KPI cards again (that exact bug is
 *     why the status filter and the stat cards were rewritten to share one
 *     predicate). The asserts below are on the numbers, not the markup.
 *  2. The pages ship FLAT arrays. An Eloquent model in a prop serializes
 *     its entire row — cost columns included — to anyone who can open the
 *     page, so `ingredients.data.0` is asserted to be exactly the display
 *     fields.
 *  3. The manage gate rides as a boolean prop, and the write paths still
 *     refuse a viewer regardless of what the client sends.
 */
class IngredientsVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $manager;
    protected User $chef;
    protected Unit $gram;
    protected Unit $kilo;
    protected Unit $ml;
    protected StorageLocation $kitchen;
    protected Ingredient $sugar;      // healthy
    protected Ingredient $flour;      // low
    protected Ingredient $salt;       // out

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'ing', 'name' => 'الفرع الرئيسي', 'is_active' => true, 'display_order' => 1]);
        BranchContext::set($this->branch->id);

        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->manager = $this->staff('manager', 'ing_manager');
        $this->chef    = $this->staff('chef', 'ing_chef');

        $this->gram = Unit::create(['code' => 'g',  'name' => 'جرام', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->kilo = Unit::create(['code' => 'kg', 'name' => 'كيلو', 'unit_type' => 'weight', 'factor_to_base' => 1000, 'is_base' => false]);
        $this->ml   = Unit::create(['code' => 'ml', 'name' => 'مل',   'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true]);

        $this->kitchen = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'kt', 'name' => 'المطبخ',
            'is_default' => true, 'active' => true, 'display_order' => 1,
        ]);

        $this->sugar = $this->ingredient('سكر', threshold: 1000, cost: 0.5, qty: 5000);
        $this->flour = $this->ingredient('طحين', threshold: 1000, cost: 2, qty: 500);
        $this->salt  = $this->ingredient('ملح', threshold: 100, cost: 1, qty: 0);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** `users` has no branch_id — membership is primary_branch_id + the pivot. */
    protected function staff(string $role, string $username): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

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

    protected function ingredient(string $name, float $threshold, float $cost, float $qty): Ingredient
    {
        $ing = Ingredient::create([
            'name' => $name,
            'base_unit_id' => $this->gram->id,
            'measurement_type' => 'weight',
            'current_stock' => $qty,
            'reorder_threshold' => $threshold,
            'cost_per_unit' => $cost,
            'track_stock' => true,
            'tracks_expiry' => false,
            'active' => true,
        ]);

        IngredientStock::create([
            'ingredient_id' => $ing->id,
            'storage_location_id' => $this->kitchen->id,
            'quantity' => $qty,
            'reorder_threshold' => 0,
        ]);

        return $ing;
    }

    // ═══════════════════════════════════════════════════════════════
    // Index
    // ═══════════════════════════════════════════════════════════════

    public function test_index_ships_flat_rows_with_every_figure_the_blade_used_to_compute(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ingredients/Index')
                ->where('branchName', 'الفرع الرئيسي')
                // Three discrete states, never "out riding under low".
                ->where('stats.total', 3)
                ->where('stats.healthy', 1)
                ->where('stats.low_stock', 1)
                ->where('stats.out_stock', 1)
                // 5000×0.5 + 500×2 + 0 = 3500
                ->where('totalInventoryValue', 3500)
                ->where('currency.decimals', 2)
                ->has('currency.symbol')
                ->where('can.manage', true)
                ->has('statCards', 4)
                ->has('filterLocations', 1)
                ->where('filterLocations.0.label', 'المطبخ (kt)')
                ->has('ingredients.data', 3)
                ->has('urls.export')
                // The adjust sheet is fed one grouped unit query instead of
                // the per-row query the Blade fired inside its loop.
                ->has('unitsByType.weight')
                ->has('unitsByType.volume'));
    }

    public function test_index_rows_are_flat_arrays_not_eloquent_models(): void
    {
        $row = $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index', ['search' => 'سكر']))
            ->assertOk()
            ->viewData('page')['props']['ingredients']['data'][0];

        $this->assertSame('سكر', $row['name']);
        $this->assertSame('healthy', $row['statusKey']);
        $this->assertSame('5 كيلوغرام', $row['stockLabel']);
        $this->assertSame('1 كيلوغرام', $row['thresholdLabel']);
        $this->assertSame('0.5', $row['costLabel']);
        $this->assertEquals(2500, $row['value']);
        $this->assertNull($row['unusableLabel']);
        $this->assertSame([['name' => 'المطبخ', 'qty' => '5 كيلوغرام', 'tooltip' => 'المطبخ — صالح: 5,000.00 g']], $row['locations']);

        // A raw model would drag these along; the flattened row must not.
        foreach (['created_at', 'updated_at', 'deleted_at', 'cost_per_unit', 'current_stock'] as $leak) {
            $this->assertArrayNotHasKey($leak, $row, "index row leaked `{$leak}` from the model");
        }
    }

    public function test_status_filter_narrows_both_the_rows_and_the_capital_banner(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index', ['status' => 'low']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusFilter', 'low')
                ->where('statusFilterLabel', 'منخفض')
                ->has('ingredients.data', 1)
                ->where('ingredients.data.0.name', 'طحين')
                // The banner follows the filter — 500 × 2, not the full 3500.
                ->where('totalInventoryValue', 1000)
                ->where('hasFilters', true));

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index', ['status' => 'out']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ingredients.data', 1)
                ->where('ingredients.data.0.name', 'ملح')
                ->where('ingredients.data.0.statusKey', 'out'));
    }

    public function test_location_filter_keeps_only_ingredients_actually_stocked_there(): void
    {
        $store = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'st', 'name' => 'المخزن',
            'is_default' => false, 'active' => true, 'display_order' => 2,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->salt->id, 'storage_location_id' => $store->id,
            'quantity' => 300, 'reorder_threshold' => 0,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index', ['storage_location_id' => $store->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ingredients.data', 1)
                ->where('ingredients.data.0.name', 'ملح'));
    }

    public function test_expired_batches_split_book_stock_from_usable_stock_on_the_row(): void
    {
        // Once an ingredient has batch history the ledger is authoritative:
        // an expired lot stays in the book figure but leaves the usable one.
        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->sugar->id,
            'storage_location_id' => $this->kitchen->id,
            'batch_number' => 'B-OLD',
            'received_date' => now()->subDays(40),
            'expiry_date' => now()->subDay(),
            'initial_qty' => 2000, 'remaining_qty' => 2000, 'unit_cost' => 0.5,
        ]);
        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->sugar->id,
            'storage_location_id' => $this->kitchen->id,
            'batch_number' => 'B-NEW',
            'received_date' => now()->subDay(),
            'expiry_date' => now()->addDays(30),
            'initial_qty' => 3000, 'remaining_qty' => 3000, 'unit_cost' => 0.5,
        ]);

        $row = $this->actingAs($this->manager)
            ->get(route('admin.ingredients.index', ['search' => 'سكر']))
            ->assertOk()
            ->viewData('page')['props']['ingredients']['data'][0];

        $this->assertSame('3 كيلوغرام', $row['stockLabel']);
        $this->assertSame('2 كيلوغرام', $row['unusableLabel']);
    }

    public function test_a_viewer_without_the_manage_gate_gets_no_write_affordances(): void
    {
        $this->actingAs($this->chef)
            ->get(route('admin.ingredients.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.manage', false)
                ->where('ingredients.data.0.can.manage', false));

        // …and the write paths refuse regardless of what the client sends.
        $this->actingAs($this->chef)->get(route('admin.ingredients.create'))->assertForbidden();
        $this->actingAs($this->chef)->get(route('admin.ingredients.edit', $this->sugar))->assertForbidden();
        $this->actingAs($this->chef)->delete(route('admin.ingredients.destroy', $this->sugar))->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // Show — كرت الصنف
    // ═══════════════════════════════════════════════════════════════

    public function test_stock_card_ships_every_tab_payload(): void
    {
        IngredientUnit::create([
            'ingredient_id' => $this->sugar->id, 'name' => 'كيس 25 كيلو',
            'factor_to_base' => 25000, 'purchase_price' => 40, 'sale_price' => null,
            'is_default_purchase' => true, 'active' => true,
        ]);

        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->sugar->id,
            'storage_location_id' => $this->kitchen->id,
            'batch_number' => 'B-1',
            'received_date' => now()->subDays(3),
            'expiry_date' => now()->addDays(3),          // near expiry
            'initial_qty' => 5000, 'remaining_qty' => 5000, 'unit_cost' => 0.5,
        ]);

        InventoryMovement::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->sugar->id,
            'type' => 'out', 'quantity' => 700, 'unit_id' => $this->gram->id,
            'quantity_in_base' => 700, 'unit_cost' => 0.5, 'total_cost' => 350,
            'stock_before' => 5700, 'stock_after' => 5000,
            'reason' => 'استهلاك مطبخ', 'occurred_at' => now()->subDays(2),
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $this->sugar))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ingredients/Show')
                ->where('ingredient.name', 'سكر')
                ->where('ingredient.baseCode', 'g')
                ->where('ingredient.isComposite', false)
                ->has('ingredient.packUnits', 1)
                ->where('ingredient.packUnits.0.factorLabel', '25000')
                ->where('status.label', 'آمن')
                ->where('hero.stockLabel', '5 كيلوغرام')
                ->where('hero.value', 2500)
                // 700g consumed over 30 days → 163.333…g/week, 214 days left.
                ->where('kpis.consumed30.label', '700 غرام')
                ->where('kpis.daysLeft', 214)
                ->has('dailySeries', 30)
                ->has('locations', 1)
                ->where('locations.0.name', 'المطبخ')
                ->where('locations.0.value', 2500)
                ->has('batches', 1)
                ->where('batches.0.statusKey', 'near')
                ->where('batches.0.rowTone', 'warning')
                ->has('movements.data', 1)
                ->where('movements.data.0.typeLabel', 'خصم')
                ->where('movements.data.0.sign', '−')
                ->where('movements.data.0.qtyLabel', '700 غرام')
                ->where('nav.total', 3)
                ->where('can.manage', true)
                ->has('urls.adjust'));

        // The adjust sheet may only offer units of the ingredient's own
        // measurement type — a volume unit here would blow up the server's
        // conversion. Assert on membership, not on a seed-dependent count.
        $units = $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $this->sugar))
            ->viewData('page')['props']['adjust']['units'];
        $labels = array_column($units, 'label');
        $this->assertContains('كيلو (kg)', $labels);
        $this->assertNotContains('مل (ml)', $labels);
    }

    public function test_stock_card_rolls_the_composite_formula_cost_up_from_its_sub_recipe(): void
    {
        $sauce = Ingredient::create([
            'name' => 'صلصة', 'base_unit_id' => $this->gram->id, 'measurement_type' => 'weight',
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 0,
            'track_stock' => true, 'tracks_expiry' => false, 'active' => true,
            'is_composite' => true, 'composite_yield' => 1000,
        ]);
        // 200g sugar @ 0.5 = 100 · 300g flour @ 2 = 600 → 700 per 1000g batch.
        \App\Models\RecipeItem::create([
            'parent_ingredient_id' => $sauce->id, 'ingredient_id' => $this->sugar->id,
            'quantity' => 200, 'unit_id' => $this->gram->id,
        ]);
        \App\Models\RecipeItem::create([
            'parent_ingredient_id' => $sauce->id, 'ingredient_id' => $this->flour->id,
            'quantity' => 300, 'unit_id' => $this->gram->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $sauce))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ingredient.isComposite', true)
                ->has('formula.lines', 2)
                ->where('formula.lines.0.lineCost', 100)
                ->where('formula.lines.1.lineCost', 600)
                ->where('formula.total', 700)
                ->where('formula.perUnit', 0.7)
                ->where('formula.yieldLabel', '1 كيلوغرام'));

        // And the raw ingredient reports where it is consumed.
        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $this->sugar))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('usedInComposites', 1)
                ->where('usedInComposites.0.name', 'صلصة')
                ->where('usedInComposites.0.qty', '200'));
    }

    public function test_stock_card_navigation_mirrors_the_alphabetical_index_order(): void
    {
        // Ordered by name: سكر · طحين · ملح
        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $this->flour))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.index', 2)
                ->where('nav.total', 3)
                ->where('nav.prevUrl', route('admin.ingredients.show', $this->sugar))
                ->where('nav.nextUrl', route('admin.ingredients.show', $this->salt)));

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.show', $this->salt))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nav.nextUrl', null));
    }

    // ═══════════════════════════════════════════════════════════════
    // Create / Edit
    // ═══════════════════════════════════════════════════════════════

    public function test_create_seeds_branches_locations_and_units_as_flat_arrays(): void
    {
        $supplier = Supplier::create(['name' => 'مورّد الشمال', 'active' => true]);
        $supplier->branches()->attach($this->branch->id);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ingredients/Create')
                ->where('activeBranchId', $this->branch->id)
                ->has('branches', 1)
                ->where('branches.0.name', 'الفرع الرئيسي')
                ->has('locationsByBranch.'.$this->branch->id, 1)
                ->where('locationsByBranch.'.$this->branch->id.'.0.is_default', true)
                ->has('suppliers', 1)
                ->has('supplierIdsByBranch.'.$this->branch->id, 1)
                ->has('units')
                ->has('units.0.unitType')
                ->has('units.0.label')
                // The Blade's @can('create', Branch::class) onboarding hint.
                // BranchPolicy::create() returns false for everyone today, so
                // this ships false — the gate stays in PHP either way.
                ->where('canCreateBranch', false)
                ->has('today')
                ->has('urls.store'));
    }

    public function test_edit_ships_the_cost_and_unit_locks_plus_the_pack_and_recipe_editors(): void
    {
        // No stock history yet → both locks open.
        $fresh = Ingredient::create([
            'name' => 'زعتر', 'base_unit_id' => $this->gram->id, 'measurement_type' => 'weight',
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 3,
            'track_stock' => true, 'tracks_expiry' => false, 'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.edit', $fresh))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Ingredients/Edit')
                ->where('costLocked', false)
                ->where('unitLocked', false)
                ->where('ingredient.name', 'زعتر')
                ->where('ingredient.cost_per_unit', 3)
                ->where('ingredient.trackedStockLabel', '0')
                ->has('packUnits', 0)
                ->has('subRecipeLines', 0)
                ->has('editUrls.unitsUpdate'));

        // One movement is enough to freeze the cost (it was posted to the
        // GL at that cost) and, with it, the base unit.
        InventoryMovement::create([
            'branch_id' => $this->branch->id, 'ingredient_id' => $fresh->id,
            'type' => 'in', 'quantity' => 100, 'unit_id' => $this->gram->id,
            'quantity_in_base' => 100, 'unit_cost' => 3, 'total_cost' => 300,
            'stock_before' => 0, 'stock_after' => 100,
            'reason' => 'كمية افتتاحية', 'occurred_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.edit', $fresh))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('costLocked', true)
                ->where('unitLocked', true));
    }

    public function test_edit_of_a_composite_seeds_the_sub_recipe_editor(): void
    {
        $sauce = Ingredient::create([
            'name' => 'صلصة', 'base_unit_id' => $this->gram->id, 'measurement_type' => 'weight',
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 0,
            'track_stock' => true, 'tracks_expiry' => false, 'active' => true,
            'is_composite' => true, 'composite_yield' => 1000,
        ]);
        \App\Models\RecipeItem::create([
            'parent_ingredient_id' => $sauce->id, 'ingredient_id' => $this->sugar->id,
            'quantity' => 200, 'unit_id' => $this->gram->id,
        ]);
        IngredientUnit::create([
            'ingredient_id' => $sauce->id, 'name' => 'دلو', 'factor_to_base' => 5000,
            'purchase_price' => null, 'sale_price' => null,
            'is_default_purchase' => false, 'active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.ingredients.edit', $sauce))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subRecipeLines', 1)
                ->where('subRecipeLines.0.ingredient_id', $this->sugar->id)
                ->where('subRecipeLines.0.quantity', 200)
                // The composite itself must never be offered as its own input.
                ->has('subRecipeIngredients', 3)
                ->has('subRecipeUnits')
                ->has('packUnits', 1)
                ->where('packUnits.0.factor_to_base', '5000')
                ->where('packUnits.0.purchase_price', ''));
    }

    // ═══════════════════════════════════════════════════════════════
    // Write paths the migrated screens post to
    // ═══════════════════════════════════════════════════════════════

    public function test_the_adjust_sheet_records_a_movement_and_still_rejects_a_mismatched_unit(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.ingredients.adjust', $this->sugar), [
                'type' => 'out', 'quantity' => 1, 'unit_id' => $this->kilo->id,
                'storage_location_id' => $this->kitchen->id,
                'reason' => 'هدر مطبخ',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $this->sugar->id,
            'type' => 'out',
            'quantity_in_base' => 1000,
        ]);

        // A volume unit on a weight ingredient would blow up the conversion.
        $this->actingAs($this->manager)
            ->post(route('admin.ingredients.adjust', $this->sugar), [
                'type' => 'out', 'quantity' => 1, 'unit_id' => $this->ml->id,
                'storage_location_id' => $this->kitchen->id,
                'reason' => 'خطأ',
            ])
            ->assertSessionHasErrors('unit_id');
    }

    public function test_creating_an_ingredient_seeds_its_home_location_and_opening_stock(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.ingredients.store'), [
                'name' => 'فلفل',
                'measurement_type' => 'weight',
                'base_unit_id' => $this->gram->id,
                'cost_per_unit' => 4,
                'reorder_threshold' => 100,
                'track_stock' => true,
                'tracks_expiry' => false,
                'active' => true,
                'is_composite' => false,
                'branch_id' => $this->branch->id,
                'storage_location_id' => $this->kitchen->id,
                'initial_quantity' => 250,
            ])
            ->assertRedirect(route('admin.ingredients.index'));

        $pepper = Ingredient::where('name', 'فلفل')->firstOrFail();
        $this->assertDatabaseHas('ingredient_stock', [
            'ingredient_id' => $pepper->id,
            'storage_location_id' => $this->kitchen->id,
        ]);
        $this->assertEqualsWithDelta(250, $pepper->trackedStock(), 0.0001);
    }

    public function test_the_pack_editor_replaces_the_unit_list_and_guards_the_single_default(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.ingredients.units.update', $this->sugar), [
                'units' => [
                    ['name' => 'كيس', 'factor_to_base' => 25000, 'barcode' => null,
                     'purchase_price' => 40, 'sale_price' => null, 'is_default_purchase' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ingredient_units', [
            'ingredient_id' => $this->sugar->id, 'name' => 'كيس', 'is_default_purchase' => true,
        ]);

        // Two defaults is not a thing the client may express.
        $this->actingAs($this->manager)
            ->post(route('admin.ingredients.units.update', $this->sugar), [
                'units' => [
                    ['name' => 'كيس', 'factor_to_base' => 25000, 'is_default_purchase' => true],
                    ['name' => 'كرتون', 'factor_to_base' => 50000, 'is_default_purchase' => true],
                ],
            ])
            ->assertSessionHas('error');
    }
}
