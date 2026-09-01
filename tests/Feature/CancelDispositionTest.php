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
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancel-disposition coverage: chef/waiter cancelling an in-flight
 * item must let the operator pick between RETURN (kitchen never
 * touched it — full lossless undo) and WASTE (chef already prepped
 * — log the loss for reporting, keep stock decremented).
 */
class CancelDispositionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $waiter;
    protected Ingredient $patty;
    protected MenuItem $burger;
    protected StorageLocation $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'cd', 'name' => 'CD', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $this->waiter = User::create([
            'name' => 'W', 'username' => 'w_cd', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);

        $g = Unit::create([
            'code' => 'g', 'name' => 'g', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $this->storage->id, 'active' => true,
        ]);
        $cat = Category::create([
            'slug' => 'mains-cd', 'name' => 'Mains',
            'default_station_id' => $kitchen->id, 'active' => true,
        ]);

        $this->patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $g->id,
            'current_stock' => 1000, 'reorder_threshold' => 0,
            'cost_per_unit' => 0.05,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => 1000, 'reorder_threshold' => 0,
        ]);

        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B-CD', 'slug' => 'burger-cd', 'name' => 'Burger', 'price' => 10,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $this->burger->id,
            'ingredient_id' => $this->patty->id,
            'quantity' => 150, 'unit_id' => $g->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /**
     * Default disposition (return) — the deduction is reversed so the
     * stock count looks like the order never happened.
     */
    public function test_cancel_with_return_restores_stock_to_pre_order_level(): void
    {
        $this->actingAs($this->waiter);

        $oi = $this->placeAndStartPreparing();   // deducts 150g → 850g on shelf

        $this->assertSame(850.0, (float) $this->patty->fresh()->current_stock);

        app(OrderService::class)->cancelItem(
            item:        $oi,
            userId:      $this->waiter->id,
            reason:      'الزبون غيّر رأيه قبل ما المطبخ يبدأ',
            disposition: 'return',
        );

        $this->assertSame(1000.0, (float) $this->patty->fresh()->current_stock,
            'Return disposition MUST put 150g back on the shelf — stock returns to 1000.');
        $this->assertSame(OrderItemStatus::Cancelled->value, $oi->fresh()->status);

        // Both the original `out` and a `return` movement exist —
        // accounting follows the existing return path (already covered
        // by AccountingPostingTest), but stock-side counts net to 0.
        $this->assertSame(1, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->where('type', 'out')->count());
        $this->assertSame(1, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->where('type', 'return')->count());
    }

    /**
     * Waste disposition — chef already touched the ingredients (opened
     * a bag, fried the patty). Stock stays decremented (the food is
     * truly gone) and a `waste` movement records the loss for the
     * waste report and end-of-day inventory section.
     */
    public function test_cancel_with_waste_keeps_stock_decremented_and_logs_waste_movement(): void
    {
        $this->actingAs($this->waiter);

        $oi = $this->placeAndStartPreparing();   // 850g after deduction
        $stockBefore = (float) $this->patty->fresh()->current_stock;

        app(OrderService::class)->cancelItem(
            item:        $oi,
            userId:      $this->waiter->id,
            reason:      'الزبون لغى بعد ما الشيف بدأ',
            disposition: 'waste',
            wasteReason: 'تلف أثناء التحضير',
        );

        $stockAfter = (float) $this->patty->fresh()->current_stock;
        $this->assertSame($stockBefore, $stockAfter,
            'Waste disposition MUST keep stock decremented — the patty is gone.');

        // The original out + the new waste record are both there.
        $this->assertSame(1, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->where('type', 'out')->count());
        $waste = InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->where('type', 'waste')->first();
        $this->assertNotNull($waste, 'A `waste` movement must be logged for the loss report.');
        $this->assertSame(150.0, (float) $waste->quantity_in_base);
        $this->assertSame('تلف أثناء التحضير', $waste->waste_reason);

        // No return movement — that's the whole point of waste path.
        $this->assertSame(0, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->where('type', 'return')->count());
    }

    /**
     * Cancelling a Pending (un-deducted) item is a no-op for inventory
     * regardless of disposition — there's nothing to undo or waste.
     */
    public function test_cancel_of_pending_item_does_not_touch_inventory(): void
    {
        $this->actingAs($this->waiter);

        $table = Table::create(['number' => 'P', 'capacity' => 2, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'pending-cd', 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->waiter->id);
        $oi = $order->items->first();

        $this->assertSame(1000.0, (float) $this->patty->fresh()->current_stock,
            'Pending item never deducted — stock untouched.');

        app(OrderService::class)->cancelItem(
            item: $oi, userId: $this->waiter->id, reason: 'Pending cancel', disposition: 'waste',
        );

        $this->assertSame(1000.0, (float) $this->patty->fresh()->current_stock,
            'Cancel of a never-deducted item must not log waste — no actual loss.');
        $this->assertSame(0, InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $oi->id)->count());
    }

    // ─── helper ───────────────────────────────────────────────────────

    protected function placeAndStartPreparing(): OrderItem
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

        // The restaurant policy now deducts ingredients when the kitchen
        // actually starts, rather than when the waiter approves the ticket.
        // These two scenarios intentionally exercise cancellation after a
        // real deduction, so move the line to Preparing first.
        $item = $order->items()->firstOrFail();
        app(OrderService::class)->startPreparing($item, $this->waiter->id);

        return $item->fresh();
    }
}
