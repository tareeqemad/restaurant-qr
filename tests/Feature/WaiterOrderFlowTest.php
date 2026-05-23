<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RecipeItem;
use App\Models\Role;
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

        $patty = $this->ing('Patty', $this->gram, 5000);
        $bun   = $this->ing('Bun',   $this->pcs,  50);

        $this->burger = MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id,
                            'quantity' => 150, 'unit_id' => $this->gram->id]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $bun->id,
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

    // ─── helper ───────────────────────────────────────────────────────

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
