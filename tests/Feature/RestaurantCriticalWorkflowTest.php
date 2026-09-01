<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
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
use Inertia\Testing\AssertableInertia as Assert;
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

        $this->cashier = $this->staff('cashier-test', 'cashier');
        $this->waiter = $this->staff('waiter-test', 'waiter');
        $this->chef = $this->staff('chef-test', 'chef');

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
        $chefRole->permissions()->syncWithoutDetaching([$kitchenPermission->id]);

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
        $this->assertSame(10.0, (float) $this->ingredient->fresh()->current_stock,
            'The safer default waits until the kitchen starts the line.');
        app(OrderService::class)->startPreparing($approved->items()->first(), $this->waiter->id);
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

    public function test_cashier_screen_and_split_flow_use_supported_payment_methods(): void
    {
        Setting::put('payment_method_cash_enabled', true, 'payments', 'bool');
        Setting::put('payment_method_transfer_enabled', true, 'payments', 'bool');

        $table = Table::create([
            'number' => '11',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);

        $session = TableSession::create([
            'table_id' => $table->id,
            'cover_count' => 2,
            'status' => 'active',
        ]);

        app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [],
        ]]);

        $invoice = app(BillingService::class)->issueInvoice($session, $this->cashier->id);

        // The classic pay page was retired; its route is now a permanent
        // redirect alias into the merged dashboard with the session selected.
        $this->actingAs($this->cashier)
            ->get(route('admin.cashier.show', $session))
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]));

        // The merged screen renders the enabled payment methods on the checkout.
        $this->actingAs($this->cashier)
            ->get(route('admin.cashier.index', ['session' => $session->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.payment_methods', 2)
                ->where('options.payment_methods.0.code', 'cash')
                ->where('options.payment_methods.1.code', 'transfer'));

        $firstShare = round((float) $invoice->total / 2, 2);
        $secondShare = round((float) $invoice->total - $firstShare, 2);

        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'Guest 1', 'amount' => $firstShare, 'method' => 'cash'],
                ['label' => 'Guest 2', 'amount' => $secondShare, 'method' => 'transfer'],
            ],
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('invoice_splits', [
            'invoice_id' => $invoice->id,
            'method' => 'cash',
        ]);
        $this->assertDatabaseHas('invoice_splits', [
            'invoice_id' => $invoice->id,
            'method' => 'transfer',
        ]);
    }

    public function test_cashier_service_blocks_closed_invoice_edge_cases(): void
    {
        $billing = app(BillingService::class);

        $paidInvoice = $this->createDirectInvoice();
        $billing->addPayment($paidInvoice, (float) $paidInvoice->balance, 'cash', $this->cashier->id);

        $writeOffBlocked = false;
        try {
            $billing->writeOffInvoice($paidInvoice->fresh(), $this->cashier->id, 'No balance left');
        } catch (\RuntimeException) {
            $writeOffBlocked = true;
        }
        $this->assertTrue($writeOffBlocked);

        $splitBlocked = false;
        try {
            $billing->splitInvoice($paidInvoice->fresh(), [
                ['label' => 'A', 'amount' => 10, 'method' => 'cash'],
                ['label' => 'B', 'amount' => 10, 'method' => 'cash'],
            ]);
        } catch (\RuntimeException) {
            $splitBlocked = true;
        }
        $this->assertTrue($splitBlocked);

        $splitInvoice = $this->createDirectInvoice();
        $splitFirstShare = round((float) $splitInvoice->total / 2, 2);
        $splitSecondShare = round((float) $splitInvoice->total - $splitFirstShare, 2);
        $billing->splitInvoice($splitInvoice, [
            ['label' => 'Guest 1', 'amount' => $splitFirstShare, 'method' => 'cash'],
            ['label' => 'Guest 2', 'amount' => $splitSecondShare, 'method' => 'cash'],
        ]);

        $split = $splitInvoice->fresh()->splits()->firstOrFail();
        $billing->paySplit($split, $this->cashier->id);

        $duplicateSplitBlocked = false;
        try {
            $billing->paySplit($split->fresh(), $this->cashier->id);
        } catch (\RuntimeException) {
            $duplicateSplitBlocked = true;
        }
        $this->assertTrue($duplicateSplitBlocked);
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

    protected function staff(string $username, string $role): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $user->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        return $user;
    }

    protected function createDirectInvoice(): Invoice
    {
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

        return app(BillingService::class)->issueInvoiceForOrder($order, $this->cashier->id);
    }
}
