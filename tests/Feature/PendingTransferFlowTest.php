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
use App\Services\PendingTransferService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bank-transfer loop: a CUSTOMER-declared transfer (recorded_by = null)
 * lands in the cashier's queue and, once the cashier verifies it, becomes a
 * real payment on the invoice — exactly like a staff-recorded one.
 */
class PendingTransferFlowTest extends TestCase
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

        $this->branch = Branch::create(['code' => 'pt', 'name' => 'PT', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->admin = User::create([
            'name' => 'A', 'username' => 'pt-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>200,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>200,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000555', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function issueVisit(): \App\Models\Invoice
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        $session = TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        return app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);
    }

    public function test_customer_transfer_reaches_cashier_queue_and_verifies_to_a_payment(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;

        // Customer declares the transfer from their phone (no staff user).
        $transfer = app(PendingTransferService::class)->record(
            session: $session, amount: 100.0, senderName: 'محمد المُرسِل', recordedByUserId: null,
        );

        $this->assertNull($transfer->recorded_by_user_id, 'Customer-declared → no recorder.');
        $this->assertSame('pending', $transfer->status);
        $this->assertSame(1, PendingTransfer::pending()->count(),
            'The customer transfer surfaces in the cashier queue with no staff step.');

        // Cashier verifies it against the bank → becomes a real transfer payment.
        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.verify', $transfer))
            ->assertSessionHas('success');

        $transfer->refresh();
        $invoice->refresh();
        $this->assertSame('verified', $transfer->status);
        $this->assertNotNull($transfer->payment_id);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(100.0, (float) $invoice->paid_total);
        $this->assertSame('transfer', $invoice->payments()->latest('id')->first()->method);

        // Money can settle before food, but the kitchen ticket and occupied
        // table must remain live until the final line is served.
        $order = $session->orders()->with('items')->first();
        $this->assertSame('approved', $order->status);
        $this->assertSame('active', $session->fresh()->status);
        $this->assertSame('occupied', $session->table->fresh()->status);

        $item = $order->items->first();
        app(OrderService::class)->startPreparing($item, $this->admin->id);
        app(OrderService::class)->markItemReady($item->fresh());
        app(OrderService::class)->markItemServed($item->fresh(), $this->admin->id);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $session->table->fresh()->status);
    }

    public function test_customer_uploads_private_receipt_and_cashier_can_view_it(): void
    {
        Storage::fake('local');
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        $this->post(route('customer.bill.transfer'), [
            'session' => $session->token,
            'sender_name' => 'Customer Sender',
            'amount' => 100,
            'proof' => UploadedFile::fake()->createWithContent('receipt.png', $png),
        ])->assertSessionHas('success');

        $transfer = PendingTransfer::where('table_session_id', $session->id)->firstOrFail();
        $this->assertNotNull($transfer->proof_path);
        Storage::disk('local')->assertExists($transfer->proof_path);

        $this->actingAs($this->admin)
            ->get(route('admin.cashier.transfers.proof', $transfer))
            ->assertOk();
    }

    /** The cashier can also record a claim from their own screen (not just the waiter). */
    public function test_cashier_can_record_a_transfer_claim(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;

        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.store', $session), [
                'sender_name' => 'زبون كاش', 'amount' => 100,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, PendingTransfer::where('table_session_id', $session->id)->pending()->count());
        $this->assertSame($this->admin->id,
            PendingTransfer::where('table_session_id', $session->id)->first()->recorded_by_user_id);
    }

    /** One real transfer = one verifiable claim: a second declaration for the
     *  same session is refused in the SHARED service, so no path can stack a
     *  duplicate the cashier could verify into a double payment. */
    public function test_second_declaration_for_same_session_is_blocked(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;
        $svc = app(PendingTransferService::class);

        $svc->record(session: $session, amount: 50.0, senderName: 'أول', recordedByUserId: null);

        try {
            $svc->record(session: $session, amount: 50.0, senderName: 'ثاني', recordedByUserId: null);
            $this->fail('Expected a duplicate transfer to be rejected.');
        } catch (\App\Exceptions\DuplicatePendingTransferException $e) {
            // expected
        }

        $this->assertSame(1, PendingTransfer::where('table_session_id', $session->id)->pending()->count());
    }

    /** The cashier's manual-record endpoint surfaces the duplicate as a friendly
     *  info flash instead of erroring, and creates no second row. */
    public function test_cashier_store_rejects_duplicate_with_info_flash(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;
        app(PendingTransferService::class)->record(session: $session, amount: 50.0, senderName: 'أول', recordedByUserId: null);

        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.store', $session), ['sender_name' => 'ثاني', 'amount' => 50])
            ->assertSessionHas('info');

        $this->assertSame(1, PendingTransfer::where('table_session_id', $session->id)->pending()->count());
    }

    /** Verifying a transfer twice must not double-post the payment — the second
     *  attempt is refused and the invoice keeps a single payment. */
    public function test_verify_twice_does_not_double_post(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;
        $transfer = app(PendingTransferService::class)
            ->record(session: $session, amount: 100.0, senderName: 'م', recordedByUserId: null);

        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.verify', $transfer))
            ->assertSessionHas('success');

        // The row is now 'verified' — a retried POST is refused, posts nothing.
        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.verify', $transfer))
            ->assertStatus(422);

        $invoice->refresh();
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(100.0, (float) $invoice->paid_total);
    }

    /** A short-pay (confirmed amount below the balance) leaves the invoice open
     *  and warns the cashier so the table isn't waved off half-paid. */
    public function test_partial_verify_warns_and_leaves_balance_open(): void
    {
        $invoice = $this->issueVisit();
        $session = $invoice->tableSession;
        $transfer = app(PendingTransferService::class)
            ->record(session: $session, amount: 100.0, senderName: 'م', recordedByUserId: null);

        $this->actingAs($this->admin)
            ->post(route('admin.cashier.transfers.verify', $transfer), ['verified_amount' => 40])
            ->assertSessionHas('warning');

        $invoice->refresh();
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertEqualsWithDelta(60.0, (float) $invoice->balance, 0.001);
        $this->assertSame(1, $invoice->payments()->count());
    }
}
