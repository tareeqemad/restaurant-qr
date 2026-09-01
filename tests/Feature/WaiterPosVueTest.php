<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\PendingTransfer;
use App\Models\RecipeItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StaffMealCharge;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * المرحلة 1 (MIGRATION-PILOT.md): the three endpoint contracts behind the
 * Vue waiter POS. Fixtures mirror WaiterPosTest so behavior stays comparable
 * screen-to-screen — same burger/shawarma/truffle cast, same money math.
 */
class WaiterPosVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $waiter;

    protected MenuItem $burger;      // plain, plenty of stock

    protected MenuItem $shawarma;    // has a modifier group (cheese +2)

    protected MenuItem $truffle;     // out of stock

    protected Ingredient $patty;

    protected ModifierGroup $extras;

    protected Modifier $cheese;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'wv', 'name' => 'WV', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'waiter', 'label' => 'Waiter', 'is_system' => true]);
        $this->waiter = User::create([
            'name' => 'Waiter', 'username' => 'waiter_vue', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
        ]);
        $this->waiter->branches()->attach($this->branch->id);

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

        $this->patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $this->patty->id, 'storage_location_id' => $storage->id,
            'quantity' => 5000, 'reorder_threshold' => 0,
        ]);
        $truffleIng = Ingredient::create([
            'name' => 'Truffle', 'base_unit_id' => $gram->id, 'current_stock' => 0,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $truffleIng->id, 'storage_location_id' => $storage->id,
            'quantity' => 0, 'reorder_threshold' => 0,
        ]);

        $this->burger = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id, 'quantity' => 150, 'unit_id' => $gram->id]);

        $this->shawarma = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'S-1', 'slug' => 'shawarma', 'name' => 'Shawarma', 'price' => 8,
            'is_available' => true, 'display_order' => 2,
        ]);
        RecipeItem::create(['menu_item_id' => $this->shawarma->id, 'ingredient_id' => $this->patty->id, 'quantity' => 100, 'unit_id' => $gram->id]);
        $this->extras = ModifierGroup::create([
            'branch_id' => $this->branch->id, 'slug' => 'extras', 'name' => 'إضافات',
            'min_select' => 0, 'max_select' => 1, 'required' => false, 'active' => true,
        ]);
        $this->cheese = Modifier::create([
            'modifier_group_id' => $this->extras->id, 'name' => 'جبنة', 'price_delta' => 2, 'active' => true,
        ]);
        $this->shawarma->modifierGroups()->attach($this->extras->id, ['display_order' => 0]);

        $this->truffle = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'T-1', 'slug' => 'truffle', 'name' => 'Truffle Pasta', 'price' => 25,
            'is_available' => true, 'display_order' => 3,
        ]);
        RecipeItem::create(['menu_item_id' => $this->truffle->id, 'ingredient_id' => $truffleIng->id, 'quantity' => 10, 'unit_id' => $gram->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    protected function table(string $number): Table
    {
        return Table::create([
            'branch_id' => $this->branch->id, 'number' => $number,
            'capacity' => 4, 'status' => 'available', 'active' => true,
        ]);
    }

    protected function line(MenuItem $item, int $qty = 1, array $mods = [], ?string $notes = null, array $excluded = []): array
    {
        return [
            'menu_item_id' => $item->id,
            'quantity' => $qty,
            'modifier_ids' => $mods,
            'excluded_ingredient_ids' => $excluded,
            'line_notes' => $notes,
        ];
    }

    // ─── show ─────────────────────────────────────────────────────────────

    /**
     * GO, then the clean break: the Vue screen owns the ONE official route,
     * and every trace of the pilot's /vue/ URLs is gone — page and API all
     * live under `waiter-orders/table/{table}` with no tech name leaking
     * into a URL.
     */
    public function test_the_official_route_serves_the_vue_screen(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('0');

        $this->get(route('admin.waiter-orders.create', $table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('WaiterPos/Show'));

        $this->get('/admin/waiter-orders/vue/'.$table->id)->assertNotFound();
    }

    public function test_show_serves_the_contract_props_and_opens_a_session(): void
    {
        $table = $this->table('1');

        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.create', $table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Strict again (Phase 3): sol's page file exists — a renamed
                // or deleted component must fail loudly here.
                ->component('WaiterPos/Show')
                ->where('table.number', '1')
                ->has('session.id')
                ->where('session.covers', 1)
                ->has('menu', 3)
                ->has('categories', 1)
                ->has('submitToken')
                ->has('notificationUrls.recent')
                ->where('carryOver.has_prior', false));

        $this->assertNotNull($table->fresh()->activeSession, 'opening the POS opens the session');
        $this->assertSame('occupied', $table->fresh()->status);
    }

    public function test_full_screen_waiter_pos_keeps_live_alerts_on_the_tablet(): void
    {
        $source = file_get_contents(resource_path('js/Pages/WaiterPos/Show.vue'));

        $this->assertStringContainsString("import NotificationsBell from '../../Components/AdminShell/NotificationsBell.vue'", $source);
        $this->assertStringContainsString('notificationUrls: { type: Object, required: true }', $source);
        $this->assertStringContainsString(':urls="notificationUrls"', $source);
    }

    public function test_waiter_reuses_a_qr_browsing_session_instead_of_opening_a_second_bill(): void
    {
        $table = $this->table('12');
        $browse = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $table->id,
            'token' => 'browse-'.uniqid(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
        ]);

        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.create', $table))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('WaiterPos/Show')
                ->where('session.id', $browse->id));

        $this->assertSame(1, $table->sessions()->where('status', 'active')->count());
        $this->assertSame($this->waiter->id, $browse->fresh()->assigned_waiter_id);
        $this->assertSame('occupied', $table->fresh()->status);
    }

    public function test_show_decorates_stock_promo_and_modifier_flags(): void
    {
        $table = $this->table('2');

        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.create', $table))
            ->assertInertia(function (Assert $page) {
                $menu = collect($page->toArray()['props']['menu']);

                $burger = $menu->firstWhere('id', $this->burger->id);
                $this->assertTrue($burger['in_stock']);
                $this->assertFalse($burger['has_mods']);

                $shawarma = $menu->firstWhere('id', $this->shawarma->id);
                $this->assertTrue($shawarma['has_mods']);
                $this->assertSame('جبنة', $shawarma['modifier_groups'][0]['modifiers'][0]['name']);

                $truffle = $menu->firstWhere('id', $this->truffle->id);
                $this->assertFalse($truffle['in_stock'], 'zero truffle stock must gate the tile');

                return $page->component('WaiterPos/Show');
            });
    }

    public function test_show_ships_the_display_currency(): void
    {
        Setting::put('currency_symbol', 'ش.إ', 'general', 'string');
        $table = $this->table('11');

        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.create', $table))
            ->assertInertia(fn (Assert $page) => $page
                ->component('WaiterPos/Show')
                ->where('currency.symbol', 'ش.إ')
                ->where('currency.decimals', 2));
    }

    // ─── covers ───────────────────────────────────────────────────────────

    /** Delta-based, clamped 1..50 — fast taps must all count, zero must not stick. */
    public function test_covers_stepper_applies_deltas_with_clamps(): void
    {
        $table = $this->table('10');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));
        $session = $table->fresh()->activeSession;

        $this->postJson(route('admin.waiter-orders.covers', $table), ['delta' => 3])
            ->assertOk()->assertJsonPath('covers', 4);
        $this->assertSame(4, (int) $session->fresh()->cover_count);

        $this->postJson(route('admin.waiter-orders.covers', $table), ['delta' => -10])
            ->assertOk()->assertJsonPath('covers', 1);

        $session->update(['status' => 'closed']);
        $this->postJson(route('admin.waiter-orders.covers', $table), ['delta' => 1])
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    // ─── preview-stock ────────────────────────────────────────────────────

    public function test_preview_stock_names_the_shortage(): void
    {
        $table = $this->table('3');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));

        $this->postJson(route('admin.waiter-orders.preview-stock', $table), [
            'cart' => [$this->line($this->truffle)],
        ])->assertOk()
            ->assertJsonPath('issues.0.ingredient', 'Truffle');
    }

    // ─── submit ───────────────────────────────────────────────────────────

    public function test_submit_creates_prices_and_fires_the_order(): void
    {
        $table = $this->table('4');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-t-1',
            'notes' => 'بدون كاتشاب',
            'cart' => [
                $this->line($this->burger, 2),
                $this->line($this->shawarma, 1, [$this->cheese->id], 'بدون بصل'),
            ],
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fired', true);

        $order = Order::where('table_session_id', $sessionId)->firstOrFail();
        $this->assertSame(OrderStatus::Approved->value, $order->status, 'direct fire → straight to the kitchen');
        $this->assertSame('بدون كاتشاب', $order->customer_notes);
        // Server-side pricing: burger 2×10 + shawarma (8+2) = 30 — never the client's numbers.
        $this->assertSame(30.0, (float) $order->subtotal);
        $this->assertSame(1, $order->items()->where('menu_item_id', $this->shawarma->id)->first()->modifiers()->count());
    }

    public function test_waiter_reviews_a_qr_round_with_an_ingredient_removed_before_firing_it(): void
    {
        $table = $this->table('41');
        $session = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $table->id,
            'token' => 'review-'.uniqid(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
            'assigned_waiter_id' => $this->waiter->id,
        ]);
        $table->update(['status' => 'occupied']);

        $order = app(OrderService::class)->createFromCart(
            session: $session,
            cart: [[
                'menu_item_id' => $this->burger->id,
                'quantity' => 1,
                'modifier_ids' => [],
                'excluded_ingredient_ids' => [$this->patty->id],
            ]],
        );
        $this->assertSame(OrderStatus::Pending->value, $order->status);

        $this->actingAs($this->waiter)
            ->get(route('admin.waiter-orders.create', ['table' => $table, 'review_order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviewOrder.id', $order->id)
                ->where('reviewOrder.lines.0.excluded_ingredient_ids.0', $this->patty->id)
                ->where('reviewOrder.lines.0.excluded_ingredient_labels.0', 'Patty'));

        $this->postJson(route('admin.waiter-orders.review', ['table' => $table, 'order' => $order]), [
            'expected_version' => $order->fresh()->updated_at->toISOString(),
            'change_request_ids' => [],
            'cart' => [[
                'order_item_id' => $order->items()->firstOrFail()->id,
                'menu_item_id' => $this->burger->id,
                'quantity' => 1,
                'modifier_ids' => [],
                'excluded_ingredient_ids' => [$this->patty->id],
                'line_notes' => 'تأكيد مع الزبون',
            ]],
        ])->assertOk()->assertJsonPath('ok', true);

        $line = $order->fresh()->items()->firstOrFail();
        $this->assertSame(OrderStatus::Approved->value, $order->fresh()->status);
        $this->assertDatabaseHas('order_item_ingredient_exclusions', [
            'order_item_id' => $line->id,
            'ingredient_id' => $this->patty->id,
            'name_snapshot' => 'Patty',
        ]);
        $this->assertSame([], app(InventoryService::class)->previewDeductionForItem(
            $this->burger->fresh('recipeItems.ingredient'),
            1,
            [],
            [$this->patty->id],
        ), 'an excluded ingredient must not be reserved or deducted from inventory');
    }

    public function test_submit_replayed_token_refuses_without_a_twin_order(): void
    {
        $table = $this->table('5');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        $payload = ['token' => 'vue-t-2', 'cart' => [$this->line($this->burger)]];
        $this->postJson(route('admin.waiter-orders.submit', $table), $payload)->assertOk();
        $this->postJson(route('admin.waiter-orders.submit', $table), $payload)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(1, Order::where('table_session_id', $sessionId)->count());
    }

    public function test_a_stock_refusal_releases_the_token_for_an_honest_retry(): void
    {
        $table = $this->table('6');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-t-3', 'cart' => [$this->line($this->truffle)],
        ])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertSame(0, Order::where('table_session_id', $sessionId)->count());

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-t-3', 'cart' => [$this->line($this->burger)],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, Order::where('table_session_id', $sessionId)->count(),
            'a refused submit must not burn the token');
    }

    public function test_submit_enforces_modifier_group_limits_server_side(): void
    {
        $table = $this->table('7');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));

        $second = Modifier::create([
            'modifier_group_id' => $this->extras->id, 'name' => 'زيتون', 'price_delta' => 1, 'active' => true,
        ]);

        // max_select = 1 — two picks must bounce regardless of what the client checked.
        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-t-4',
            'cart' => [$this->line($this->shawarma, 1, [$this->cheese->id, $second->id])],
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertSame(0, Order::count());
    }

    public function test_submit_refuses_a_closed_session(): void
    {
        $table = $this->table('8');
        $this->actingAs($this->waiter)->get(route('admin.waiter-orders.create', $table));
        $table->fresh()->activeSession->update(['status' => 'closed']);

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-t-5', 'cart' => [$this->line($this->burger)],
        ])->assertStatus(422)->assertJsonPath('ok', false);
    }

    // ─── الميزات المنقولة من الشاشة القديمة (وجبة موظف / زبون / حوالة) ────

    public function test_a_staff_meal_submit_stamps_and_charges_the_employee(): void
    {
        $staff = User::create([
            'name' => 'موظف المطبخ', 'username' => 'staff_meal_vue', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'waiter',
            'monthly_meal_allowance' => 200,
        ]);
        $staff->branches()->attach($this->branch->id);

        $this->actingAs($this->waiter);
        $table = $this->table('20');
        $this->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-staff-1',
            'staff_user_id' => $staff->id,
            'cart' => [$this->line($this->burger)],
        ])->assertOk()->assertJsonPath('ok', true);

        $order = Order::where('table_session_id', $sessionId)->firstOrFail();
        $this->assertSame($staff->id, (int) $order->staff_consumer_user_id, 'the meal lands on the employee\'s tab');
        $this->assertFalse(StaffMealCharge::where('order_id', $order->id)->exists(),
            'Submitting the order must not close it before the kitchen prepares it.');

        $item = $order->items()->firstOrFail();
        $orders = app(OrderService::class);
        $orders->startPreparing($item, $this->waiter->id);
        $orders->markItemReady($item->fresh());
        $orders->markItemServed($item->fresh(), $this->waiter->id);

        $this->assertSame(OrderStatus::Completed->value, $order->fresh()->status);
        $charge = StaffMealCharge::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(0.0, (float) $charge->amount);
        $this->assertSame('allowance', $charge->settlement_method);
    }

    /** An ineligible pick refuses BEFORE the token burns — retry stays honest. */
    public function test_an_ineligible_staff_pick_refuses_and_keeps_the_token(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('21');
        $this->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        // waiter has NO allowance configured → ineligible as a consumer.
        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-staff-2',
            'staff_user_id' => $this->waiter->id,
            'cart' => [$this->line($this->burger)],
        ])->assertStatus(422);

        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'vue-staff-2',
            'cart' => [$this->line($this->burger)],
        ])->assertOk();

        $this->assertSame(1, Order::where('table_session_id', $sessionId)->count());
    }

    public function test_customer_can_be_linked_created_and_detached_without_login_steps(): void
    {
        $this->actingAs($this->waiter);
        $table = $this->table('22');
        $this->get(route('admin.waiter-orders.create', $table));
        $session = $table->fresh()->activeSession;
        $earlierOrder = app(OrderService::class)->createFromCart(
            $session,
            [$this->line($this->burger)],
            $this->waiter->id,
        );
        $this->assertNull($earlierOrder->customer_id);

        // Unknown phone without create flag → named refusal.
        $this->postJson(route('admin.waiter-orders.customer', $table), [
            'phone' => '0599000111',
        ])->assertStatus(422);

        // Create-on-miss → linked without adding a login step to table service.
        $created = $this->postJson(route('admin.waiter-orders.customer', $table), [
            'phone' => '0599000111', 'name' => 'أبو العبد', 'create_if_missing' => true,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('customer.name', 'أبو العبد')
            ->assertJsonMissingPath('pin');
        $customerId = $session->fresh()->customer_id;
        $this->assertNotNull($customerId);
        $this->assertSame($customerId, $earlierOrder->fresh()->customer_id, 'linking mid-visit must include rounds already sent');
        $this->assertNotNull(Customer::find($customerId)?->loyalty_customer_id);

        // Detach clears the snapshot.
        $this->postJson(route('admin.waiter-orders.customer', $table), ['detach' => true])
            ->assertOk();
        $this->assertNull($session->fresh()->customer_id);
    }

    public function test_a_transfer_claim_is_recorded_once_and_duplicates_refuse(): void
    {
        Setting::put('bank_transfer_details', 'بنك فلسطين — IBAN PS12', 'billing', 'string');

        $this->actingAs($this->waiter);
        $table = $this->table('23');
        $this->get(route('admin.waiter-orders.create', $table));
        $sessionId = $table->fresh()->activeSession->id;

        $this->postJson(route('admin.waiter-orders.transfer-claim', $table), [
            'amount' => 30, 'sender_name' => 'أبو العبد',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, PendingTransfer::where('table_session_id', $sessionId)->count());

        $this->postJson(route('admin.waiter-orders.transfer-claim', $table), [
            'amount' => 30, 'sender_name' => 'أبو العبد',
        ])->assertStatus(422);

        $this->assertSame(1, PendingTransfer::where('table_session_id', $sessionId)->count(),
            'a second pending claim must never stack on the same table');
    }

    public function test_guests_cannot_reach_any_pilot_endpoint(): void
    {
        $table = $this->table('9');

        $this->get(route('admin.waiter-orders.create', $table))->assertRedirect();
        $this->postJson(route('admin.waiter-orders.submit', $table), [
            'token' => 'x', 'cart' => [$this->line($this->burger)],
        ])->assertStatus(401);
    }
}
