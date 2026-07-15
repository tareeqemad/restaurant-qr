<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\PendingTransfer;
use App\Models\RecipeItem;
use App\Models\Role;
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
 * Groups B & D of the cashier-screen merge, posted from the LIVE Volt
 * dashboard:
 *   - Group B: per-payment VOID (reverse a mistaken entry, reopen the invoice).
 *   - Group D: pending bank-transfer VERIFY / REJECT (confirm the money landed
 *     in the bank app, or record why it didn't).
 *
 * Both surfaces reuse the EXISTING already-hardened controller routes with a
 * hidden `return_session`. Unlike the Group-A actions (which run through
 * redirectAfterInvoiceAction and read `return_session` directly),
 * CashierController::voidPayment and every PendingTransferController action
 * redirect via back() — so from the merged screen the referer IS
 * ?session=ID and the cashier lands right back on the checkout. These tests
 * pin that routing behaviour + the ability guard; no new money logic is added.
 */
class CashierMergeGroupBDTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $waiter;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', false, 'billing', 'bool');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'gbd', 'name' => 'GBD', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'gbd-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        // Waiter has no Payment ability — the deny side of every guard test.
        $this->waiter = User::create([
            'name' => 'W', 'username' => 'gbd-waiter', 'role' => 'waiter',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->waiter->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>500,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>500,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599222663', defaultBranchId: $this->branch->id);
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

    /** @return array{0: \App\Models\Invoice, 1: TableSession} */
    private function issueMeal(): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
        return [$invoice, $session];
    }

    private function pendingTransferFor(TableSession $session, float $amount = 100.0): PendingTransfer
    {
        return PendingTransfer::create([
            'branch_id'           => $this->branch->id,
            'table_session_id'    => $session->id,
            'customer_id'         => $this->customer->id,
            'amount'              => $amount,
            'sender_name'         => 'المُرسِل',
            'status'              => PendingTransfer::STATUS_PENDING,
            'recorded_by_user_id' => $this->admin->id,
        ]);
    }

    // ─── Group B: void a payment ──────────────────────────────────────

    /**
     * Voiding a payment from the merged screen (referer = ?session=ID) lands
     * back on that checkout and REVERSES the payment — the fully-paid invoice
     * reopens and the row is hard-deleted (a fix, not a refund).
     */
    public function test_void_payment_with_return_session_redirects_to_session_checkout_and_reopens_invoice(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();                                          // total 100
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);  // fully paid
        $this->assertSame('paid', $invoice->fresh()->status);

        $payment = $invoice->payments()->latest('id')->first();

        $this->from(route('admin.cashier.index', ['session' => $session->id]))
            ->post(route('admin.cashier.payments.void', $payment), [
                'reason'         => 'خطأ في طريقة الدفع',
                'return_session' => $session->id,
            ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status, 'Voiding the only payment must reopen the invoice.');
        $this->assertSame(0, $invoice->payments()->count(), 'Void hard-deletes the payment row.');
        $this->assertEqualsWithDelta(0.0, (float) $invoice->paid_total, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $invoice->balance, 0.001);
    }

    /** Void is gated by the Payment ability — a waiter is 403'd and nothing changes. */
    public function test_void_payment_requires_payment_ability(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 100.0, 'cash', $this->admin->id);
        $payment = $invoice->payments()->latest('id')->first();

        $this->actingAs($this->waiter)
            ->post(route('admin.cashier.payments.void', $payment), [
                'reason'         => 'محاولة غير مصرّحة',
                'return_session' => $session->id,
            ])
            ->assertForbidden();

        $this->assertSame(1, $invoice->payments()->count(), 'A denied void must change nothing.');
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    // ─── Group D: verify / reject a pending transfer ──────────────────

    /**
     * Verifying a claimed transfer from the merged screen records it as a
     * payment (via BillingService::addPayment) AND redirects back to the
     * session checkout. A full-amount verify pays the invoice off.
     */
    public function test_verify_pending_transfer_with_return_session_redirects_to_session_checkout(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();       // total 100
        $transfer = $this->pendingTransferFor($session, 100.0);

        $this->from(route('admin.cashier.index', ['session' => $session->id]))
            ->post(route('admin.cashier.transfers.verify', $transfer), [
                'verified_amount' => 100.0,
                'return_session'  => $session->id,
            ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame(PendingTransfer::STATUS_VERIFIED, $transfer->status);
        $this->assertNotNull($transfer->payment_id, 'Verify links the created payment back to the transfer.');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status, 'A full-amount verify pays the invoice.');
        $this->assertSame(1, $invoice->payments()->where('method', 'transfer')->count());
    }

    /**
     * Rejecting a claimed transfer (reason required, captured via the prompt
     * pattern in the markup) flips it to rejected AND redirects back to the
     * session checkout. No payment is recorded.
     */
    public function test_reject_pending_transfer_with_return_session_redirects_to_session_checkout(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();
        $transfer = $this->pendingTransferFor($session, 100.0);

        $this->from(route('admin.cashier.index', ['session' => $session->id]))
            ->post(route('admin.cashier.transfers.reject', $transfer), [
                'reason'         => 'لم يصل المبلغ للبنك',
                'return_session' => $session->id,
            ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $transfer->refresh();
        $this->assertSame(PendingTransfer::STATUS_REJECTED, $transfer->status);
        $this->assertSame('لم يصل المبلغ للبنك', $transfer->rejection_reason);
        $this->assertSame(0, $invoice->fresh()->payments()->count(), 'A rejected transfer records no payment.');
    }

    /** Verify is gated by the Payment ability — a waiter is 403'd and the claim stays pending. */
    public function test_verify_pending_transfer_requires_payment_ability(): void
    {
        $this->actingAs($this->admin);
        [, $session] = $this->issueMeal();
        $transfer = $this->pendingTransferFor($session, 100.0);

        $this->actingAs($this->waiter)
            ->post(route('admin.cashier.transfers.verify', $transfer), [
                'verified_amount' => 100.0,
                'return_session'  => $session->id,
            ])
            ->assertForbidden();

        $this->assertSame(PendingTransfer::STATUS_PENDING, $transfer->fresh()->status,
            'A denied verify must leave the transfer pending.');
    }
}
