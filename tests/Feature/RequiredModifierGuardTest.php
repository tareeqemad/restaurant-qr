<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A required modifier group must be satisfied before a ticket can exist.
 *
 * "Required" used to be honoured in exactly ONE of the three order-entry
 * points: the waiter POS blocked it in its own modal, the cashier rendered
 * «الحجم مطلوب» next to a button that worked anyway, and the customer cart
 * never looked. So a burger with a mandatory size could reach the pass with no
 * size on it — a ticket the cook cannot make, for a guest already waiting.
 *
 * The guard now sits in OrderService, which is the only thing all three share.
 * These tests pin it at that seam, per entry point.
 */
class RequiredModifierGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected MenuItem $burger;      // has a REQUIRED size group
    protected Modifier $large;
    protected ModifierGroup $size;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'rm', 'name' => 'RM', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $kitchen->id, 'active' => true,
        ]);

        $this->burger = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);

        // "Pick a size" — you cannot cook this without an answer.
        $this->size = ModifierGroup::create([
            'branch_id' => $this->branch->id, 'slug' => 'size', 'name' => 'الحجم',
            'min_select' => 1, 'max_select' => 1, 'required' => true, 'active' => true,
        ]);
        $this->large = Modifier::create([
            'modifier_group_id' => $this->size->id, 'name' => 'كبير', 'price_delta' => 3, 'active' => true,
        ]);
        $this->burger->modifierGroups()->attach($this->size->id, ['display_order' => 0]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function openSession(): TableSession
    {
        $table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '1',
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);

        return TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $table->id,
            'token' => 'rm-'.uniqid(), 'status' => 'active', 'opened_at' => now(),
            'cover_count' => 1,
        ]);
    }

    protected function line(array $modifierIds): array
    {
        return [['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => $modifierIds]];
    }

    /** The table path (waiter POS + customer QR cart both land here). */
    public function test_create_from_cart_refuses_a_missing_required_group(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الحجم');

        app(OrderService::class)->createFromCart($this->openSession(), $this->line([]));
    }

    /** The cashier path — the one that showed a warning and created it anyway. */
    public function test_create_cashier_order_refuses_a_missing_required_group(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الحجم');

        app(OrderService::class)->createCashierOrder(
            customer: null, branch: $this->branch, type: 'takeaway',
            source: 'other', cart: $this->line([]),
        );
    }

    /** Answer the question and the ticket goes through, priced with the modifier. */
    public function test_a_satisfied_required_group_creates_the_order(): void
    {
        $order = app(OrderService::class)->createFromCart($this->openSession(), $this->line([$this->large->id]));

        $this->assertNotNull($order->id);
        $this->assertSame(1, $order->items()->count());
    }

    /** max_select is enforced at the same seam. */
    public function test_exceeding_max_select_is_refused(): void
    {
        $extra = Modifier::create([
            'modifier_group_id' => $this->size->id, 'name' => 'وسط', 'price_delta' => 1, 'active' => true,
        ]);

        $this->expectException(\RuntimeException::class);

        app(OrderService::class)->createFromCart(
            $this->openSession(),
            $this->line([$this->large->id, $extra->id])
        );
    }

    /** An OPTIONAL group stays optional — the guard must not over-reach. */
    public function test_an_optional_group_is_not_forced(): void
    {
        $this->size->update(['required' => false, 'min_select' => 0]);

        $order = app(OrderService::class)->createFromCart($this->openSession(), $this->line([]));

        $this->assertNotNull($order->id);
    }
}
