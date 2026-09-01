<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * rep-stock — the three inventory-money report screens on Inertia/Vue:
 * stock valuation, inventory consumption, and consumption variance.
 *
 * These pages carry numbers an accountant reads, so the tests assert on
 * the PROPS, not on markup. What is pinned here is specifically the stuff
 * that used to be computed inside a Blade template and is easy to get
 * wrong on the way out:
 *
 *   • the ABC/Pareto ladder and its INCLUSIVE 80 / 95 boundaries
 *   • sharePct, cumulativePct and the clamped bar width
 *   • the 0.0001 live-mode quantity epsilon — and the fact that `value`
 *     is deliberately NOT recomputed after the clamp
 *   • variancePct staying NULL (not 0) when the theoretical side is zero
 *   • the pre-rendered `varianceDisplay` string, which is why a negative
 *     variance shows no minus sign and why assertSee('+50') still works
 *   • the two extra gates on stock valuation (inventory.viewAny to open
 *     it at all, reports.export to see the Excel button)
 */
class ReportsStockVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Unit $gram;
    protected Unit $ml;
    protected StorageLocation $storage;
    protected Station $kitchen;
    protected Category $category;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'rs-main', 'name' => 'الفرع الرئيسي', 'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        // The seeder grants the `admin` role every permission, but only if the
        // Role row already exists — create it first.
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = $this->staff('admin', 'rs_admin');

        $this->gram = Unit::create(['code' => 'g',  'name' => 'g',  'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $this->ml   = Unit::create(['code' => 'ml', 'name' => 'ml', 'unit_type' => 'volume', 'factor_to_base' => 1, 'is_base' => true]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'Main',
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
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ═══════════════════════ 1. Stock valuation ═══════════════════════

    /**
     * The ABC ladder, the two percentages and the totals — all of which the
     * Blade computed at render time. The fixture is built so both Pareto
     * boundaries land EXACTLY on 80 and 95: those comparisons are inclusive
     * (`<= 80`, `<= 95`), and an off-by-one there silently reclassifies the
     * items a buyer is told to count weekly.
     */
    public function test_stock_valuation_ships_abc_share_and_cumulative_props(): void
    {
        $supplier = Supplier::create(['name' => 'مورّد الخضار', 'active' => true]);

        // 800 / 150 / 50 out of 1000 → cumulative 80 %, 95 %, 100 %.
        $this->stocked('Flour', 'ING-00101', 400, 2.0, supplier: $supplier);
        $this->stocked('Rice',  'ING-00102', 150, 1.0);
        $this->stocked('Salt',  'ING-00103', 100, 0.5, reorderThreshold: 200);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.stock-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/StockValuation')
                ->where('totalValue', 1000)
                ->where('rowCount', 3)
                // Only Salt is below its reorder threshold.
                ->where('lowStockValue', 50)
                ->where('abcCounts', ['A' => 1, 'B' => 1, 'C' => 1])
                ->where('abcValues', ['A' => 800, 'B' => 150, 'C' => 50])
                ->where('abcAPct', 80)
                ->where('isHistorical', false)
                ->where('asOf', null)
                ->where('branchId', $this->branch->id)
                ->where('branchName', 'الفرع الرئيسي')
                ->where('canExport', true)
                ->where('currency.decimals', 2)
                ->has('currency.symbol')
                ->has('rows', 3)
                // value DESC — never re-sorted client-side.
                ->where('rows.0.name', 'Flour')
                ->where('rows.0.sku', 'ING-00101')
                ->where('rows.0.supplier', 'مورّد الخضار')
                ->where('rows.0.unitCode', 'g')
                ->where('rows.0.qty', 400)
                ->where('rows.0.unitCost', 2)
                ->where('rows.0.value', 800)
                ->where('rows.0.sharePct', 80)
                ->where('rows.0.cumulativePct', 80)
                ->where('rows.0.cumulativeBarPct', 80)
                ->where('rows.0.abcClass', 'A')
                ->where('rows.0.isLowStock', false)
                // Pre-rendered by the PHP-only helpers — there is no JS twin.
                ->where('rows.0.qtyDisplay', '400 غرام')
                ->where('rows.0.qtyTitle', '400 g')
                ->where('rows.0.unitCostDisplay', '2')
                ->where('rows.0.priceHistoryUrl', route('admin.vendor-prices.ingredient', $page->toArray()['props']['rows'][0]['ingredientId']))
                // 950/1000 = 95 → still B, the boundary is inclusive.
                ->where('rows.1.name', 'Rice')
                ->where('rows.1.cumulativePct', 95)
                ->where('rows.1.abcClass', 'B')
                ->where('rows.1.sharePct', 15)
                ->where('rows.2.name', 'Salt')
                ->where('rows.2.cumulativePct', 100)
                ->where('rows.2.cumulativeBarPct', 100)
                ->where('rows.2.abcClass', 'C')
                ->where('rows.2.sharePct', 5)
                ->where('rows.2.isLowStock', true)
                ->where('rows.2.supplier', null)
            );
    }

    /** No tracked ingredients at all → the divide-by-zero branch. */
    public function test_stock_valuation_empty_set_ships_null_abc_pct_not_zero(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.stock-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/StockValuation')
                ->where('rowCount', 0)
                ->where('totalValue', 0)
                ->has('rows', 0)
                // null, NOT 0 — the page renders the literal '0%' for it.
                ->where('abcAPct', null)
            );
    }

    /**
     * The project's 0.0001 live-mode epsilon. A hair-negative quantity is
     * clamped to zero for display, and `value` is deliberately left alone —
     * the balance-sheet total is the sum of `value`, so "tidying" the clamp
     * would move a real number.
     */
    public function test_stock_valuation_epsilon_clamps_qty_but_not_value(): void
    {
        $ing = Ingredient::create([
            'name' => 'Drift', 'sku' => 'ING-00201', 'base_unit_id' => $this->gram->id,
            'current_stock' => 0, 'reorder_threshold' => 0, 'cost_per_unit' => 2,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ing->id, 'storage_location_id' => $this->storage->id,
            'quantity' => -0.0001, 'reorder_threshold' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.stock-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.qty', 0)
                ->where('rows.0.value', -0.0002)
            );
    }

    /**
     * Historical mode rewinds the quantity by every movement after the cutoff
     * and re-costs the row at the last purchase price at-or-before it. The
     * export URL must carry `as_of` through, or the user downloads today's
     * snapshot while looking at last month's.
     */
    public function test_stock_valuation_historical_mode_rewinds_and_preserves_as_of_in_export_url(): void
    {
        $ing = $this->stocked('Sugar', 'ING-00301', 400, 9.0);
        $asOf = now()->subDay()->toDateString();

        // Bought at 2.5 before the cutoff → that is the historical unit cost.
        $this->movement($ing, 'in', 100, occurredAt: now()->subDays(3), unitCost: 2.5);
        // Received 100 g AFTER the cutoff → rewind 400 back to 300.
        $this->movement($ing, 'in', 100, occurredAt: now(), unitCost: 7.0);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.stock-valuation', ['as_of' => $asOf]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/StockValuation')
                ->where('isHistorical', true)
                ->where('asOf', $asOf)
                ->where('rows.0.qty', 300)
                ->where('rows.0.unitCost', 2.5)
                ->where('rows.0.value', 750)
                ->where('totalValue', 750)
                ->where('urls.exportXlsx', route('admin.reports.stock-valuation', ['as_of' => $asOf, 'export' => 'xlsx']))
                ->where('urls.current', route('admin.reports.stock-valuation'))
            );
    }

    /** `inventory.viewAny` is an EXTRA gate on top of the route's reports.viewAny. */
    public function test_stock_valuation_403s_without_inventory_view_any(): void
    {
        $user = $this->staffWithPermissions('manager', 'rs_reporter', ['reports.viewAny']);

        // The same user reaches the sibling report fine, so the 403 below is
        // the extra abort_unless, not the shell or the route group.
        $this->actingAs($user)
            ->get(route('admin.reports.inventory'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('admin.reports.stock-valuation'))
            ->assertForbidden();
    }

    /**
     * The Blade rendered the Excel button for everyone and it 403'd for users
     * without `reports.export`. The page now hides it — a deliberate, small
     * behaviour change, so it gets pinned.
     */
    public function test_stock_valuation_can_export_is_false_without_reports_export(): void
    {
        $user = $this->staffWithPermissions(
            'manager', 'rs_viewer', ['reports.viewAny', 'inventory.viewAny'],
        );

        $this->actingAs($user)
            ->get(route('admin.reports.stock-valuation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canExport', false));

        // …and the export itself is still refused server-side.
        $this->actingAs($user)
            ->get(route('admin.reports.stock-valuation', ['export' => 'xlsx']))
            ->assertForbidden();
    }

    // ════════════════════ 2. Inventory consumption ════════════════════

    /**
     * The five stat-rail totals lived in the template. They are props now,
     * so the headline numbers can no longer drift from the table body.
     */
    public function test_inventory_report_flattens_rows_and_ships_the_five_totals(): void
    {
        $flour = $this->stocked('Flour', 'ING-00401', 0, 1.0);
        $oil   = $this->stocked('Oil',   'ING-00402', 0, 1.0, unit: $this->ml);

        $this->movement($flour, 'in',    5000, totalCost: 50);
        $this->movement($flour, 'out',   1500, totalCost: 15);
        $this->movement($flour, 'waste',  500, totalCost: 5);
        $this->movement($oil,   'in',    2000, totalCost: 20);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.inventory'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/Inventory')
                ->where('rowCount', 2)
                ->has('rows', 2)
                ->where('totals', [
                    'in' => 7000, 'out' => 1500, 'waste' => 500,
                    'outCost' => 15, 'wasteCost' => 5,
                ])
                ->where('rows.0.ingredientId', $flour->id)
                ->where('rows.0.name', 'Flour')
                ->where('rows.0.unitCode', 'g')
                ->where('rows.0.inQty', 5000)
                ->where('rows.0.outQty', 1500)
                ->where('rows.0.wasteQty', 500)
                ->where('rows.0.outCost', 15)
                ->where('rows.0.wasteCost', 5)
                // The unit ladder is PHP-only; both body and tooltip ship rendered.
                ->where('rows.0.inDisplay', '5 كيلوغرام')
                ->where('rows.0.outDisplay', '1.5 كيلوغرام')
                ->where('rows.0.wasteDisplay', '500 غرام')
                ->where('rows.0.inTitle', '5,000.0000 g')
                ->where('rows.1.name', 'Oil')
                ->where('rows.1.unitCode', 'ml')
                ->where('rows.1.inDisplay', '2 لتر')
                ->where('rows.1.wasteQty', 0)
                ->has('shortcuts.today.from')
                ->has('shortcuts.week.from')
                ->has('shortcuts.month.from')
                ->where('currency.decimals', 2)
                ->where('urls.self', route('admin.reports.inventory'))
            );
    }

    /**
     * The report groups by all five movement types but renders three, so an
     * ingredient whose only movement was an adjustment still forms a 0/0/0
     * row AND is counted in the panel badge. Existing behaviour, pinned so
     * nobody "cleans it up" by accident.
     */
    public function test_inventory_report_counts_adjustment_only_ingredient_as_a_zero_row(): void
    {
        $spice = $this->stocked('Spice', 'ING-00501', 0, 1.0);
        $this->movement($spice, 'adjustment', 25);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.inventory'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rowCount', 1)
                ->where('rows.0.name', 'Spice')
                ->where('rows.0.inQty', 0)
                ->where('rows.0.outQty', 0)
                ->where('rows.0.wasteQty', 0)
                ->where('totals.in', 0)
            );
    }

    /** The date filter is a real query filter, not decoration. */
    public function test_inventory_report_respects_the_date_filter(): void
    {
        $flour = $this->stocked('Flour', 'ING-00601', 0, 1.0);
        $this->movement($flour, 'in', 1000, occurredAt: now()->subDays(40), totalCost: 10);

        // Default window is 30 days back → the movement is outside it.
        $this->actingAs($this->admin)
            ->get(route('admin.reports.inventory'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rowCount', 0)->has('rows', 0));

        $this->actingAs($this->admin)
            ->get(route('admin.reports.inventory', [
                'from' => now()->subDays(45)->toDateString(),
                'to'   => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rowCount', 1)
                ->where('from', now()->subDays(45)->toDateString())
                ->where('totals.in', 1000)
            );
    }

    // ═══════════════════ 3. Consumption variance ══════════════════════

    /**
     * The theft detector's headline row. 2 × a 200 g recipe = 400 g
     * theoretical, 450 g actually deducted → +50 g over, priced at the
     * ingredient's cost_per_unit.
     *
     * `varianceDisplay` is the load-bearing string: the sign is rendered
     * OUTSIDE an abs() unit ladder, which is why a negative variance shows
     * no minus and why InventoryAdvancedFeaturesTest's assertSee('+50')
     * survives the switch to an Inertia payload.
     */
    public function test_consumption_variance_ships_precomputed_display_strings_and_tones(): void
    {
        $chicken = $this->stocked('Chicken', 'ING-00701', 1000, 1.0);
        [$order, $orderItem] = $this->soldTwo($chicken, 200);

        $this->movement($chicken, 'out', 450, referenceType: OrderItem::class, referenceId: $orderItem->id);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.consumption-variance', [
            'from' => now()->toDateString(), 'to' => now()->toDateString(),
        ]));

        $response->assertOk();
        // The exact assertion the pre-existing inventory test makes.
        $response->assertSee('+50');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/ConsumptionVariance')
            ->where('rowCount', 1)
            ->where('rows.0.name', 'Chicken')
            ->where('rows.0.isComposite', false)
            ->where('rows.0.unitCode', 'g')
            ->where('rows.0.theoretical', 400)
            ->where('rows.0.actual', 450)
            ->where('rows.0.variance', 50)
            ->where('rows.0.variancePct', 12.5)
            ->where('rows.0.varianceCost', 50)
            ->where('rows.0.varianceDisplay', '+50 غرام')
            ->where('rows.0.varianceTitle', '50.0000 g')
            ->where('rows.0.variancePctText', '+12.5%')
            // 12.5 % sits in the 5–15 band.
            ->where('rows.0.pctTone', 'bg-warning')
            ->where('rows.0.varianceTone', 'text-danger')
            ->where('rows.0.costTone', 'text-danger')
            // |50| is well past the 0.5-currency dead-band.
            ->where('rows.0.rowTone', 'table-danger')
            ->where('summary.ordersInRange', 1)
            ->where('summary.itemsInRange', 1)
            ->where('summary.totalTheoreticalCost', 400)
            ->where('summary.totalVarianceCost', 50)
            ->where('summary.varianceTone', 'danger')
            ->where('urls.self', route('admin.reports.consumption-variance'))
        );
    }

    /**
     * A NEGATIVE variance shows no minus sign — only the amber colour. The
     * sign is prepended only when positive, and the magnitude goes through
     * abs() before the unit ladder.
     */
    public function test_consumption_variance_negative_variance_hides_the_minus_sign(): void
    {
        $chicken = $this->stocked('Chicken', 'ING-00801', 1000, 1.0);
        [$order, $orderItem] = $this->soldTwo($chicken, 200);

        // Only 350 g deducted against a 400 g theoretical → −50 g.
        $this->movement($chicken, 'out', 350, referenceType: OrderItem::class, referenceId: $orderItem->id);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.consumption-variance', [
                'from' => now()->toDateString(), 'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.variance', -50)
                ->where('rows.0.varianceDisplay', '50 غرام')   // no '-'
                ->where('rows.0.varianceTitle', '-50.0000 g')  // the tooltip IS signed
                ->where('rows.0.varianceTone', 'text-warning')
                ->where('rows.0.rowTone', 'table-warning')
                ->where('summary.varianceTone', 'warning')
            );
    }

    /**
     * No theoretical side (a pure waste write-off, nothing sold) → the
     * percentage is NULL, not 0. The page renders a muted em dash for null;
     * shipping 0 would claim a perfectly-on-recipe kitchen.
     */
    public function test_consumption_variance_pct_is_null_not_zero_when_theoretical_is_zero(): void
    {
        $olives = $this->stocked('Olives', 'ING-00901', 1000, 1.0);
        $this->movement($olives, 'waste', 120, totalCost: 120);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.consumption-variance', [
                'from' => now()->toDateString(), 'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rowCount', 1)
                ->where('rows.0.theoretical', 0)
                ->where('rows.0.actual', 0)
                ->where('rows.0.waste', 120)
                ->where('rows.0.variancePct', null)
                ->where('rows.0.variancePctText', null)
                ->where('rows.0.pctTone', null)
                ->where('rows.0.varianceTone', 'text-muted')
                ->where('rows.0.rowTone', null)
                ->where('rows.0.costTone', null)
                ->where('summary.totalWasteCost', 120)
                // Inside the 0.5 dead-band → the card stays green.
                ->where('summary.varianceTone', 'success')
            );
    }

    /** Rows arrive sorted by ABSOLUTE variance cost, biggest leak first. */
    public function test_consumption_variance_rows_are_sorted_by_absolute_variance_cost(): void
    {
        $small = $this->stocked('Small', 'ING-01001', 1000, 1.0);
        $big   = $this->stocked('Big',   'ING-01002', 1000, 1.0);

        $this->movement($small, 'waste', 5, totalCost: 5);
        $this->movement($big,   'waste', 5, totalCost: 5);
        // Actual consumption with no recipe behind it → variance = actual.
        $item = $this->menuItem('Meal');
        $order = Order::create([
            'branch_id' => $this->branch->id, 'status' => 'approved',
            'subtotal' => 10, 'total' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
        $oi = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id,
            'name_snapshot' => 'Meal', 'quantity' => 1, 'unit_price' => 10,
            'subtotal' => 10, 'status' => 'approved',
        ]);
        $this->movement($small, 'out', 10, referenceType: OrderItem::class, referenceId: $oi->id);
        $this->movement($big,   'out', 900, referenceType: OrderItem::class, referenceId: $oi->id);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.consumption-variance', [
                'from' => now()->toDateString(), 'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 2)
                ->where('rows.0.name', 'Big')
                ->where('rows.1.name', 'Small')
            );
    }

    // ─────────────────────────── helpers ──────────────────────────────

    protected function staff(string $role, string $username): User
    {
        Role::firstOrCreate(['name' => $role], ['label' => $role, 'is_system' => true]);

        $user = User::create([
            'name' => $username, 'username' => $username, 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => $role,
        ]);
        $user->branches()->attach($this->branch->id);

        return $user;
    }

    /** A non-owner staff member whose role grants exactly the listed permissions. */
    protected function staffWithPermissions(string $role, string $username, array $permissions): User
    {
        $user = $this->staff($role, $username);

        Role::where('name', $role)->first()
            ->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return $user;
    }

    /** A tracked, active ingredient with a stock row at the branch's location. */
    protected function stocked(
        string $name,
        string $sku,
        float $qty,
        float $cost,
        ?Unit $unit = null,
        ?Supplier $supplier = null,
        float $reorderThreshold = 0,
    ): Ingredient {
        $ing = Ingredient::create([
            'name' => $name,
            'sku' => $sku,
            'base_unit_id' => ($unit ?? $this->gram)->id,
            'supplier_id' => $supplier?->id,
            'current_stock' => $qty,
            'reorder_threshold' => $reorderThreshold,
            'cost_per_unit' => $cost,
            'track_stock' => true,
            'active' => true,
        ]);

        IngredientStock::create([
            'ingredient_id' => $ing->id,
            'storage_location_id' => $this->storage->id,
            'quantity' => $qty,
            // Null/zero here means the per-location sum is 0, so the global
            // ingredient threshold is what actually decides "low stock".
            'reorder_threshold' => 0,
        ]);

        return $ing;
    }

    protected function movement(
        Ingredient $ing,
        string $type,
        float $qty,
        ?\DateTimeInterface $occurredAt = null,
        float $totalCost = 0,
        ?float $unitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): InventoryMovement {
        return InventoryMovement::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ing->id,
            'type' => $type,
            'quantity' => $qty,
            'quantity_in_base' => $qty,
            'unit_id' => $ing->base_unit_id,
            'unit_cost' => $unitCost ?? 0,
            'total_cost' => $totalCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    protected function menuItem(string $name): MenuItem
    {
        return MenuItem::create([
            'category_id' => $this->category->id, 'station_id' => $this->kitchen->id,
            'sku' => 'M-'.\Illuminate\Support\Str::random(6), 'slug' => \Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(4),
            'name' => $name, 'price' => 10, 'is_available' => true,
        ]);
    }

    /** One approved order for 2 × a menu item whose recipe uses $gramsEach. */
    protected function soldTwo(Ingredient $ing, float $gramsEach): array
    {
        $item = $this->menuItem('Meal');
        RecipeItem::create([
            'menu_item_id' => $item->id, 'ingredient_id' => $ing->id,
            'quantity' => $gramsEach, 'unit_id' => $ing->base_unit_id,
        ]);

        $order = Order::create([
            'branch_id' => $this->branch->id, 'status' => 'approved',
            'subtotal' => 20, 'total' => 20, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id,
            'name_snapshot' => 'Meal', 'quantity' => 2, 'unit_price' => 10,
            'subtotal' => 20, 'status' => 'approved',
        ]);

        return [$order, $orderItem];
    }
}
