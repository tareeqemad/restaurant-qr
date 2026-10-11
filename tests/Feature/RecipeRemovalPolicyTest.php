<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\MenuDuplicationService;
use App\Services\OrderService;
use App\Services\RecipeCostService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class RecipeRemovalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Table $table;

    private TableSession $session;

    private MenuItem $water;

    private Ingredient $bottle;

    private RecipeItem $recipe;

    private IngredientStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->branch = Branch::create(['code' => 'sealed', 'name' => 'Test', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'Owner', 'is_system' => true]);
        $this->owner = User::create([
            'name' => 'Owner', 'username' => 'sealed-owner', 'password' => bcrypt('test'),
            'role' => 'super_admin', 'status' => 'active', 'primary_branch_id' => $this->branch->id,
        ]);
        $this->owner->branches()->attach($this->branch->id, ['is_primary' => true]);
        $unit = Unit::create(['code' => 'pcs', 'name' => 'Piece', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'bar', 'name' => 'Bar', 'is_default' => true, 'active' => true,
        ]);
        $station = Station::create(['code' => 'bar', 'name' => 'Bar', 'storage_location_id' => $storage->id, 'active' => true]);
        $category = Category::create(['slug' => 'drinks', 'name' => 'Drinks', 'default_station_id' => $station->id, 'active' => true]);
        $this->bottle = Ingredient::create([
            'name' => 'عبوة ماء 500 مل', 'base_unit_id' => $unit->id, 'current_stock' => 5,
            'reorder_threshold' => 0, 'cost_per_unit' => 1.2, 'track_stock' => true, 'active' => true,
        ]);
        $this->stock = IngredientStock::create([
            'ingredient_id' => $this->bottle->id, 'storage_location_id' => $storage->id, 'quantity' => 5,
        ]);
        $this->water = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id, 'slug' => 'water',
            'name' => 'ماء 500 مل', 'price' => 3, 'is_available' => true,
        ]);
        $this->recipe = RecipeItem::create([
            'menu_item_id' => $this->water->id, 'ingredient_id' => $this->bottle->id,
            'quantity' => 1, 'unit_id' => $unit->id, 'is_optional' => false, 'is_removable' => false,
        ]);
        $this->table = Table::create(['number' => 'W1', 'capacity' => 2, 'status' => 'available', 'active' => true]);
        $this->session = TableSession::create([
            'table_id' => $this->table->id, 'token' => 'sealed-session', 'status' => 'active',
            'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_sealed_recipe_is_visible_but_not_offered_for_removal_to_customers_or_waiters(): void
    {
        $this->withCookie('table_session', $this->session->token)
            ->get(route('customer.menu.open', $this->table->qr_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.items.0.ingredients.0', 'عبوة ماء 500 مل')
                ->where('categories.0.items.0.removable_ingredients', []));

        $this->actingAs($this->owner)->get(route('admin.waiter-orders.create', $this->table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('menu.0.removable_ingredients', []));
    }

    public function test_forged_customer_and_waiter_exclusions_are_rejected_without_an_order(): void
    {
        $line = ['menu_item_id' => $this->water->id, 'quantity' => 1, 'modifier_ids' => [], 'excluded_ingredient_ids' => [$this->bottle->id]];
        $this->postJson(route('customer.cart.add'), ['session' => $this->session->token, ...$line])
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertSame([], session('cart.'.$this->session->token, []));

        $this->actingAs($this->owner)->postJson(route('admin.waiter-orders.submit', $this->table), [
            'token' => 'sealed-reject', 'session_id' => $this->session->id, 'cart' => [$line],
        ])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_item_ingredient_exclusions', 0);
        $this->assertDatabaseCount('waiter_submission_receipts', 0);
    }

    public function test_shared_order_service_rejects_a_forbidden_exclusion_and_rolls_back(): void
    {
        try {
            app(OrderService::class)->createFromCart($this->session, [[
                'menu_item_id' => $this->water->id, 'quantity' => 1,
                'excluded_ingredient_ids' => [$this->bottle->id],
            ]]);
            $this->fail('A sealed recipe must be protected outside the customer/waiter controllers too.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('لا يمكن استبعاد', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame('available', $this->table->fresh()->status);
        $this->assertSame(5.0, (float) $this->stock->fresh()->quantity);
    }

    public function test_a_normal_bottle_sale_keeps_its_recipe_cost_and_deducts_the_bottles(): void
    {
        $this->assertEqualsWithDelta(1.2, app(RecipeCostService::class)->costForMenuItem($this->water), .0001);
        $order = app(OrderService::class)->createFromCart($this->session, [[
            'menu_item_id' => $this->water->id, 'quantity' => 2,
        ]]);
        $line = $order->items()->sole();
        app(InventoryService::class)->deductForOrderItem($line);
        $this->assertSame(6.0, (float) $line->subtotal);
        $this->assertSame(3.0, (float) $this->stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'ingredient_id' => $this->bottle->id, 'type' => 'out', 'quantity_in_base' => 2, 'total_cost' => 2.4,
        ]);
        $this->assertFalse($this->recipe->fresh()->is_optional);
        $this->assertFalse($this->recipe->fresh()->is_removable);
    }

    public function test_recipe_edit_preserves_an_omitted_lock_and_accepts_an_explicit_choice(): void
    {
        $this->actingAs($this->owner)->get(route('admin.menu-items.edit', $this->water))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('item.recipe.0.is_removable', false));
        $payload = [
            'category_id' => $this->water->category_id, 'name' => $this->water->name, 'price' => 3,
            'recipe' => [[
                'ingredient_id' => $this->bottle->id, 'quantity' => 2, 'unit_id' => 'u:'.$this->recipe->unit_id,
            ]],
        ];
        $this->put(route('admin.menu-items.update', $this->water), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($this->water->recipeItems()->sole()->is_removable);
        $this->assertSame(2.0, (float) $this->water->recipeItems()->sole()->quantity);

        $payload['recipe'][0]['is_removable'] = true;
        $this->put(route('admin.menu-items.update', $this->water), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($this->water->recipeItems()->sole()->is_removable);
        $this->assertFalse($this->water->recipeItems()->sole()->is_optional);
    }

    public function test_copying_a_menu_preserves_its_recipe_removal_policy(): void
    {
        $target = Branch::create(['code' => 'copy', 'name' => 'Copy', 'is_active' => true]);
        app(MenuDuplicationService::class)->duplicate($this->branch, $target);
        $copy = MenuItem::withoutGlobalScopes()->where('branch_id', $target->id)->sole();
        $this->assertFalse($copy->recipeItems()->sole()->is_removable);
        $this->assertFalse($copy->recipeItems()->sole()->is_optional);
        $this->assertSame(1.0, (float) $copy->recipeItems()->sole()->quantity);
    }
}
