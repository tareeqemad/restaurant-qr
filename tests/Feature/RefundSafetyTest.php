<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAdvanceTransaction;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\JournalLine;
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
use App\Services\CreditNoteService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Guards the refund-safety fixes:
 *   #2  addPayment must measure balance against NET payments (paid − refunded)
 *       so an invoice can never close "paid" while net cash collected < total.
 *   #13 A written-off / cancelled invoice can no longer be refunded (which used
 *       to resurrect it on the debt board with a balance the ledger no longer held).
 */
class RefundSafetyTest extends TestCase
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
            phone: '0599000222',
            defaultBranchId: $this->branch->id,
        );
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** A return reduces the sale and pays the resulting customer credit; it does not create a fake debt. */
    public function test_partial_refund_reduces_adjusted_sale_without_reopening_invoice_balance(): void
    {
        $this->actingAs($this->cashier);

        // Pay the 100 invoice in full → paid.
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->cashier->id);
        $this->assertSame('paid', $invoice->fresh()->status);

        // Refund 30 → both the sale and net collected become 70.
        app(RefundService::class)->issue($invoice->fresh(), 30.0, 'cash', 'صنف معاد', $this->cashier->id);
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(30.0, (float) $invoice->credited_total);
        $this->assertSame(30.0, (float) $invoice->refunded_total);
        $this->assertSame(70.0, $invoice->adjustedTotal());
        $this->assertSame(70.0, $invoice->netPaid());

        $this->expectException(\RuntimeException::class);
        app(BillingService::class)->addPayment($invoice->fresh(), 10.0, 'cash', $this->cashier->id);
    }

    public function test_pending_refunds_reserve_capacity_and_complete_without_over_refund(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->cashier->id);

        $first = app(RefundService::class)->issue($invoice->fresh(), 60, 'original', 'إرجاع أول', $this->cashier->id, ['status' => 'pending']);
        try {
            app(RefundService::class)->issue($invoice->fresh(), 41, 'original', 'تجاوز', $this->cashier->id, ['status' => 'pending']);
            $this->fail('A second pending refund must not exceed the reserved capacity.');
        } catch (ValidationException) {
            $this->assertSame(1, \App\Models\Refund::query()->where('status', 'pending')->count());
        }

        $second = app(RefundService::class)->issue($invoice->fresh(), 40, 'original', 'إرجاع ثان', $this->cashier->id, ['status' => 'pending']);
        app(RefundService::class)->complete($first, $this->cashier->id);
        app(RefundService::class)->complete($second, $this->cashier->id);

        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->credited_total);
        $this->assertSame(100.0, (float) $invoice->refunded_total);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(100.0, (float) $invoice->refunds()->where('status', 'completed')->sum('amount'));

        $ar = Account::query()->where('code', '1100')->firstOrFail();
        $netAr = JournalLine::query()->where('account_id', $ar->id)->sum('debit')
            - JournalLine::query()->where('account_id', $ar->id)->sum('credit');
        $this->assertEqualsWithDelta(0, $netAr, 0.001);
    }

    public function test_completed_refund_can_be_reversed_without_deleting_its_document(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 100, 'cash', $this->cashier->id);
        $refund = app(RefundService::class)->issue($invoice->fresh(), 30, 'cash', 'خطأ بالتجهيز', $this->cashier->id);

        app(RefundService::class)->reverse($refund, $this->cashier->id, 'تم تسجيل الاسترداد على الفاتورة الخطأ');

        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('credit_notes', ['id' => $refund->credit_note_id, 'status' => 'reversed']);
        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->credited_total);
        $this->assertSame(0.0, (float) $invoice->refunded_total);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_original_method_splits_refund_across_the_actual_payments(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 40, 'cash', $this->cashier->id);
        app(BillingService::class)->addPayment($invoice->fresh(), 60, 'card', $this->cashier->id);

        $refund = app(RefundService::class)->issue($invoice->fresh(), 70, 'original', 'إرجاع إلى الدفعات الأصلية', $this->cashier->id);
        $allocations = $refund->allocations()->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method');

        $this->assertSame('mixed', $refund->method);
        $this->assertSame(40.0, (float) $allocations['cash']);
        $this->assertSame(30.0, (float) $allocations['card']);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance);
    }

    public function test_refund_to_customer_balance_updates_subledger_and_reverses_safely(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 100, 'cash', $this->cashier->id);

        $refund = app(RefundService::class)->issue($invoice->fresh(), 30, 'customer_advance', 'إضافة المرتجع لرصيد الزبون', $this->cashier->id);
        $this->assertSame(30.0, (float) $this->customer->fresh()->advance_balance);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $this->customer->id,
            'refund_id' => $refund->id,
            'type' => CustomerAdvanceTransaction::REFUND_CREDIT,
            'amount' => 30,
        ]);

        app(RefundService::class)->reverse($refund, $this->cashier->id, 'اختبار عكس رصيد الاسترداد');
        $this->assertSame(0.0, (float) $this->customer->fresh()->advance_balance);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'refund_id' => $refund->id,
            'type' => CustomerAdvanceTransaction::REFUND_CREDIT_REVERSAL,
            'amount' => 30,
        ]);
    }

    public function test_debt_adjustment_reduces_receivable_and_can_be_reversed(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        $dueDate = today()->addDays(21)->toDateString();
        app(BillingService::class)->settleOnAccount($invoice, $this->cashier->id, 'اتفاق تحصيل', $dueDate);
        $this->assertSame($dueDate, $invoice->fresh()->due_date?->toDateString());

        $note = app(CreditNoteService::class)->issue(
            $invoice->fresh(), 25, 'debt_adjustment', 'تصحيح كمية مفوترة', $this->cashier->id,
            ['metadata' => ['reason_type' => 'billing_correction']],
        );
        $invoice->refresh();
        $this->assertSame(75.0, (float) $invoice->balance);
        $this->assertSame(25.0, (float) $invoice->credited_total);

        app(CreditNoteService::class)->reverse($note, $this->cashier->id, 'التصحيح كان على فاتورة أخرى');
        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->balance);
        $this->assertSame(0.0, (float) $invoice->credited_total);
    }

    public function test_partial_writeoff_is_a_document_and_reversal_restores_debt(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->settleOnAccount($invoice, $this->cashier->id);

        app(BillingService::class)->writeOffInvoice($invoice->fresh(), $this->cashier->id, 'تعذر تحصيل جزء معتمد', 20);
        $writeoff = \App\Models\DebtWriteoff::query()->where('invoice_id', $invoice->id)->sole();
        $invoice->refresh();
        $this->assertSame(80.0, (float) $invoice->balance);
        $this->assertSame(20.0, (float) $invoice->written_off_total);
        $this->assertDatabaseHas('debt_writeoffs', ['id' => $writeoff->id, 'status' => 'posted']);

        app(BillingService::class)->reverseWriteoff($writeoff, $this->cashier->id, 'عاد الدين للتحصيل');
        $invoice->refresh();
        $this->assertSame(100.0, (float) $invoice->balance);
        $this->assertSame(0.0, (float) $invoice->written_off_total);
        $this->assertDatabaseHas('debt_writeoffs', ['id' => $writeoff->id, 'status' => 'reversed']);
    }

    /** #13 — a written-off invoice can no longer be refunded. */
    public function test_refund_is_rejected_on_written_off_invoice(): void
    {
        $this->actingAs($this->cashier);

        $invoice = $this->doVisit(total: 100);
        app(BillingService::class)->addPayment($invoice, 40.0, 'cash', $this->cashier->id);

        // Simulate the write-off outcome: residual expensed to bad debt, status closed.
        $invoice->update(['status' => 'unpaid_writeoff']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/مشطوبة|ملغاة/');
        app(RefundService::class)->issue($invoice->fresh(), 40.0, 'cash', 'محاولة استرداد', $this->cashier->id);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function doVisit(float $total, int $quantity = 1): \App\Models\Invoice
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
            'name' => 'Cashier', 'username' => 'cashier_r',
            'password' => bcrypt('x'), 'status' => 'active',
            'primary_branch_id' => $this->branch->id, 'role' => 'cashier',
        ]);
        $user->branches()->attach($this->branch->id);
        return $user;
    }
}
