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
use App\Models\OrderChangeRequest;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderChangeRequestService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderChangeRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private MenuItem $meal;

    private Ingredient $ingredient;

    private User $waiter;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->branch = Branch::create([
            'code' => 'changes-test',
            'name' => 'Changes Test',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $this->waiter = $this->staff('change-waiter', 'waiter');
        $this->cashier = $this->staff('change-cashier', 'cashier');

        $unit = Unit::create([
            'code' => 'change-pcs',
            'name' => 'Piece',
            'unit_type' => 'count',
            'factor_to_base' => 1,
            'is_base' => true,
        ]);
        $storage = StorageLocation::create([
            'code' => 'change-kitchen',
            'name' => 'Change Kitchen',
            'is_default' => true,
            'active' => true,
        ]);
        $station = Station::create([
            'code' => 'change-station',
            'name' => 'Kitchen',
            'storage_location_id' => $storage->id,
            'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'change-mains',
            'name' => 'Mains',
            'default_station_id' => $station->id,
            'active' => true,
        ]);
        $this->ingredient = Ingredient::create([
            'sku' => 'CHANGE-ING',
            'name' => 'Change Ingredient',
            'base_unit_id' => $unit->id,
            'current_stock' => 20,
            'reorder_threshold' => 0,
            'cost_per_unit' => 2,
            'track_stock' => true,
            'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->ingredient->id,
            'storage_location_id' => $storage->id,
            'quantity' => 20,
            'reorder_threshold' => 0,
        ]);
        $this->meal = MenuItem::create([
            'category_id' => $category->id,
            'station_id' => $station->id,
            'sku' => 'CHANGE-MEAL',
            'slug' => 'change-meal',
            'name' => 'Change Meal',
            'price' => 25,
            'cost' => 4,
            'prep_time_minutes' => 8,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $this->meal->id,
            'ingredient_id' => $this->ingredient->id,
            'quantity' => 2,
            'unit_id' => $unit->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_customer_can_request_change_from_qr_and_duplicate_pending_request_is_blocked(): void
    {
        [$session, $order] = $this->activeOrder();
        $item = $order->items()->firstOrFail();

        $response = $this->post(route('customer.orders.change-requests.store', $order), [
            'session' => $session->token,
            'type' => 'change_item',
            'order_item_id' => $item->id,
            'requested_quantity' => 2,
            'request_note' => 'بدون بصل',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('order_change_requests', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'change_item',
            'status' => OrderChangeRequest::STATUS_PENDING,
        ]);

        $duplicate = $this->post(route('customer.orders.change-requests.store', $order), [
            'session' => $session->token,
            'type' => 'cancel_item',
            'order_item_id' => $item->id,
        ]);

        $duplicate->assertSessionHas('error');
        $this->assertSame(1, OrderChangeRequest::where('order_id', $order->id)->count());
    }

    public function test_waiter_can_replace_a_started_item_and_return_its_stock(): void
    {
        [$session, $order] = $this->activeOrder();
        $orders = app(OrderService::class);
        $changes = app(OrderChangeRequestService::class);

        $order = $orders->approve($order, $this->waiter->id);
        $original = $order->items()->firstOrFail();
        $orders->startPreparing($original, $this->waiter->id);
        $this->assertSame(18.0, (float) $this->ingredient->fresh()->current_stock);

        $request = $changes->request($order->refresh(), $session, [
            'type' => 'change_item',
            'order_item_id' => $original->id,
            'requested_quantity' => 2,
            'request_note' => 'ضاعف الكمية',
        ]);
        $resolved = $changes->resolve($request, $this->waiter->id, 'approve', 'return');

        $replacement = $resolved->replacementOrderItem;
        $this->assertNotNull($replacement);
        $this->assertSame(OrderItemStatus::Cancelled->value, $original->fresh()->status);
        $this->assertSame(OrderItemStatus::Approved->value, $replacement->status);
        $this->assertSame(2.0, (float) $replacement->quantity);
        $this->assertSame(25.0, (float) $replacement->unit_price);
        $this->assertSame(50.0, (float) $order->fresh()->subtotal);
        $this->assertSame(20.0, (float) $this->ingredient->fresh()->current_stock);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_id' => $original->id,
            'type' => 'return',
        ]);
    }

    public function test_waiter_can_cancel_started_food_as_waste_without_restoring_stock(): void
    {
        [$session, $order] = $this->activeOrder();
        $orders = app(OrderService::class);
        $changes = app(OrderChangeRequestService::class);

        $order = $orders->approve($order, $this->waiter->id);
        $item = $order->items()->firstOrFail();
        $orders->startPreparing($item, $this->waiter->id);
        $this->assertSame(18.0, (float) $this->ingredient->fresh()->current_stock);

        $request = $changes->request($order->refresh(), $session, [
            'type' => 'cancel_order',
            'request_note' => 'الزبون غادر',
        ]);
        $resolved = $changes->resolve($request, $this->waiter->id, 'approve', 'waste');

        $this->assertSame(OrderChangeRequest::STATUS_APPROVED, $resolved->status);
        $this->assertSame(OrderStatus::Cancelled->value, $order->fresh()->status);
        $this->assertSame(OrderItemStatus::Cancelled->value, $item->fresh()->status);
        $this->assertSame(18.0, (float) $this->ingredient->fresh()->current_stock);
        $this->assertTrue(InventoryMovement::where('reference_id', $item->id)->where('type', 'waste')->exists());
    }

    public function test_pending_customer_change_pauses_the_item_until_the_waiter_decides(): void
    {
        [$session, $order] = $this->activeOrder();
        $orders = app(OrderService::class);
        $changes = app(OrderChangeRequestService::class);

        $orders->approve($order, $this->waiter->id);
        $item = $order->items()->firstOrFail();
        $request = $changes->request($order->refresh(), $session, [
            'type' => 'cancel_item',
            'order_item_id' => $item->id,
            'request_note' => 'غيرت رأيي',
        ]);

        try {
            $orders->startPreparing($item, $this->waiter->id);
            $this->fail('A station must not advance a line while its customer change is pending.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('طلب تعديل من الزبون', $exception->getMessage());
        }
        $this->assertSame(OrderItemStatus::Approved->value, $item->fresh()->status);

        $changes->resolve($request, $this->waiter->id, 'reject');
        $orders->startPreparing($item->fresh(), $this->waiter->id);
        $this->assertSame(OrderItemStatus::Preparing->value, $item->fresh()->status);
    }

    public function test_waiter_must_review_again_when_the_station_started_after_the_card_loaded(): void
    {
        [$session, $order] = $this->activeOrder();
        $orders = app(OrderService::class);
        $changes = app(OrderChangeRequestService::class);

        $orders->approve($order, $this->waiter->id);
        $item = $order->items()->firstOrFail();
        // The station started first; the waiter still holds an older
        // "not started" card and must not accidentally return stock.
        $orders->startPreparing($item, $this->waiter->id);
        $request = $changes->request($order->refresh(), $session, [
            'type' => 'cancel_item',
            'order_item_id' => $item->id,
        ]);

        try {
            $changes->resolve($request, $this->waiter->id, 'approve', 'return', null, false);
            $this->fail('A stale pre-preparation decision must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('بدأ المطبخ أو البار', $exception->getMessage());
        }

        $this->assertSame(OrderChangeRequest::STATUS_PENDING, $request->fresh()->status);
        $this->assertSame(OrderItemStatus::Preparing->value, $item->fresh()->status);

        $changes->resolve($request->fresh(), $this->waiter->id, 'approve', 'waste', null, true);
        $this->assertSame(OrderItemStatus::Cancelled->value, $item->fresh()->status);
        $this->assertTrue(InventoryMovement::where('reference_id', $item->id)->where('type', 'waste')->exists());
    }

    public function test_pending_change_on_a_waiting_round_blocks_approval_until_it_is_resolved(): void
    {
        [$session, $order] = $this->activeOrder();
        $orders = app(OrderService::class);
        $changes = app(OrderChangeRequestService::class);

        $request = $changes->request($order, $session, [
            'type' => 'change_item',
            'order_item_id' => $order->items()->firstOrFail()->id,
            'requested_quantity' => 2,
        ]);

        try {
            $orders->approve($order->fresh(), $this->waiter->id);
            $this->fail('Waiter approval must wait for the customer change decision.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('عالج التعديل أولاً', $exception->getMessage());
        }

        $changes->resolve($request, $this->waiter->id, 'approve', expectedStarted: false);
        $orders->approve($order->fresh(), $this->waiter->id);
        $this->assertSame(OrderStatus::Approved->value, $order->fresh()->status);
        $this->assertSame(2.0, (float) $order->items()->where('status', 'approved')->firstOrFail()->quantity);
    }

    public function test_pending_change_blocks_invoice_until_waiter_rejects_it(): void
    {
        [$session, $order] = $this->activeOrder();
        $changes = app(OrderChangeRequestService::class);
        $billing = app(BillingService::class);

        $request = $changes->request($order, $session, [
            'type' => 'cancel_item',
            'order_item_id' => $order->items()->firstOrFail()->id,
            'request_note' => 'غيرت رأيي',
        ]);

        $this->get(route('customer.bill', ['session' => $session->token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Bill')
                ->where('hasPendingChangeRequest', true)
            );
        $this->post(route('customer.bill.transfer'), ['session' => $session->token])
            ->assertSessionHas('info');
        $this->assertDatabaseCount('pending_transfers', 0);

        try {
            $billing->issueInvoice($session, $this->cashier->id);
            $this->fail('Invoice issuance must stop while a change request is pending.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('بانتظار الجرسون', $exception->getMessage());
        }
        $this->assertSame(0, Invoice::count());

        $changes->resolve($request, $this->waiter->id, 'reject');
        $invoice = $billing->issueInvoice($session->fresh(), $this->cashier->id);

        $this->assertNotNull($invoice->id);
        $this->assertSame(OrderChangeRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(OrderItemStatus::Approved->value, $order->items()->firstOrFail()->fresh()->status);
    }

    private function activeOrder(): array
    {
        $table = Table::create([
            'number' => 'T-'.Table::count(),
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'token' => 'change-session-'.TableSession::count(),
            'cover_count' => 2,
            'status' => 'active',
            'opened_at' => now(),
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->meal->id,
            'quantity' => 1,
            'modifier_ids' => [],
            'notes' => null,
        ]]);

        return [$session, $order];
    }

    private function staff(string $username, string $role): User
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
}
