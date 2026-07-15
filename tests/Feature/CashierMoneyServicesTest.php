<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
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
use App\Models\Scopes\BranchScope;
use App\Models\Shift;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Regressions for the money-services fixes of the 2026-07 cashier audit:
 *  - shift X/Z aggregates must sum by shift_id UNSCOPED (cross-branch money
 *    physically sitting in the drawer counted as phantom variance before)
 *  - voiding a split-tab payment must un-mark the split it settled
 *  - voiding is refused once the payment's drawer closed (frozen Z-report)
 *  - credit-limit + FIFO debt collection are CUSTOMER-global, not per-branch
 *  - un-parking a settle-on-account is flag-only and blocked once touched
 *  - a prepaid takeaway stays on its kitchen lifecycle until actually served
 */
class CashierMoneyServicesTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Branch $branchB;
    protected User $admin;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'cms', 'name' => 'CMS', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'cmsb', 'name' => 'CMS-B', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'cms-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>500,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>500,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000999', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openShift(float $opening): Shift
    {
        return Shift::create([
            'user_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'cash_opening' => $opening, 'status' => 'open', 'opened_at' => now(),
        ]);
    }

    /** @return array{0: Invoice, 1: TableSession, 2: \App\Models\Order} */
    private function issueMeal(): array
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        $session = TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
        return [$invoice, $session, $order];
    }

    /**
     * A bare invoice living on branch B (no order/kitchen ceremony) —
     * enough surface for the debt-ledger and shift-aggregate assertions.
     * Number passed explicitly so the fixture never races the generator.
     */
    private function makeBranchBInvoice(float $total, array $extra = []): Invoice
    {
        return BranchContext::forBranch($this->branchB->id, function () use ($total, $extra) {
            $table = Table::create(['number'=>'B-'.random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
            $session = TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);

            return Invoice::create(array_merge([
                'number'            => 'INV-TESTB-'.uniqid(),
                'branch_id'         => $this->branchB->id,
                'table_session_id'  => $session->id,
                'customer_id'       => $this->customer->id,
                'issued_by_user_id' => $this->admin->id,
                'subtotal'          => $total,
                'discount_total'    => 0,
                'tax_total'         => 0,
                'service_total'     => 0,
                'delivery_fee'      => 0,
                'tip'               => 0,
                'total'             => $total,
                'balance'           => $total,
                'status'            => 'issued',
                'issued_at'         => now(),
            ], $extra));
        });
    }

    /** Cross-branch money physically in the drawer shows up in the X-report
     *  AND the close, keyed by shift_id alone — no phantom variance. */
    public function test_shift_sums_include_cross_branch_payments_bound_to_the_drawer(): void
    {
        $this->actingAs($this->admin);
        $shift = $this->openShift(100.0);

        // A branch-B invoice paid into THIS drawer (payments carry the
        // INVOICE's branch, the drawer keeps the shift_id).
        $invoiceB = $this->makeBranchBInvoice(80.0);
        BranchContext::forBranch($this->branchB->id, function () use ($invoiceB, $shift) {
            Payment::create([
                'branch_id'           => $this->branchB->id,
                'invoice_id'          => $invoiceB->id,
                'method'              => 'cash',
                'amount'              => 80,
                'received_by_user_id' => $this->admin->id,
                'shift_id'            => $shift->id,
                'paid_at'             => now(),
            ]);
        });

        // Viewer stands on branch A — the X-report must still see the 80.
        $resp = $this->actingAs($this->admin)->get(route('admin.shifts.x-report', $shift));
        $resp->assertOk();
        $b = $resp->viewData('breakdown');
        $this->assertEqualsWithDelta(80.0, $b['cash_sales'], 0.001, 'Branch scope must not zero the drawer sums.');
        $this->assertEqualsWithDelta(180.0, $b['expected_cash'], 0.001, 'opening 100 + cross-branch cash 80');

        // Closing with the true count stores the same sums and no variance.
        $this->actingAs($this->admin)
            ->post(route('admin.shifts.close', $shift), ['cash_closing' => 180])
            ->assertSessionHas('success');

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertEqualsWithDelta(80.0, (float) $shift->cash_sales, 0.001);
        $this->assertEqualsWithDelta(180.0, (float) $shift->expected_cash, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $shift->cash_variance, 0.001, 'No phantom variance posted for cross-branch money.');
    }

    /** Voiding a split-tab payment un-marks the split it settled so the tab
     *  can be re-collected — it must not stay «مدفوع» over deleted money. */
    public function test_void_payment_unmarks_the_split_it_settled(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();                          // total 100

        app(BillingService::class)->splitInvoice($invoice->fresh(), [
            ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
            ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
        ]);
        $splits = $invoice->fresh()->splits()->orderBy('id')->get();
        $payment = app(BillingService::class)->paySplit($splits[0], $this->admin->id);
        $this->assertTrue($splits[0]->fresh()->paid, 'Sanity: paySplit marks the split.');

        app(BillingService::class)->voidPayment($payment->fresh(), $this->admin->id, 'أُدخلت مرتين');

        $this->assertFalse($splits[0]->fresh()->paid, 'The settled split must reopen with its payment.');
        $this->assertNull($splits[0]->fresh()->paid_at);
        $this->assertFalse($splits[1]->fresh()->paid, 'The untouched split stays untouched.');

        $invoice->refresh();
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertEqualsWithDelta(100.0, (float) $invoice->balance, 0.001, 'Invoice fully reopens.');
    }

    /** Void is refused once the payment's drawer closed — the stored Z-report
     *  and any variance GL entry already counted this payment. */
    public function test_void_payment_blocked_after_drawer_closed(): void
    {
        $this->actingAs($this->admin);
        $shift = $this->openShift(0.0);
        [$invoice] = $this->issueMeal();
        $payment = app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        $this->assertSame($shift->id, $payment->shift_id, 'Sanity: payment bound to the open drawer.');

        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/أُغلق/u');
        app(BillingService::class)->voidPayment($payment->fresh(), $this->admin->id, 'محاولة متأخرة');
    }

    /** Credit-limit check sums the customer's debt across ALL branches —
     *  a debtor maxed out at branch B can't keep borrowing at branch A. */
    public function test_credit_limit_counts_debt_across_branches(): void
    {
        $this->actingAs($this->admin);
        $this->customer->update(['credit_limit' => 100]);

        // 80 already parked as debt on branch B.
        $this->makeBranchBInvoice(80.0, [
            'status'                        => 'partially_paid',
            'settled_on_account_at'         => now()->subDay(),
            'settled_on_account_by_user_id' => $this->admin->id,
        ]);

        // Branch A visit: 100 total, 60 collected, tries to park 40.
        [$invoice] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);

        // 80 (branch B) + 40 (here) = 120 > limit 100 → refused, even though
        // the viewer stands on branch A where only 40 is visible.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/الحد الائتماني/u');
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
    }

    /** FIFO debt collection reaches debts parked on other branches. */
    public function test_fifo_debt_payment_collects_cross_branch_debt(): void
    {
        $this->actingAs($this->admin);
        $debtB = $this->makeBranchBInvoice(80.0, [
            'status'                        => 'partially_paid',
            'settled_on_account_at'         => now()->subDay(),
            'settled_on_account_by_user_id' => $this->admin->id,
        ]);

        $allocations = app(BillingService::class)->payCustomerDebt($this->customer, 30.0, 'cash', $this->admin->id);

        $this->assertCount(1, $allocations);
        $this->assertSame($debtB->id, $allocations[0]['invoice_id'], 'The branch-B debt must be reachable from branch A.');

        $fresh = Invoice::withoutGlobalScope(BranchScope::class)->findOrFail($debtB->id);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->balance, 0.001, '80 − 30 collected.');
    }

    /**
     * outstandingDebt() must read GLOBALLY so the debt board, the credit
     * preview, and the FIFO collector all agree. A viewer pinned to branch A
     * must still see a debt parked at branch B — otherwise the payment lands
     * on an invoice the cashier can't see and the balance appears to vanish.
     */
    public function test_outstanding_debt_is_global_regardless_of_active_branch(): void
    {
        $this->actingAs($this->admin);

        $this->makeBranchBInvoice(80.0, [
            'status'                        => 'partially_paid',
            'settled_on_account_at'         => now()->subDay(),
            'settled_on_account_by_user_id' => $this->admin->id,
        ]);

        // Viewer is on branch A (setUp binds it) — the branch-B debt is not
        // in this branch, yet the customer's debt total must include it.
        $this->assertEqualsWithDelta(80.0, $this->customer->fresh()->outstandingDebt(), 0.001,
            'A branch-B debt must count while the cashier stands on branch A.');
    }

    /** Un-park happy path: flag-only reversal — balance/status untouched,
     *  session stays closed, and NOT ONE journal entry is posted. */
    public function test_unpark_clears_flag_without_gl_entries(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();                 // 100
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
        $this->assertNotNull($invoice->fresh()->settled_on_account_at);

        $entriesBefore = JournalEntry::count();

        app(BillingService::class)->unparkSettleOnAccount($invoice->fresh(), $this->admin->id);

        $invoice->refresh();
        $this->assertNull($invoice->settled_on_account_at, 'The debt flag is cleared.');
        $this->assertNull($invoice->settled_on_account_by_user_id);
        $this->assertSame('partially_paid', $invoice->status, 'Status untouched — the invoice is a normal open ticket again.');
        $this->assertEqualsWithDelta(40.0, (float) $invoice->balance, 0.001, 'Balance untouched.');
        $this->assertStringContainsString('[unpark]', (string) $invoice->notes);

        $this->assertSame($entriesBefore, JournalEntry::count(), 'Un-park is flag-only: parking posted no GL, neither does its reversal.');
        $this->assertSame('closed', $session->fresh()->status, 'The freed table is NOT re-seated by the un-park.');
        $this->assertDatabaseHas('activity_logs', ['event' => 'invoice.unparked_on_account']);
    }

    /** Un-park is refused once a debt payment landed after parking. */
    public function test_unpark_blocked_after_debt_payment_landed(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);

        // The customer comes back later and pays toward the debt.
        $this->travel(2)->minutes();
        app(BillingService::class)->payCustomerDebt($this->customer, 10.0, 'cash', $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/بعد تأجيله/u');
        app(BillingService::class)->unparkSettleOnAccount($invoice->fresh(), $this->admin->id);
    }

    /** Un-park requires an actually-parked invoice. */
    public function test_unpark_requires_a_parked_invoice(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ليست مؤجّلة/u');
        app(BillingService::class)->unparkSettleOnAccount($invoice->fresh(), $this->admin->id);
    }

    /** A takeaway paid in FULL up-front keeps its kitchen lifecycle (the
     *  ticket must not vanish from the KDS), then auto-completes the moment
     *  the last line is served. */
    public function test_prepaid_takeaway_stays_live_until_served_then_completes(): void
    {
        $this->actingAs($this->admin);

        $order = app(OrderService::class)->createCashierOrder(
            null, $this->branch, 'takeaway', 'phone',
            [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]],
            [], $this->admin->id,
        );
        app(OrderService::class)->approve($order->fresh(), $this->admin->id);

        $invoice = app(BillingService::class)->issueInvoiceForOrder($order->fresh(), $this->admin->id);
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);

        $this->assertSame('paid', $invoice->fresh()->status, 'The invoice settles immediately.');
        $order->refresh();
        $this->assertSame(OrderStatus::Approved->value, $order->status,
            'Paid-but-uncooked ticket must stay on its kitchen lifecycle, not jump to completed.');

        // Kitchen cooks and hands it over → NOW the ticket closes.
        $item = $order->items()->first();
        app(OrderService::class)->startPreparing($item, $this->admin->id);
        app(OrderService::class)->markItemReady($item->fresh());
        app(OrderService::class)->markItemServed($item->fresh(), $this->admin->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Completed->value, $order->status, 'Serving the last line completes the prepaid ticket.');
        $this->assertNotNull($order->completed_at);
    }
}
