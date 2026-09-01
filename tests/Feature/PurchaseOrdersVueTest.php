<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Permission;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * أوامر الشراء — القائمة + التفاصيل on Inertia/Vue.
 *
 * The two screens the Wave-4 pass left behind. Three things are worth
 * pinning hard, because all three were computed inside the Blade template
 * and are therefore easy to lose in a port:
 *
 *  1. **Effective price.** The line's price on the details screen is the
 *     weighted average of what ACTUALLY arrived (Σ qty×price ÷ Σ qty), not
 *     what was ordered — it is the number that feeds the supplier invoice
 *     and the weighted-average cost. It falls back to the PO price only
 *     while nothing has been received.
 *  2. **The action gates.** BasePolicy::before hands owner-level users a
 *     blanket `true`, so the policy ALONE would show a super-admin every
 *     workflow button at once. Each `can.*` prop must AND the policy with
 *     the state guard, and the approve/send/receive chain must stay
 *     mutually exclusive.
 *  3. **The index receive button.** The Blade gated it on state only, so a
 *     viewer without `purchase_orders.receive` was shown a button the
 *     server answers with a 403.
 *
 * Assertions are on props (Inertia\Testing), never on markup.
 */
class PurchaseOrdersVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Branch $other;

    protected User $manager;

    protected Supplier $supplier;

    protected Ingredient $flour;

    protected Unit $gram;

    protected StorageLocation $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'pox', 'name' => 'فرع الشمال', 'is_active' => true, 'display_order' => 1]);
        $this->other  = Branch::create(['code' => 'poz', 'name' => 'فرع الجنوب', 'is_active' => true, 'display_order' => 2]);

        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'manager'], [
            'label' => 'manager',
            'is_system' => true,
        ]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->manager = $this->staff('manager', 'po_manager', $this->branch);

        $this->gram = Unit::create([
            'code' => 'g', 'name' => 'جرام', 'unit_type' => 'weight',
            'factor_to_base' => 1, 'is_base' => true,
        ]);
        $this->store = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 's', 'name' => 'المخزن',
            'is_default' => true, 'active' => true,
        ]);
        $this->supplier = Supplier::create(['name' => 'مورّد الطحين', 'active' => true]);
        $this->supplier->branches()->attach($this->branch->id);

        $this->flour = Ingredient::create([
            'name' => 'طحين', 'base_unit_id' => $this->gram->id, 'current_stock' => 0,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
            'supplier_id' => $this->supplier->id,
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

    protected function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    /** 1000 g @ 2 → total 2000. */
    protected function makePo(float $qty = 1000, float $price = 2, ?Supplier $supplier = null): PurchaseOrder
    {
        return $this->service()->create(
            data: ['supplier_id' => ($supplier ?? $this->supplier)->id, 'expected_at' => null, 'notes' => null],
            lines: [[
                'ingredient_id' => $this->flour->id,
                'unit_id' => $this->gram->id,
                'quantity_ordered' => $qty,
                'unit_price' => $price,
            ]],
            userId: $this->manager->id,
        );
    }

    /** Drive the PO to `sent` through the real transitions. */
    protected function sent(?PurchaseOrder $po = null): PurchaseOrder
    {
        $po = $po ?? $this->makePo();
        $this->service()->approve($po, $this->manager->id);
        $this->service()->send($po->refresh());

        return $po->refresh();
    }

    protected function index(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)
            ->get(route('admin.purchase-orders.index', $query));
    }

    protected function show(PurchaseOrder $po, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)
            ->get(route('admin.purchase-orders.show', $po));
    }

    // ── Index ────────────────────────────────────────────────────────

    public function test_the_index_renders_the_vue_page_with_flat_rows(): void
    {
        $po = $this->makePo();

        $this->index()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PurchaseOrders/Index')
                ->has('shell')
                ->has('pos.data', 1)
                ->has('pos.links')
                ->where('pos.total', 1)
                ->where('pos.data.0.number', $po->number)
                ->where('pos.data.0.supplierName', 'مورّد الطحين')
                ->where('pos.data.0.itemsCount', 1)
                ->where('pos.data.0.totalLabel', $po->formatMoney(2000))
                ->where('pos.data.0.statusLabel', 'مسودة')
                ->where('pos.data.0.statusColor', 'secondary')
                ->where('pos.data.0.createdAt', $po->created_at->format('Y-m-d'))
                ->where('pos.data.0.expectedAt', null)
                ->where('pos.data.0.urls.show', route('admin.purchase-orders.show', $po))
                ->has('statusOptions', 5)
                ->has('suppliers', 1)
                ->where('can.create', true)
                ->where('hasFilters', false)
                ->where('urls.create', route('admin.purchase-orders.create'))
            );
    }

    /**
     * `receivedValue()` / `outstandingValue()` used to be called from inside
     * the row loop. They price the RECEIVED quantity at the PO's own line
     * price, so a partial receipt splits the money in two.
     */
    public function test_a_row_carries_its_received_and_outstanding_money(): void
    {
        $po = $this->sent();
        $line = $po->items->first();

        $this->service()->receive($po, [$line->id => 400], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id],
        ]);

        $this->index()->assertInertia(fn (Assert $page) => $page
            ->where('pos.data.0.receivedLabel', Money::format(800))
            ->where('pos.data.0.receivedAny', true)
            ->where('pos.data.0.outstandingLabel', Money::format(1200))
            ->where('pos.data.0.outstandingOpen', true)
            ->where('pos.data.0.statusLabel', 'مستلم جزئياً')
        );
    }

    public function test_a_base_currency_po_ships_no_duplicate_equivalent(): void
    {
        $this->makePo();

        // Same currency as the accounting base → the "≈" line is pointless.
        $this->index()->assertInertia(fn (Assert $page) => $page
            ->where('pos.data.0.baseTotalLabel', null));
    }

    public function test_the_stat_rail_counts_by_status_and_totals_the_month(): void
    {
        $this->makePo();                       // draft
        $this->sent($this->makePo());          // sent
        $cancelled = $this->makePo();
        $this->service()->cancel($cancelled, 'تجربة', $this->manager->id);

        $this->index()->assertInertia(fn (Assert $page) => $page
            ->where('stats.draft', 1)
            ->where('stats.sent', 1)
            ->where('stats.partial', 0)
            // cancelled POs are excluded from the month's purchases.
            ->where('stats.thisMonth', fn ($v) => (float) $v === 4000.0)
            ->where('stats.thisMonthLabel', Money::format(4000))
        );
    }

    public function test_every_filter_narrows_the_list_and_echoes_back(): void
    {
        $draft = $this->makePo();
        $sent  = $this->sent($this->makePo());

        $second = Supplier::create(['name' => 'مورّد ثانٍ', 'active' => true]);
        $second->branches()->attach($this->branch->id);
        $other = $this->makePo(supplier: $second);

        $this->index(['status' => 'sent'])->assertInertia(fn (Assert $page) => $page
            ->has('pos.data', 1)
            ->where('pos.data.0.number', $sent->number)
            ->where('filters.status', 'sent')
            ->where('hasFilters', true)
        );

        $this->index(['supplier_id' => $second->id])->assertInertia(fn (Assert $page) => $page
            ->has('pos.data', 1)
            ->where('pos.data.0.number', $other->number)
            ->where('filters.supplier_id', (string) $second->id)
        );

        $this->index(['search' => substr($draft->number, -4)])
            ->assertInertia(fn (Assert $page) => $page
                ->has('pos.data', 1)
                ->where('pos.data.0.number', $draft->number)
            );

        // A window that starts tomorrow can't contain anything created today.
        $this->index(['from' => now()->addDay()->toDateString()])
            ->assertInertia(fn (Assert $page) => $page->has('pos.data', 0));

        $this->index(['to' => now()->toDateString()])
            ->assertInertia(fn (Assert $page) => $page->has('pos.data', 3));
    }

    /**
     * The Blade showed the receive shortcut on STATE alone. A viewer with
     * `purchase_orders.viewAny` but no `receive` grant was handed a button
     * the controller answers with a 403.
     */
    public function test_the_index_receive_shortcut_rides_on_the_policy_not_just_the_state(): void
    {
        $this->sent();

        $viewer = $this->staff('chef', 'po_viewer', $this->branch);
        $viewer->permissions()->attach(
            Permission::where('name', 'purchase_orders.viewAny')->value('id'),
            ['granted' => true]
        );

        $this->index([], $viewer->fresh())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pos.data.0.canReceive', false)
                ->where('can.create', false)
            );

        $this->index()->assertInertia(fn (Assert $page) => $page
            ->where('pos.data.0.canReceive', true));
    }

    public function test_the_list_is_scoped_to_the_active_branch(): void
    {
        $mine = $this->makePo();

        // The same supplier and manager may legitimately serve both
        // branches. The PO service must allow that explicit assignment,
        // while the active-branch list remains completely isolated.
        $this->manager->branches()->attach($this->other->id);
        $this->supplier->branches()->attach($this->other->id);
        BranchContext::set($this->other->id);
        $theirs = $this->service()->create(
            data: ['supplier_id' => $this->supplier->id],
            lines: [['ingredient_id' => $this->flour->id, 'unit_id' => $this->gram->id,
                     'quantity_ordered' => 5, 'unit_price' => 1]],
            userId: $this->manager->id,
        );
        BranchContext::set($this->branch->id);

        $this->index()->assertInertia(fn (Assert $page) => $page
            ->has('pos.data', 1)
            ->where('pos.data.0.number', $mine->number)
        );

        // …and the other branch's PO is not reachable by URL either.
        $blocked = $this->show($theirs);
        $this->assertNotSame(200, $blocked->getStatusCode(),
            'a PO from another branch must never render: got '
            .$blocked->getStatusCode().' → '.($blocked->headers->get('Location') ?? '—'));
    }

    public function test_viewany_is_enforced(): void
    {
        $chef = $this->staff('chef', 'po_nobody', $this->branch);

        $this->index([], $chef)->assertForbidden();
    }

    // ── Show ─────────────────────────────────────────────────────────

    public function test_the_show_page_renders_with_facts_lines_and_totals(): void
    {
        $po = $this->makePo();

        $this->show($po)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($po) {
                $page->component('Admin/PurchaseOrders/Show')
                    ->has('shell')
                    ->where('po.number', $po->number)
                    ->where('po.supplierName', 'مورّد الطحين')
                    ->where('po.statusLabel', 'مسودة')
                    ->where('po.railColor', 'muted')
                    ->where('po.approved', false)
                    ->where('po.itemsCount', 1)
                    ->where('po.totalLabel', $po->formatMoney(2000))
                    ->where('po.receiptsCount', 0)
                    ->where('po.invoicesCount', 0)
                    ->has('lines', 1)
                    ->where('lines.0.name', 'طحين')
                    ->where('lines.0.orderedLabel', '1000 g')
                    ->where('lines.0.receivedLabel', '0 g')
                    ->where('lines.0.receivedColor', 'secondary')
                    ->where('lines.0.invoicedLabel', '0 g')
                    ->where('lines.0.invoicedCovered', false)
                    ->where('lines.0.priceLabel', Money::format(2))
                    ->where('lines.0.priceChanged', false)
                    ->where('lines.0.hasReceipt', false)
                    ->where('lines.0.subtotalLabel', $po->formatMoney(2000))
                    ->where('lines.0.percentLabel', '0')
                    ->where('totals.match', true)
                    ->where('totals.effectiveLabel', Money::format(2000))
                    ->has('receipts', 0)
                    ->has('invoices', 0);

                // The facts panel drops every empty row — an unapproved,
                // unsent draft has no approver/sender/receiver lines.
                $labels = array_column($page->toArray()['props']['facts'], 'label');
                $this->assertSame(
                    ['رقم PO', 'المورد', 'الحالة', 'تاريخ الإنشاء', 'أنشأ بواسطة'],
                    $labels
                );
            });
    }

    /**
     * THE formula: the line price on this screen is the weighted average of
     * what actually arrived, and the grand total follows it — not the PO.
     */
    public function test_the_effective_price_is_the_weighted_average_of_the_receipts(): void
    {
        $po = $this->sent();
        $line = $po->items->first();

        // 400 g arrived at 3 (the PO said 2).
        $this->service()->receive($po, [$line->id => 400], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id, 'actual_unit_price' => 3.0],
        ]);
        $po->refresh();

        $this->show($po)->assertInertia(fn (Assert $page) => $page
            ->where('lines.0.priceLabel', Money::format(3))
            ->where('lines.0.priceChanged', true)
            ->where('lines.0.priceUp', true)
            ->where('lines.0.priceDeltaLabel', '+'.Money::format(1))
            ->where('lines.0.orderedPriceTitle', 'السعر في الـ PO الأصلي كان '.Money::format(2))
            ->where('lines.0.hasReceipt', true)
            // 400 × 3 — the receipt's money, not the PO's 2000.
            ->where('lines.0.subtotalLabel', $po->formatMoney(1200))
            ->where('lines.0.orderedSubtotalLabel', $po->formatMoney(2000))
            ->where('lines.0.receivedLabel', '400 g')
            ->where('lines.0.receivedColor', 'warning')
            ->where('lines.0.percent', fn ($v) => (float) $v === 40.0)
            ->where('lines.0.percentLabel', '40')
            // Grand total follows the receipts and flags the divergence.
            ->where('totals.match', false)
            ->where('totals.effectiveLabel', Money::format(1200))
            ->where('totals.orderedLabel', Money::format(2000))
            ->where('totals.deltaUp', false)
            ->where('totals.deltaLabel', $po->formatMoney(-800))
        );
    }

    public function test_a_receipt_at_the_ordered_price_reports_no_divergence(): void
    {
        $po = $this->sent();
        $line = $po->items->first();

        $this->service()->receive($po, [$line->id => 1000], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id],
        ]);
        $po->refresh();

        $this->show($po)->assertInertia(fn (Assert $page) => $page
            ->where('lines.0.priceChanged', false)
            ->where('lines.0.hasReceipt', true)
            ->where('lines.0.receivedColor', 'success')
            ->where('lines.0.percentLabel', '100')
            ->where('totals.match', true)
            ->where('po.statusLabel', 'مستلم')
            ->has('receipts', 1)
            ->where('receipts.0.itemsCount', 1)
            ->where('receipts.0.receiverName', 'po_manager')
        );
    }

    // ── Gates ────────────────────────────────────────────────────────

    public function test_a_draft_offers_approve_edit_and_cancel_only(): void
    {
        $po = $this->makePo();

        $this->show($po)->assertInertia(fn (Assert $page) => $page
            ->where('can.approve', true)
            ->where('can.send', false)
            ->where('can.receive', false)
            ->where('can.edit', true)
            ->where('can.cancel', true)
            ->where('can.invoice', false)
            ->where('can.fullyInvoiced', false)
            ->where('can.showStatusBadge', true)
        );
    }

    public function test_an_approved_draft_swaps_approve_for_send_and_locks_editing(): void
    {
        $po = $this->makePo();
        $this->service()->approve($po, $this->manager->id);

        $this->show($po->refresh())->assertInertia(fn (Assert $page) => $page
            ->where('can.approve', false)
            ->where('can.send', true)
            ->where('can.receive', false)
            // isEditable() is draft && !approved_at — approving locks it.
            ->where('can.edit', false)
            ->where('can.cancel', true)
            ->where('po.approved', true)
        );
    }

    public function test_a_sent_po_offers_receiving_and_relabels_it_on_a_partial(): void
    {
        $po = $this->sent();

        $this->show($po)->assertInertia(fn (Assert $page) => $page
            ->where('can.approve', false)
            ->where('can.send', false)
            ->where('can.receive', true)
            ->where('labels.receive', 'استلام البضاعة')
            ->where('can.invoice', false)
        );

        $line = $po->items->first();
        $this->service()->receive($po, [$line->id => 400], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id],
        ]);

        $this->show($po->refresh())->assertInertia(fn (Assert $page) => $page
            ->where('can.receive', true)
            ->where('labels.receive', 'استلام دفعة جديدة')
            // Goods have arrived → invoicing becomes available, but as a
            // secondary action while a receipt is still outstanding.
            ->where('can.invoice', true)
            ->where('invoicePrimary', false)
            ->where('labels.invoice', 'فاتورة المورد')
        );
    }

    public function test_a_fully_received_po_promotes_invoicing_and_forbids_cancelling(): void
    {
        $po = $this->sent();
        $line = $po->items->first();

        $this->service()->receive($po, [$line->id => 1000], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id],
        ]);

        $this->show($po->refresh())->assertInertia(fn (Assert $page) => $page
            ->where('can.receive', false)
            ->where('can.cancel', false)
            ->where('can.showStatusBadge', false)
            ->where('can.invoice', true)
            ->where('invoicePrimary', true)
            ->where('labels.invoice', 'تسجيل فاتورة المورد')
            ->where('can.fullyInvoiced', false)
        );
    }

    /**
     * Once every received unit is covered by a supplier-invoice line the CTA
     * must disappear, or the user keeps registering duplicate invoices.
     */
    public function test_a_fully_invoiced_po_swaps_the_cta_for_a_badge(): void
    {
        $po = $this->sent();
        $line = $po->items->first();

        $this->service()->receive($po, [$line->id => 1000], $this->manager->id, [
            $line->id => ['storage_location_id' => $this->store->id],
        ]);
        $po->refresh();

        $invoice = SupplierInvoice::create([
            'number' => 'SI-TEST-0001',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'currency_code' => $po->currency_code,
            'exchange_rate' => 1,
            'subtotal' => 2000, 'tax_total' => 0, 'total' => 2000,
            'paid_total' => 0, 'balance' => 2000,
            'status' => 'unpaid',
            'invoice_date' => now()->toDateString(),
        ]);
        SupplierInvoiceItem::create([
            'supplier_invoice_id' => $invoice->id,
            'purchase_order_item_id' => $line->id,
            'ingredient_id' => $this->flour->id,
            'unit_id' => $this->gram->id,
            'description' => 'طحين',
            'quantity' => 1000,
            'unit_price' => 2,
            'subtotal' => 2000,
            'total' => 2000,
        ]);

        $this->show($po)->assertInertia(fn (Assert $page) => $page
            ->where('can.invoice', false)
            ->where('can.fullyInvoiced', true)
            ->where('po.invoicesCount', 1)
            ->has('invoices', 1)
            ->where('invoices.0.number', 'SI-TEST-0001')
            ->where('invoices.0.totalLabel', $invoice->formatMoney(2000))
            ->where('invoices.0.balanceLabel', Money::format(2000))
            ->where('invoices.0.balanceOpen', true)
            ->where('invoices.0.statusLabel', 'غير مدفوعة')
            ->where('invoices.0.statusColor', 'danger')
            ->where('lines.0.invoicedLabel', '1000 g')
            ->where('lines.0.invoicedCovered', true)
        );
    }

    public function test_cancelling_still_requires_a_reason_and_surfaces_it(): void
    {
        $po = $this->makePo();

        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.cancel', $po), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->manager)
            ->post(route('admin.purchase-orders.cancel', $po), ['reason' => 'المورد اعتذر'])
            ->assertRedirect();

        $this->show($po->refresh())->assertInertia(fn (Assert $page) => $page
            ->where('po.statusLabel', 'ملغي')
            ->where('po.railColor', 'danger')
            ->where('po.cancelReason', 'المورد اعتذر')
            ->where('can.cancel', false)
            ->where('can.approve', false)
            ->where('can.edit', false)
        );
    }

    /**
     * The whole point of shipping gates as booleans: a user who may LOOK at
     * a PO must not be handed the workflow buttons the server would refuse.
     */
    public function test_a_view_only_user_gets_every_action_gate_closed(): void
    {
        $po = $this->makePo();

        $viewer = $this->staff('chef', 'po_readonly', $this->branch);
        foreach (['purchase_orders.viewAny', 'purchase_orders.view'] as $name) {
            $viewer->permissions()->attach(
                Permission::where('name', $name)->value('id'),
                ['granted' => true]
            );
        }

        $this->show($po, $viewer->fresh())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.approve', false)
                ->where('can.send', false)
                ->where('can.receive', false)
                ->where('can.edit', false)
                ->where('can.cancel', false)
            );
    }
}
