<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CashierPaymentService;
use App\Services\CashierShiftService;
use App\Services\CustomerAdvanceService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierRecoveryAndShiftsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $cashier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['name' => 'عهدة الاختبار', 'code' => 'shift-test', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);
        $this->cashier = User::create(['name' => 'موظف الاختبار', 'username' => 'custody-test', 'role' => 'admin', 'status' => 'active', 'password' => bcrypt('test'), 'primary_branch_id' => $this->branch->id]);
        $this->cashier->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
        $this->customer = Customer::create(['name' => 'زبون الاختبار', 'phone' => '0599000001']);
        $this->actingAs($this->cashier)->withSession(['active_branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function invoice(?int $branchId = null): Invoice
    {
        return Invoice::create(['branch_id' => $branchId ?? $this->branch->id, 'customer_id' => $this->customer->id, 'issued_by_user_id' => $this->cashier->id, 'is_opening_balance' => true, 'subtotal' => 100, 'total' => 100, 'balance' => 100, 'status' => 'issued', 'issued_at' => now()]);
    }

    public function test_cash_requires_an_open_shift_and_a_failed_claim_can_be_retried_then_recovered_after_cache_loss(): void
    {
        $invoice = $this->invoice();
        $payload = ['token' => 'durable-cash-payment', 'amount' => 30, 'method' => 'cash'];
        $url = route('admin.cashier.api.payments.store', $invoice);
        $this->postJson($url, $payload)->assertStatus(422)->assertJsonValidationErrors('shift');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cashier_payment_receipts', 0);
        $this->postJson(route('admin.cashier.api.shifts.open'), ['opening_cash' => 50])->assertOk();
        $paymentId = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.submission_state', 'committed')->json('data.payment_id');
        Cache::flush();
        $this->getJson(route('admin.cashier.api.payments.verify', $invoice).'?token='.$payload['token'])
            ->assertOk()->assertJsonPath('data.payment_id', $paymentId)->assertJsonPath('data.payment_status', 'posted');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.payment_id', $paymentId);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cashier_shift_movements', 1);
        $this->assertSame(80.0, app(CashierShiftService::class)->workspace($this->cashier->id)['active']['expected_cash']);
    }

    public function test_changed_payload_is_rejected_without_a_second_payment_and_unknown_verification_does_not_claim_failure(): void
    {
        $invoice = $this->invoice();
        app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 0);
        $payload = ['token' => 'payload-bound-token', 'amount' => 10, 'method' => 'cash'];
        $this->postJson(route('admin.cashier.api.payments.store', $invoice), $payload)->assertOk();
        $this->postJson(route('admin.cashier.api.payments.store', $invoice), [...$payload, 'amount' => 20])->assertStatus(409);
        $this->getJson(route('admin.cashier.api.payments.verify', $invoice).'?token=unknown-operation-token')->assertOk()->assertJsonPath('data.submission_state', 'unconfirmed');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('90.00', $invoice->fresh()->balance);
    }

    public function test_lost_acknowledgement_recovers_after_method_disabled_and_reports_a_later_void(): void
    {
        $invoice = $this->invoice();
        app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 0);
        $payload = ['token' => 'disabled-method-recovery', 'amount' => 15, 'method' => 'cash'];
        $url = route('admin.cashier.api.payments.store', $invoice);
        $id = $this->postJson($url, $payload)->assertOk()->json('data.payment_id');
        Setting::put('payment_method_cash_enabled', false, 'billing', 'bool');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.payment_id', $id)->assertJsonPath('data.payment_status', 'posted');
        Setting::put('payment_method_cash_enabled', true, 'billing', 'bool');
        app(BillingService::class)->voidPayment(Payment::findOrFail($id), $this->cashier->id, 'تصحيح بعد القبض');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.payment_status', 'voided');
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
        $this->assertDatabaseCount('cashier_shift_movements', 2);
    }

    public function test_cash_refund_and_reversal_use_the_actual_cash_allocation(): void
    {
        $invoice = $this->invoice();
        $shifts = app(CashierShiftService::class);
        $shifts->open($this->cashier->id, $this->branch->id, 50);
        app(BillingService::class)->addPayment($invoice, 60, 'cash', $this->cashier->id);
        $refund = app(RefundService::class)->issue($invoice->fresh(), 10, 'cash', 'استرداد اختبار', $this->cashier->id);
        $this->assertSame(100.0, $shifts->workspace($this->cashier->id)['active']['expected_cash']);
        app(RefundService::class)->reverse($refund, $this->cashier->id, 'إلغاء الاسترداد');
        $this->assertSame(110.0, $shifts->workspace($this->cashier->id)['active']['expected_cash']);
        $this->assertDatabaseCount('cashier_shift_movements', 3);
    }

    public function test_exception_after_nested_payment_and_ledger_writes_rolls_back_receipt_cash_and_invoice_together(): void
    {
        $invoice = $this->invoice();
        app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 25);
        $payload = ['token' => 'late-failure-retry', 'amount' => 15, 'method' => 'cash'];
        try {
            app(CashierPaymentService::class)->execute($invoice, $this->cashier->id, $payload, function () use ($invoice) {
                app(BillingService::class)->addPayment($invoice, 15, 'cash', $this->cashier->id);
                throw new \RuntimeException('Injected late failure');
            });
            $this->fail('The command should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected late failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('cashier_shift_movements', 0);
        $this->assertDatabaseCount('cashier_payment_receipts', 0);
        $this->assertSame('100.00', $invoice->fresh()->balance);
        $this->postJson(route('admin.cashier.api.payments.store', $invoice), $payload)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_shift_spans_midnight_and_a_later_void_belongs_to_the_new_shift_without_rewriting_the_closed_count(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(23));
        $shifts = app(CashierShiftService::class);
        $first = $shifts->open($this->cashier->id, $this->branch->id, 40);
        $openedAt = DB::table('cashier_shifts')->where('id', $first['id'])->value('opened_at');
        $this->travel(2)->hours();
        $payment = app(BillingService::class)->addPayment($this->invoice(), 30, 'cash', $this->cashier->id);
        $this->assertSame($first['id'], $payment->cashier_shift_id);
        $closed = $shifts->close($first['id'], $this->cashier->id, 70, null);
        $this->assertSame($openedAt, DB::table('cashier_shifts')->where('id', $first['id'])->value('opened_at'), 'Closing must never rewrite the start of custody.');
        $this->assertNotSame($closed['opened_at'], $closed['closed_at']);
        $this->assertSame(0.0, $closed['variance']);
        $this->assertSame($closed, $shifts->close($first['id'], $this->cashier->id, 999, 'ignored repeat'));
        $second = $shifts->open($this->cashier->id, $this->branch->id, 50);
        app(BillingService::class)->voidPayment($payment, $this->cashier->id, 'تصحيح قبض خاطئ');
        $this->assertSame(20.0, $shifts->workspace($this->cashier->id)['active']['expected_cash']);
        $this->assertDatabaseHas('cashier_shifts', ['id' => $first['id'], 'counted_cash' => 70, 'expected_cash' => 70]);
        $this->assertDatabaseHas('cashier_shift_movements', ['cashier_shift_id' => $second['id'], 'kind' => 'payment_void', 'amount' => -30]);
        $this->travelBack();
    }

    public function test_cross_branch_debt_cash_stays_in_collectors_drawer_while_document_branch_is_preserved(): void
    {
        $other = Branch::create(['name' => 'فرع ثان', 'code' => 'shift-other', 'is_active' => true]);
        $shift = app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 0);
        $payment = app(BillingService::class)->addPayment($this->invoice($other->id), 20, 'cash', $this->cashier->id);
        $this->assertSame($other->id, (int) $payment->branch_id);
        $this->assertSame($shift['id'], $payment->cashier_shift_id);
        $this->assertDatabaseHas('cashier_shift_movements', ['cashier_shift_id' => $shift['id'], 'source_branch_id' => $other->id, 'amount' => 20]);
    }

    public function test_cash_advance_and_reversal_change_custody_once_without_counting_wallet_redemption_as_cash(): void
    {
        $shifts = app(CashierShiftService::class);
        $shifts->open($this->cashier->id, $this->branch->id, 10);
        $advance = app(CustomerAdvanceService::class)->deposit($this->customer, 25, 'cash', $this->branch->id, $this->cashier->id);
        $this->assertSame(35.0, $shifts->workspace($this->cashier->id)['active']['expected_cash']);
        app(CustomerAdvanceService::class)->reverseDeposit($advance, $this->cashier->id, 'تصحيح الرصيد');
        $this->assertSame(10.0, $shifts->workspace($this->cashier->id)['active']['expected_cash']);
        $this->assertDatabaseCount('cashier_shift_movements', 2);
    }

    public function test_manual_cash_out_has_required_reason_immutable_retry_and_no_general_ledger_side_effect(): void
    {
        $shift = app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 100);
        $url = route('admin.cashier.api.shifts.movements', $shift['id']);
        $payload = ['token' => 'cash-pickup-token', 'amount' => 25, 'direction' => 'out', 'reason' => 'تسليم للمدير', 'reference' => 'وصل 42'];
        $this->postJson($url, [...$payload, 'reason' => ''])->assertStatus(422);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.shift.expected_cash', 75);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($url, [...$payload, 'amount' => 30])->assertStatus(409);
        $this->assertDatabaseCount('cashier_shift_movements', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        app(CashierShiftService::class)->close($shift['id'], $this->cashier->id, 75, null);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->postJson($url, [...$payload, 'token' => 'new-after-close'])->assertStatus(422);
    }

    public function test_only_owner_can_close_or_move_cash_and_logout_requires_a_counted_close(): void
    {
        $shift = app(CashierShiftService::class)->open($this->cashier->id, $this->branch->id, 50);
        $other = User::create(['name' => 'آخر', 'username' => 'another-cashier', 'role' => 'admin', 'status' => 'active', 'password' => bcrypt('test'), 'primary_branch_id' => $this->branch->id]);
        $other->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
        $this->actingAs($other)->postJson(route('admin.cashier.api.shifts.close', $shift['id']), ['counted_cash' => 50])->assertNotFound();
        $this->actingAs($other)->postJson(route('admin.cashier.api.shifts.movements', $shift['id']), ['token' => 'other-user-cash', 'amount' => 5, 'direction' => 'out', 'reason' => 'تجربة', 'reference' => 'ref'])->assertNotFound();
        $this->actingAs($this->cashier)->post(route('logout'))->assertRedirect(route('admin.cashier.index'));
        $this->assertAuthenticatedAs($this->cashier);
        $this->postJson(route('admin.cashier.api.shifts.close', $shift['id']), ['counted_cash' => 49])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->postJson(route('admin.cashier.api.shifts.close', $shift['id']), ['counted_cash' => 49, 'notes' => 'عجز قيد المراجعة'])->assertOk()->assertJsonPath('data.variance', -1);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_accounting_shift_details_are_branch_scoped_and_show_cash_movement_references(): void
    {
        $shifts = app(CashierShiftService::class);
        $shift = $shifts->open($this->cashier->id, $this->branch->id, 15);
        $this->get(route('admin.accounting.cash-shifts', ['shift_id' => $shift['id']]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Accounting/CashShifts')->where('selected.id', $shift['id'])->has('movements.data', 0));
        $other = Branch::create(['name' => 'فرع محجوب', 'code' => 'hidden-shift', 'is_active' => true]);
        DB::table('cashier_shifts')->where('id', $shift['id'])->update(['branch_id' => $other->id]);
        $this->get(route('admin.accounting.cash-shifts', ['shift_id' => $shift['id']]))->assertNotFound();
    }
}
