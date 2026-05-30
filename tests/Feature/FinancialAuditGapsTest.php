<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\OrderService;
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
 *   G3 — Cash refunds during a shift weren't subtracted from
 *        expected_cash, surfacing as phantom shortages at close.
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

    // ───────────────────────────────────────────────────────────────
    // G3 — Cash refunds reduce expected_cash at shift close
    // ───────────────────────────────────────────────────────────────

    public function test_g3_cash_refund_is_subtracted_from_shift_expected_cash(): void
    {
        $shift = Shift::create([
            'branch_id'    => $this->branch->id,
            'user_id'      => $this->cashier->id,
            'status'       => 'open',
            'opened_at'    => now()->subHours(2),
            'cash_opening' => 200,
        ]);

        // Build an order so the invoice satisfies its origin invariant.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g3-'.uniqid(), 'status' => 'active',
            'opened_at' => now()->subHour(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        // Sanity: NO payment created — we only test the refund subtract.
        // Refund::method='cash' simulates cash handed back to the customer.
        Refund::create([
            'number'         => 'R-'.uniqid(),
            'branch_id'      => $this->branch->id,
            'invoice_id'     => Invoice::create([
                'branch_id' => $this->branch->id, 'order_id' => $order->id,
                'issued_by_user_id' => $this->cashier->id,
                'subtotal' => 50, 'discount_total' => 0, 'tax_total' => 0,
                'service_total' => 0, 'delivery_fee' => 0, 'tip' => 0,
                'total' => 50, 'balance' => 0, 'status' => 'paid',
                'issued_at' => now()->subHour(), 'paid_at' => now()->subHour(),
            ])->id,
            'shift_id'       => $shift->id,
            'amount'         => 30,
            'method'         => 'cash',
            'reason'         => 'wrong item',
            'created_by_user_id' => $this->cashier->id,
            'status'         => 'completed',
            'refunded_at'    => now(),
        ]);

        $this->actingAs($this->cashier)
            ->post(route('admin.shifts.close', $shift), [
                'cash_closing' => 170,   // 200 opening - 30 refund = 170 expected
            ])
            ->assertRedirect();

        $shift->refresh();
        $this->assertEqualsWithDelta(170.0, (float) $shift->expected_cash, 0.01,
            'Expected cash MUST subtract the 30 in cash refunds.');
        $this->assertEqualsWithDelta(0.0, (float) $shift->cash_variance, 0.01,
            'When closing cash matches expected, variance is zero — no phantom shortage.');
    }
}
