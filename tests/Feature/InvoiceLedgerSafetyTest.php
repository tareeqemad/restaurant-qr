<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Guards two invoice/debt ledger fixes:
 *   #1  Cancelling an invoice that was re-posted after a post-issuance discount
 *       must reverse the LIVE entry (invoice_reissued_N), not blindly the original
 *       invoice_issued — otherwise A/R and revenue drift to phantom negatives.
 *   #14 payCustomerDebt must be atomic: an over-payment aborts with nothing posted.
 */
class InvoiceLedgerSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled',     false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'main', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $this->cashier = $this->makeCashier();

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'main-kitchen','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'Kitchen','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'mains','name'=>'Mains','default_station_id'=>$station->id,'active'=>true]);

        $ingredient = Ingredient::create([
            'sku' => 'ING-1', 'name' => 'Stock', 'base_unit_id' => $unit->id,
            'current_stock' => 200, 'reorder_threshold' => 0, 'cost_per_unit' => 1,
            'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id, 'storage_location_id' => $storage->id,
            'quantity' => 200, 'reorder_threshold' => 0,
        ]);

        $this->menuItem = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $station->id,
            'sku' => 'M-1', 'slug' => 'meal', 'name' => 'Meal', 'price' => 100, 'cost' => 10,
            'prep_time_minutes' => 5, 'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $this->menuItem->id, 'ingredient_id' => $ingredient->id,
            'quantity' => 1, 'unit_id' => $unit->id,
        ]);

        [$this->customer] = Customer::createFromCashier(
            name: 'زبون اختبار',
            phone: '0599000333',
            defaultBranchId: $this->branch->id,
        );
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** #1 — plain cancel (no discount) nets the invoice's ledger to zero. */
    public function test_cancelling_a_plain_invoice_nets_ledger_to_zero(): void
    {
        $this->actingAs($this->cashier);

        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->cancelInvoice($invoice, $this->cashier->id, 'إلغاء');

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(0.0, $this->netFor($invoice, '1100'), 'A/R must net to zero after cancel.');
        $this->assertSame(0.0, $this->netFor($invoice, '4000'), 'Revenue must net to zero after cancel.');
    }

    /** #1 — cancel AFTER a post-issuance discount repost must reverse the live repost. */
    public function test_cancelling_after_post_issuance_repost_nets_ledger_to_zero(): void
    {
        $this->actingAs($this->cashier);

        $invoice = $this->doVisit(total: 100);   // invoice_issued posted (DR 1100 100 / CR 4000 100)
        $acc = app(AccountingService::class);

        // Simulate the post-issuance discount flow: reverse the original issue
        // entry, drop the totals by a 20 discount, and re-post under
        // invoice_reissued_1 — exactly what OrderDiscountService does.
        $live = $acc->latestUnreversedInvoicePosting($invoice);
        $acc->reverseEntry($live, 'invoice_discount_repost_reversal', now(), 'عكس بسبب خصم لاحق');
        $invoice->update(['discount_total' => 20, 'total' => 80, 'balance' => 80]);
        $acc->repostInvoiceWithDiscount($invoice->fresh());

        // Sanity: there is now a live invoice_reissued_1 to reverse.
        $this->assertSame('invoice_reissued_1', $acc->latestUnreversedInvoicePosting($invoice->fresh())->event_type);

        // Cancel via the real path.
        app(BillingService::class)->cancelInvoice($invoice->fresh(), $this->cashier->id, 'إلغاء بعد الخصم');

        // Every invoice-sourced account must net to zero — no phantom residue.
        // BEFORE the fix, 1100 and 4000 each netted to −20 (double reverse of
        // invoice_issued + the repost left standing).
        $this->assertSame(0.0, $this->netFor($invoice, '1100'), 'A/R must net to zero.');
        $this->assertSame(0.0, $this->netFor($invoice, '4000'), 'Revenue must net to zero.');
        $this->assertSame(0.0, $this->netFor($invoice, '4090'), 'Sales-discount must net to zero.');
    }

    /** #14 — an over-payment on customer debt aborts atomically: nothing committed. */
    public function test_debt_overpayment_is_rejected_without_committing(): void
    {
        $this->actingAs($this->cashier);

        // Build a 60 debt: pay 40 of 100, park the remainder on account.
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 40.0, 'cash', $this->cashier->id);
        app(BillingService::class)->settleOnAccount($invoice, $this->cashier->id);
        $this->assertSame(60.0, (float) $invoice->fresh()->balance);

        $paymentsBefore = Payment::count();

        // Cashier fat-fingers 200 for a 60 debt. Must reject and commit NOTHING.
        try {
            app(BillingService::class)->payCustomerDebt(
                customer: $this->customer->refresh(),
                amount:   200.0,
                method:   'cash',
                userId:   $this->cashier->id,
            );
            $this->fail('Over-payment should have thrown.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/أكبر من إجمالي المستحق/', $e->getMessage());
        }

        // No allocation leaked through: balance unchanged, no new payment row.
        $this->assertSame(60.0, (float) $invoice->fresh()->balance,
            'Debt balance must be untouched after a rejected over-payment.');
        $this->assertSame($paymentsBefore, Payment::count(),
            'No payment may be committed when the debt payment is rejected.');
    }

    // ─── helpers ──────────────────────────────────────────────────────

    private function netFor(Invoice $invoice, string $code): float
    {
        $entries = JournalEntry::with('lines.account')
            ->where('source_type', $invoice::class)
            ->where('source_id', $invoice->id)
            ->get();

        $sum = 0.0;
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->account?->code === $code) {
                    $sum += (float) $line->debit - (float) $line->credit;
                }
            }
        }
        return round($sum, 2);
    }

    protected function doVisit(float $total, int $quantity = 1): Invoice
    {
        $table = Table::create([
            'number'   => (string) random_int(1, 9999),
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id, 'customer_id' => $this->customer->id,
            'cover_count' => 1, 'status' => 'active',
        ]);

        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->menuItem->id, 'quantity' => $quantity, 'modifier_ids' => [],
        ]]);
        app(OrderService::class)->approve($order, $this->cashier->id);

        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->cashier->id);
        $this->assertSame($total, (float) $invoice->total, 'Sanity: invoice total mismatch.');
        return $invoice;
    }

    protected function makeCashier(): User
    {
        $user = User::create([
            'name' => 'Cashier', 'username' => 'cashier_l',
            'password' => bcrypt('x'), 'status' => 'active',
            'primary_branch_id' => $this->branch->id, 'role' => 'cashier',
        ]);
        $user->branches()->attach($this->branch->id);
        return $user;
    }
}
