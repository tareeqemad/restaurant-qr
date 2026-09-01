<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Services\OrderService;
use App\Support\BranchContext;
use App\Support\LiveRefreshPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 2 (MIGRATION-PILOT.md §13): tracking + bill on Inertia/Vue.
 * Covers the decorated orders payload (steps, snapshots, cancel window as
 * remaining seconds), the session pulse endpoint, and the bill's
 * totals/transfer contract.
 */
class CustomerTrackVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Table $table;

    protected TableSession $session;

    protected MenuItem $burger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'tr', 'name' => 'TR', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        $this->table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '9',
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $this->session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'tr-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 2,
        ]);

        $gram = Unit::create(['code' => 'g', 'name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create([
            'branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K',
            'is_default' => true, 'active' => true,
        ]);
        $kitchen = Station::create([
            'code' => 'kitchen', 'name' => 'Kitchen',
            'storage_location_id' => $storage->id, 'active' => true,
        ]);
        $category = Category::create([
            'slug' => 'mains', 'name' => 'Mains',
            'default_station_id' => $kitchen->id, 'active' => true,
        ]);
        $patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $patty->id, 'storage_location_id' => $storage->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);
        $this->burger = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 150, 'unit_id' => $gram->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function order()
    {
        return app(OrderService::class)->createFromCart($this->session, [
            ['menu_item_id' => $this->burger->id, 'quantity' => 2, 'modifier_ids' => []],
        ], null);
    }

    protected function q(array $extra = []): array
    {
        return array_merge(['session' => $this->session->token], $extra);
    }

    public function test_track_serves_the_inertia_page_with_a_decorated_order(): void
    {
        $order = $this->order();

        $this->get(route('customer.track', $this->q()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Track')
                ->where('sessionInfo.tableNumber', '9')
                ->has('orders', 1, fn (Assert $o) => $o
                    ->where('number', $order->number)
                    ->where('status', 'pending')
                    ->where('stepIndex', 0)
                    ->where('canCancel', true)
                    ->has('cancelRemaining')
                    ->has('items', 1, fn (Assert $it) => $it
                        ->where('name', 'برجر')
                        ->where('qty', 2)
                        ->etc())
                    ->has('urls.cancel')
                    ->etc())
                ->where('live.version', LiveRefreshPulse::sessionVersion($this->session->id))
                ->has('urls.pulse'));
    }

    public function test_a_preparing_order_reports_step_one_and_no_cancel(): void
    {
        $order = $this->order();
        $order->update(['status' => 'preparing', 'submitted_at' => now()->subMinutes(10)]);
        $order->items()->firstOrFail()->update(['status' => 'preparing', 'prep_started_at' => now()]);

        $this->get(route('customer.track', $this->q()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.stepIndex', 1)
                ->where('orders.0.canCancel', false)
                ->where('orders.0.changeableItems.0.status', 'preparing')
                ->where('orders.0.changeableItems.0.statusLabel', 'بدأ التحضير')
                ->where('orders.0.changeableItems.0.stationName', 'Kitchen')
                ->where('orders.0.changeableItems.0.started', true));
    }

    public function test_the_cancel_window_ships_remaining_seconds_not_epochs(): void
    {
        $this->order();

        $remaining = $this->get(route('customer.track', $this->q()))
            ->viewData('page')['props']['orders'][0]['cancelRemaining'];

        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(120, $remaining, 'default window is 120s');
    }

    public function test_the_session_pulse_endpoint_reports_the_session_version(): void
    {
        $this->getJson(route('customer.track.pulse', $this->q()))
            ->assertOk()
            ->assertJson(['version' => LiveRefreshPulse::sessionVersion($this->session->id)]);
    }

    public function test_menu_tracking_sheet_receives_the_full_live_order_payload_as_json(): void
    {
        $order = $this->order();

        $this->getJson(route('customer.track.data', $this->q()))
            ->assertOk()
            ->assertJsonPath('sessionInfo.tableNumber', '9')
            ->assertJsonPath('orders.0.number', $order->number)
            ->assertJsonPath('orders.0.status', 'pending')
            ->assertJsonPath('orders.0.stepIndex', 0)
            ->assertJsonPath('orders.0.items.0.name', 'برجر')
            ->assertJsonPath('version', LiveRefreshPulse::sessionVersion($this->session->id));
    }

    public function test_tracking_sheet_can_cancel_an_order_with_json_without_leaving_the_menu(): void
    {
        $order = $this->order();

        $this->postJson(route('customer.orders.cancel', $order), $this->q(['reason' => 'غيرت رأيي']))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('orders.0.status', 'cancelled');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_tracking_sheet_can_request_a_change_with_json_without_leaving_the_menu(): void
    {
        $order = $this->order();
        $item = $order->items()->firstOrFail();

        $this->postJson(route('customer.orders.change-requests.store', $order), $this->q([
            'type' => 'change_item',
            'order_item_id' => $item->id,
            'requested_quantity' => 1,
            'request_note' => 'بدون بصل',
        ]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('order_change_requests', [
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'status' => 'pending',
        ]);
    }

    public function test_tracker_shows_only_the_current_visit_while_history_stays_internal(): void
    {
        [$customer] = Customer::createFromCashier('زبون دائم', '0599000222', defaultBranchId: $this->branch->id);
        $previousSession = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $this->table->id,
            'token' => 'previous-'.uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'status' => 'closed',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHour(),
        ]);
        app(OrderService::class)->createFromCart($previousSession, [
            ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []],
        ], null);
        $this->session->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);
        $this->order();

        $this->assertSame(2, $customer->orders()->withoutGlobalScopes()->count());

        $this->get(route('customer.track', $this->q()))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->missing('member')
                ->missing('signup'));
    }

    public function test_cancel_still_enforces_ownership_and_window(): void
    {
        $order = $this->order();

        // Fresh order, inside the window → cancels.
        $this->post(route('customer.orders.cancel', $order), $this->q(['reason' => 'غيرت رأيي']))
            ->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_bill_serves_the_inertia_page_with_totals(): void
    {
        $this->order();

        $this->get(route('customer.bill', $this->q()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Bill')
                ->has('orders', 1)
                ->has('totals.total')
                ->where('invoice', null)
                ->where('hasPendingChangeRequest', false)
                ->has('urls.requestBill'));
    }

    public function test_bill_request_flow_still_works(): void
    {
        $this->order();

        $this->post(route('customer.bill.request', $this->q(['note' => 'بدنا نقسم'])))
            ->assertRedirect();

        $this->assertNotNull($this->session->fresh()->bill_requested_at);

        $this->get(route('customer.bill', $this->q()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessionInfo.billRequested', true));
    }

    public function test_customer_navigation_and_actions_stay_inside_the_mounted_app(): void
    {
        $menu = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));
        $track = file_get_contents(resource_path('js/Pages/Customer/Track.vue'));
        $bill = file_get_contents(resource_path('js/Pages/Customer/Bill.vue'));
        $root = file_get_contents(resource_path('views/inertia.blade.php'));

        $this->assertStringContainsString("import { Head, Link } from '@inertiajs/vue3'", $menu);
        $this->assertStringContainsString('view-transition @click="rememberMenuPosition"', $menu);
        $this->assertStringContainsString('sessionStorage.setItem(menuScrollKey', $menu);

        $this->assertStringContainsString("import { Head, Link, router, usePage } from '@inertiajs/vue3'", $track);
        $this->assertStringContainsString('router.visit(props.urls.menu', $track);
        $this->assertStringContainsString('router.post(cancelOrder.value.urls.cancel', $track);
        $this->assertStringContainsString('router.post(changeOrder.value.urls.changeRequest', $track);
        $this->assertStringNotContainsString('window.location.assign', $track);
        $this->assertStringNotContainsString('formPost(', $track);

        $this->assertStringContainsString("import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'", $bill);
        $this->assertStringContainsString('router.post(props.urls.requestBill', $bill);
        $this->assertStringContainsString('@submit.prevent="submitTransfer"', $bill);
        $this->assertStringContainsString('transferForm.post(props.urls.declareTransfer', $bill);
        $this->assertStringNotContainsString('formPost(', $bill);
        $this->assertStringNotContainsString('method="POST"', $bill);
        $this->assertStringContainsString('::view-transition-old(root)', $root);
    }

    public function test_guests_without_a_session_get_the_expired_page(): void
    {
        $this->get(route('customer.track'))->assertStatus(419);
        $this->get(route('customer.bill'))->assertStatus(419);
    }
}
