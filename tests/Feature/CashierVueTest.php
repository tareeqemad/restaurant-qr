<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerAdvanceTransaction;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItemIngredientExclusion;
use App\Models\Payment;
use App\Models\PendingTransfer;
use App\Models\RecipeItem;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\BillingService;
use App\Services\CustomerAdvanceService;
use App\Services\OrderService;
use App\Services\PendingTransferService;
use App\Support\BranchContext;
use App\Support\PaymentMethods;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Contract tests shared by the current cashier and its Vue replacement.
 *
 * They pin edge cases before the new screen consumes the same services, so a
 * UI rewrite cannot silently change order, payment, or accounting behaviour.
 */
class CashierVueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private MenuItem $menuItem;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create([
            'code' => 'cv',
            'name' => 'Cashier Vue',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Admin', 'is_system' => true],
        );
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin',
            'username' => 'cashier-vue-admin',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('x'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        $unit = Unit::create([
            'code' => 'pcs',
            'name' => 'قطعة',
            'unit_type' => 'count',
            'factor_to_base' => 1,
            'is_base' => true,
        ]);
        $storage = StorageLocation::create([
            'code' => 'cv-kitchen',
            'name' => 'مخزن المطبخ',
            'is_default' => true,
            'active' => true,
        ]);
        $station = Station::create([
            'code' => 'cv-kitchen',
            'name' => 'المطبخ',
            'storage_location_id' => $storage->id,
            'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'cv-meals',
            'name' => 'وجبات',
            'default_station_id' => $station->id,
            'active' => true,
        ]);
        $ingredient = Ingredient::create([
            'sku' => 'CV-I',
            'name' => 'مكوّن',
            'base_unit_id' => $unit->id,
            'current_stock' => 500,
            'reorder_threshold' => 0,
            'cost_per_unit' => 1,
            'track_stock' => true,
            'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $storage->id,
            'quantity' => 500,
            'reorder_threshold' => 0,
        ]);
        $this->menuItem = MenuItem::create([
            'category_id' => $category->id,
            'station_id' => $station->id,
            'sku' => 'CV-M',
            'slug' => 'cashier-vue-meal',
            'name' => 'وجبة الاختبار',
            'price' => 20,
            'cost' => 5,
            'prep_time_minutes' => 5,
            'is_available' => true,
        ]);
        RecipeItem::create([
            'menu_item_id' => $this->menuItem->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 1,
            'unit_id' => $unit->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** @return array{0: TableSession, 1: Invoice|null} */
    private function checkoutSession(bool $issueInvoice = true): array
    {
        [$customer] = Customer::createFromCashier(
            name: 'زبون الكاشير',
            phone: '0599000111',
            defaultBranchId: $this->branch->id,
        );
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '12',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'customer_id' => $customer->id,
            'cover_count' => 2,
            'status' => 'active',
            'bill_requested_at' => now()->subMinutes(9),
        ]);
        $order = app(OrderService::class)->createFromCart(
            $session,
            [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]],
            $this->admin->id,
        );
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = $issueInvoice
            ? app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id)
            : null;

        return [$session, $invoice];
    }

    public function test_cashier_vue_issues_one_invoice_and_one_accounting_entry_on_retry(): void
    {
        [$session] = $this->checkoutSession(issueInvoice: false);
        $this->actingAs($this->admin);

        $endpoint = route('admin.cashier.api.sessions.invoice', $session);

        $this->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $invoice = Invoice::query()->sole();
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_type', 'invoice_issued')
            ->count());
    }

    public function test_cashier_can_audit_and_cancel_a_fired_item_before_invoicing(): void
    {
        [$session] = $this->checkoutSession(issueInvoice: false);
        $order = $session->orders()->sole();
        $item = $order->items()->sole();
        $ingredient = Ingredient::query()->sole();

        // Fire the item first so its real recipe is deducted. The exclusion
        // below is display/audit data for the cashier and must not erase the
        // already-recorded kitchen consumption in this scenario.
        app(OrderService::class)->startPreparing($item->fresh(), $this->admin->id);

        OrderItemIngredientExclusion::create([
            'order_item_id' => $item->id,
            'ingredient_id' => $ingredient->id,
            'name_snapshot' => 'بصل',
        ]);

        $this->actingAs($this->admin);
        $this->getJson(route('admin.cashier.api.state', [
            'session_id' => $session->id,
            'full' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.workspace.orders.0.items.0.can_cancel', true)
            ->assertJsonPath('data.workspace.orders.0.items.0.exclusions.0.name', 'بصل');

        $this->postJson(route('admin.cashier.api.order-items.cancel', $item), [
            'token' => 'cashier-cancel-fired-line',
            'reason' => 'الزبون أعاد الصنف بعد بدء التحضير',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.disposition', 'waste');

        $item->refresh();
        $this->assertSame('cancelled', $item->status);
        $this->assertSame($this->admin->id, $item->cancelled_by_user_id);
        $this->assertSame('الزبون أعاد الصنف بعد بدء التحضير', $item->cancelled_reason);
        $this->assertSame(0.0, (float) $order->fresh()->total);
        $this->assertTrue(InventoryMovement::query()
            ->where('reference_type', $item::class)
            ->where('reference_id', $item->id)
            ->where('type', 'waste')
            ->exists());

        $this->getJson(route('admin.cashier.api.state', [
            'session_id' => $session->id,
            'full' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.workspace.orders.0.items.0.can_cancel', false)
            ->assertJsonPath('data.workspace.orders.0.items.0.cancelled_reason', 'الزبون أعاد الصنف بعد بدء التحضير')
            ->assertJsonPath('data.workspace.orders.0.items.0.cancelled_by', $this->admin->name)
            ->assertJsonPath('data.workspace.can_close_without_billing', true)
            ->assertJson(fn ($json) => $json
                ->whereType('data.workspace.orders.0.items.0.cancelled_at', 'string')
                ->etc());
    }

    public function test_cashier_item_correction_is_hidden_after_invoice_issuance(): void
    {
        [$session] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $this->getJson(route('admin.cashier.api.state', [
            'session_id' => $session->id,
            'full' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.workspace.orders.0.items.0.can_cancel', false);

        $item = $session->orders()->sole()->items()->sole();
        $this->postJson(route('admin.cashier.api.order-items.cancel', $item), [
            'token' => 'cashier-cancel-invoiced-line',
            'reason' => 'محاولة تعديل بعد الفاتورة',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertNotSame('cancelled', $item->fresh()->status);
    }

    public function test_cashier_vue_issues_a_direct_order_invoice_through_the_same_billing_service(): void
    {
        $order = app(OrderService::class)->createCashierOrder(
            customer: null,
            branch: $this->branch,
            type: 'takeaway',
            source: 'phone',
            cart: [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]],
            opts: ['customer_name' => 'زبون هاتفي', 'customer_phone' => '0599111222'],
            createdByUserId: $this->admin->id,
        );
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.orders.invoice', $order))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $invoice = Invoice::query()->sole();
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertNull($invoice->table_session_id);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'event_type' => 'invoice_issued',
        ]);

        $this->getJson(route('admin.cashier.api.state', [
            'mode' => 'remote',
            'filter' => 'all',
            'full' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.queues.remote_orders.0.id', $order->id)
            ->assertJsonPath('data.queues.remote_orders.0.invoice.id', $invoice->id);
    }

    public function test_cashier_vue_index_exposes_one_screen_contract(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $this->get(route('admin.cashier.index', ['session_id' => $session->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Cashier/Index')
                ->where('initialState.workspace.kind', 'session')
                ->where('initialState.workspace.id', $session->id)
                ->where('initialState.workspace.invoice.id', $invoice->id)
                ->where('initialState.workspace.fulfillment.waiting', 1)
                ->where('initialState.workspace.fulfillment.preparing', 0)
                ->where('initialState.workspace.fulfillment.ready', 0)
                ->where('initialState.workspace.fulfillment.complete', false)
                ->where('initialState.workspace.fulfillment.stations.0.name', 'المطبخ')
                ->where('initialState.counts.checkout_sessions', 1)
                ->has('initialState.attention', 1)
                ->has('catalog.items', 1)
                ->has('options.payment_methods')
                ->where('initialState.abilities.refund', true)
                ->where('initialState.abilities.record_transfer', true)
                ->where('endpoints.tables', route('admin.tables.index'))
                ->where('endpoints.commands.record_transfer', '/admin/cashier/api/sessions/:session/transfers')
                ->has('shell.nav')
                ->has('shell.user')
                ->has('initialState.abilities'));
    }

    public function test_cashier_workspace_navigation_stays_inside_inertia_and_preserves_its_state(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Admin/Cashier/Index.vue'));
        $store = file_get_contents(resource_path('js/Stores/cashierStore.js'));
        $api = file_get_contents(resource_path('js/Api/cashierApi.js'));

        $this->assertMatchesRegularExpression('/import\s+\{\s*Head,\s*Link\s*\}\s+from\s+[\'\"]@inertiajs\/vue3[\'\"]/', $page);
        $this->assertMatchesRegularExpression('/<Link\s+:href="endpoints\.tables"\s+preserve-scroll\s+view-transition/', $page);
        $this->assertMatchesRegularExpression('/window\.history\.replaceState\(window\.history\.state,\s*[\'\"]{2},\s*url\)/', $page);
        $this->assertMatchesRegularExpression('/url\.searchParams\.set\([\'\"]search[\'\"],\s*cashier\.search\)/', $page);
        $this->assertStringNotContainsString('<a :href="endpoints.tables"', $page);

        $this->assertStringContainsString('activeRequest?.abort()', $store);
        $this->assertStringContainsString("error?.name === 'AbortError'", $store);
        $this->assertStringContainsString('signal: activeRequest.signal', $store);
        $this->assertStringContainsString('signal,', $api);
        $this->assertStringContainsString('await openInitialTask()', $page);
        $this->assertStringContainsString('const first = cashier.sessions[0] ?? cashier.remoteOrders[0]', $page);
        $this->assertStringContainsString('completedName === "issue"', $page);
        $this->assertStringContainsString('تسليم العهدة', $page);
        $this->assertStringContainsString('const handoverTasks = computed', $page);
        $this->assertStringContainsString('cashier.counts.pending_transfers', $page);
        $this->assertStringContainsString('هذه مراجعة لعهدة المستخدم الحالي', $page);
    }

    public function test_payment_sheet_defaults_to_direct_full_collection_and_keeps_partial_payment(): void
    {
        $source = file_get_contents(resource_path('js/Components/Cashier/PaymentSheet.vue'));

        $this->assertMatchesRegularExpression('/amountMode\.value\s*=\s*[\'\"]full[\'\"]/', $source);
        $this->assertStringContainsString('amount.value = balance.value.toFixed(2)', $source);
        $this->assertStringContainsString('دفع كامل', $source);
        $this->assertStringContainsString('دفع جزئي', $source);
        $this->assertStringContainsString('استلمت مبلغاً أكبر؟ احسب الفكة', $source);
        $this->assertStringContainsString('تحصيل ${formatMoney(paymentAmount.value, props.currency)}', $source);
        $this->assertStringNotContainsString('مراجعة الدفعة', $source);
        $this->assertStringNotContainsString('تأكيد التسجيل', $source);
    }

    public function test_cashier_today_summary_belongs_only_to_the_signed_in_user(): void
    {
        [, $invoice] = $this->checkoutSession();
        $other = User::create([
            'name' => 'Other Cashier',
            'username' => 'other-cashier',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('x'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $other->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        $mineCash = Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'cash',
            'amount' => 20,
            'received_by_user_id' => $this->admin->id,
            'paid_at' => now(),
        ]);
        Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'transfer',
            'amount' => 15,
            'received_by_user_id' => $this->admin->id,
            'paid_at' => now(),
        ]);
        Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'cash',
            'amount' => 100,
            'received_by_user_id' => $other->id,
            'paid_at' => now(),
        ]);
        Refund::create([
            'branch_id' => $this->branch->id,
            'number' => 'REF-MINE',
            'invoice_id' => $invoice->id,
            'payment_id' => $mineCash->id,
            'amount' => 4,
            'method' => 'cash',
            'status' => 'completed',
            'reason' => 'تصحيح اختبار',
            'processed_by' => $this->admin->id,
            'refunded_at' => now(),
        ]);
        Refund::create([
            'branch_id' => $this->branch->id,
            'number' => 'REF-OTHER',
            'invoice_id' => $invoice->id,
            'amount' => 2,
            'method' => 'cash',
            'status' => 'completed',
            'reason' => 'استرداد مستخدم آخر',
            'processed_by' => $other->id,
            'refunded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.cashier.api.state', ['full' => 1]))
            ->assertOk()
            ->assertJsonPath('data.today.collector_name', 'Admin')
            ->assertJsonPath('data.today.payments_count', 2)
            ->assertJsonPath('data.today.refunds_count', 1)
            ->assertJsonPath('data.today.gross', 35)
            ->assertJsonPath('data.today.refunds', 4)
            ->assertJsonPath('data.today.net', 31)
            ->assertJsonPath('data.today.cash', 16)
            ->assertJsonPath('data.today.non_cash', 15);
    }

    public function test_cashier_queue_prioritizes_checkout_but_search_finds_a_quiet_table(): void
    {
        $quietTable = Table::create([
            'branch_id' => $this->branch->id,
            'number' => 'QUIET-9',
            'capacity' => 2,
            'status' => 'occupied',
            'active' => true,
        ]);
        $quiet = TableSession::create([
            'table_id' => $quietTable->id,
            'cover_count' => 1,
            'status' => 'active',
        ]);
        $urgentTable = Table::create([
            'branch_id' => $this->branch->id,
            'number' => 'BILL-1',
            'capacity' => 2,
            'status' => 'occupied',
            'active' => true,
        ]);
        $urgent = TableSession::create([
            'table_id' => $urgentTable->id,
            'cover_count' => 1,
            'status' => 'active',
            'bill_requested_at' => now(),
        ]);
        $this->actingAs($this->admin);

        $default = $this->getJson(route('admin.cashier.api.state', ['full' => 1]))
            ->assertOk()
            ->assertJsonPath('data.queues.sessions.0.id', $urgent->id);
        $defaultIds = collect($default->json('data.queues.sessions'))->pluck('id');
        $this->assertTrue($defaultIds->contains($urgent->id));
        $this->assertFalse($defaultIds->contains($quiet->id));

        $this->getJson(route('admin.cashier.api.state', ['full' => 1, 'search' => 'QUIET-9']))
            ->assertOk()
            ->assertJsonPath('data.queues.sessions.0.id', $quiet->id);
    }

    public function test_cashier_queue_shows_a_four_shekel_unbilled_session_that_blocks_table_release(): void
    {
        $this->menuItem->update(['price' => 4]);
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => 'MONEY-4',
            'capacity' => 2,
            'status' => 'occupied',
            'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'cover_count' => 1,
            'status' => 'active',
        ]);
        app(OrderService::class)->createFromCart(
            $session,
            [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]],
            $this->admin->id,
        );
        $this->actingAs($this->admin);

        $response = $this->getJson(route('admin.cashier.api.state', ['full' => 1]))
            ->assertOk()
            ->assertJsonPath('data.counts.checkout_sessions', 1)
            ->assertJsonPath('data.attention.0.title', 'طلبات بانتظار الفوترة')
            ->assertJsonPath('data.attention.0.amount', 4);

        $row = collect($response->json('data.queues.sessions'))->firstWhere('id', $session->id);

        $this->assertNotNull($row);
        $this->assertNull($row['invoice']);
        $this->assertTrue($row['needs_checkout']);
        $this->assertEqualsWithDelta(4, (float) $row['total'], 0.001);

        $this->getJson(route('admin.cashier.api.state', [
            'full' => 1,
            'session_id' => $session->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.workspace.id', $session->id)
            ->assertJsonPath('data.workspace.orders.0.total', 4);
    }

    public function test_paid_active_session_waiting_on_kitchen_is_not_shown_as_needing_checkout(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        app(BillingService::class)->addPayment(
            $invoice,
            (float) $invoice->balance,
            'cash',
            $this->admin->id,
        );
        $this->assertSame('active', $session->fresh()->status);
        $this->actingAs($this->admin);

        $default = $this->getJson(route('admin.cashier.api.state', ['full' => 1]))
            ->assertOk()
            ->assertJsonPath('data.counts.checkout_sessions', 0);

        $this->assertFalse(
            collect($default->json('data.queues.sessions'))->pluck('id')->contains($session->id),
        );

        $all = $this->getJson(route('admin.cashier.api.state', [
            'full' => 1,
            'filter' => 'all',
        ]))->assertOk();
        $row = collect($all->json('data.queues.sessions'))->firstWhere('id', $session->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['needs_checkout']);

        $this->getJson(route('admin.cashier.api.state', [
            'full' => 1,
            'filter' => 'all',
            'session_id' => $session->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.workspace.invoice.status', 'paid')
            ->assertJsonPath('data.workspace.fulfillment.waiting', 1)
            ->assertJsonPath('data.workspace.fulfillment.complete', false);
    }

    public function test_cashier_vue_closes_only_an_empty_session_and_releases_its_table(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => 'E1',
            'capacity' => 2,
            'status' => 'occupied',
            'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'cover_count' => 1,
            'status' => 'active',
        ]);
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.sessions.close-empty', $session);

        $this->postJson($endpoint, ['token' => 'close-empty-session-1'])
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, ['token' => 'close-empty-session-1'])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $table->fresh()->status);
        $this->assertNotNull($table->fresh()->needs_cleaning_since);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_cashier_state_poll_returns_a_lean_not_changed_response(): void
    {
        $this->checkoutSession();
        $this->actingAs($this->admin);

        $first = $this->getJson(route('admin.cashier.api.state', ['full' => 1]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.changed', true);

        $version = $first->json('data.version');

        $this->getJson(route('admin.cashier.api.state', ['since' => $version]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.changed', false)
            ->assertJsonPath('data.version', $version)
            ->assertJsonMissingPath('data.queues');
    }

    public function test_cashier_vue_payment_is_idempotent_and_uses_the_accounting_service_path(): void
    {
        [, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $payload = [
            'token' => 'cashier-payment-1',
            'amount' => (float) $invoice->balance,
            'method' => 'cash',
            'reference' => null,
            'notes' => 'قبض من شاشة Vue',
        ];

        $this->postJson(route('admin.cashier.api.payments.store', $invoice), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'تم تسجيل الدفعة.');

        $payment = Payment::query()->sole();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'event_type' => 'payment_received',
        ]);

        $this->postJson(route('admin.cashier.api.payments.store', $invoice), $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_type', 'payment_received')
            ->count());
    }

    public function test_cashier_vue_payment_validation_uses_the_stable_arabic_envelope(): void
    {
        [, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.payments.store', $invoice), [
            'token' => 'cashier-payment-invalid',
            'method' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'راجع الحقول المطلوبة ثم حاول مرة أخرى.')
            ->assertJsonStructure(['errors' => ['amount']]);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cashier_vue_refund_is_idempotent_and_posts_one_reversal_entry(): void
    {
        [, $invoice] = $this->checkoutSession();
        app(BillingService::class)->addPayment(
            $invoice,
            (float) $invoice->balance,
            'cash',
            $this->admin->id,
        );
        $this->actingAs($this->admin);

        $payload = [
            'token' => 'cashier-refund-1',
            'amount' => 5,
            'method' => 'transfer',
            'reason' => 'إرجاع صنف للزبون',
            'reference' => 'RF-SLIP-55',
            'notes' => 'اختبار مسار Vue',
        ];
        $endpoint = route('admin.cashier.api.refunds.store', $invoice);

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $refund = Refund::query()->sole();
        $this->assertSame('RF-SLIP-55', $refund->reference);
        $this->assertSame('اختبار مسار Vue', $refund->notes);
        $this->assertEqualsWithDelta(5, (float) $invoice->fresh()->refunded_total, 0.001);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Refund::class)
            ->where('source_id', $refund->id)
            ->where('event_type', 'refund_completed')
            ->count());
    }

    public function test_cashier_vue_session_discount_recalculates_and_reposts_the_invoice(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.sessions.discounts.store', $session), [
            'type' => 'percent',
            'value' => 10,
            'reason' => 'خصم رضا الزبون',
            'name' => 'خصم 10%',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'تم تطبيق الخصم وإعادة احتساب الفاتورة.')
            ->assertJsonCount(1, 'data.discount_ids');

        $discount = OrderDiscount::query()->sole();
        $this->assertEqualsWithDelta(2, (float) $discount->amount, 0.001);
        $this->assertEqualsWithDelta(2, (float) $invoice->fresh()->discount_total, 0.001);
        $this->assertEqualsWithDelta(18, (float) $invoice->fresh()->total, 0.001);
        $this->assertEqualsWithDelta(18, (float) $invoice->fresh()->balance, 0.001);

        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'event_type' => 'invoice_discount_repost_reversal',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'event_type' => 'invoice_reissued_1',
        ]);
    }

    public function test_cashier_vue_discount_removal_is_idempotent_and_reposts_the_invoice(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.sessions.discounts.store', $session), [
            'type' => 'fixed',
            'value' => 3,
            'reason' => 'اختبار إزالة الخصم',
        ])->assertOk();
        $discount = OrderDiscount::query()->sole();
        $endpoint = route('admin.cashier.api.discounts.remove', ['discount' => $discount->id]);
        $payload = ['token' => 'cashier-remove-discount-1'];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.discount_id', $discount->id);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('order_discounts', 0);
        $this->assertEqualsWithDelta(20, (float) $invoice->fresh()->total, 0.001);
        $this->assertSame(2, JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_type', 'like', 'invoice_reissued_%')
            ->count());
    }

    public function test_cashier_vue_split_payment_is_idempotent_and_posts_one_payment_entry(): void
    {
        [, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);

        $splitPayload = [
            'token' => 'cashier-split-create-1',
            'splits' => [
                ['label' => 'ضيف 1', 'amount' => 10, 'method' => 'cash'],
                ['label' => 'ضيف 2', 'amount' => 10, 'method' => 'cash'],
            ],
        ];
        $splitEndpoint = route('admin.cashier.api.splits.store', $invoice);

        $this->postJson($splitEndpoint, $splitPayload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($splitEndpoint, $splitPayload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('invoice_splits', 2);
        $split = InvoiceSplit::query()->orderBy('id')->firstOrFail();
        $payEndpoint = route('admin.cashier.api.splits.pay', ['invoice' => $invoice, 'split' => $split]);
        $payPayload = ['token' => 'cashier-split-payment-1'];

        $this->postJson($payEndpoint, $payPayload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.split_id', $split->id);
        $this->postJson($payEndpoint, $payPayload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $payment = Payment::query()->sole();
        $this->assertTrue($split->fresh()->paid);
        $this->assertEqualsWithDelta(10, (float) $invoice->fresh()->balance, 0.001);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_type', 'payment_received')
            ->count());
    }

    public function test_cashier_vue_can_clear_an_unpaid_split_without_leaving_orphans(): void
    {
        [, $invoice] = $this->checkoutSession();
        app(BillingService::class)->splitInvoice($invoice, [
            ['label' => 'نصف أول', 'amount' => 10, 'method' => 'cash'],
            ['label' => 'نصف ثان', 'amount' => 10, 'method' => 'cash'],
        ]);
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.splits.clear', $invoice);
        $payload = ['token' => 'cashier-split-clear-1'];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('invoice_splits', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertEqualsWithDelta(20, (float) $invoice->fresh()->balance, 0.001);
    }

    public function test_cashier_vue_records_one_pending_transfer_without_posting_a_payment(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);
        $journalCountBefore = JournalEntry::query()->count();
        $endpoint = route('admin.cashier.api.transfers.store', $session);
        $payload = [
            'token' => 'cashier-transfer-record-1',
            'amount' => 20,
            'sender_name' => 'صاحب الحساب البنكي',
            'customer_name' => 'زبون الطاولة',
            'customer_phone' => '0599000111',
            'notes' => 'آخر المرجع 7788',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'تم تسجيل الحوالة بانتظار مطابقتها مع حساب البنك.');
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $transfer = PendingTransfer::query()->sole();
        $this->assertSame(PendingTransfer::STATUS_PENDING, $transfer->status);
        $this->assertSame($session->id, $transfer->table_session_id);
        $this->assertSame($invoice->id, $transfer->invoice_id);
        $this->assertSame($this->admin->id, $transfer->recorded_by_user_id);
        $this->assertSame('صاحب الحساب البنكي', $transfer->sender_name);
        $this->assertEqualsWithDelta(20, (float) $transfer->amount, 0.001);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame($journalCountBefore, JournalEntry::query()->count(), 'تسجيل الادعاء لا ينشئ قيداً محاسبياً.');
    }

    public function test_cashier_vue_verifies_a_transfer_once_and_posts_it_as_a_payment(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $transfer = app(PendingTransferService::class)->record(
            session: $session,
            amount: 20,
            senderName: 'محمد المحوّل',
            recordedByUserId: $this->admin->id,
        );
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.transfers.verify', $transfer);
        $payload = [
            'token' => 'cashier-transfer-verify-1',
            'verified_amount' => 20,
            'verification_notes' => 'طابق كشف البنك',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('warning', null);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $transfer->refresh();
        $payment = Payment::query()->sole();
        $this->assertSame(PendingTransfer::STATUS_VERIFIED, $transfer->status);
        $this->assertSame($payment->id, $transfer->payment_id);
        $this->assertSame('transfer', $payment->method);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_type', 'payment_received')
            ->count());
    }

    public function test_cashier_vue_rejects_a_transfer_with_auditable_reason_and_no_payment(): void
    {
        [$session] = $this->checkoutSession();
        $transfer = app(PendingTransferService::class)->record(
            session: $session,
            amount: 20,
            senderName: 'اسم غير موجود',
            recordedByUserId: $this->admin->id,
        );
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.transfers.reject', $transfer);
        $payload = [
            'token' => 'cashier-transfer-reject-1',
            'reason' => 'لم يظهر التحويل في كشف البنك',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $transfer->refresh();
        $this->assertSame(PendingTransfer::STATUS_REJECTED, $transfer->status);
        $this->assertSame('لم يظهر التحويل في كشف البنك', $transfer->rejection_reason);
        $this->assertSame($this->admin->id, $transfer->verified_by_user_id);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cashier_vue_voids_a_mistaken_payment_once_and_reverses_its_entry(): void
    {
        [, $invoice] = $this->checkoutSession();
        $payment = app(BillingService::class)->addPayment(
            $invoice,
            5,
            'cash',
            $this->admin->id,
            notes: 'دفعة مدخلة بالخطأ',
        );
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.payments.void', ['payment' => $payment->id]);
        $payload = [
            'token' => 'cashier-payment-void-1',
            'reason' => 'اختيار مبلغ خاطئ',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.payment_id', $payment->id);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'voided']);
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertEqualsWithDelta(20, (float) $invoice->fresh()->balance, 0.001);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'event_type' => 'payment_voided_'.$payment->id,
        ]);
    }

    public function test_cashier_vue_can_park_the_full_unpaid_invoice_as_customer_debt(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $entriesBefore = JournalEntry::query()->count();
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.invoices.settle-on-account', $invoice);
        $payload = [
            'token' => 'cashier-settle-debt-1',
            'notes' => 'يتابع الزبون السداد نهاية الأسبوع',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $invoice->refresh();
        $this->assertNotNull($invoice->settled_on_account_at);
        $this->assertSame('issued', $invoice->status);
        $this->assertEqualsWithDelta(20, (float) $invoice->balance, 0.001);
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertSame('available', $session->table->fresh()->status);
        $this->assertSame($entriesBefore, JournalEntry::query()->count(), 'Parking is an A/R flag, not a second journal posting.');
    }

    public function test_customer_advance_is_deposited_by_phone_redeemed_on_invoice_and_restored_when_payment_is_voided(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $customer = $session->customer;
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.customers.advances.store'), [
            'token' => 'customer-advance-deposit-1',
            'phone' => $customer->phone,
            'amount' => 50,
            'method' => 'cash',
            'notes' => 'رصيد زيارة قادمة',
        ])
            ->assertOk()
            ->assertJsonPath('data.customer.advance_balance', 50);

        $this->assertEqualsWithDelta(50, (float) $customer->fresh()->advance_balance, 0.001);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $customer->id,
            'type' => CustomerAdvanceTransaction::DEPOSIT,
            'amount' => 50,
            'balance_after' => 50,
        ]);

        $deposit = CustomerAdvanceTransaction::query()->where('type', CustomerAdvanceTransaction::DEPOSIT)->firstOrFail();
        $depositEntry = JournalEntry::query()->where('event_type', 'customer_advance_deposited')->firstOrFail();
        $this->assertTrue($depositEntry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::CASH))->where('debit', 50)->exists());
        $this->assertTrue($depositEntry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::CUSTOMER_ADVANCES))->where('credit', 50)->exists());

        $paymentResponse = $this->postJson(route('admin.cashier.api.payments.store', $invoice), [
            'token' => 'customer-advance-redemption-1',
            'amount' => 20,
            'method' => PaymentMethods::CUSTOMER_ADVANCE,
        ])->assertOk();

        $paymentId = (int) $paymentResponse->json('data.payment_id');
        $this->assertEqualsWithDelta(30, (float) $customer->fresh()->advance_balance, 0.001);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $customer->id,
            'payment_id' => $paymentId,
            'type' => CustomerAdvanceTransaction::REDEMPTION,
            'amount' => 20,
            'balance_after' => 30,
        ]);

        $redemptionEntry = JournalEntry::query()->where('event_type', 'customer_advance_redeemed')->firstOrFail();
        $this->assertTrue($redemptionEntry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::CUSTOMER_ADVANCES))->where('debit', 20)->exists());
        $this->assertTrue($redemptionEntry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::ACCOUNTS_RECEIVABLE))->where('credit', 20)->exists());

        $this->postJson(route('admin.cashier.api.payments.void', $paymentId), [
            'token' => 'customer-advance-void-1',
            'reason' => 'اختبار عكس استخدام الرصيد',
        ])->assertOk();

        $this->assertEqualsWithDelta(50, (float) $customer->fresh()->advance_balance, 0.001);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'voided']);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $customer->id,
            'payment_id' => $paymentId,
            'type' => CustomerAdvanceTransaction::REDEMPTION_REVERSAL,
            'amount' => 20,
            'balance_after' => 50,
        ]);

        $this->postJson(route('admin.cashier.api.customers.advances.reverse', $deposit->id), [
            'token' => 'customer-advance-deposit-reverse-1',
            'reason' => 'إيداع اختباري تم إدخاله بالخطأ',
        ])->assertOk();

        $this->assertEqualsWithDelta(0, (float) $customer->fresh()->advance_balance, 0.001);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $customer->id,
            'reversed_transaction_id' => $deposit->id,
            'type' => CustomerAdvanceTransaction::DEPOSIT_REVERSAL,
            'amount' => 50,
            'balance_after' => 0,
        ]);
        $depositReversal = CustomerAdvanceTransaction::query()
            ->where('reversed_transaction_id', $deposit->id)
            ->firstOrFail();
        $this->assertDatabaseHas('journal_entries', [
            'event_type' => 'customer_advance_deposit_reversed_'.$depositReversal->id,
        ]);
    }

    public function test_cash_change_can_be_saved_as_customer_advance_without_inflating_invoice_payment(): void
    {
        [$session, $invoice] = $this->checkoutSession();
        $customer = $session->customer;

        $payment = app(BillingService::class)->addPayment(
            invoice: $invoice,
            amount: 20,
            method: 'cash',
            userId: $this->admin->id,
            tenderedAmount: 50,
            saveChangeAsAdvance: true,
        );

        $this->assertEqualsWithDelta(20, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(20, (float) $invoice->fresh()->paid_total, 0.001);
        $this->assertEqualsWithDelta(30, (float) $customer->fresh()->advance_balance, 0.001);
        $this->assertDatabaseHas('customer_advance_transactions', [
            'customer_id' => $customer->id,
            'payment_id' => $payment->id,
            'type' => CustomerAdvanceTransaction::DEPOSIT,
            'amount' => 30,
            'balance_after' => 30,
        ]);
    }

    public function test_customer_advance_opening_balance_posts_liability_against_opening_equity(): void
    {
        [$customer] = Customer::createFromCashier(
            name: 'زبون برصيد سابق',
            phone: '0599333444',
            defaultBranchId: $this->branch->id,
        );

        app(CustomerAdvanceService::class)->openingBalance(
            customer: $customer,
            amount: 75,
            branchId: $this->branch->id,
            userId: $this->admin->id,
            postedOn: now()->toDateString(),
            notes: 'ترحيل من النظام السابق',
        );

        $this->assertEqualsWithDelta(75, (float) $customer->fresh()->advance_balance, 0.001);
        $entry = JournalEntry::query()->where('event_type', 'customer_advance_opening')->firstOrFail();
        $this->assertTrue($entry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::OPENING_BALANCE_EQUITY))->where('debit', 75)->exists());
        $this->assertTrue($entry->lines()->whereHas('account', fn ($query) => $query->where('code', AccountingService::CUSTOMER_ADVANCES))->where('credit', 75)->exists());
    }

    public function test_cashier_vue_can_unpark_an_untouched_customer_debt_without_a_journal_entry(): void
    {
        [, $invoice] = $this->checkoutSession();
        app(BillingService::class)->addPayment($invoice, 5, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice, $this->admin->id, 'تأجيل اختباري');
        $entriesBefore = JournalEntry::query()->count();
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.invoices.unpark', $invoice);
        $payload = ['token' => 'cashier-unpark-debt-1'];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $invoice->refresh();
        $this->assertNull($invoice->settled_on_account_at);
        $this->assertEqualsWithDelta(15, (float) $invoice->balance, 0.001);
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertSame($entriesBefore, JournalEntry::query()->count());
    }

    public function test_cashier_vue_writeoff_closes_the_balance_and_posts_bad_debt_once(): void
    {
        [, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.invoices.writeoff', $invoice);
        $payload = [
            'token' => 'cashier-writeoff-1',
            'reason' => 'تعذر تحصيل الفاتورة بموافقة الإدارة',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $invoice->refresh();
        $this->assertSame('unpaid_writeoff', $invoice->status);
        $this->assertEqualsWithDelta(0, (float) $invoice->balance, 0.001);
        $writeoff = \App\Models\DebtWriteoff::query()->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', \App\Models\DebtWriteoff::class)
            ->where('source_id', $writeoff->id)
            ->where('event_type', 'debt_writeoff_posted')
            ->count());
    }

    public function test_cashier_vue_cancels_an_unpaid_invoice_and_reverses_the_live_issue_entry(): void
    {
        [, $invoice] = $this->checkoutSession();
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.invoices.cancel', $invoice);
        $payload = [
            'token' => 'cashier-invoice-cancel-1',
            'reason' => 'فاتورة أُصدرت على الطلب الخطأ',
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_type', 'invoice_cancelled')
            ->count());
    }

    public function test_cashier_creates_and_sends_one_phone_order_without_delivery_obligations(): void
    {
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.orders.store');
        $payload = [
            'token' => 'cashier-order-create-1',
            'customer_name' => 'زبون هاتفي جديد',
            'customer_phone' => '0598123456',
            'notes' => 'بدون بصل',
            'cart' => [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 2,
                'modifier_ids' => [],
                'notes' => null,
            ]],
        ];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.sent_to_kitchen', true)
            ->assertJsonPath('data.customer_created', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $order = Order::query()->sole();
        $this->assertSame('approved', $order->status);
        $this->assertSame('takeaway', $order->order_type, 'The internal compatibility type stays server-owned.');
        $this->assertSame('phone', $order->order_source);
        $this->assertSame('زبون هاتفي جديد', $order->customer_name);
        $this->assertSame('0598123456', $order->customer_phone);
        $this->assertNull($order->delivery_address);
        $this->assertEqualsWithDelta(0, (float) $order->delivery_fee, 0.001);
        $this->assertEqualsWithDelta(40, (float) $order->total, 0.001);
        $this->assertNull($order->invoice);
        $customer = Customer::query()->sole();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertNotNull($customer->loyalty_customer_id);
        $this->assertSame(0, JournalEntry::query()->count(), 'Kitchen dispatch is operational, not a sale posting.');
    }

    public function test_cashier_can_register_a_customer_without_creating_an_order(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.customers.store'), [
            'token' => 'cashier-customer-create-1',
            'name' => 'زبون الكاشير',
            'phone' => '٠٥٩٩-١٢٣-٤٥٦',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.phone', '0599123456')
            ->assertJsonMissingPath('data.pin');

        $customer = Customer::query()->sole();
        $this->assertSame('0599123456', $customer->phone);
        $this->assertNotNull($customer->loyalty_customer_id);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cashier_vue_requires_a_phone_for_phone_orders_before_creating_anything(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.orders.store'), [
            'token' => 'cashier-phone-order-missing-phone',
            'customer_name' => 'زبون هاتفي',
            'cart' => [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 1,
                'modifier_ids' => [],
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonValidationErrors('customer_phone');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_cashier_rejects_delivery_fees_addresses_and_platform_fields(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.orders.store'), [
            'token' => 'cashier-forged-delivery-order',
            'order_type' => 'delivery',
            'order_source' => 'delivery',
            'customer_phone' => '0598111222',
            'delivery_address' => 'عنوان لا يديره المطعم',
            'delivery_fee' => 7,
            'cart' => [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 1,
                'modifier_ids' => [],
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonValidationErrors([
                'order_type',
                'order_source',
                'delivery_address',
                'delivery_fee',
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_cashier_vue_approves_a_pending_counter_order_as_a_separate_idempotent_step(): void
    {
        $order = app(OrderService::class)->createCashierOrder(
            customer: null,
            branch: $this->branch,
            type: 'takeaway',
            source: 'other',
            cart: [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]],
            createdByUserId: $this->admin->id,
        );
        $this->actingAs($this->admin);
        $endpoint = route('admin.cashier.api.orders.approve', $order);
        $payload = ['token' => 'cashier-order-approve-1'];

        $this->postJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->postJson($endpoint, $payload)
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertSame(0, JournalEntry::query()->count(), 'Kitchen dispatch is operational, not a sale posting.');
    }

    public function test_cashier_accepts_choices_when_modifier_group_has_no_upper_limit(): void
    {
        $group = ModifierGroup::create([
            'branch_id' => $this->branch->id,
            'slug' => 'unlimited-extras',
            'name' => 'إضافات مفتوحة',
            'min_select' => 0,
            'max_select' => 0,
            'required' => false,
            'active' => true,
        ]);
        $first = Modifier::create([
            'modifier_group_id' => $group->id,
            'name' => 'إضافة أولى',
            'price_delta' => 1,
            'active' => true,
        ]);
        $second = Modifier::create([
            'modifier_group_id' => $group->id,
            'name' => 'إضافة ثانية',
            'price_delta' => 2,
            'active' => true,
        ]);
        $this->menuItem->modifierGroups()->attach($group->id, ['display_order' => 0]);

        $this->actingAs($this->admin);

        $this->postJson(route('admin.cashier.api.orders.store'), [
            'token' => 'cashier-unlimited-modifiers',
            'customer_phone' => '0598777666',
            'cart' => [[
                'menu_item_id' => $this->menuItem->id,
                'quantity' => 1,
                'modifier_ids' => [$first->id, $second->id],
                'notes' => '',
            ]],
        ])->assertOk()->assertJsonPath('ok', true);

        $order = Order::query()->sole();
        $this->assertSame('approved', $order->status);
        $this->assertCount(2, $order->items()->sole()->modifiers);
    }

    public function test_cashier_phone_order_sheet_has_one_clear_flow(): void
    {
        $source = file_get_contents(resource_path('js/Components/Cashier/NewOrderSheet.vue'));

        $this->assertStringContainsString('إدخال طلب هاتفي', $source);
        $this->assertStringContainsString('إنشاء وإرسال للمطبخ', $source);
        $this->assertStringNotContainsString('orderType', $source);
        $this->assertStringNotContainsString('deliveryFee', $source);
        $this->assertStringNotContainsString('platform_commission_pct', $source);
        $this->assertStringNotContainsString('منصة توصيل', $source);
    }
}
