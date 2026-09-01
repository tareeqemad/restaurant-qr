<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\IngredientSupplierPrice;
use App\Models\IngredientUnit;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AlertsService;
use App\Services\BatchInventoryService;
use App\Services\InventoryService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InventoryExpiryPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private StorageLocation $storage;
    private Unit $gram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'exp', 'name' => 'Expiry', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id,
            'code' => 'main',
            'name' => 'Main',
            'is_default' => true,
            'active' => true,
        ]);
        $this->gram = Unit::create([
            'code' => 'g',
            'name' => 'غرام',
            'unit_type' => 'weight',
            'factor_to_base' => 1,
            'is_base' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function ingredient(array $extra = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'name' => 'دجاج',
            'base_unit_id' => $this->gram->id,
            'measurement_type' => 'weight',
            'current_stock' => 0,
            'reorder_threshold' => 10,
            'cost_per_unit' => 1,
            'track_stock' => true,
            'tracks_expiry' => true,
            'active' => true,
        ], $extra));
    }

    public function test_fifo_never_consumes_expired_lots_and_today_is_still_valid(): void
    {
        $ingredient = $this->ingredient();

        $expired = IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'received_date' => now()->subDays(3)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'initial_qty' => 20,
            'remaining_qty' => 20,
            'unit_cost' => 1,
        ]);
        $today = app(BatchInventoryService::class)->createBatchOnReceipt(
            $ingredient, 5, 2, now()->toDateString(), storageLocationId: $this->storage->id
        );
        $tomorrow = app(BatchInventoryService::class)->createBatchOnReceipt(
            $ingredient, 10, 3, now()->addDay()->toDateString(), storageLocationId: $this->storage->id
        );

        $taken = app(BatchInventoryService::class)->deductFifo($ingredient, 6, $this->storage->id);

        $this->assertSame([$today->id, $tomorrow->id], collect($taken)->pluck('batch.id')->all());
        $this->assertEqualsWithDelta(20, (float) $expired->fresh()->remaining_qty, 0.0001);
        $this->assertFalse($today->isExpired());

        // Waste/count reconciliation can explicitly remove quarantined stock.
        app(BatchInventoryService::class)->deductFifo(
            $ingredient,
            5,
            $this->storage->id,
            includeExpired: true,
        );
        $this->assertEqualsWithDelta(15, (float) $expired->fresh()->remaining_qty, 0.0001);
    }

    public function test_customer_stock_preview_excludes_expired_quantity(): void
    {
        $ingredient = $this->ingredient(['current_stock' => 20]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 20,
            'reorder_threshold' => 10,
        ]);
        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'received_date' => now()->subDays(5)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'initial_qty' => 20,
            'remaining_qty' => 20,
            'unit_cost' => 1,
        ]);

        $station = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen', 'active' => true,
            'storage_location_id' => $this->storage->id,
        ]);
        $category = Category::create([
            'slug' => 'food', 'name' => 'Food', 'active' => true,
            'default_station_id' => $station->id,
        ]);
        $item = MenuItem::create([
            'category_id' => $category->id,
            'station_id' => $station->id,
            'name' => 'وجبة',
            'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $item->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'unit_id' => $this->gram->id,
        ]);

        $issues = app(InventoryService::class)->checkStockForOrderPreview([[
            'menu_item_id' => $item->id,
            'quantity' => 1,
            'modifier_ids' => [],
        ]], $this->branch->id);

        $this->assertCount(1, $issues);
        $this->assertSame(0.0, (float) $issues[0]['available']);
        $this->assertSame(0.0, $ingredient->trackedUsableStock());
        $this->assertTrue($ingredient->isLowStock());

        $outAlert = collect(app(AlertsService::class)->dashboardSnapshot())
            ->first(fn ($alert) => str_contains($alert['route'], 'status=out'));
        $this->assertNotNull($outAlert);
        $this->assertGreaterThanOrEqual(1, $outAlert['count']);
    }

    public function test_expiry_tracked_ingredient_requires_date_on_receipt(): void
    {
        $this->expectException(ValidationException::class);

        app(BatchInventoryService::class)->createBatchOnReceipt(
            $this->ingredient(),
            10,
            1,
            storageLocationId: $this->storage->id,
        );
    }

    public function test_expired_lot_can_be_written_off_without_becoming_sellable(): void
    {
        $ingredient = $this->ingredient(['current_stock' => 10, 'cost_per_unit' => 2]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 10,
            'reorder_threshold' => 1,
        ]);
        $batch = IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'received_date' => now()->subDays(5)->toDateString(),
            'expiry_date' => now()->subDay()->toDateString(),
            'initial_qty' => 10,
            'remaining_qty' => 10,
            'unit_cost' => 2,
        ]);

        app(InventoryService::class)->recordMovement(
            ingredient: $ingredient,
            type: 'waste',
            qtyBase: 4,
            unitCost: 2,
            reason: 'Expired stock write-off',
            storageLocationId: $this->storage->id,
            syncBatches: true,
        );

        $this->assertEqualsWithDelta(6, (float) $batch->fresh()->remaining_qty, 0.0001);
        $this->assertEqualsWithDelta(6, (float) $ingredient->fresh()->current_stock, 0.0001);
        $this->assertSame(0.0, $ingredient->fresh()->usableStockAtBranch($this->branch->id));
    }

    public function test_branch_recipe_cost_uses_same_ingredient_unit_formula_as_deduction(): void
    {
        $ingredient = $this->ingredient(['tracks_expiry' => false]);
        $scoop = IngredientUnit::create([
            'ingredient_id' => $ingredient->id,
            'name' => 'مغرفة',
            'factor_to_base' => 10,
            'active' => true,
        ]);
        IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $this->storage->id,
            'received_date' => now()->toDateString(),
            'initial_qty' => 100,
            'remaining_qty' => 100,
            'unit_cost' => 3,
        ]);
        $category = Category::create(['slug' => 'cost', 'name' => 'Cost', 'active' => true]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'طبق', 'price' => 100]);
        RecipeItem::create([
            'menu_item_id' => $item->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 2,
            'unit_id' => $this->gram->id,
            'ingredient_unit_id' => $scoop->id,
        ]);

        // 2 scoops × 10g × 3 per gram = 60.
        $this->assertEqualsWithDelta(60, $item->costAtBranch($this->branch->id), 0.0001);
    }

    public function test_monthly_reorder_screen_renders_with_verified_alternative_supplier_data(): void
    {
        Cache::flush();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = User::create([
            'name' => 'Admin',
            'username' => 'expiry_admin',
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => 'admin',
            'primary_branch_id' => $this->branch->id,
        ]);
        $admin->branches()->attach($this->branch->id);

        $ingredient = $this->ingredient(['name' => 'حليب', 'tracks_expiry' => false]);
        $supplier = Supplier::create(['name' => 'مورد بديل', 'active' => true]);
        $supplier->branches()->attach($this->branch->id);
        IngredientSupplierPrice::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $this->gram->id,
            'unit_price' => 2,
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'unit_price_in_base' => 2,
            'source' => 'manual',
            'observed_at' => now(),
        ]);

        // The screen went Vue in Wave 4, so assert the DATA it now ships
        // instead of a rendered Arabic label: the alternative supplier and
        // its observed price must reach the buyer's picker.
        $this->actingAs($admin)
            ->get(route('admin.reports.reorder-suggestions'))
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Admin/Reports/ReorderSuggestions')
                ->has('rows', fn (\Inertia\Testing\AssertableInertia $rows) => $rows
                    ->where('0.name', 'حليب')
                    ->has('0.suppliers', 1, fn (\Inertia\Testing\AssertableInertia $sup) => $sup
                        ->where('name', 'مورد بديل')
                        ->where('price', 2)
                        ->etc())
                    ->etc()));
    }
}
