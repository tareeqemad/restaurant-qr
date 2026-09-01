<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockCountService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 4 (MIGRATION-PILOT.md §13): the stock-count workspace on
 * Inertia/Vue. Finalize physics stay pinned by InventoryAuditFixesTest
 * (set-to-counted against LIVE stock, not repeatable); this file covers
 * the new surface and — most importantly — that the autosave path runs
 * through the VALIDATED controller action, so a negative counted_qty
 * cannot reach the ledger.
 */
class StockCountVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $manager;
    protected Ingredient $patty;
    protected StorageLocation $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'sc', 'name' => 'SC', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'manager', 'label' => 'Manager', 'is_system' => true]);
        $this->manager = User::create([
            'name' => 'M', 'username' => 'sc_manager', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'manager',
        ]);
        $this->manager->branches()->attach($this->branch->id);

        $gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->kitchen = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'المطبخ',
            'is_default' => true, 'active' => true,
        ]);
        $this->patty = Ingredient::create([
            'name' => 'لحمة', 'base_unit_id' => $gram->id, 'current_stock' => 100,
            'reorder_threshold' => 0, 'cost_per_unit' => 2, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $this->kitchen->id,
            'quantity' => 100, 'reorder_threshold' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function makeCount(): StockCount
    {
        return app(StockCountService::class)->create([
            'count_date' => now()->toDateString(),
            'storage_location_id' => $this->kitchen->id,
        ], $this->manager->id);
    }

    public function test_the_workspace_serves_rows_with_the_line_id_as_the_save_key(): void
    {
        $count = $this->makeCount();

        $this->actingAs($this->manager)
            ->get(route('admin.stock-counts.show', $count))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StockCounts/Show')
                ->where('count.number', $count->number)
                ->where('count.editable', true)
                ->has('rows', 1, fn (Assert $row) => $row
                    // The save key is the LINE id, never ingredient_id.
                    ->where('id', $count->items->first()->id)
                    ->where('name', 'لحمة')
                    ->where('systemQty', 100)
                    // Not counted yet — must be null, never 0.
                    ->where('countedQty', null)
                    ->etc())
                ->where('summary.counted', 0)
                ->has('urls.save'));
    }

    public function test_autosave_answers_json_so_the_table_keeps_its_state(): void
    {
        $count = $this->makeCount();
        $itemId = $count->items->first()->id;

        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $count), [
                'counts' => [$itemId => 95],
                'notes' => [$itemId => 'عدّ يدوي'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $item = $count->items()->first();
        $this->assertSame(95.0, (float) $item->counted_qty);
        $this->assertSame(-5.0, (float) $item->variance);
    }

    /**
     * The controller validates before casting, so a negative count cannot
     * create a bogus adjustment.
     */
    public function test_a_negative_count_is_refused(): void
    {
        $count = $this->makeCount();
        $itemId = $count->items->first()->id;

        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $count), [
                'counts' => [$itemId => -5],
            ])
            ->assertStatus(422);

        $this->assertNull($count->items()->first()->counted_qty);
    }

    public function test_zero_is_a_real_count_and_empty_stays_uncounted(): void
    {
        $count = $this->makeCount();
        $itemId = $count->items->first()->id;

        // 0 = "we physically have none" — a real answer.
        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $count), ['counts' => [$itemId => 0]])
            ->assertOk();
        $this->assertSame(0.0, (float) $count->items()->first()->counted_qty);

        // null = "not counted yet" — finalize must leave it alone.
        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $count), ['counts' => [$itemId => null]])
            ->assertOk();
        $this->assertNull($count->items()->first()->counted_qty);
    }

    public function test_a_finalized_count_is_read_only_in_the_payload(): void
    {
        $count = $this->makeCount();
        $itemId = $count->items->first()->id;

        app(StockCountService::class)->saveCounts($count, [$itemId => 95], []);
        app(StockCountService::class)->finalize($count, $this->manager->id);

        $this->actingAs($this->manager)
            ->get(route('admin.stock-counts.show', $count->fresh()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('count.editable', false)
                ->where('count.status', 'finalized')
                ->where('summary.counted', 1));

        // And the write path still refuses — the POLICY rejects it before
        // the service is even reached (update() denies non-draft counts).
        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $count), ['counts' => [$itemId => 50]])
            ->assertForbidden();

        $this->assertSame(95.0, (float) $count->items()->first()->counted_qty);
    }

    public function test_a_crafted_payload_cannot_write_another_counts_lines(): void
    {
        $mine = $this->makeCount();
        $other = $this->makeCount();
        $otherItemId = $other->items->first()->id;

        // Post another count's line id against MY count — silently skipped.
        $this->actingAs($this->manager)
            ->postJson(route('admin.stock-counts.save-counts', $mine), [
                'counts' => [$otherItemId => 42],
            ])
            ->assertOk();

        $this->assertNull($other->items()->first()->counted_qty,
            'saveCounts must only touch lines belonging to the posted count');
    }
}
