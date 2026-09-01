<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * دفعات المكونات on Inertia/Vue.
 *
 * The whole point of this screen is the expiry verdict, and the Blade used to
 * decide it in-template (`$b->isExpired() ? 'table-danger' : …`). It now ships
 * as `rowTone` / `isExpired` / `isNearExpiry` / `daysUntilExpiry` computed by
 * the SAME model methods the FIFO consumption engine uses, so the table and
 * the engine can never disagree about what "expired" means.
 *
 * The manual-batch form is pinned here too: it injects stock with no PO trail,
 * so the page must not offer it to anyone the write path would refuse.
 */
class BatchesVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $manager;
    protected User $chef;
    protected Ingredient $patty;
    protected Unit $gram;
    protected StorageLocation $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'bx', 'name' => 'BX', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        Role::create(['name' => 'chef', 'label' => 'Chef', 'is_system' => true]);

        $this->manager = User::create([
            'name' => 'M', 'username' => 'bx_manager', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'manager',
        ]);
        $this->manager->branches()->attach($this->branch->id);

        $this->chef = User::create([
            'name' => 'C', 'username' => 'bx_chef', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'chef',
        ]);
        $this->chef->branches()->attach($this->branch->id);

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->kitchen = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'المطبخ',
            'is_default' => true, 'active' => true,
        ]);

        $this->patty = Ingredient::create([
            'name' => 'لحمة', 'base_unit_id' => $this->gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 2, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->kitchen->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function lot(array $attrs = []): IngredientBatch
    {
        return IngredientBatch::create(array_merge([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $this->patty->id,
            'storage_location_id' => $this->kitchen->id,
            'initial_qty' => 1000,
            'remaining_qty' => 1000,
            'unit_cost' => 2,
            'received_date' => now()->subDays(5),
        ], $attrs));
    }

    /** The four lots the assertions below lean on, newest receipt first. */
    protected function seedLots(): void
    {
        $this->lot([                                  // row 0 — healthy
            'batch_number' => 'FRESH',
            'initial_qty' => 2000, 'remaining_qty' => 2000, 'unit_cost' => 1,
            'received_date' => now()->subDays(2), 'expiry_date' => now()->addDays(60),
        ]);
        $this->lot([                                  // row 1 — expires in 3 days
            'batch_number' => 'SOON',
            'initial_qty' => 1000, 'remaining_qty' => 1000, 'unit_cost' => 3,
            'received_date' => now()->subDays(5), 'expiry_date' => now()->addDays(3),
        ]);
        $this->lot([                                  // row 2 — expired yesterday
            'batch_number' => 'GONE',
            'initial_qty' => 500, 'remaining_qty' => 500, 'unit_cost' => 2,
            'received_date' => now()->subDays(10), 'expiry_date' => now()->subDay(),
        ]);
        $this->lot([                                  // row 3 — fully consumed
            'batch_number' => 'DEAD',
            'initial_qty' => 100, 'remaining_qty' => 0, 'unit_cost' => 5,
            'received_date' => now()->subDays(20),
        ]);
    }

    public function test_the_board_ships_flat_rows_with_the_expiry_verdict_decided_server_side(): void
    {
        $this->seedLots();

        $this->actingAs($this->manager)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Batches/Index')
                // KPIs: only lots with stock left count; 2,000×1 + 1,000×3 + 500×2.
                ->where('stats.active', 3)
                ->where('stats.expiring', 1)
                ->where('stats.expired', 1)
                ->where('stats.totalValue', 6000)
                ->has('batches.data', 4)
                // Newest receipt first — latest('received_date').
                ->where('batches.data.0.batchNumber', 'FRESH')
                ->where('batches.data.0.rowTone', null)
                ->where('batches.data.0.isExpired', false)
                ->where('batches.data.0.isNearExpiry', false)
                ->where('batches.data.0.remainingQtyDisplay', '2 كيلوغرام')
                ->where('batches.data.0.remainingQtyTitle', '2000 g')
                ->where('batches.data.0.unitCostDisplay', '1')
                ->where('batches.data.0.value', 2000)
                ->where('batches.data.0.locationName', 'المطبخ')
                ->where('batches.data.0.ingredientName', 'لحمة')
                // Near expiry → amber row + the exact day count the Blade printed.
                ->where('batches.data.1.batchNumber', 'SOON')
                ->where('batches.data.1.rowTone', 'warning')
                ->where('batches.data.1.isNearExpiry', true)
                ->where('batches.data.1.daysUntilExpiry', 3)
                ->where('batches.data.1.value', 3000)
                // Expired wins over near-expiry.
                ->where('batches.data.2.batchNumber', 'GONE')
                ->where('batches.data.2.rowTone', 'danger')
                ->where('batches.data.2.isExpired', true)
                ->where('batches.data.2.isNearExpiry', false)
                // Depleted lot renders «نفذت», not a quantity.
                ->where('batches.data.3.isDepleted', true)
                ->where('batches.data.3.expiryDate', null)
                ->where('batches.data.3.batchNumber', 'DEAD')
                ->has('batches.links')
                ->where('currency.decimals', 2)
                ->where('hasFilters', false));
    }

    public function test_a_lot_without_a_number_or_expiry_still_renders(): void
    {
        $this->lot(['batch_number' => null, 'expiry_date' => null]);

        $this->actingAs($this->manager)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('batches.data.0.batchNumber', '—')
                ->where('batches.data.0.expiryDate', null)
                ->where('batches.data.0.daysUntilExpiry', null)
                ->where('batches.data.0.rowTone', null));
    }

    public function test_the_expired_lens_and_the_active_filter_narrow_the_table(): void
    {
        $this->seedLots();

        $this->actingAs($this->manager)
            ->get(route('admin.batches.index', ['expired' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('batches.data', 1)
                ->where('batches.data.0.batchNumber', 'GONE')
                ->where('filters.expired', true)
                ->where('hasFilters', true));

        // «نشطة فقط» drops the fully-consumed lot.
        $this->actingAs($this->manager)
            ->get(route('admin.batches.index', ['active' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('batches.data', 3));
    }

    public function test_the_ingredient_filter_rides_the_query_string(): void
    {
        $this->seedLots();
        $other = Ingredient::create([
            'name' => 'جبنة', 'base_unit_id' => $this->gram->id, 'current_stock' => 100,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        $this->lot(['ingredient_id' => $other->id, 'batch_number' => 'CHEESE']);

        $this->actingAs($this->manager)
            ->get(route('admin.batches.index', ['ingredient_id' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('batches.data', 1)
                ->where('batches.data.0.batchNumber', 'CHEESE')
                ->where('filters.ingredientId', (string) $other->id));
    }

    /**
     * A chef reads inventory but cannot inject stock without a PO trail.
     * The button is a prop, and the write path refuses regardless.
     */
    public function test_manual_batch_creation_is_gated_on_manage(): void
    {
        $this->actingAs($this->chef)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.create', false));

        $this->actingAs($this->chef)
            ->post(route('admin.batches.store'), [
                'ingredient_id' => $this->patty->id,
                'storage_location_id' => $this->kitchen->id,
                'qty' => 250,
            ])
            ->assertForbidden();

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock);
    }

    public function test_a_manager_can_add_a_manual_batch_and_the_stock_follows(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.batches.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', true)
                ->has('urls.store')
                // The modal's ingredient list carries the base unit the qty
                // field is read in.
                ->has('ingredients', 1, fn (Assert $i) => $i
                    ->where('name', 'لحمة')
                    ->where('unitCode', 'g')
                    ->etc())
                ->has('storageLocations', 1, fn (Assert $l) => $l
                    ->where('isDefault', true)
                    ->etc()));

        $this->actingAs($this->manager)
            ->post(route('admin.batches.store'), [
                'ingredient_id' => $this->patty->id,
                'storage_location_id' => $this->kitchen->id,
                'qty' => 250,
                'unit_cost' => 4,
                'batch_number' => 'MANUAL-1',
                'expiry_date' => now()->addDays(10)->toDateString(),
            ])
            ->assertSessionHas('success');

        $this->assertSame(5250.0, (float) $this->patty->fresh()->current_stock);
        $this->assertSame(250.0, (float) IngredientBatch::where('batch_number', 'MANUAL-1')->value('remaining_qty'));
    }

    /** Validation failures come back with errors, not a 500 or a silent drop. */
    public function test_a_manual_batch_without_a_quantity_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.batches.store'), [
                'ingredient_id' => $this->patty->id,
                'storage_location_id' => $this->kitchen->id,
            ])
            ->assertSessionHasErrors('qty');

        $this->assertSame(5000.0, (float) $this->patty->fresh()->current_stock);
    }
}
