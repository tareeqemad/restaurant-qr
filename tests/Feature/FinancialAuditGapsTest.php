<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\OrderService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the three financial-audit gaps that surfaced in
 * the comprehensive money-flow audit:
 *
 *   G1 — Invoice::promoSavings was returning 0 for dine-in invoices
 *        (the default for tables) because it only walked invoice.order
 *        instead of session.orders.
 *   G2 — Post-issuance cashier discount changes never re-posted the
 *        journal entry, so A/R stayed inflated by the discount amount.
 *   G4 — Approved non-cash expenses were deletable even after posting
 *        their accounting entry.
 *   G5 — Posted supplier invoices were deletable instead of being
 *        cancelled through the reversing-entry flow.
 */
class FinancialAuditGapsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected MenuItem $burger;
    protected Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'audit', 'name' => 'Audit', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);

        $this->cashier = User::create([
            'name' => 'C', 'username' => 'ctest', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->cashier->branches()->attach($this->branch->id, ['is_primary' => true]);

        $g = Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $cat = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);
        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B', 'slug' => 'b', 'name' => 'Burger',
            'price' => 30, 'is_available' => true,
        ]);
        $this->table = Table::create(['number' => 'T', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────────
    // G1 — Dine-in invoices surface their promo savings
    // ───────────────────────────────────────────────────────────────

    public function test_g1_dine_in_invoice_picks_up_promo_savings_from_session_orders(): void
    {
        // Live 25% off on burger (30 → 22.5).
        MenuPromotion::create([
            'branch_id'   => null,
            'name'        => 'Lunch deal',
            'type'        => 'percent', 'value' => 25,
            'target_type' => 'menu_item', 'target_id' => $this->burger->id,
            'active'      => true, 'priority' => 0,
        ]);

        // Build a dine-in session with one order through it.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g1-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 2,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 4, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        // Issue the invoice tied to the SESSION, not the order_id (the
        // exact pattern that used to zero out promoSavings).
        $invoice = Invoice::create([
            'branch_id'         => $this->branch->id,
            'table_session_id'  => $session->id,
            // order_id intentionally null — this is the dine-in shape.
            'issued_by_user_id' => $this->cashier->id,
            'subtotal'          => 90,     // 4 × 22.5
            'discount_total'    => 0,
            'tax_total'         => 0,
            'service_total'     => 0,
            'delivery_fee'      => 0,
            'tip'               => 0,
            'total'             => 90,
            'balance'           => 90,
            'status'            => 'issued',
            'issued_at'         => now(),
        ]);

        $this->assertEqualsWithDelta(
            30.0,
            $invoice->promoSavings(),
            0.01,
            'Pre-G1 fix returned 0 here — the savings were silent.'
        );

        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);
        $entry->load('lines.account');
        $rev  = $entry->lines->first(fn ($l) => $l->account->code === '4000');
        $disc = $entry->lines->first(fn ($l) => $l->account->code === '4090');
        $this->assertEqualsWithDelta(120.0, (float) $rev->credit, 0.01,
            'Dine-in revenue is now grossed up correctly.');
        $this->assertEqualsWithDelta(30.0, (float) $disc->debit, 0.01,
            'Sales discount picks up the savings on dine-in too.');
    }

    // ───────────────────────────────────────────────────────────────
    // G2 — Post-issuance discount triggers reverse + repost
    // ───────────────────────────────────────────────────────────────

    public function test_g2_post_issuance_discount_reverses_and_reposts_journal(): void
    {
        // Build a real order so the invariant on Invoice (must have
        // table_session_id XOR order_id) holds.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g2-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        // Issue an invoice with no discount first.
        $invoice = Invoice::create([
            'branch_id'         => $this->branch->id,
            'order_id'          => $order->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal'          => 100,
            'discount_total'    => 0,
            'tax_total'         => 0,
            'service_total'     => 0,
            'delivery_fee'      => 0,
            'tip'               => 0,
            'total'             => 100,
            'balance'           => 100,
            'status'            => 'issued',
            'issued_at'         => now(),
        ]);
        app(AccountingService::class)->recordInvoiceIssued($invoice);

        // Simulate a cashier-applied 10% discount AFTER issuance by
        // calling the protected writer indirectly through the service's
        // public toll: we use the service's existing flow to write
        // updated totals.
        $service = app(\App\Services\OrderDiscountService::class);
        $reflection = new \ReflectionClass($service);
        $writer = $reflection->getMethod('writeInvoiceTotals');
        $writer->setAccessible(true);
        $writer->invoke($service, $invoice, [
            'subtotal'       => 100,
            'discount_total' => 10,
            'tax_total'      => 0,
            'service_total'  => 0,
            'delivery_fee'   => 0,
            'tip'            => 0,
            'total'          => 90,
        ]);

        $entries = JournalEntry::where('source_id', $invoice->id)
            ->where('source_type', Invoice::class)
            ->orderBy('id')
            ->with('lines.account')
            ->get();

        $this->assertGreaterThanOrEqual(3, $entries->count(),
            'Expect at least: original, reversal, repost.');

        $reversal = $entries->firstWhere('event_type', 'invoice_discount_repost_reversal');
        $repost   = $entries->firstWhere('event_type', 'invoice_reissued_1');
        $this->assertNotNull($reversal, 'Reversal entry must exist after discount update.');
        $this->assertNotNull($repost,   'Repost entry must exist with corrected amounts.');

        $repostDisc = $repost->lines->first(fn ($l) => $l->account->code === '4090');
        $this->assertEqualsWithDelta(10.0, (float) $repostDisc->debit, 0.01,
            'Repost picks up the new 10 discount.');

        $repostRcv = $repost->lines->first(fn ($l) => $l->account->code === '1100');
        $this->assertEqualsWithDelta(90.0, (float) $repostRcv->debit, 0.01,
            'Repost receivable matches the new total (post-discount).');
    }

    public function test_g2_multiple_post_issuance_discounts_reverse_the_latest_repost_only(): void
    {
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g2b-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 100,
            'balance' => 100,
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        app(AccountingService::class)->recordInvoiceIssued($invoice);

        $service = app(\App\Services\OrderDiscountService::class);
        $reflection = new \ReflectionClass($service);
        $writer = $reflection->getMethod('writeInvoiceTotals');
        $writer->setAccessible(true);

        $writer->invoke($service, $invoice, [
            'subtotal' => 100, 'discount_total' => 10, 'tax_total' => 0,
            'service_total' => 0, 'delivery_fee' => 0, 'tip' => 0, 'total' => 90,
        ]);
        $writer->invoke($service, $invoice->fresh(), [
            'subtotal' => 100, 'discount_total' => 20, 'tax_total' => 0,
            'service_total' => 0, 'delivery_fee' => 0, 'tip' => 0, 'total' => 80,
        ]);

        $entries = JournalEntry::where('source_id', $invoice->id)
            ->where('source_type', Invoice::class)
            ->orderBy('id')
            ->with('lines.account')
            ->get();

        $reversedIds = $entries
            ->map(fn (JournalEntry $entry) => (int) ($entry->metadata['reverses_entry_id'] ?? 0))
            ->filter()
            ->all();
        $activePostings = $entries
            ->filter(fn (JournalEntry $entry) => $entry->event_type === 'invoice_issued'
                || preg_match('/^invoice_reissued_\d+$/', (string) $entry->event_type))
            ->reject(fn (JournalEntry $entry) => in_array((int) $entry->id, $reversedIds, true))
            ->values();

        $this->assertCount(1, $activePostings, 'Only the latest corrected invoice posting should remain unreversed.');
        $this->assertSame('invoice_reissued_2', $activePostings->first()->event_type);

        $netByCode = [];
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $code = $line->account->code;
                $netByCode[$code] = ($netByCode[$code] ?? 0)
                    + (float) $line->debit
                    - (float) $line->credit;
            }
        }

        $this->assertEqualsWithDelta(80.0, $netByCode['1100'] ?? 0, 0.01, 'Net A/R must match the latest invoice total.');
        $this->assertEqualsWithDelta(20.0, $netByCode['4090'] ?? 0, 0.01, 'Net discounts must match the latest discount.');
        $this->assertEqualsWithDelta(-100.0, $netByCode['4000'] ?? 0, 0.01, 'Net sales revenue should stay at gross sales.');
    }

    public function test_g4_approved_non_cash_expense_cannot_be_deleted_after_accounting_post(): void
    {
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $admin = User::create([
            'name' => 'Admin', 'username' => 'admin-expense-audit', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $admin->branches()->attach($this->branch->id, ['is_primary' => true]);

        $category = Lookup::create([
            'group' => 'expense_categories',
            'code' => 'audit',
            'label' => 'Audit',
            'is_active' => true,
            'is_system' => true,
        ]);

        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $category->id,
            'description' => 'Bank fee',
            'amount' => 25,
            'payment_method' => 'bank_transfer',
            'expense_date' => now()->toDateString(),
            'status' => 'approved',
            'created_by_user_id' => $admin->id,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
        ]);
        app(AccountingService::class)->recordExpenseApproved($expense);

        $this->actingAs($admin)
            ->delete(route('admin.expenses.destroy', $expense))
            ->assertForbidden();

        $this->assertFalse($expense->fresh()->trashed(), 'Posted approved expenses must remain visible for audit.');
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Expense::class,
            'source_id' => $expense->id,
            'event_type' => 'expense_approved',
        ]);
    }

    public function test_g5_posted_supplier_invoice_must_be_cancelled_not_deleted(): void
    {
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $admin = User::create([
            'name' => 'Admin', 'username' => 'admin-supplier-audit', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $admin->branches()->attach($this->branch->id, ['is_primary' => true]);

        $supplier = Supplier::create(['name' => 'Audit Supplier', 'active' => true]);
        $supplier->branches()->attach($this->branch->id);

        $invoice = app(SupplierInvoiceService::class)->create([
            'number' => 'AUD-SUP-'.uniqid(),
            'supplier_id' => $supplier->id,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'invoice_date' => now()->toDateString(),
            'lines' => [[
                'description' => 'Cleaning service',
                'quantity' => 1,
                'unit_price' => 100,
                'tax_total' => 0,
            ]],
        ], $admin->id);

        $this->actingAs($admin)
            ->delete(route('admin.supplier-invoices.destroy', $invoice))
            ->assertForbidden();

        $this->assertFalse($invoice->fresh()->trashed(), 'Posted supplier invoices must remain available for cancellation/reversal.');
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => $invoice::class,
            'source_id' => $invoice->id,
            'event_type' => 'supplier_invoice_created',
        ]);
    }
}
