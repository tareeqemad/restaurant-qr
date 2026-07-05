<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderDiscountService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regressions for the confirmed cashier bugs found in the 2026-07 audit:
 *  - discount-after-refund must net out refunds (not silently close as paid)
 *  - discounts on a dine-in SESSION order must be blocked once the invoice is paid
 *  - settle-on-account must be idempotent (no double-count / re-close)
 *  - pay() must be idempotent against a double-submitted token
 */
class CashierHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'chd', 'name' => 'CHD', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'chd-admin', 'role' => 'admin',
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

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000777', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openSession(): TableSession
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        return TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
    }

    private function acctNet(string $code): float
    {
        $lines = \App\Models\JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))->get();
        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    /** @return array{0: \App\Models\Invoice, 1: TableSession, 2: \App\Models\Order} */
    private function issueMeal(): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
        return [$invoice, $session, $order];
    }

    /** #1 — a discount applied AFTER a partial refund must net out the refund, not
     *  close the invoice as 'paid' on the gross payment while money is still owed. */
    public function test_discount_after_partial_refund_keeps_balance_open(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();          // total 100

        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'صنف مرتجع', $this->admin->id);
        // net paid now 70, balance 30, status partially_paid.

        // Cashier comps 20 off → new total 80. Net paid 70 → still owes 10.
        app(OrderDiscountService::class)->applyToSession(
            $session->fresh(), ['type' => 'fixed', 'value' => 20, 'reason' => 'مجاملة'], $this->admin
        );

        $invoice->refresh();
        $this->assertSame('partially_paid', $invoice->status,
            'Gross-payment math would wrongly close this as paid; net math keeps it open.');
        $this->assertEqualsWithDelta(10.0, (float) $invoice->balance, 0.001, 'Still owes 80 − net-paid 70 = 10.');
    }

    /** #2a — a session discount cannot be applied once the invoice is paid/closed. */
    public function test_session_discount_blocked_after_invoice_paid(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);   // → paid

        $this->expectException(ValidationException::class);
        app(OrderDiscountService::class)->applyToSession(
            $session->fresh(), ['type' => 'fixed', 'value' => 20, 'reason' => 'مجاملة'], $this->admin
        );
    }

    /** #2b — removing a discount on a PAID session order is blocked. Before the fix
     *  Order::invoice() was null for session orders, so the guard was a no-op. */
    public function test_removing_discount_blocked_on_paid_session_order(): void
    {
        $this->actingAs($this->admin);
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);

        $created = app(OrderDiscountService::class)->applyToSession(
            $session->fresh(), ['type' => 'fixed', 'value' => 20, 'reason' => 'مجاملة'], $this->admin
        );
        $discount = $created[0];

        app(OrderService::class)->approve($order->fresh(), $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);   // total 80
        app(BillingService::class)->addPayment($invoice, 80.0, 'cash', $this->admin->id);           // → paid

        $this->expectException(ValidationException::class);
        app(OrderDiscountService::class)->remove($discount->fresh(), $this->admin);
    }

    /** #5 — settle-on-account is idempotent: a second call is refused. */
    public function test_settle_on_account_is_idempotent(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);   // partially_paid, balance 40

        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
        $this->assertNotNull($invoice->fresh()->settled_on_account_at);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/مؤجّلة كدين مسبقاً/u');
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
    }

    /** #6 — a double-submitted pay() with the same idempotency token posts ONCE. */
    public function test_pay_is_idempotent_against_double_submit(): void
    {
        [$invoice] = $this->issueMeal();
        $token = 'test-idem-token-abc';

        $this->actingAs($this->admin)
            ->post(route('admin.cashier.pay', $invoice), ['amount' => 40, 'method' => 'cash', '_idem' => $token])
            ->assertSessionHas('success');

        // Same token again (double-click / retried POST) → bounced, no second payment.
        $this->actingAs($this->admin)
            ->post(route('admin.cashier.pay', $invoice), ['amount' => 40, 'method' => 'cash', '_idem' => $token])
            ->assertSessionHas('info');

        $invoice->refresh();
        $this->assertSame(1, $invoice->payments()->count(), 'Duplicate token must not post a second payment.');
        $this->assertEqualsWithDelta(40.0, (float) $invoice->paid_total, 0.001);
    }

    /** Deferred-item #1 — a refund on a settle-on-account (customer-debt) invoice
     *  is refused: refunding would inflate the parked debt vs the GL A/R. */
    public function test_refund_blocked_on_settled_on_account_invoice(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);   // partial
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);   // park 40 as debt

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/مؤجّلة كدين/u');
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'مرتجع', $this->admin->id);
    }

    /** Voiding a mistaken payment reverses its ledger entry and reopens the invoice. */
    public function test_void_payment_reverses_ledger_and_reopens_invoice(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();                                  // total 100
        $payment = app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        $this->assertSame('paid', $invoice->fresh()->status);

        app(BillingService::class)->voidPayment($payment->fresh(), $this->admin->id, 'أُدخل مرتين');

        $invoice->refresh();
        $this->assertSame(0, $invoice->payments()->count(), 'The voided payment row is removed.');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->balance, 0.001, 'Invoice reopens fully unpaid.');
        $this->assertSame('issued', $invoice->status);

        // The payment_received entry AND its reversal are both posted (audit
        // trail kept in the ledger), and the cash account nets back to zero.
        $this->assertSame(2, \App\Models\JournalEntry::where('source_type', \App\Models\Payment::class)
            ->where('source_id', $payment->id)->count());
        $this->assertEqualsWithDelta(0.0, $this->acctNet('1000'), 0.001, 'Cash posting fully reversed.');
    }

    /** Void is refused when the invoice already has a completed refund. */
    public function test_void_payment_blocked_when_refund_exists(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        $payment = app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'صنف معاد', $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/استرداد/u');
        app(BillingService::class)->voidPayment($payment->fresh(), $this->admin->id, 'محاولة');
    }

    /** Void is refused on an invoice parked as customer debt. */
    public function test_void_payment_blocked_on_settled_invoice(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();
        $payment = app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/مؤجّلة كدين/u');
        app(BillingService::class)->voidPayment($payment->fresh(), $this->admin->id, 'محاولة');
    }

    /** Deferred-item #2 — a capped cashier cannot STACK within-cap discounts past
     *  the cumulative role ceiling (max of percent- and fixed-ceilings). */
    public function test_capped_cashier_cannot_stack_discounts_past_ceiling(): void
    {
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);
        $cashier = User::create([
            'name' => 'K', 'username' => 'chd-cashier', 'role' => 'cashier',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $cashier->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);

        // Subtotal 100, cashier cap 10% / fixed 5 → cumulative ceiling max(10,5)=10.
        // First 10% (=10) is exactly at the ceiling and allowed.
        app(OrderDiscountService::class)->applyToSession(
            $session->fresh(), ['type' => 'percent', 'value' => 10, 'reason' => 'ولاء'], $cashier
        );
        $this->assertEqualsWithDelta(10.0,
            (float) \App\Models\OrderDiscount::where('order_id', $order->id)->sum('amount'), 0.001);

        // A second within-cap 5% would push the cumulative to 15 > 10 → refused.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/يتجاوز حد دورك/u');
        app(OrderDiscountService::class)->applyToSession(
            $session->fresh(), ['type' => 'percent', 'value' => 5, 'reason' => 'إضافي'], $cashier
        );
    }
}
