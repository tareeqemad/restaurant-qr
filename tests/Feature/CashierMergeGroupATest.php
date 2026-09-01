<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Group A of the cashier-screen merge: the four deliberate money actions
 * (settle-on-account, un-park, cancel, write-off) posted from the LIVE Volt
 * dashboard carry a hidden `return_session`, and the shared controller helper
 * redirects back to the ONE merged screen (?session=ID) with the checkout
 * pre-selected — while the classic show page (no `return_session`) keeps its
 * original redirect. No new money logic is added; these tests pin the routing
 * behaviour + the ability guard.
 */
class CashierMergeGroupATest extends TestCase
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

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'gat', 'name' => 'GAT', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'waiter'], ['label' => 'Waiter', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'gat-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        // Waiter has no Payment ability — the deny side of every guard test.
        $this->waiter = User::create([
            'name' => 'W', 'username' => 'gat-waiter', 'role' => 'waiter',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branch->id,
        ]);
        $this->waiter->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $unit = Unit::create(['code' => 'pcs', 'name' => 'pcs', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $station = Station::create(['code' => 'kitchen', 'name' => 'K', 'storage_location_id' => $storage->id, 'active' => true]);
        $category = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $station->id, 'active' => true]);
        $ing = Ingredient::create(['sku' => 'I', 'name' => 'S', 'base_unit_id' => $unit->id, 'current_stock' => 500, 'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true]);
        IngredientStock::create(['ingredient_id' => $ing->id, 'storage_location_id' => $storage->id, 'quantity' => 500, 'reorder_threshold' => 0]);
        $this->menuItem = MenuItem::create(['category_id' => $category->id, 'station_id' => $station->id, 'sku' => 'M1', 'slug' => 'm', 'name' => 'Meal', 'price' => 100, 'cost' => 10, 'prep_time_minutes' => 5, 'is_available' => true]);
        RecipeItem::create(['menu_item_id' => $this->menuItem->id, 'ingredient_id' => $ing->id, 'quantity' => 1, 'unit_id' => $unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599111662', defaultBranchId: $this->branch->id);
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

    /** @return array{0: Invoice, 1: TableSession} */
    private function issueMeal(): array
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'modifier_ids' => []]]);
        app(OrderService::class)->approve($order, $this->admin->id);
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->admin->id);

        return [$invoice, $session];
    }

    /**
     * Settle-on-account with `return_session` parks the debt AND redirects to
     * the merged dashboard with the session pre-selected. This case keeps a
     * partial payment to cover the mixed-payment path; full unpaid debt is
     * covered by the Vue cashier contract test.
     */
    public function test_settle_on_account_with_return_session_redirects_to_index_and_parks(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();                                         // total 100
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);  // balance 40

        $this->post(route('admin.cashier.settle_on_account', $invoice), [
            'notes' => 'وعد بالتسديد الأسبوع القادم',
            'return_session' => $session->id,
        ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertNotNull($invoice->settled_on_account_at, 'The remaining balance must be parked as debt.');
        $this->assertEqualsWithDelta(40.0, $this->customer->outstandingDebt(), 0.001,
            'The parked balance must show on the customer debt ledger.');
    }

    /**
     * Un-park with `return_session` restores normal collection AND redirects
     * back to the merged screen — the checkout renders even though the session
     * was closed by the settle step (Phase 0 made it session-addressable).
     */
    public function test_unpark_with_return_session_redirects_to_index_and_restores_collection(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();
        app(BillingService::class)->addPayment($invoice, 60.0, 'cash', $this->admin->id);
        app(BillingService::class)->settleOnAccount($invoice->fresh(), $this->admin->id);
        $this->assertEqualsWithDelta(40.0, $this->customer->outstandingDebt(), 0.001);

        $this->post(route('admin.cashier.unpark', $invoice), ['return_session' => $session->id])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertNull($invoice->settled_on_account_at, 'Un-park must lift the parked-debt flag.');
        $this->assertEqualsWithDelta(0.0, $this->customer->outstandingDebt(), 0.001,
            'The balance must leave the customer debt ledger once un-parked.');
    }

    /**
     * Cancel with `return_session` lands on the merged screen; the CLASSIC
     * path (no `return_session`) keeps its original back() redirect and is not
     * bounced onto the dashboard.
     */
    public function test_cancel_with_return_session_redirects_to_index_classic_path_preserved(): void
    {
        $this->actingAs($this->admin);

        // Merged path.
        [$invoice, $session] = $this->issueMeal();
        $this->post(route('admin.cashier.cancel', $invoice), [
            'reason' => 'خطأ في الطلب',
            'return_session' => $session->id,
        ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $invoice->fresh()->status);

        // Classic path — no return_session, so the old redirect stands.
        [$invoice2] = $this->issueMeal();
        $res = $this->post(route('admin.cashier.cancel', $invoice2), ['reason' => 'خطأ']);
        $res->assertSessionHas('success');
        $this->assertSame('cancelled', $invoice2->fresh()->status);
        $this->assertStringNotContainsString('session=', (string) $res->headers->get('Location'),
            'The classic path must not be redirected onto the merged dashboard.');
    }

    /** Write-off with `return_session` closes the invoice AND redirects to the merged screen. */
    public function test_writeoff_with_return_session_redirects_to_index(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        $this->post(route('admin.cashier.writeoff', $invoice), [
            'reason' => 'الزبون غادر بدون دفع',
            'return_session' => $session->id,
        ])
            ->assertRedirect(route('admin.cashier.index', ['session' => $session->id]))
            ->assertSessionHas('success');

        $this->assertSame('unpaid_writeoff', $invoice->fresh()->status);
    }

    /**
     * A forged/unknown `return_session` is never trusted: it falls back to the
     * action's classic redirect instead of building a bogus dashboard URL.
     */
    public function test_unknown_return_session_falls_back_to_classic_redirect(): void
    {
        $this->actingAs($this->admin);
        [$invoice] = $this->issueMeal();

        $res = $this->post(route('admin.cashier.cancel', $invoice), [
            'reason' => 'خطأ',
            'return_session' => 999999,   // no such session
        ]);
        $res->assertSessionHas('success');
        $this->assertStringNotContainsString('session=', (string) $res->headers->get('Location'));
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    /** Deny side: a waiter (no Payment ability) is 403'd — the guard is untouched by the merge. */
    public function test_waiter_without_payment_ability_is_forbidden(): void
    {
        $this->actingAs($this->admin);
        [$invoice, $session] = $this->issueMeal();

        $this->actingAs($this->waiter)
            ->post(route('admin.cashier.cancel', $invoice), [
                'reason' => 'محاولة غير مصرّحة',
                'return_session' => $session->id,
            ])
            ->assertForbidden();

        $this->assertSame('issued', $invoice->fresh()->status, 'A denied cancel must change nothing.');
    }
}
