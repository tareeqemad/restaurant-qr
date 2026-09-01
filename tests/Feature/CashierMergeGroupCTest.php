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
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Group C — full split-bill management merged into the live Volt cashier
 * dashboard (replacing the old read-only split-blind panel + «إدارة التقسيم»
 * handoff to the classic page).
 *
 * The dashboard's split forms POST to the SAME already-hardened split routes
 * (admin.cashier.split / .split.pay / .split.clear). NO money logic lives in
 * the component, so these are controller/route regressions that lock in the
 * behaviours the dashboard relies on:
 *   - a split created / paid / cleared from the dashboard lands the cashier
 *     back on THIS session's checkout (the dashboard is at ?session=ID, so
 *     the classic forms' back() redirect returns to that same screen — the
 *     hidden return_session mirrors the other merged money forms);
 *   - the exact-sum invariant (floor-share + remainder-on-last, NO tolerance)
 *     the lifted builder reproduces is the one the service actually accepts;
 *   - per-split payment stamps «دفعة جزء: {label}» + persists the reference;
 *   - regroup is allowed while nothing is paid and 422s once a part is paid.
 *
 * Setup mirrors CashierSplitUnparkTest.
 */
class CashierMergeGroupCTest extends TestCase
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

        $this->branch = Branch::create(['code' => 'gc', 'name' => 'GC', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'gc-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $station = Station::create(['code' => 'kitchen', 'name' => 'K', 'storage_location_id' => $storage->id, 'active' => true]);
        $category = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $station->id, 'active' => true]);
        $ing = Ingredient::create(['sku' => 'I', 'name' => 'S', 'base_unit_id' => $unit->id, 'current_stock' => 500, 'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true]);
        IngredientStock::create(['ingredient_id' => $ing->id, 'storage_location_id' => $storage->id, 'quantity' => 500, 'reorder_threshold' => 0]);
        $this->menuItem = MenuItem::create(['category_id' => $category->id, 'station_id' => $station->id, 'sku' => 'M1', 'slug' => 'm', 'name' => 'Meal', 'price' => 100, 'cost' => 10, 'prep_time_minutes' => 5, 'is_available' => true]);
        RecipeItem::create(['menu_item_id' => $this->menuItem->id, 'ingredient_id' => $ing->id, 'quantity' => 1, 'unit_id' => $unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000991', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function openSession(): TableSession
    {
        $table = Table::create(['number' => (string) random_int(1, 9999), 'capacity' => 4, 'status' => 'occupied', 'active' => true]);

        return TableSession::create(['table_id' => $table->id, 'customer_id' => $this->customer->id, 'cover_count' => 1, 'status' => 'active']);
    }

    /** @return array{0: \App\Models\Invoice, 1: TableSession} A fresh issued 100.00 ticket. */
    private function issueMeal(): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);

        return [$invoice, $session];
    }

    /** The dashboard's checkout screen for a session — where the split forms return to. */
    private function checkoutUrl(TableSession $session): string
    {
        return route('admin.cashier.index', ['session' => $session->id]);
    }

    /**
     * Creating a split from the dashboard (return_session on the POST) lands the
     * cashier back on THAT session's checkout, and persists N shares that sum
     * to the invoice total.
     */
    public function test_create_split_with_return_session_lands_on_session_checkout(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();   // total 100

        $this->from($this->checkoutUrl($session))
            ->post(route('admin.cashier.split', $invoice), [
                'return_session' => $session->id,
                'splits' => [
                    ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
                    ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
                ],
            ])
            ->assertRedirect($this->checkoutUrl($session))
            ->assertSessionHas('success');

        $splits = $invoice->splits()->orderBy('id')->get();
        $this->assertSame(2, $splits->count());
        $this->assertEqualsWithDelta(
            (float) $invoice->total,
            (float) $splits->sum('amount'),
            0.001,
            'The persisted shares must sum to the invoice total.'
        );
    }

    /**
     * The floor-share + remainder-on-the-last-row math the lifted builder
     * produces (33.33 / 33.33 / 33.34 for a 100 ÷ 3 split) is accepted by the
     * service — its sum is EXACTLY the total.
     */
    public function test_equal_share_remainder_math_sums_to_total_and_is_accepted(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();   // total 100

        $this->from($this->checkoutUrl($session))
            ->post(route('admin.cashier.split', $invoice), [
                'return_session' => $session->id,
                'splits' => [
                    ['label' => 'الشخص 1', 'amount' => 33.33, 'method' => 'cash'],
                    ['label' => 'الشخص 2', 'amount' => 33.33, 'method' => 'cash'],
                    ['label' => 'الشخص 3', 'amount' => 33.34, 'method' => 'cash'],
                ],
            ])
            ->assertSessionHas('success');

        $this->assertSame(3, $invoice->splits()->count());
        $this->assertEqualsWithDelta(100.0, (float) $invoice->splits()->sum('amount'), 0.001);
    }

    /**
     * The service enforces the exact-sum invariant with NO 0.01 tolerance — a
     * naive equal split WITHOUT the remainder row (33.33 × 3 = 99.99) is
     * refused and nothing is persisted. This is why the builder must drop the
     * remainder on the last row.
     */
    public function test_mismatched_shares_are_refused_and_persist_nothing(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();   // total 100

        $this->from($this->checkoutUrl($session))
            ->post(route('admin.cashier.split', $invoice), [
                'return_session' => $session->id,
                'splits' => [
                    ['label' => 'الشخص 1', 'amount' => 33.33, 'method' => 'cash'],
                    ['label' => 'الشخص 2', 'amount' => 33.33, 'method' => 'cash'],
                    ['label' => 'الشخص 3', 'amount' => 33.33, 'method' => 'cash'],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, $invoice->splits()->count(), 'A rejected split must persist no rows.');
    }

    /**
     * Paying one split from the dashboard marks it paid, persists the optional
     * reference into payments.reference, stamps the note «دفعة جزء: {label}»
     * (the mark voidPayment matches to un-mark the split), and returns to the
     * session checkout.
     */
    public function test_pay_split_with_return_session_marks_paid_and_persists_reference_and_stamp(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'الشخص 1', 'amount' => 60, 'method' => 'transfer'],
                ['label' => 'الشخص 2', 'amount' => 40, 'method' => 'cash'],
            ],
        ])->assertSessionHas('success');

        $transferSplit = $invoice->splits()->where('method', 'transfer')->firstOrFail();

        $this->from($this->checkoutUrl($session))
            ->post(route('admin.cashier.split.pay', ['invoice' => $invoice, 'split' => $transferSplit]), [
                'return_session' => $session->id,
                'reference' => 'TRX-98765',
            ])
            ->assertRedirect($this->checkoutUrl($session))
            ->assertSessionHas('success');

        $this->assertTrue((bool) $transferSplit->fresh()->paid, 'The paid split must be flagged.');

        $payment = $invoice->payments()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame('TRX-98765', $payment->reference);
        $this->assertSame('transfer', $payment->method);
        $this->assertSame('دفعة جزء: الشخص 1', $payment->notes,
            'The «دفعة جزء: {label}» stamp is what voidPayment matches to un-mark the split.');
    }

    /**
     * «تعديل التقسيم» re-POSTs the split route: while nothing is paid the rows
     * are replaced; once ANY split is paid the regroup is refused with a 422
     * (the guard the dashboard mirrors by hiding the edit button after a pay).
     */
    public function test_split_edit_replaces_while_unpaid_then_422_after_paid(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        // Create 2 equal shares.
        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
                ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
            ],
        ])->assertSessionHas('success');
        $this->assertSame(2, $invoice->splits()->count());

        // Regroup into 3 uneven shares while nothing is paid — replaces the rows.
        $this->from($this->checkoutUrl($session))
            ->post(route('admin.cashier.split', $invoice), [
                'return_session' => $session->id,
                'splits' => [
                    ['label' => 'أحمد', 'amount' => 40, 'method' => 'transfer'],
                    ['label' => 'سمير', 'amount' => 30, 'method' => 'cash'],
                    ['label' => 'ليلى', 'amount' => 30, 'method' => 'cash'],
                ],
            ])
            ->assertRedirect($this->checkoutUrl($session))
            ->assertSessionHas('success');

        $splits = $invoice->splits()->orderBy('id')->get();
        $this->assertSame(3, $splits->count(), 'Old rows must be replaced by the new grouping.');
        $this->assertSame(['أحمد', 'سمير', 'ليلى'], $splits->pluck('label')->all());

        // Pay one part, then attempt a regroup — refused with 422, grouping survives.
        $this->post(route('admin.cashier.split.pay', ['invoice' => $invoice, 'split' => $splits->first()]))
            ->assertSessionHas('success');

        $this->postJson(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'أ', 'amount' => 60, 'method' => 'cash'],
                ['label' => 'ب', 'amount' => 40, 'method' => 'cash'],
            ],
        ])->assertStatus(422);

        $this->assertSame(3, $invoice->splits()->count(), 'The paid grouping must survive the refused edit.');
        $this->assertTrue((bool) $splits->first()->fresh()->paid);
    }

    /**
     * «إزالة التقسيم» clears the rows while nothing is paid and returns the
     * cashier to the session checkout; once a part is paid the clear is refused
     * (the guard the dashboard mirrors by hiding the button after a pay).
     */
    public function test_clear_split_with_return_session_lands_on_checkout_then_blocked_after_paid(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
                ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
            ],
        ])->assertSessionHas('success');

        // Clear while unpaid — success + back to the checkout, no rows left.
        $this->from($this->checkoutUrl($session))
            ->delete(route('admin.cashier.split.clear', $invoice), ['return_session' => $session->id])
            ->assertRedirect($this->checkoutUrl($session))
            ->assertSessionHas('success');
        $this->assertSame(0, $invoice->splits()->count());

        // Re-split, pay one, then a clear is refused and the rows survive.
        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'الشخص 1', 'amount' => 50, 'method' => 'cash'],
                ['label' => 'الشخص 2', 'amount' => 50, 'method' => 'cash'],
            ],
        ])->assertSessionHas('success');
        $this->post(route('admin.cashier.split.pay', ['invoice' => $invoice, 'split' => $invoice->splits()->orderBy('id')->first()]))
            ->assertSessionHas('success');

        $this->from($this->checkoutUrl($session))
            ->delete(route('admin.cashier.split.clear', $invoice), ['return_session' => $session->id])
            ->assertSessionHas('error');
        $this->assertSame(2, $invoice->splits()->count(), 'A refused clear must leave the split grouping intact.');
    }

    /** The Vue cashier receives every split row in its one-screen contract. */
    public function test_dashboard_renders_split_manager_and_drops_classic_handoff(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        $this->post(route('admin.cashier.split', $invoice), [
            'splits' => [
                ['label' => 'رامي', 'amount' => 50, 'method' => 'cash'],
                ['label' => 'هبة', 'amount' => 50, 'method' => 'transfer'],
            ],
        ])->assertSessionHas('success');

        $this->get($this->checkoutUrl($session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Cashier/Index')
                ->has('initialState.workspace.invoice.splits', 2)
                ->where('initialState.workspace.invoice.splits.0.label', 'رامي')
                ->where('initialState.workspace.invoice.splits.1.label', 'هبة'));
    }
}
