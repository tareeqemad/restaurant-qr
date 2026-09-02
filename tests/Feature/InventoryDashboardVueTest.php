<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Unit;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * inventory-dashboard — the stock command centre + the movements ledger on
 * Inertia/Vue.
 *
 * Both screens were Blade templates that did real arithmetic at render time,
 * so what is pinned here is exactly the stuff that can silently drift on the
 * way out of a template:
 *
 *   • the reorder queue's target-stock formula — max(threshold × 2,
 *     threshold + 1) — and the three-key sort that puts a NAFID ingredient
 *     above a merely-low one regardless of money
 *   • the seven stat-rail tints and the seven action-card headlines, all of
 *     which were `$x > 0 ? 'danger' : 'muted'` inside the markup
 *   • actionCount, the sum of six counts PLUS the invoice queue's size —
 *     the badge a manager reads first
 *   • the expiry tone ladder (منتهية / ≤ 2 days / else) and the fact that an
 *     EXPIRED batch still counts as "expiring" while being excluded from
 *     usable stock — the one place those two rules disagree
 *   • the 0.0001 / 0.01 variance epsilons, and that a NULL variance renders
 *     an em dash but still tones muted
 *   • every QuantityFormatter::smart ladder string and its 4-decimal
 *     tooltip — PHP-only helpers with no JS twin
 *   • the movements ledger's badge map, the morph reference chip (label +
 *     icon + deep link) and the filters round-tripping through the query
 *     string
 *
 * Assertions are on props (Inertia\Testing), never on markup.
 */
class InventoryDashboardVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Unit $gram;

    protected StorageLocation $storage;

    protected User $admin;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'inv-main', 'name' => 'الفرع الرئيسي', 'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        // The seeder grants `admin` every permission — but only if the Role
        // row already exists, so create it first.
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = $this->staff('admin', 'inv_admin');

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'g', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);

        $this->storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'main', 'name' => 'المخزن الرئيسي',
            'is_default' => true, 'active' => true,
        ]);

        $this->supplier = Supplier::create(['name' => 'مورّد الطحين', 'active' => true]);
        $this->supplier->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ══════════════════════ 1. Dashboard — figures ══════════════════════

    /**
     * The headline numbers. `stock_value` is SUM(per-branch stock × per-branch
     * cost), and the three population buckets must partition the tracked set
     * exactly: healthy = tracked − low − out. If any of those drift the buyer
     * is told a different story by the rail than by the panels underneath it.
     */
    public function test_dashboard_ships_stats_and_the_action_count(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Dashboard')
                ->where('branchName', 'الفرع الرئيسي')
                ->where('subtitle', 'مركز قرار سريع لفرع الفرع الرئيسي')
                ->where('stats.tracked', 5)
                ->where('stats.lowStock', 1)     // طحين: 100 ≤ 500
                ->where('stats.outStock', 2)     // ملح (0) + خيار (batch expired)
                ->where('stats.healthy', 2)      // tracked − low − out
                // 200 (طحين) + 0 (ملح) + 3000 (سكر) + 200 (حليب) + 50 (خيار)
                ->where('stats.stockValue', 3450)
                ->where('stats.stockValueLabel', Money::format(3450))
                // 20 (ملح) + 1800 (طحين)
                ->where('stats.reorderCost', 1820)
                ->where('stats.reorderCostLabel', Money::format(1820))
                // Both dated lots count — the expired one included.
                ->where('stats.expiringBatches', 2)
                ->where('stats.overduePos', 1)
                ->where('stats.uninvoicedPos', 1)
                ->where('stats.invoiceVariances', 1)
                ->where('stats.receipts7d', 1)
                ->where('stats.apDue', 250)
                ->where('stats.apOverdue', 250)
                ->where('stats.waste7d', 12.5)
                // 1 + 2 + 2 + 1 + 1 + 1 + 1 (the invoice queue's own size)
                ->where('actionCount', 9)
            );
    }

    public function test_dashboard_keeps_the_daily_decision_surface_compact(): void
    {
        $dashboard = file_get_contents(resource_path('js/Pages/Admin/Inventory/Dashboard.vue'));
        $workspace = file_get_contents(resource_path('js/Components/Inventory/InventoryWorkspaceNav.vue'));
        $fallback = file_get_contents(public_path('assets/dashtic/css/relax-components.css'));

        $this->assertStringContainsString('@media(min-width:1181px){.pulse-grid{gap:0;', $dashboard);
        $this->assertStringContainsString('.workflow-card__body small{display:none}', $dashboard);
        $this->assertStringContainsString("import { Link } from '@inertiajs/vue3';", $workspace);
        $this->assertStringContainsString('watch(activeGroup, () => {', $workspace);
        $this->assertStringContainsString('<Link v-for="item in shown.items"', $workspace);
        $this->assertStringContainsString(':aria-selected="shown.id === group.id"', $workspace);
        $this->assertStringNotContainsString('current: activeGroup === group.id', $workspace);
        $this->assertStringContainsString('html body .app-content .inv-workspace', $fallback);
        $this->assertStringContainsString('html body .app-content .inventory-board .pulse-grid', $fallback);
        $this->assertStringContainsString('button.current:not(.active)', $fallback);
    }

    /**
     * The rail's tints were PHP conditionals inside the Blade's stat array.
     * They ship as props so a red "نافد" can never sit next to a zero.
     */
    public function test_dashboard_stat_rail_tints_follow_the_counts(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('statRail', 7)
                ->where('statRail.0.label', 'قيمة المخزون')
                ->where('statRail.0.color', 'primary')
                ->where('statRail.1.label', 'يحتاج إجراء')
                ->where('statRail.1.value', '9')
                ->where('statRail.1.color', 'danger')
                ->where('statRail.2.color', 'accent')   // low_stock > 0
                ->where('statRail.3.color', 'danger')   // out_stock > 0
                ->where('statRail.4.color', 'success')
                ->where('statRail.5.color', 'danger')   // ap_overdue > 0
                ->where('statRail.6.color', 'danger')   // variances > 0
            );
    }

    public function test_dashboard_exposes_the_purchase_to_ledger_handoff(): void
    {
        $this->seedDashboard();

        $receivedPo = PurchaseOrder::where('status', 'received')->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workflow', 4)
                ->where('workflow.0.key', 'need')
                ->where('workflow.2.key', 'receive')
                ->where('workflow.3.key', 'invoice')
                ->has('accountingBridge.rows', 3)
                ->where('accountingBridge.rows.1.key', 'grni')
                ->has('uninvoicedPurchaseOrders', 1)
                ->where('uninvoicedPurchaseOrders.0.id', $receivedPo->id)
                ->where('uninvoicedPurchaseOrders.0.createInvoiceUrl', route('admin.supplier-invoices.create', ['po' => $receivedPo->id]))
                ->where('can.createPurchaseOrder', true)
                ->where('can.createSupplierInvoice', true));
    }

    public function test_dashboard_converts_supplier_balances_to_the_base_currency(): void
    {
        SupplierInvoice::create([
            'number' => 'SI-USD-DASHBOARD',
            'supplier_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'exchange_rate' => 3.5,
            'subtotal' => 100,
            'total' => 100,
            'paid_total' => 0,
            'balance' => 100,
            'status' => 'unpaid',
            'invoice_date' => today(),
            'due_date' => today()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.apDue', 350)
                ->where('stats.apOverdue', 350));
    }

    /**
     * Every action card's headline was a string built in the template out of a
     * count (or a Money::format) plus Arabic. The first card ADDS two counts —
     * منخفض + نافد — which is exactly the kind of in-template arithmetic that
     * goes stale.
     */
    public function test_dashboard_action_cards_carry_their_headlines_and_links(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions', 7)
                ->where('actions.0.title', '3 مكون منخفض أو نافد')   // 1 + 2
                ->where('actions.0.tone', 'danger')
                ->where('actions.0.url', route('admin.ingredients.index', ['low_stock' => 1]))
                ->where('actions.1.title', Money::format(1820).' طلب مقترح')
                ->where('actions.1.url', route('admin.reports.reorder-suggestions'))
                ->where('actions.2.title', '2 دفعة قريبة الانتهاء')
                ->where('actions.2.url', route('admin.batches.index'))
                ->where('actions.3.title', '1 أمر شراء متأخر')
                ->where('actions.3.url', route('admin.purchase-orders.index', ['status' => 'sent']))
                ->where('actions.4.title', Money::format(250).' مستحقات متأخرة')
                ->where('actions.4.url', route('admin.supplier-invoices.index', ['overdue' => 1]))
                ->where('actions.5.title', '1 أمر مستلم بلا فاتورة')
                ->where('actions.5.tone', 'muted')
                ->where('actions.6.title', '1 فرق فاتورة/استلام')
                ->where('actions.6.tone', 'warning')
            );
    }

    /**
     * The reorder queue is the page's only real algorithm:
     *   target = max(threshold × 2, threshold + 1)
     *   need   = max(0, target − stock)
     *   sort   = [in-stock last, health % asc, need cost desc]
     *
     * ملح is out of stock and cheap; طحين is merely low and expensive. The
     * out-of-stock one MUST come first — the sort's first key exists precisely
     * so money can't outrank an ingredient that has already stopped the menu.
     */
    public function test_dashboard_reorder_queue_math_and_ordering(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reorderQueue', 2)
                // ── ملح: stock 0, threshold 10, cost 1 → target 20, need 20.
                ->where('reorderQueue.0.name', 'ملح')
                ->where('reorderQueue.0.stock', 0)
                ->where('reorderQueue.0.stockDisplay', '0 غرام')
                ->where('reorderQueue.0.stockTitle', '0.0000 g')
                ->where('reorderQueue.0.stockTone', 'text-danger fw-bold')
                ->where('reorderQueue.0.needQty', 20)
                ->where('reorderQueue.0.needDisplay', '20 غرام')
                ->where('reorderQueue.0.needCost', 20)
                ->where('reorderQueue.0.needCostLabel', Money::format(20))
                ->where('reorderQueue.0.supplierName', 'بدون مورد افتراضي')
                // ── طحين: stock 100, threshold 500, cost 2 → target 1000.
                ->where('reorderQueue.1.name', 'طحين')
                ->where('reorderQueue.1.stock', 100)
                ->where('reorderQueue.1.stockTone', 'text-warning fw-bold')
                ->where('reorderQueue.1.needQty', 900)
                ->where('reorderQueue.1.needDisplay', '900 غرام')
                ->where('reorderQueue.1.needTitle', '900.0000 g')
                ->where('reorderQueue.1.needCost', 1800)
                ->where('reorderQueue.1.supplierName', 'مورّد الطحين')
            );
    }

    /**
     * Expiry tones. An already-expired lot reads "منتهية" in red; ≤ 2 days is
     * amber; anything further out is muted. Rows are ordered by expiry date,
     * so the expired one leads.
     */
    public function test_dashboard_expiring_batches_tone_ladder_and_order(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('expiringBatches', 2)
                ->where('expiringBatches.0.ingredientName', 'خيار')
                ->where('expiringBatches.0.days', -1)
                ->where('expiringBatches.0.daysLabel', 'منتهية')
                ->where('expiringBatches.0.tone', 'text-danger')
                ->where('expiringBatches.0.locationName', 'المخزن الرئيسي')
                ->where('expiringBatches.0.batchLabel', 'B-CUCUMBER')
                ->where('expiringBatches.1.ingredientName', 'حليب')
                ->where('expiringBatches.1.days', 3)
                ->where('expiringBatches.1.daysLabel', 'بعد 3 يوم')
                ->where('expiringBatches.1.tone', 'text-muted')
            );
    }

    /**
     * The purchasing panels. `openPurchaseOrders` carries the status badge's
     * label AND colour (settings-free enums, but still PHP), and the overdue
     * panel carries diffForHumans — Carbon-only, no JS twin.
     */
    public function test_dashboard_purchase_panels_flatten_to_labels_and_urls(): void
    {
        $this->seedDashboard();

        $sentPo = PurchaseOrder::where('status', 'sent')->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('overduePurchaseOrders', 1)
                ->where('overduePurchaseOrders.0.number', $sentPo->number)
                ->where('overduePurchaseOrders.0.supplierName', 'مورّد الطحين')
                ->where('overduePurchaseOrders.0.totalLabel', Money::format(500))
                ->where('overduePurchaseOrders.0.url', route('admin.purchase-orders.show', $sentPo))
                ->has('overduePurchaseOrders.0.expectedLabel')
                // Only the `sent` PO is open — the received one dropped out.
                ->has('openPurchaseOrders', 1)
                ->where('openPurchaseOrders.0.statusLabel', 'مُرسل')
                ->where('openPurchaseOrders.0.statusColor', 'info')
                ->has('recentReceipts', 1)
                ->where('recentReceipts.0.supplierName', 'مورّد الطحين')
                ->has('recentReceipts.0.poNumber')
                ->has('recentReceipts.0.receivedAtLabel')
            );
    }

    /**
     * The AP queue and the variance table. The variance tones run off the
     * project's two epsilons (0.0001 on quantity, 0.01 on money) applied to
     * the CAST value, which is why a null quantity variance tones muted while
     * still printing an em dash.
     */
    public function test_dashboard_invoice_queue_and_variance_epsilons(): void
    {
        $this->seedDashboard();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('supplierInvoiceQueue', 1)
                ->where('supplierInvoiceQueue.0.number', 'SI-INV-1')
                ->where('supplierInvoiceQueue.0.statusLabel', 'غير مدفوعة')
                ->where('supplierInvoiceQueue.0.balanceLabel', Money::format(250))
                // due_date was yesterday → overdue reads red, not amber.
                ->where('supplierInvoiceQueue.0.tone', 'text-danger')
                ->has('invoiceVarianceItems', 1)
                ->where('invoiceVarianceItems.0.invoiceNumber', 'SI-INV-1')
                ->where('invoiceVarianceItems.0.description', 'طحين ٥٠ كغ')
                ->where('invoiceVarianceItems.0.varianceQtyLabel', '2')
                ->where('invoiceVarianceItems.0.varianceQtyTone', 'text-danger fw-bold')
                ->where('invoiceVarianceItems.0.varianceTotalLabel', Money::format(15))
                ->where('invoiceVarianceItems.0.varianceTotalTone', 'text-danger fw-bold')
                ->has('highWaste', 1)
                ->where('highWaste.0.name', 'طحين')
                ->where('highWaste.0.eventsCount', 1)
                ->where('highWaste.0.qty', 250)
                ->where('highWaste.0.qtyDisplay', '250 غرام')
                ->where('highWaste.0.qtyTitle', '250.0000 g')
                ->where('highWaste.0.totalCostLabel', Money::format(12.5))
            );
    }

    /**
     * A quantity variance below the 0.0001 epsilon must NOT be flagged, and a
     * NULL one renders the em dash. Both rows still reach the table because
     * the money side is over its own 0.01 epsilon.
     */
    public function test_dashboard_variance_below_epsilon_tones_muted_and_null_renders_dash(): void
    {
        $invoice = SupplierInvoice::create([
            'number' => 'SI-EPS', 'supplier_id' => $this->supplier->id,
            'subtotal' => 100, 'total' => 100, 'paid_total' => 0, 'balance' => 100,
            'status' => 'unpaid', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        // Explicit, distinct created_at values: the panel is ordered by
        // latest(), and identical timestamps leave the tie to the driver.
        $nullRow = SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id, 'description' => 'بند بدون فرق كمية',
            'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100,
            'variance_qty' => null, 'variance_total' => 9,
        ]);
        $nullRow->forceFill(['created_at' => now()])->save();

        $epsilonRow = SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id, 'description' => 'بند بفرق مالي فقط',
            'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'total' => 100,
            // Under the quantity epsilon; over the money one.
            'variance_qty' => 0.00005, 'variance_total' => 5,
        ]);
        $epsilonRow->forceFill(['created_at' => now()->subMinute()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('invoiceVarianceItems', 2)
                // Newest first → the null-variance row leads.
                ->where('invoiceVarianceItems.0.description', 'بند بدون فرق كمية')
                ->where('invoiceVarianceItems.0.varianceQtyLabel', '—')
                ->where('invoiceVarianceItems.0.varianceQtyTone', 'text-muted')
                // 0.00005 is under the epsilon → muted, and Qty::format's two
                // decimals render it as a bare '0'.
                ->where('invoiceVarianceItems.1.description', 'بند بفرق مالي فقط')
                ->where('invoiceVarianceItems.1.varianceQtyLabel', '0')
                ->where('invoiceVarianceItems.1.varianceQtyTone', 'text-muted')
                ->where('invoiceVarianceItems.1.varianceTotalTone', 'text-danger fw-bold')
            );
    }

    /**
     * A clean install. Every panel empty, every count zero, and — the bit that
     * matters — «يحتاج إجراء» goes GREEN, not red. The Blade drove that off
     * `$actionCount > 0`; shipping it wrong makes an idle kitchen look on fire.
     */
    public function test_dashboard_on_an_empty_system_ships_zeroes_and_a_green_action_rail(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Dashboard')
                ->where('actionCount', 0)
                ->where('stats.tracked', 0)
                ->where('stats.healthy', 0)
                ->where('stats.stockValue', 0)
                ->where('statRail.1.color', 'success')
                ->where('statRail.2.color', 'muted')
                ->where('statRail.3.color', 'muted')
                ->where('statRail.5.color', 'info')
                ->where('statRail.6.color', 'muted')
                ->has('reorderQueue', 0)
                ->has('expiringBatches', 0)
                ->has('overduePurchaseOrders', 0)
                ->has('openPurchaseOrders', 0)
                ->has('supplierInvoiceQueue', 0)
                ->has('invoiceVarianceItems', 0)
                ->has('recentReceipts', 0)
                ->has('highWaste', 0)
            );
    }

    /** Owner "all branches" mode has no branch bound → the generic subtitle. */
    public function test_dashboard_without_a_branch_context_uses_the_system_wide_subtitle(): void
    {
        BranchContext::clear();

        $owner = $this->staff('super_admin', 'inv_owner');

        $this->actingAs($owner)
            ->withSession(['view_all_branches' => true])
            ->get(route('admin.inventory.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branchName', null)
                ->where('subtitle', 'مركز قرار سريع للمخزون والمشتريات عبر النظام')
            );
    }

    // ═════════════════ 2. Movements ledger (سجل الحركات) ═════════════════

    /**
     * The ledger's row shape. The badge map, both quantity ladders with their
     * 4-decimal tooltips, the waste-reason chip and the morph reference chip
     * were all resolved inside the Blade.
     */
    public function test_movements_index_flattens_rows_badges_and_reference_chips(): void
    {
        [$flour, $po, $poItem, $batch] = $this->seedMovements();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Inventory/Index')
                ->where('movements.total', 3)
                ->has('movements.data', 3)
                // latest('occurred_at') → the manual adjustment leads.
                ->where('movements.data.0.type', 'adjustment')
                ->where('movements.data.0.typeLabel', 'تعديل')
                ->where('movements.data.0.typeColor', 'secondary')
                ->where('movements.data.0.locationName', null)
                ->where('movements.data.0.batchLabel', null)
                ->where('movements.data.0.wasteReason', null)
                ->where('movements.data.0.reference.label', 'يدوي')
                ->where('movements.data.0.reference.url', null)
                ->where('movements.data.0.reference.icon', 'bi-pencil-square')
                ->where('movements.data.0.userName', '—')
                // A purchase line — the chip deep-links to the PO.
                ->where('movements.data.1.type', 'in')
                ->where('movements.data.1.typeLabel', 'إضافة')
                ->where('movements.data.1.typeColor', 'success')
                ->where('movements.data.1.reference.label', 'أمر شراء '.$po->number)
                ->where('movements.data.1.reference.url', route('admin.purchase-orders.show', $po))
                ->where('movements.data.1.reference.icon', 'bi-bag-check')
                ->where('movements.data.1.qtyDisplay', '5 كيلوغرام')
                ->where('movements.data.1.qtyTitle', '5,000.0000 g')
                ->where('movements.data.1.afterDisplay', '5 كيلوغرام')
                ->where('movements.data.1.userName', 'inv_admin')
                // Waste — red badge plus the reason chip beside it.
                ->where('movements.data.2.type', 'waste')
                ->where('movements.data.2.typeLabel', 'هدر')
                ->where('movements.data.2.typeColor', 'danger')
                ->where('movements.data.2.wasteReason.label', 'انتهت الصلاحية')
                ->where('movements.data.2.wasteReason.color', 'warning')
                ->where('movements.data.2.locationName', 'المخزن الرئيسي')
                ->where('movements.data.2.batchLabel', '#B-FLOUR')
                ->where('movements.data.2.ingredientName', 'طحين')
                ->where('movements.data.2.qtyDisplay', '250 غرام')
                ->where('movements.data.2.reason', 'تلف بعد انقطاع الكهرباء')
                // Qty::format, not Money::format — this column is unit-less.
                ->where('movements.data.2.costLabel', '12.5')
                ->where('stats.today', 3)
                ->where('stats.in', 1)
                ->where('stats.waste', 1)
                ->where('stats.adjustment', 1)
                ->where('stats.out', 0)
                ->where('stats.return', 0)
                ->where('hasFilters', false)
                ->where('urls.index', route('admin.inventory.index'))
            );
    }

    /**
     * The six filters are real query filters and they round-trip through the
     * query string, so a bookmarked lens reopens on the same rows.
     */
    public function test_movements_index_filters_narrow_the_ledger_and_echo_back(): void
    {
        $this->seedMovements();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', [
                'type' => 'waste',
                'storage_location_id' => $this->storage->id,
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.total', 1)
                ->has('movements.data', 1)
                ->where('movements.data.0.type', 'waste')
                ->where('hasFilters', true)
                ->where('filters.type', 'waste')
                ->where('filters.storage_location_id', (string) $this->storage->id)
                ->where('filters.from', now()->subDay()->toDateString())
                // The stat rail is deliberately unfiltered — it is a
                // "what happened today" strip, not a filtered subtotal.
                ->where('stats.today', 3)
            );
    }

    /** A source filter narrows by morph class; the option list ships flattened. */
    public function test_movements_index_reference_type_filter_and_option_lists(): void
    {
        [$flour] = $this->seedMovements();

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.index', [
                'reference_type' => PurchaseOrderItem::class,
                'ingredient_id' => $flour->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.total', 1)
                ->where('movements.data.0.type', 'in')
                ->has('options.types', 5)
                ->where('options.types.0', ['value' => 'in', 'label' => 'إضافة'])
                ->where('options.types.4', ['value' => 'return', 'label' => 'إرجاع'])
                ->has('options.ingredients', 1)
                ->where('options.ingredients.0', ['id' => $flour->id, 'name' => 'طحين'])
                ->has('options.storageLocations', 1)
                ->where('options.storageLocations.0', ['id' => $this->storage->id, 'name' => 'المخزن الرئيسي'])
                ->has('options.referenceTypes', 4)
                ->where('options.referenceTypes.0', [
                    'value' => PurchaseOrderItem::class, 'label' => 'مشتريات',
                ])
            );
    }

    /** The ledger paginates at 30 and ships Laravel's link collection. */
    public function test_movements_index_paginates_at_thirty(): void
    {
        $flour = $this->tracked('طحين', 'ING-P1', 0, 1, 0);

        for ($i = 0; $i < 32; $i++) {
            $this->movement($flour, 'in', 10, occurredAt: now()->subMinutes($i));
        }

        $this->actingAs($this->admin)
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements.total', 32)
                ->has('movements.data', 30)
                ->has('movements.links')
            );
    }

    // ═══════════════════════════ 3. The gate ════════════════════════════

    /**
     * Both screens sit behind `viewAny` on Ingredient. A waiter reaches the
     * admin shell but must not read the stock ledger or the purchasing board.
     */
    public function test_both_screens_403_without_inventory_view_any(): void
    {
        $waiter = $this->staffWithPermissions('waiter', 'inv_waiter', []);

        $this->actingAs($waiter)->get(route('admin.inventory.dashboard'))->assertForbidden();
        $this->actingAs($waiter)->get(route('admin.inventory.index'))->assertForbidden();
    }

    /** …and the explicit permission is enough — no admin role required. */
    public function test_a_permissioned_non_admin_can_open_both_screens(): void
    {
        $user = $this->staffWithPermissions('waiter', 'inv_keeper', ['inventory.viewAny']);

        $this->actingAs($user)->get(route('admin.inventory.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('admin.inventory.index'))->assertOk();
    }

    // ─────────────────────────── fixtures ───────────────────────────────

    /**
     * Five tracked ingredients spanning every bucket the dashboard sorts into,
     * plus one of each purchasing document.
     *
     * Note حليب and خيار get BATCHES: once an ingredient has batch history the
     * batch ledger becomes authoritative for USABLE stock (expired lots drop
     * out) while `value` still runs off the aggregate IngredientStock row.
     * خيار exists to pin exactly that split — expired lot, so it reads نافد
     * while still holding 50 of value.
     */
    protected function seedDashboard(): void
    {
        // Low: 100 on hand against a 500 threshold, at 2/unit.
        $flour = $this->tracked('طحين', 'ING-D01', 100, 2, 500, $this->supplier);
        // Out: nothing on hand, threshold 10, at 1/unit.
        $this->tracked('ملح', 'ING-D02', 0, 1, 10);
        // Healthy and expensive — the biggest slice of stock value.
        $this->tracked('سكر', 'ING-D03', 1000, 3, 100);

        // Healthy, batch-tracked, expiring in 3 days.
        $milk = $this->tracked('حليب', 'ING-D04', 50, 4, 0);
        $this->batch($milk, 'B-MILK', 50, 4, now()->addDays(3));

        // Batch-tracked and EXPIRED → usable 0 (نافد) but still 50 of value.
        $cucumber = $this->tracked('خيار', 'ING-D05', 10, 5, 0);
        $this->batch($cucumber, 'B-CUCUMBER', 10, 5, now()->subDay());

        // Overdue + open: sent, expected yesterday.
        PurchaseOrder::create([
            'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id,
            'number' => 'PO-OVERDUE-1', 'status' => 'sent',
            'subtotal' => 500, 'total' => 500,
            'expected_at' => now()->subDay()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        // Received but never invoiced → the "أغلق الحلقة" card.
        $received = PurchaseOrder::create([
            'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id,
            'number' => 'PO-RECEIVED-1', 'status' => 'received',
            'subtotal' => 300, 'total' => 300, 'received_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        PurchaseReceipt::create([
            'branch_id' => $this->branch->id,
            'number' => 'GRN-TEST-1', 'purchase_order_id' => $received->id,
            'supplier_id' => $this->supplier->id, 'received_by' => $this->admin->id,
            'received_at' => now(),
        ]);

        // Unpaid and already past due → red in both the rail and the queue.
        $invoice = SupplierInvoice::create([
            'number' => 'SI-INV-1', 'supplier_id' => $this->supplier->id,
            'subtotal' => 250, 'total' => 250, 'paid_total' => 0, 'balance' => 250,
            'status' => 'unpaid',
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
        ]);

        SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id, 'description' => 'طحين ٥٠ كغ',
            'quantity' => 50, 'unit_price' => 5, 'subtotal' => 250, 'total' => 250,
            'received_qty' => 48, 'variance_qty' => 2, 'variance_total' => 15,
        ]);

        $this->movement($flour, 'waste', 250, totalCost: 12.5);
    }

    /** Three movements, one per reference shape, on distinct timestamps. */
    protected function seedMovements(): array
    {
        $flour = $this->tracked('طحين', 'ING-M01', 5000, 1, 0);
        $batch = $this->batch($flour, 'B-FLOUR', 5000, 1, null);

        $po = PurchaseOrder::create([
            'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id,
            'number' => 'PO-MOVE-1', 'status' => 'received',
            'subtotal' => 5000, 'total' => 5000, 'created_by' => $this->admin->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $flour->id,
            'unit_id' => $this->gram->id, 'quantity_ordered' => 5000,
            'unit_price' => 1, 'subtotal' => 5000,
        ]);

        $this->movement($flour, 'waste', 250,
            occurredAt: now()->subHours(2), totalCost: 12.5,
            extra: [
                'storage_location_id' => $this->storage->id,
                'batch_id' => $batch->id,
                'waste_reason' => 'expired',
                'reason' => 'تلف بعد انقطاع الكهرباء',
                'user_id' => $this->admin->id,
            ]);

        $this->movement($flour, 'in', 5000,
            occurredAt: now()->subHour(), totalCost: 5000,
            extra: [
                'stock_after' => 5000,
                'reference_type' => PurchaseOrderItem::class,
                'reference_id' => $poItem->id,
                'user_id' => $this->admin->id,
            ]);

        // No reference, no user → the 'يدوي' chip and the '—' fallback.
        $this->movement($flour, 'adjustment', 5, occurredAt: now());

        return [$flour, $po, $poItem, $batch];
    }

    protected function tracked(
        string $name,
        string $sku,
        float $qty,
        float $cost,
        float $threshold,
        ?Supplier $supplier = null,
    ): Ingredient {
        $ing = Ingredient::create([
            'name' => $name, 'sku' => $sku, 'base_unit_id' => $this->gram->id,
            'supplier_id' => $supplier?->id,
            'current_stock' => $qty, 'reorder_threshold' => $threshold,
            'cost_per_unit' => $cost, 'track_stock' => true, 'active' => true,
        ]);

        IngredientStock::create([
            'ingredient_id' => $ing->id, 'storage_location_id' => $this->storage->id,
            'quantity' => $qty,
            // 0 here means "no per-location threshold" → the global one decides.
            'reorder_threshold' => 0,
        ]);

        return $ing;
    }

    protected function batch(
        Ingredient $ing,
        string $number,
        float $remaining,
        float $unitCost,
        ?\DateTimeInterface $expiry,
    ): IngredientBatch {
        return IngredientBatch::create([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ing->id,
            'storage_location_id' => $this->storage->id,
            'batch_number' => $number,
            'received_date' => now()->subDays(2)->toDateString(),
            'expiry_date' => $expiry?->format('Y-m-d'),
            'initial_qty' => $remaining, 'remaining_qty' => $remaining,
            'unit_cost' => $unitCost,
        ]);
    }

    protected function movement(
        Ingredient $ing,
        string $type,
        float $qty,
        ?\DateTimeInterface $occurredAt = null,
        float $totalCost = 0,
        array $extra = [],
    ): InventoryMovement {
        return InventoryMovement::create(array_merge([
            'branch_id' => $this->branch->id,
            'ingredient_id' => $ing->id,
            'type' => $type,
            'quantity' => $qty,
            'quantity_in_base' => $qty,
            'unit_id' => $ing->base_unit_id,
            'unit_cost' => 0,
            'total_cost' => $totalCost,
            'occurred_at' => $occurredAt ?? now(),
        ], $extra));
    }

    /** `users` has no branch_id — membership is primary_branch_id + the pivot. */
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

    /** A non-admin whose role grants exactly the listed permissions. */
    protected function staffWithPermissions(string $role, string $username, array $permissions): User
    {
        $user = $this->staff($role, $username);

        Role::where('name', $role)->first()
            ->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return $user;
    }
}
