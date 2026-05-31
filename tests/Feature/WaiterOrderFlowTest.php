<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TableSessionTransferService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Waiter-facing flows:
 *  1. Waiter places an order on a walk-in's behalf (no QR).
 *  2. Waiter moves all in-flight orders from one table to another.
 *
 * These mirror what a busy floor actually does — the QR flow is only
 * one of several ways an order reaches the kitchen.
 */
class WaiterOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $waiter;
    protected Unit $gram;
    protected Unit $pcs;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;
    protected MenuItem $burger;
    protected Ingredient $patty;
    protected Ingredient $bun;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->branch = Branch::create(['code' => 'w', 'name' => 'W', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $this->waiter = User::create([
            'name' => 'Waiter', 'username' => 'waiter_t', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);

        $this->gram = Unit::create(['code' => 'g',   'name' => 'g',   'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->pcs  = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count',  'factor_to_base' => 1, 'is_base' => true]);

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
        $this->bun   = $this->ing('Bun',   $this->pcs,  50);

        $this->burger = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id,
                            'quantity' => 150, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->bun->id,
                            'quantity' => 1,   'unit_id' => $this->pcs->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /**
     * The end-to-end waiter happy-path: a walk-in sits down, the waiter
     * opens a session on table 5, builds a cart with the OrderService
     * the controller would call, and the order materialises in the
     * pending queue ready for the kitchen.
     */
    public function test_waiter_can_create_a_table_order_on_a_walk_ins_behalf(): void
    {
        $this->actingAs($this->waiter);

        $table = Table::create(['number' => '5', 'capacity' => 4, 'status' => 'available', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id'  => $table->id,
            'token'     => 'walk-in-test',
            'status'    => 'active',
            'opened_at' => now(),
            'cover_count'        => 1,
            'assigned_waiter_id' => $this->waiter->id,
        ]);

        // Build the same cart shape the controller's submit() hands off.
        $order = app(OrderService::class)->createFromCart(
            session: $session,
            cart: [[
                'menu_item_id' => $this->burger->id,
                'quantity'     => 2,
                'modifier_ids' => [],
                'notes'        => null,
            ]],
            createdByUserId: $this->waiter->id,
            customerNotes:   'بدون كاتشاب',
        );

        $this->assertSame($table->id, $order->table_id,
            'Waiter-created order MUST anchor to the table the waiter picked.');
        $this->assertSame($session->id, $order->table_session_id);
        $this->assertSame(OrderStatus::Pending->value, $order->status,
            'Goes through the normal approval queue — same as a QR order.');
        $this->assertSame(20.0, (float) $order->subtotal);
        $this->assertSame($this->waiter->id, $order->created_by_user_id);
        $this->assertCount(1, $order->items);
    }

    /**
     * Linking a regular customer to the session is part of the waiter
     * flow — the same field cashiers + portal use, so the debt-ledger
     * and loyalty downstream pick it up unchanged.
     */
    public function test_waiter_can_attach_a_customer_to_an_active_table_session(): void
    {
        $this->actingAs($this->waiter);

        $table = Table::create(['number' => '6', 'capacity' => 2, 'status' => 'available', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'attach-test', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
        [$customer] = Customer::createFromCashier(
            name: 'محمد الدائم',
            phone: '0599111222',
            defaultBranchId: $this->branch->id,
        );

        // What the controller does after Customer::findForLogin matches.
        $session->update([
            'customer_id'    => $customer->id,
            'customer_name'  => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->assertSame($customer->id, $session->fresh()->customer_id);
        $this->assertSame('محمد الدائم', $session->fresh()->customer_name);
    }

    /**
     * Transfer all in-flight orders from one table to another mid-meal.
     * The waiter just clicks a button; behind the scenes both the
     * session AND every linked order move so the kitchen ticket
     * (printed with table #X) stays correct.
     */
    public function test_table_transfer_moves_session_plus_all_linked_orders(): void
    {
        $this->actingAs($this->waiter);

        $tableA = Table::create(['number' => '10', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
        $tableB = Table::create(['number' => '11', 'capacity' => 4, 'status' => 'available', 'active' => true]);

        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $tableA->id,
            'token' => 'transfer-test', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity'     => 1,
            'modifier_ids' => [],
        ]], createdByUserId: $this->waiter->id);

        $this->assertSame($tableA->id, $order->table_id);

        app(TableSessionTransferService::class)->transfer($tableA, $tableB, $this->waiter->id);

        $session->refresh();
        $order->refresh();

        $this->assertSame($tableB->id, $session->table_id,
            'Session itself moves to the new table.');
        $this->assertSame($tableB->id, $order->table_id,
            'EVERY linked order moves too — kitchen tickets need to show the new table.');
        $this->assertSame('available', $tableA->fresh()->status,
            'Source table freed.');
        $this->assertSame('occupied', $tableB->fresh()->status,
            'Target table marked occupied.');
    }

    /**
     * One table ticket can split across stations: food goes to kitchen,
     * drinks go to bar, and the waiter serves each ready line without
     * breaking the whole order's status.
     */
    public function test_waiter_kitchen_and_bar_lifecycle_keeps_order_status_in_sync(): void
    {
        $this->actingAs($this->waiter);

        $barStorage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'b', 'name' => 'B',
            'is_default' => false, 'active' => true,
        ]);
        $bar = Station::create([
            'code' => 'bar', 'name' => 'Bar',
            'storage_location_id' => $barStorage->id, 'active' => true,
        ]);
        $drinks = Category::create([
            'slug' => 'drinks', 'name' => 'Drinks',
            'default_station_id' => $bar->id, 'active' => true,
        ]);
        $syrup = $this->ing('Syrup', $this->gram, 1000);
        $cola = MenuItem::create([
            'category_id' => $drinks->id, 'station_id' => $bar->id,
            'sku' => 'D-1', 'slug' => 'cola', 'name' => 'Cola', 'price' => 4,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $cola->id, 'ingredient_id' => $syrup->id,
            'quantity' => 40, 'unit_id' => $this->gram->id,
        ]);

        $table = Table::create(['number' => '20', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'station-split-test', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 2, 'assigned_waiter_id' => $this->waiter->id,
        ]);

        $order = app(OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []],
            ['menu_item_id' => $cola->id, 'quantity' => 1, 'modifier_ids' => []],
        ], createdByUserId: $this->waiter->id);

        app(OrderService::class)->approve($order, $this->waiter->id);

        $items = $order->fresh()->items()->get()->keyBy('menu_item_id');
        $kitchenLine = $items[$this->burger->id];
        $barLine = $items[$cola->id];

        $this->assertSame($this->kitchen->id, $kitchenLine->station_id);
        $this->assertSame($bar->id, $barLine->station_id);
        $this->assertSame(OrderItemStatus::Approved->value, $kitchenLine->status);
        $this->assertSame(OrderItemStatus::Approved->value, $barLine->status);

        app(OrderService::class)->startPreparing($kitchenLine, $this->waiter->id);
        $this->assertSame(OrderStatus::Preparing->value, $order->fresh()->status);

        app(OrderService::class)->markItemReady($kitchenLine->fresh());
        app(OrderService::class)->markItemServed($kitchenLine->fresh(), $this->waiter->id);
        $this->assertSame(OrderStatus::Preparing->value, $order->fresh()->status,
            'Serving the ready kitchen line must not close the order while the bar line is still open.');

        app(OrderService::class)->startPreparing($barLine, $this->waiter->id);
        app(OrderService::class)->markItemReady($barLine->fresh());
        $this->assertSame(OrderStatus::Ready->value, $order->fresh()->status,
            'Once the remaining bar line is ready, the waiter sees the ticket in the ready queue.');

        app(OrderService::class)->markItemServed($barLine->fresh(), $this->waiter->id);

        $this->assertSame(OrderStatus::Delivered->value, $order->fresh()->status);
        $this->assertSame(OrderItemStatus::Served->value, $kitchenLine->fresh()->status);
        $this->assertSame(OrderItemStatus::Served->value, $barLine->fresh()->status);
    }

    public function test_station_and_waiter_actions_reject_status_jumps(): void
    {
        $this->actingAs($this->waiter);

        $table = Table::create(['number' => '21', 'capacity' => 2, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'status-jump-test', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->waiter->id);
        $item = $order->items->first();

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->markItemReady($item),
            __('ui.customer_order.workflow_action_mark_ready'),
        );

        app(OrderService::class)->approve($order, $this->waiter->id);
        $item = $item->fresh();

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->markItemServed($item, $this->waiter->id),
            __('ui.customer_order.workflow_action_mark_served'),
        );

        app(OrderService::class)->startPreparing($item, $this->waiter->id);
        $item = $item->fresh();

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->startPreparing($item, $this->waiter->id),
            __('ui.customer_order.workflow_action_start_preparing'),
        );

        app(OrderService::class)->markItemReady($item);
        $item = $item->fresh();

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->markItemReady($item),
            __('ui.customer_order.workflow_action_mark_ready'),
        );

        app(OrderService::class)->markItemServed($item, $this->waiter->id);
        $item = $item->fresh();

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->markItemServed($item, $this->waiter->id),
            __('ui.customer_order.workflow_action_mark_served'),
        );
    }

    public function test_manual_order_transition_route_cannot_bypass_item_and_invoice_constraints(): void
    {
        $this->actingAs($this->waiter);

        $item = $this->placeAndApprove();
        $order = $item->order;

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->transitionTo($order, OrderStatus::Ready->value, $this->waiter->id),
            __('ui.customer_order.workflow_order_ready_blocked'),
        );

        app(OrderService::class)->startPreparing($item, $this->waiter->id);
        app(OrderService::class)->markItemReady($item->fresh());

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->transitionTo($order->fresh(), OrderStatus::Delivered->value, $this->waiter->id),
            __('ui.customer_order.workflow_order_delivered_blocked'),
        );

        app(OrderService::class)->markItemServed($item->fresh(), $this->waiter->id);

        $this->assertTransitionFails(
            fn () => app(OrderService::class)->transitionTo($order->fresh(), OrderStatus::Completed->value, $this->waiter->id),
            __('ui.customer_order.workflow_order_completed_invoice_blocked'),
        );
    }

    public function test_cancel_return_before_delayed_deduction_does_not_inflate_stock(): void
    {
        $this->actingAs($this->waiter);
        Setting::put('inventory_deduction_stage', 'preparing', 'inventory', 'string');

        $item = $this->placeAndApprove();

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock,
            'Approval should not deduct when the restaurant deducts at preparing.');

        app(OrderService::class)->cancelItem(
            item: $item,
            userId: $this->waiter->id,
            reason: 'Customer changed their mind before prep',
            disposition: 'return',
        );

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock,
            'Returning an item that was never deducted must not add phantom stock.');
        $this->assertSame(0, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->count());
    }

    public function test_cancel_waste_with_delayed_deduction_records_the_real_loss(): void
    {
        $this->actingAs($this->waiter);
        Setting::put('inventory_deduction_stage', 'served', 'inventory', 'string');

        $item = $this->placeAndApprove();

        app(OrderService::class)->cancelItem(
            item: $item,
            userId: $this->waiter->id,
            reason: 'Kitchen started before the button was pressed',
            disposition: 'waste',
            wasteReason: 'Prep loss',
        );

        $this->assertSame(4850.0, (float) $this->patty->fresh()->current_stock);
        $this->assertSame(49.0, (float) $this->bun->fresh()->current_stock);
        $this->assertSame(2, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', 'out')->count());
        $this->assertSame(2, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $item->id)->where('type', 'waste')->count());
    }

    // ─── helper ───────────────────────────────────────────────────────

    protected function placeAndApprove(): OrderItem
    {
        $table = Table::create([
            'number' => (string) random_int(100, 999),
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'sess-'.uniqid(), 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->waiter->id);
        app(OrderService::class)->approve($order, $this->waiter->id);

        return $order->items->first()->fresh();
    }

    protected function assertTransitionFails(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail("Expected workflow transition to fail with [{$message}].");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    protected function ing(string $name, Unit $unit, float $stock): Ingredient
    {
        $ing = Ingredient::create([
            'name'              => $name,
            'base_unit_id'      => $unit->id,
            'current_stock'     => $stock,
            'reorder_threshold' => 0,
            'cost_per_unit'     => 1,
            'track_stock'       => true,
            'active'            => true,
        ]);
        IngredientStock::create([
            'ingredient_id'       => $ing->id,
            'storage_location_id' => $this->storage->id,
            'quantity'            => $stock,
            'reorder_threshold'   => 0,
        ]);
        return $ing;
    }
}
