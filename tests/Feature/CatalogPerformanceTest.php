<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CashierVueController;
use App\Http\Controllers\Admin\WaiterPosVueController;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Services\MenuAvailabilityService;
use App\Services\UsableStockSnapshot;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private StorageLocation $location;

    private Ingredient $ingredient;

    private MenuItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'perf', 'name' => 'Performance', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $grams = Unit::create(['code' => 'g', 'name' => 'Gram', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $kg = Unit::create(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'factor_to_base' => 1000]);
        $this->location = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'kitchen', 'name' => 'Kitchen', 'active' => true, 'is_default' => true]);
        $station = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $this->location->id, 'active' => true]);
        $category = Category::create(['slug' => 'meals', 'name' => 'Meals', 'default_station_id' => $station->id, 'active' => true]);
        $this->ingredient = Ingredient::create(['name' => 'Flour', 'base_unit_id' => $grams->id, 'track_stock' => true, 'active' => true, 'reorder_threshold' => 20]);
        IngredientStock::create(['ingredient_id' => $this->ingredient->id, 'storage_location_id' => $this->location->id, 'quantity' => 1000, 'reorder_threshold' => 15]);
        $composite = Ingredient::create(['name' => 'Dough', 'base_unit_id' => $grams->id, 'is_composite' => true, 'composite_yield' => 1000, 'track_stock' => true, 'active' => true]);
        RecipeItem::create(['parent_ingredient_id' => $composite->id, 'ingredient_id' => $this->ingredient->id, 'quantity' => .8, 'unit_id' => $kg->id]);
        for ($i = 0; $i < 120; $i++) {
            $item = MenuItem::create(['category_id' => $category->id, 'station_id' => $station->id, 'slug' => 'meal-'.$i, 'name' => 'Meal '.$i, 'price' => 20, 'is_available' => true]);
            RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $composite->id, 'quantity' => .5, 'unit_id' => $kg->id]);
            $this->item = $item;
        }
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_waiter_and_cashier_catalog_queries_do_not_grow_per_menu_item(): void
    {
        foreach ([WaiterPosVueController::class => 'menuPayload', CashierVueController::class => 'menuCatalog'] as $controller => $method) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $catalog = (new \ReflectionMethod($controller, $method))->invoke(app($controller));
            $elapsed = round((hrtime(true) - $start) / 1e6, 1);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDERR, "\n{$method}: {$queries} queries, {$elapsed} ms, 120 composite recipes\n");
            $this->assertCount(120, $catalog['items']);
            $this->assertTrue($catalog['items']->every(fn ($item) => $item['in_stock']));
            $this->assertLessThanOrEqual(25, $queries, 'Catalogue queries must be bounded by recipe depth, not menu size.');
        }
    }

    public function test_snapshot_preserves_expiry_location_branch_threshold_and_legacy_fallback(): void
    {
        $otherBranch = Branch::create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $otherLocation = StorageLocation::create(['branch_id' => $otherBranch->id, 'code' => 'other', 'name' => 'Other', 'active' => true]);
        IngredientStock::create(['ingredient_id' => $this->ingredient->id, 'storage_location_id' => $otherLocation->id, 'quantity' => 700]);
        $expired = IngredientBatch::create(['branch_id' => $this->branch->id, 'ingredient_id' => $this->ingredient->id, 'storage_location_id' => $this->location->id, 'batch_number' => 'expired', 'received_date' => now()->subDays(5), 'expiry_date' => now()->subDay(), 'initial_qty' => 1000, 'remaining_qty' => 1000, 'unit_cost' => 1]);
        $snapshot = UsableStockSnapshot::capture([$this->ingredient->id]);
        $this->assertSame(0.0, $snapshot->atLocation($this->ingredient->id, $this->location->id));
        $this->assertSame(700.0, $snapshot->atBranch($this->ingredient->id, $otherBranch->id));
        $this->assertSame($this->ingredient->trackedUsableStock(), $snapshot->allBranches($this->ingredient->id));
        $this->assertSame(15.0, $snapshot->threshold($this->ingredient, $this->branch->id));
        $this->assertFalse(app(MenuAvailabilityService::class)->forItems(MenuItem::whereKey($this->item->id)->get())[$this->item->id]);
        $expired->update(['expiry_date' => now()->toDateString(), 'remaining_qty' => 500]);
        $fresh = UsableStockSnapshot::capture([$this->ingredient->id]);
        $this->assertSame(500.0, $fresh->atLocation($this->ingredient->id, $this->location->id));
        $this->assertTrue(app(MenuAvailabilityService::class)->forItems(MenuItem::whereKey($this->item->id)->get())[$this->item->id]);
        $expired->delete();
        $this->assertSame(1000.0, UsableStockSnapshot::capture([$this->ingredient->id])->atBranch($this->ingredient->id, $this->branch->id));
    }

    public function test_catalogue_uses_category_station_then_branch_default_and_keeps_cycle_detection(): void
    {
        $this->item->update(['station_id' => null]);
        IngredientStock::where('ingredient_id', $this->ingredient->id)->update(['quantity' => 399]);
        $service = app(MenuAvailabilityService::class);
        $this->assertFalse($service->forItems(MenuItem::whereKey($this->item->id)->get())[$this->item->id]);
        $this->item->category->update(['default_station_id' => null]);
        $this->assertFalse($service->forItems(MenuItem::whereKey($this->item->id)->get())[$this->item->id]);
        IngredientStock::where('ingredient_id', $this->ingredient->id)->update(['quantity' => 400]);
        $this->assertTrue($service->forItems(MenuItem::whereKey($this->item->id)->get())[$this->item->id]);
        $composite = $this->item->recipeItems()->first()->ingredient;
        RecipeItem::create(['parent_ingredient_id' => $composite->id, 'ingredient_id' => $composite->id, 'quantity' => 1, 'unit_id' => $composite->base_unit_id]);
        $this->expectException(\RuntimeException::class);
        $service->forItems(MenuItem::whereKey($this->item->id)->get());
    }

    public function test_customer_catalogue_is_bounded_and_does_not_advertise_cashier_only_prices(): void
    {
        $this->withoutVite();
        $table = Table::create(['number' => 'P1', 'capacity' => 2, 'status' => 'available', 'active' => true]);
        $session = TableSession::create(['table_id' => $table->id, 'token' => 'perf-session', 'status' => 'active', 'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 1]);
        MenuPromotion::create(['name' => 'Cashier only', 'target_type' => 'category', 'target_id' => $this->item->category_id, 'type' => 'percent', 'value' => 50, 'channels' => ['cashier'], 'active' => true]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->withCookie('table_session', $session->token)->get(route('customer.menu.open', $table->qr_token));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('categories.0.items', 120)
            ->where('categories.0.items.0.price', 20)->where('categories.0.items.0.can_order', true));
        fwrite(STDERR, "\nCustomer full request: {$queries} queries for 120 composite recipes\n");
        $this->assertLessThanOrEqual(95, $queries);
        $cashier = (new \ReflectionMethod(CashierVueController::class, 'menuCatalog'))->invoke(app(CashierVueController::class));
        $waiter = (new \ReflectionMethod(WaiterPosVueController::class, 'menuPayload'))->invoke(app(WaiterPosVueController::class));
        $this->assertSame(10.0, $cashier['items'][0]['price']);
        $this->assertSame(20.0, $waiter['items'][0]['price']);
    }
}
