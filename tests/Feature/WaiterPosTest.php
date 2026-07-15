<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Live waiter POS (Livewire component `admin.waiter-pos`).
 *
 * Exercises the reactive order builder that replaced the reload-on-every-add
 * server-rendered screen: tile add (plain + with modifiers), quantity stepper,
 * line removal, out-of-stock guard, and submit → OrderService::createFromCart
 * + session-cart clear.
 *
 * Fixture setup mirrors WaiterOrderFlowTest.
 */
class WaiterPosTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $waiter;
    protected Unit $gram;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;
    protected MenuItem $burger;      // plain item, plenty of stock
    protected MenuItem $shawarma;    // item WITH a modifier group
    protected MenuItem $truffle;     // out-of-stock item
    protected ModifierGroup $extras;
    protected Modifier $cheese;
    protected Ingredient $patty;
    protected Ingredient $bun;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Deterministic money: no tax/service so subtotal == price math.
        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'wp', 'name' => 'WP', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $this->waiter = User::create([
            'name' => 'Waiter', 'username' => 'waiter_pos', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);

        $this->gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->pcs = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $this->kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $this->category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $this->kitchen->id, 'active' => true,
        ]);

        $this->patty = $this->ing('Patty', $this->gram, 5000);
        $this->bun = $this->ing('Bun', $this->pcs, 50);

        // Plain item.
        $this->burger = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id, 'quantity' => 150, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->bun->id, 'quantity' => 1, 'unit_id' => $this->pcs->id]);

        // Item with a modifier group ("Extras" → +2 cheese).
        $this->shawarma = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'S-1', 'slug' => 'shawarma', 'name' => 'Shawarma', 'price' => 8,
            'is_available' => true, 'display_order' => 2,
        ]);
        RecipeItem::create(['menu_item_id' => $this->shawarma->id, 'ingredient_id' => $this->patty->id, 'quantity' => 100, 'unit_id' => $this->gram->id]);

        $this->extras = ModifierGroup::create([
            'branch_id' => $this->branch->id, 'slug' => 'extras', 'name' => 'إضافات',
            'min_select' => 0, 'max_select' => 1, 'required' => false, 'active' => true,
        ]);
        $this->cheese = Modifier::create([
            'modifier_group_id' => $this->extras->id, 'name' => 'جبنة', 'price_delta' => 2, 'active' => true,
        ]);
        $this->shawarma->modifierGroups()->attach($this->extras->id, ['display_order' => 0]);

        // Out-of-stock item: recipe needs a truffle we have 0 of.
        $truffleIng = $this->ing('Truffle', $this->gram, 0);
        $this->truffle = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'T-1', 'slug' => 'truffle', 'name' => 'Truffle Pasta', 'price' => 25,
            'is_available' => true, 'display_order' => 3,
        ]);
        RecipeItem::create(['menu_item_id' => $this->truffle->id, 'ingredient_id' => $truffleIng->id, 'quantity' => 10, 'unit_id' => $this->gram->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function ing(string $name, Unit $unit, float $stock): Ingredient
    {
        $ing = Ingredient::create([
            'name' => $name, 'base_unit_id' => $unit->id, 'current_stock' => $stock,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ing->id, 'storage_location_id' => $this->storage->id,
            'quantity' => $stock, 'reorder_threshold' => 0,
        ]);

        return $ing;
    }

    protected function table(string $number): Table
    {
        return Table::create([
            'branch_id' => $this->branch->id, 'number' => $number,
            'capacity' => 4, 'status' => 'available', 'active' => true,
        ]);
    }

    // ─── Full-page render (controller → wrapper → embedded component) ─────

    public function test_the_create_page_renders_and_embeds_the_live_pos(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('0');

        $this->get(route('admin.waiter-orders.create', $table))
            ->assertOk()
            ->assertSeeLivewire('admin.waiter-pos')
            ->assertSee('سلة الطلب');
    }

    // ─── Mount opens a session ────────────────────────────────────────────

    public function test_mount_opens_an_active_session_for_the_table(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('1');

        $this->assertNull($table->activeSession);

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);

        $session = TableSession::where('table_id', $table->id)->where('status', 'active')->first();
        $this->assertNotNull($session, 'Mounting the POS must open (or resume) the table session.');
        $this->assertSame($session->id, $component->get('sessionId'));
        $this->assertSame('occupied', $table->fresh()->status);
    }

    // ─── Add two items (one with a modifier) ──────────────────────────────

    public function test_add_two_items_including_a_modifier_builds_the_cart(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('2');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);

        // Plain item → qty-1 line at 10.
        $component->call('addItem', $this->burger->id);

        // Modifier item via the modal → 8 + 2 cheese = 10.
        $component->call('openModifiers', $this->shawarma->id)
            ->set('modSelected', [$this->cheese->id])
            ->call('addWithModifiers');

        $cart = $component->get('cart');
        $this->assertCount(2, $cart);

        $burgerLine = collect($cart)->firstWhere('menu_item_id', $this->burger->id);
        $this->assertSame(1, (int) $burgerLine['quantity']);
        $this->assertSame(10.0, (float) $burgerLine['unit_price']);
        $this->assertSame(10.0, (float) $burgerLine['subtotal']);
        $this->assertSame([], $burgerLine['modifier_ids']);

        $shawarmaLine = collect($cart)->firstWhere('menu_item_id', $this->shawarma->id);
        $this->assertSame(8.0, (float) $shawarmaLine['unit_price']);
        $this->assertSame(2.0, (float) $shawarmaLine['modifiers_total']);
        $this->assertSame(10.0, (float) $shawarmaLine['subtotal']);
        $this->assertSame([$this->cheese->id], $shawarmaLine['modifier_ids']);
        $this->assertContains('جبنة', $shawarmaLine['modifier_labels']);

        // Running total 10 + 10.
        $this->assertSame(20.0, (float) collect($cart)->sum('subtotal'));

        // Modal closed after adding.
        $this->assertNull($component->get('modItemId'));

        // Cart mirrored to the session (survives a native-form reload).
        $this->assertCount(2, session('waiter_cart.'.$this->waiter->id.'.'.$component->get('sessionId')));
    }

    // ─── Tapping the same plain tile increments the line ──────────────────

    public function test_tapping_the_same_plain_item_increments_its_line(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('3');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $component->call('addItem', $this->burger->id)
            ->call('addItem', $this->burger->id);

        $cart = $component->get('cart');
        $this->assertCount(1, $cart);
        $this->assertSame(2, (int) $cart[0]['quantity']);
        $this->assertSame(20.0, (float) $cart[0]['subtotal']);
    }

    // ─── Quantity stepper ─────────────────────────────────────────────────

    public function test_change_qty_updates_the_line_and_zero_removes_it(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('4');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $component->call('addItem', $this->burger->id);

        $component->call('changeQty', 0, 1);
        $cart = $component->get('cart');
        $this->assertSame(2, (int) $cart[0]['quantity']);
        $this->assertSame(20.0, (float) $cart[0]['subtotal']);

        // Down to 1.
        $component->call('changeQty', 0, -1);
        $this->assertSame(1, (int) $component->get('cart')[0]['quantity']);

        // A step below 1 removes the line entirely.
        $component->call('changeQty', 0, -1);
        $this->assertCount(0, $component->get('cart'));
    }

    public function test_remove_line_drops_the_item(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('5');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $component->call('addItem', $this->burger->id)
            ->call('addItem', $this->shawarma->id);   // shawarma has mods → this opens the modal, no line yet

        // shawarma has a modifier group so addItem opened the modal instead of
        // adding — only the burger line exists. Confirm, then remove it.
        $this->assertCount(1, $component->get('cart'));
        $component->call('closeModifiers');

        $component->call('removeLine', 0);
        $this->assertCount(0, $component->get('cart'));
        $this->assertSame([], session('waiter_cart.'.$this->waiter->id.'.'.$component->get('sessionId'), []));
    }

    // ─── Out-of-stock guard ───────────────────────────────────────────────

    public function test_out_of_stock_item_cannot_be_added(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('6');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $component->call('addItem', $this->truffle->id);

        $this->assertCount(0, $component->get('cart'), 'An out-of-stock item must never enter the cart.');
        $component->assertDispatched('toast');
    }

    public function test_submit_re_checks_stock_so_a_forged_cart_cannot_order_out_of_stock(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('8');

        // $cart is a public Livewire property — simulate a tampered snapshot
        // that smuggles the out-of-stock truffle straight past addItem's guard.
        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $sessionId = $component->get('sessionId');
        $component->set('cart', [[
            'id' => 'forged-1',
            'menu_item_id' => $this->truffle->id,
            'name' => $this->truffle->name,
            'unit_price' => (float) $this->truffle->price,
            'modifiers_total' => 0.0,
            'quantity' => 3,
            'modifier_ids' => [],
            'modifier_labels' => [],
            'line_notes' => null,
            'subtotal' => (float) $this->truffle->price * 3,
        ]]);

        $component->call('submit')->assertDispatched('toast');

        $this->assertNull(Order::where('table_session_id', $sessionId)->first(),
            'Submit must re-check stock and refuse to create an order for an out-of-stock line.');
    }

    // ─── Submit ───────────────────────────────────────────────────────────

    public function test_submit_creates_an_order_via_order_service_and_clears_the_cart(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('7');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $sessionId = $component->get('sessionId');

        // Burger ×2 + shawarma (with cheese).
        $component->call('addItem', $this->burger->id)
            ->call('changeQty', 0, 1)
            ->call('openModifiers', $this->shawarma->id)
            ->set('modSelected', [$this->cheese->id])
            ->call('addWithModifiers');

        $this->assertCount(2, $component->get('cart'));

        $component->set('submitNotes', 'بدون كاتشاب')
            ->call('submit')
            ->assertRedirect(route('admin.waiter-orders.index'));

        // Cart cleared both in the component and the mirrored session key.
        $this->assertSame([], $component->get('cart'));
        $this->assertSame([], session('waiter_cart.'.$this->waiter->id.'.'.$sessionId, []));

        $order = Order::where('table_session_id', $sessionId)->first();
        $this->assertNotNull($order, 'submit() must materialise an Order via OrderService::createFromCart.');
        $this->assertSame(OrderStatus::Pending->value, $order->status, 'auto_approve defaults off → stays Pending.');
        $this->assertSame($this->waiter->id, $order->created_by_user_id);
        $this->assertSame('بدون كاتشاب', $order->customer_notes);

        $items = $order->items()->get()->keyBy('menu_item_id');
        $this->assertCount(2, $items);
        $this->assertSame(2.0, (float) $items[$this->burger->id]->quantity);
        $this->assertSame(1.0, (float) $items[$this->shawarma->id]->quantity);
        // Shawarma line snapshot carries the cheese modifier.
        $this->assertSame(1, $items[$this->shawarma->id]->modifiers()->count());

        // Subtotal: burger 2×10 + shawarma (8+2) = 30.
        $this->assertSame(30.0, (float) $order->subtotal);
    }

    public function test_submit_with_an_empty_cart_is_guarded(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('8');

        $component = Livewire::test('admin.waiter-pos', ['tableId' => $table->id]);
        $component->call('submit')->assertNoRedirect();

        $component->assertDispatched('toast');
        $sessionId = $component->get('sessionId');
        $this->assertSame(0, Order::where('table_session_id', $sessionId)->count());
    }
}
