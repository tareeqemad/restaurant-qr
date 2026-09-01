<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
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
 * مواقع التخزين on Inertia/Vue — index, show, create/edit form and the
 * inter-location transfer screen.
 *
 * What these pin, beyond "the page renders":
 *
 *  1. Every figure the card grid shows (stock value, low-stock count,
 *     ingredient count) is a SERVER number now. The retired Blade did the
 *     number_format in-template and the totals could drift.
 *  2. The gates ship as props. The Blade painted «موقع جديد» / «تعديل» /
 *     «حذف» for anyone who could merely READ inventory, and the transfer
 *     screen handed a chef a fully-live submit button that answered 403 —
 *     transferForm authorizes `viewAny` while transferStore authorizes
 *     `manage`.
 *  3. The transfer write path still refuses to move more than the source
 *     actually holds, and still refuses a same-location move — the client
 *     caps are convenience only.
 *  4. `stockByLocation` (the map that drives the ingredient dropdown) is
 *     scoped to the locations the user can actually pick. It used to be
 *     built over the whole table, so the payload carried every other
 *     branch's per-location quantities.
 */
class StorageLocationsVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Branch $other;

    protected User $manager;

    protected User $chef;

    protected Unit $gram;

    protected Ingredient $patty;

    protected StorageLocation $kitchen;

    protected StorageLocation $cellar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'sl-a', 'name' => 'الفرع الأول', 'is_active' => true, 'display_order' => 1]);
        $this->other = Branch::create(['code' => 'sl-b', 'name' => 'الفرع الثاني', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->branch->id);

        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->manager = $this->staff('manager', 'sl_manager', $this->branch);
        $this->chef = $this->staff('chef', 'sl_chef', $this->branch);

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->kitchen = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'kitchen', 'name' => 'المطبخ',
            'is_default' => true, 'active' => true, 'display_order' => 1, 'color' => '#166534',
        ]);
        $this->cellar = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'cellar', 'name' => 'القبو',
            'is_default' => false, 'active' => true, 'display_order' => 2, 'description' => 'مخزون جاف',
        ]);

        $this->patty = Ingredient::create([
            'name' => 'لحمة', 'base_unit_id' => $this->gram->id, 'current_stock' => 800,
            'reorder_threshold' => 100, 'cost_per_unit' => 2.5, 'track_stock' => true, 'active' => true,
        ]);

        // 500 g in the kitchen (above its 100 g location threshold), 300 g
        // in the cellar (at/below its 400 g threshold → low).
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->kitchen->id,
            'quantity' => 500, 'reorder_threshold' => 100,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->cellar->id,
            'quantity' => 300, 'reorder_threshold' => 400,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** `users` has no branch_id — membership is primary_branch_id + the pivot. */
    protected function staff(string $role, string $username, Branch ...$branches): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username,
            'username' => $username,
            'password' => bcrypt('x'),
            'status' => 'active',
            'primary_branch_id' => $branches[0]->id,
            'role' => $role,
        ]);

        foreach ($branches as $branch) {
            $user->branches()->attach($branch->id);
        }

        return $user;
    }

    // ── Index ────────────────────────────────────────────────────────

    public function test_the_index_ships_flat_cards_with_server_computed_figures(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StorageLocations/Index')
                ->has('locations', 2)
                ->has('locations.0', fn (Assert $loc) => $loc
                    ->where('name', 'المطبخ')
                    ->where('code', 'kitchen')
                    ->where('isDefault', true)
                    ->where('active', true)
                    ->where('branchName', 'الفرع الأول')
                    ->where('ingredientsCount', 1)
                    // 500 g above a 100 g threshold — not low.
                    ->where('lowStockCount', 0)
                    // 500 × 2.5 — the Blade did this multiplication in-template.
                    ->where('stockValue', 1250)
                    // Holds stock → destroy() would refuse.
                    ->where('blocksDelete', true)
                    ->has('urls.show')
                    ->has('urls.edit')
                    ->has('urls.destroy')
                    ->etc())
                ->has('locations.1', fn (Assert $loc) => $loc
                    ->where('name', 'القبو')
                    // 300 ≤ 400 → the location threshold fires.
                    ->where('lowStockCount', 1)
                    ->where('stockValue', 750)
                    ->etc())
                ->where('can.manage', true)
                ->where('currency.decimals', 2)
                ->has('currency.symbol')
                ->has('urls.create')
                ->has('urls.transfer'));
    }

    public function test_the_index_hides_write_actions_from_a_read_only_role(): void
    {
        // A chef can READ inventory (viewAny) but create/update/delete all
        // resolve to InventoryPolicy::manage, which they don't have.
        $this->actingAs($this->chef)
            ->get(route('admin.storage-locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        // And the server agrees, whatever the client renders.
        $this->actingAs($this->chef)
            ->get(route('admin.storage-locations.create'))
            ->assertForbidden();

        $this->actingAs($this->chef)
            ->delete(route('admin.storage-locations.destroy', $this->cellar))
            ->assertForbidden();
    }

    public function test_the_index_is_scoped_to_the_active_branch(): void
    {
        StorageLocation::create([
            'branch_id' => $this->other->id, 'code' => 'far', 'name' => 'مخزن الفرع الثاني',
            'is_default' => true, 'active' => true, 'display_order' => 1,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('locations', 2));
    }

    // ── Show ─────────────────────────────────────────────────────────

    public function test_the_show_page_ships_per_row_value_and_low_stock_verdict(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.show', $this->cellar))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StorageLocations/Show')
                ->where('location.name', 'القبو')
                ->has('stocks', 1, fn (Assert $s) => $s
                    ->where('name', 'لحمة')
                    ->where('unitCode', 'g')
                    // Qty::format trims the 4-decimal storage precision.
                    ->where('quantityLabel', '300')
                    ->where('thresholdLabel', '400')
                    ->where('costLabel', '2.5')
                    ->where('value', 750)
                    ->where('isLow', true)
                    ->etc())
                ->has('urls.purchaseOrder')
                ->has('urls.supplierInvoice')
                ->has('urls.transfer')
                ->has('urls.branchTransfers')
                ->has('urls.stockCount')
                ->has('urls.waste'));
    }

    public function test_an_empty_location_ships_an_empty_stock_list(): void
    {
        $empty = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'bar', 'name' => 'البار',
            'is_default' => false, 'active' => true, 'display_order' => 3,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.show', $empty))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('stocks', 0));
    }

    // ── Create / edit form ───────────────────────────────────────────

    public function test_the_create_form_ships_a_null_location(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StorageLocations/Form')
                ->where('location', null)
                ->has('urls.submit')
                ->has('urls.index'));
    }

    public function test_the_edit_form_ships_the_flattened_row(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.edit', $this->cellar))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StorageLocations/Form')
                ->where('location.code', 'cellar')
                ->where('location.name', 'القبو')
                ->where('location.description', 'مخزون جاف')
                ->where('location.isDefault', false)
                ->where('location.active', true)
                ->where('location.displayOrder', 2));
    }

    public function test_a_location_can_be_created_and_lands_in_the_active_branch(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.storage-locations.store'), [
                'code' => 'walk_in',
                'name' => 'الثلاجة الكبيرة',
                'icon' => 'bi-snow',
                'color' => '#166534',
                'description' => '',
                'is_default' => false,
                'active' => true,
                'display_order' => 5,
            ])
            ->assertRedirect(route('admin.storage-locations.index'));

        $created = StorageLocation::where('code', 'walk_in')->first();
        $this->assertNotNull($created);
        $this->assertSame($this->branch->id, $created->branch_id);
    }

    public function test_an_invalid_code_bounces_back_with_errors(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.create'))
            ->post(route('admin.storage-locations.store'), [
                'code' => 'has spaces',      // alpha_dash
                'name' => '',                // required
            ])
            ->assertRedirect(route('admin.storage-locations.create'))
            ->assertSessionHasErrors(['code', 'name']);
    }

    public function test_a_location_holding_stock_cannot_be_deleted(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.index'))
            ->delete(route('admin.storage-locations.destroy', $this->cellar))
            ->assertSessionHas('error');

        $this->assertNotNull($this->cellar->fresh());
    }

    // ── Transfer ─────────────────────────────────────────────────────

    public function test_the_transfer_form_ships_locations_ingredients_and_the_stock_map(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.transfer-form'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StorageLocations/Transfer')
                ->has('locations', 2)
                ->has('locations.0', fn (Assert $l) => $l
                    ->where('name', 'المطبخ')
                    ->where('branchName', 'الفرع الأول')
                    ->has('branchId')
                    ->has('isDefault')
                    ->etc())
                ->has('ingredients', 1, fn (Assert $i) => $i
                    ->where('name', 'لحمة')
                    ->where('unitCode', 'g')
                    ->where('globalThreshold', 100)
                    ->etc())
                ->where('can.transfer', true)
                ->has('urls.store')
                ->has('urls.branchTransferCreate'));
    }

    /**
     * The map is what the client filters the ingredient dropdown with. It
     * must carry the pickable locations' quantities — and nothing else.
     */
    public function test_the_stock_map_covers_only_the_pickable_locations(): void
    {
        $far = StorageLocation::create([
            'branch_id' => $this->other->id, 'code' => 'far', 'name' => 'مخزن بعيد',
            'is_default' => true, 'active' => true, 'display_order' => 1,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $far->id,
            'quantity' => 999, 'reorder_threshold' => 0,
        ]);

        $response = $this->actingAs($this->manager)->get(route('admin.storage-locations.transfer-form'));
        $map = (array) $response->viewData('page')['props']['stockByLocation'];

        $this->assertArrayHasKey($this->kitchen->id, $map);
        $this->assertArrayHasKey($this->cellar->id, $map);
        $this->assertArrayNotHasKey($far->id, $map);
        $this->assertEquals(500, ((array) $map[$this->kitchen->id])[$this->patty->id]);
    }

    public function test_an_inactive_location_is_not_offered_as_a_transfer_endpoint(): void
    {
        StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'closed', 'name' => 'مخزن مقفول',
            'is_default' => false, 'active' => false, 'display_order' => 9,
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.storage-locations.transfer-form'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('locations', 2));
    }

    public function test_a_read_only_role_opens_the_transfer_screen_but_cannot_move_stock(): void
    {
        // The screen opens (viewAny) — but the payload says the submit is dead.
        $this->actingAs($this->chef)
            ->get(route('admin.storage-locations.transfer-form'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.transfer', false));

        // And the write path refuses whatever the client posts.
        $this->actingAs($this->chef)
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $this->kitchen->id,
                'to_id' => $this->cellar->id,
                'quantity' => 10,
            ])
            ->assertForbidden();

        $this->assertSame(500.0, $this->quantityAt($this->kitchen));
    }

    public function test_a_transfer_moves_the_quantity_between_the_two_locations(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.transfer-form'))
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $this->kitchen->id,
                'to_id' => $this->cellar->id,
                'quantity' => 200,
                'reason' => 'إعادة توزيع',
            ])
            ->assertRedirect(route('admin.storage-locations.transfer-form'))
            ->assertSessionHas('success');

        $this->assertSame(300.0, $this->quantityAt($this->kitchen));
        $this->assertSame(500.0, $this->quantityAt($this->cellar));

        // A move shifts stock, it doesn't create or destroy it.
        $this->assertSame(800.0, (float) $this->patty->fresh()->current_stock);
    }

    public function test_moving_more_than_the_source_holds_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.transfer-form'))
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $this->kitchen->id,
                'to_id' => $this->cellar->id,
                'quantity' => 501,
            ])
            ->assertSessionHas('error');

        $this->assertSame(500.0, $this->quantityAt($this->kitchen));
        $this->assertSame(300.0, $this->quantityAt($this->cellar));
    }

    public function test_moving_stock_out_of_a_location_that_has_none_is_refused(): void
    {
        $bar = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'bar', 'name' => 'البار',
            'is_default' => false, 'active' => true, 'display_order' => 3,
        ]);

        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.transfer-form'))
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $bar->id,      // no ingredient_stock row at all
                'to_id' => $this->kitchen->id,
                'quantity' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertSame(500.0, $this->quantityAt($this->kitchen));
    }

    public function test_source_and_destination_cannot_be_the_same_location(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.transfer-form'))
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $this->kitchen->id,
                'to_id' => $this->kitchen->id,
                'quantity' => 10,
            ])
            ->assertSessionHasErrors('from_id');

        $this->assertSame(500.0, $this->quantityAt($this->kitchen));
    }

    public function test_a_zero_or_negative_quantity_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->from(route('admin.storage-locations.transfer-form'))
            ->post(route('admin.storage-locations.transfer-store'), [
                'ingredient_id' => $this->patty->id,
                'from_id' => $this->kitchen->id,
                'to_id' => $this->cellar->id,
                'quantity' => -5,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(500.0, $this->quantityAt($this->kitchen));
    }

    protected function quantityAt(StorageLocation $location): float
    {
        return (float) IngredientStock::where('ingredient_id', $this->patty->id)
            ->where('storage_location_id', $location->id)
            ->value('quantity');
    }
}
