<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RestaurantCriticalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Unit $unit;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;
    protected Ingredient $ingredient;
    protected MenuItem $menuItem;
    protected User $cashier;
    protected User $waiter;
    protected User $chef;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->branch = Branch::create([
            'code' => 'main-test',
            'name' => 'Main Test',
            'is_active' => true,
        ]);

        BranchContext::set($this->branch->id);

        $cashierRole = Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $waiterRole = Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $chefRole = Role::create(['name' => 'chef', 'label' => 'Kitchen', 'is_system' => true]);

        $this->cashier = $this->staff('cashier-test', 'cashier', $cashierRole);
        $this->waiter = $this->staff('waiter-test', 'waiter', $waiterRole);
        $this->chef = $this->staff('chef-test', 'chef', $chefRole);

        $this->unit = Unit::create([
            'code' => 'pcs',
            'name' => 'Piece',
            'unit_type' => 'count',
            'factor_to_base' => 1,
            'is_base' => true,
        ]);

        $this->storage = StorageLocation::create([
            'code' => 'main-test-kitchen',
            'name' => 'Main Kitchen',
            'is_default' => true,
            'active' => true,
        ]);

        $this->kitchen = Station::create([
            'code' => 'kitchen',
            'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id,
            'active' => true,
        ]);

        $kitchenPermission = Permission::where('name', 'station.kitchen.view')->firstOrFail();
        $chefRole->permissions()->attach($kitchenPermission->id);

        $this->category = Category::create([
            'slug' => 'mains',
            'name' => 'Mains',
            'default_station_id' => $this->kitchen->id,
            'active' => true,
        ]);

        $this->ingredient = Ingredient::create([
            'sku' => 'ING-001',
            'name' => 'Test Stock',
            'base_unit_id' => $this->unit->id,
            'current_stock' => 10,
            'reorder_threshold' => 0,
            'cost_per_unit' => 3,
            'track_stock' => true,
            'active' => true,
        ]);

        IngredientStock::create([
            'ingredient_id' => $this->ingredient->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 10,
            'reorder_threshold' => 0,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $this->category->id,
            'station_id' => $this->kitchen->id,
            'sku' => 'FOOD-001',
            'slug' => 'test-meal',
            'name' => 'Test Meal',
            'price' => 20,
            'cost' => 6,
            'prep_time_minutes' => 7,
            'is_available' => true,
        ]);

        RecipeItem::create([
            'menu_item_id' => $this->menuItem->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 2,
            'unit_id' => $this->unit->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();

        parent::tearDown();
    }

    public function test_qr_order_is_approved_by_waiter_sent_to_kitchen_and_deducts_stock(): void
    {
        $table = Table::create([
            'number' => '7',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);

        $session = TableSession::create([
            'table_id' => $table->id,
            'cover_count' => 2,
            'status' => 'active',
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [],
            'notes' => 'No onions',
        ]]);

        $this->actingAs($this->waiter);
        $this->assertSame(OrderStatus::Pending->value, $order->status);
        $this->assertTrue($this->waiter->can('approve', $order));

        $approved = app(OrderService::class)->approve($order, $this->waiter->id);

        $this->assertSame(OrderStatus::Approved->value, $approved->status);
        $this->assertSame($this->waiter->id, $session->fresh()->assigned_waiter_id);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $approved->id,
            'station_id' => $this->kitchen->id,
            'status' => OrderItemStatus::Approved->value,
        ]);
        $this->assertSame(8.0, (float) $this->ingredient->fresh()->current_stock);
        $this->assertSame(8.0, (float) IngredientStock::first()->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::where('type', 'out')->count());
    }

    public function test_cashier_can_create_no_table_delivery_send_it_to_kitchen_and_take_payment(): void
    {
        $this->actingAs($this->cashier);
        $this->assertTrue($this->cashier->can('create', Order::class));

        $order = app(OrderService::class)->createCashierOrder(
            customer: null,
            branch: $this->branch,
            type: 'delivery',
            source: 'phone',
            cart: [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 1,
                'modifier_ids' => [],
                'notes' => 'Call on arrival',
            ]],
            opts: [
                'customer_name' => 'Phone Guest',
                'customer_phone' => '0599000000',
                'delivery_address' => 'Test Street',
                'delivery_fee' => 5,
            ],
            createdByUserId: $this->cashier->id,
        );

        $this->assertNull($order->table_session_id);
        $this->assertSame('delivery', $order->order_type);
        $this->assertSame('phone', $order->order_source);
        $this->assertTrue($this->cashier->can('approve', $order));

        $approved = app(OrderService::class)->approve($order, $this->cashier->id);
        $invoice = app(BillingService::class)->issueInvoiceForOrder($approved, $this->cashier->id);
        $payment = app(BillingService::class)->addPayment($invoice, (float) $invoice->balance, 'cash', $this->cashier->id);

        $this->assertSame(OrderStatus::Approved->value, $approved->status);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $approved->id,
            'station_id' => $this->kitchen->id,
            'status' => OrderItemStatus::Approved->value,
        ]);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame(8.0, (float) $this->ingredient->fresh()->current_stock);
    }

    public function test_direct_order_cannot_be_cancelled_after_invoice_is_issued(): void
    {
        $this->actingAs($this->cashier);

        $order = app(OrderService::class)->createCashierOrder(
            customer: null,
            branch: $this->branch,
            type: 'takeaway',
            source: 'other',
            cart: [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 1,
                'modifier_ids' => [],
            ]],
            createdByUserId: $this->cashier->id,
        );

        app(BillingService::class)->issueInvoiceForOrder($order, $this->cashier->id);

        $this->expectException(\RuntimeException::class);

        app(OrderService::class)->cancel($order->fresh(), $this->cashier->id, 'Customer changed mind');
    }

    public function test_station_access_uses_station_permissions_not_only_role_names(): void
    {
        $this->assertTrue($this->chef->fresh()->canAccessStation('kitchen'));
        $this->assertFalse($this->cashier->fresh()->canAccessStation('kitchen'));
    }

    public function test_cashier_role_owns_payment_screen_not_waiter(): void
    {
        $this->assertTrue($this->cashier->can('viewAny', Payment::class));
        $this->assertTrue($this->cashier->can('create', Payment::class));
        $this->assertFalse($this->waiter->can('viewAny', Payment::class));
        $this->assertFalse($this->waiter->can('create', Payment::class));
    }

    protected function staff(string $username, string $role, Role $roleModel): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $user->branches()->attach($this->branch->id, [
            'role_id' => $roleModel->id,
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        return $user;
    }
}
