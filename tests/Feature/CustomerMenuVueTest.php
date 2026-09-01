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
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillingService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Wave 2 (MIGRATION-PILOT.md §13): the customer menu on Inertia/Vue.
 *
 * The cart endpoints keep their full suites (CustomerStockGatingTest,
 * MenuPromotionTest) — this file covers the new Inertia surface: the
 * decorated item payload (promo effectivePrice, live stock reasons),
 * session identity, the money/tax contract, and the absorbed /cart page.
 */
class CustomerMenuVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Table $table;

    protected TableSession $session;

    protected MenuItem $burger;

    protected MenuItem $truffle;   // out of stock

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'cm', 'name' => 'CM', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        $this->table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '7',
            'capacity' => 4, 'status' => 'occupied', 'active' => true,
        ]);
        $this->session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'cm-'.uniqid(), 'status' => 'active',
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
            'default_station_id' => $kitchen->id, 'active' => true, 'display_order' => 1,
        ]);

        $patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $gram->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 1, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $patty->id, 'storage_location_id' => $storage->id,
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
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'برجر', 'price' => 10,
            'is_available' => true, 'display_order' => 1,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $patty->id, 'quantity' => 150, 'unit_id' => $gram->id]);

        $this->truffle = MenuItem::create([
            'category_id' => $category->id, 'station_id' => $kitchen->id,
            'sku' => 'T-1', 'slug' => 'truffle', 'name' => 'ترافل', 'price' => 30,
            'is_available' => true, 'display_order' => 2,
        ]);
        RecipeItem::create(['menu_item_id' => $this->truffle->id, 'ingredient_id' => $truffleIng->id, 'quantity' => 50, 'unit_id' => $gram->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** The table QR is the only menu URL; the active visit cookie proves the
     * browser already entered through that table's physical QR. */
    protected function openMenu()
    {
        return $this
            ->withCookie('table_session', $this->session->token)
            ->get(route('customer.menu.open', $this->table->qr_token));
    }

    protected function sessionParam(array $extra = []): array
    {
        return array_merge(['session' => $this->session->token], $extra);
    }

    public function test_scanning_qr_opens_a_browsing_session_without_occupying_the_table(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '8',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]);

        $this->get(route('customer.menu.open', $table->qr_token))
            ->assertRedirect(route('customer.menu.open', $table->qr_token));

        $table->refresh();
        $this->assertSame('available', $table->status, 'browsing the menu is not an occupied table');
        $this->assertNotNull($table->activeSession, 'the browser still needs a session-backed cart');
        $this->assertFalse($table->activeSession->orders()->exists());
    }

    public function test_a_new_scanner_reuses_an_abandoned_browsing_session_without_identity_leak_or_busy_page(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '9',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ]);
        $browse = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $table->id,
            'token' => 'abandoned-'.uniqid(),
            'status' => 'active',
            'opened_at' => now(),
            'customer_name' => 'Previous visitor',
            'customer_phone' => '0599000000',
        ]);

        $this->get(route('customer.menu.open', $table->qr_token))
            ->assertRedirect(route('customer.menu.open', $table->qr_token));

        $this->assertSame(1, $table->sessions()->where('status', 'active')->count());
        $this->assertNull($browse->fresh()->customer_name);
        $this->assertNull($browse->fresh()->customer_phone);
        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_scanning_an_occupied_table_rejoins_its_active_visit_without_a_busy_wall(): void
    {
        $openedAt = $this->session->opened_at;

        $response = $this->get(route('customer.menu.open', $this->table->qr_token));

        $response
            ->assertRedirect(route('customer.menu.open', $this->table->qr_token))
            ->assertCookie('table_session', $this->session->token);

        $this->assertSame(1, $this->table->sessions()->where('status', 'active')->count());
        $this->assertSame($openedAt->toDateTimeString(), $this->session->fresh()->opened_at->toDateTimeString());
        $this->assertSame('occupied', $this->table->fresh()->status);
    }

    public function test_the_menu_serves_the_inertia_page_with_decorated_items(): void
    {
        $this->openMenu()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Menu')
                ->where('sessionInfo.tableNumber', '7')
                ->where('sessionInfo.canOrder', true)
                ->where('sessionInfo.helpPending', false)
                ->has('money.symbol')
                ->has('categories', 1, fn (Assert $cat) => $cat
                    ->has('items', 2)
                    ->has('items.0', fn (Assert $item) => $item
                        ->where('name', 'برجر')
                        ->where('price', 10)
                        ->where('can_order', true)
                        ->where('unavailable_reason', null)
                        ->where('ingredients.0', 'Patty')
                        ->etc())
                    ->has('items.1', fn (Assert $item) => $item
                        ->where('name', 'ترافل')
                        ->where('can_order', false)
                        ->where('unavailable_reason', 'غير متوفر اليوم')
                        ->etc())
                    ->etc())
                ->has('submitToken')
                ->has('sessionOrders', 0)
                ->has('urls.cartSubmit')
                ->where('urls.trackData', route('customer.track.data'))
                ->where('urls.trackPulse', route('customer.track.pulse'))
                ->where('urls.callWaiter', route('customer.help.request')));
    }

    public function test_sold_out_dish_stays_visible_and_returns_automatically_after_restock(): void
    {
        $this->openMenu()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories.0.items', 2)
                ->where('categories.0.items.1.id', $this->truffle->id)
                ->where('categories.0.items.1.can_order', false)
                ->where('categories.0.items.1.unavailable_reason', 'غير متوفر اليوم'));

        $ingredient = $this->truffle->recipeItems()->firstOrFail()->ingredient;
        IngredientStock::where('ingredient_id', $ingredient->id)->update(['quantity' => 5000]);
        $ingredient->update(['current_stock' => 5000]);

        $this->openMenu()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories.0.items', 2)
                ->where('categories.0.items.1.id', $this->truffle->id)
                ->where('categories.0.items.1.can_order', true)
                ->where('categories.0.items.1.unavailable_reason', null));
    }

    public function test_call_waiter_is_persisted_idempotent_and_visible_to_the_menu(): void
    {
        $this->table->update(['status' => 'available']);

        $first = $this->postJson(route('customer.help.request'), $this->sessionParam());

        $first->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pending', true)
            ->assertJsonPath('already_pending', false)
            ->assertJsonStructure(['requested_at']);

        $requestedAt = $this->session->fresh()->help_requested_at;
        $this->assertNotNull($requestedAt);
        $this->assertSame('occupied', $this->table->fresh()->status, 'an explicit waiter call claims the table');
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'session.help_requested',
            'subject_type' => TableSession::class,
            'subject_id' => $this->session->id,
        ]);

        $this->postJson(route('customer.help.request'), $this->sessionParam())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already_pending', true);

        $this->assertTrue($requestedAt->equalTo($this->session->fresh()->help_requested_at));

        $this->openMenu()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/Menu')
                ->where('sessionInfo.helpPending', true));
    }

    public function test_the_plain_menu_url_does_not_exist(): void
    {
        $this->get('/menu')->assertNotFound();
        $this->assertFalse(Route::has('customer.menu'));
    }

    public function test_the_cart_page_is_absorbed_into_the_menu(): void
    {
        $this->get(route('customer.cart.view', ['session' => $this->session->token]))
            ->assertRedirect(route('customer.menu.open', $this->table->qr_token));
    }

    public function test_the_cart_hydration_endpoint_returns_rows(): void
    {
        // Seed one row through the real add endpoint (stock-gated).
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 2,
        ]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('row.quantity', 2);

        $this->getJson(route('customer.cart.view', ['session' => $this->session->token]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'cart')
            ->assertJsonStructure(['submitToken']);
    }

    public function test_customer_can_remove_an_ingredient_and_the_choice_survives_submission_and_tracking(): void
    {
        $ingredient = $this->burger->recipeItems()->firstOrFail()->ingredient;

        $this->openMenu()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.items.0.removable_ingredients.0.id', $ingredient->id)
                ->where('categories.0.items.0.removable_ingredients.0.name', 'Patty')
                ->where('categories.0.items.0.removable_ingredients.0.requires_confirmation', true));

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
            'excluded_ingredient_ids' => [$ingredient->id],
        ]))
            ->assertOk()
            ->assertJsonPath('row.excluded_ingredient_ids.0', $ingredient->id)
            ->assertJsonPath('row.excluded_ingredients.0.name', 'Patty');

        $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => 'customer-without-patty',
        ]))
            ->assertOk()
            ->assertJsonPath('orders.0.items.0.exclusions.0', 'Patty');

        $line = $this->session->orders()->sole()->items()->sole();
        $this->assertDatabaseHas('order_item_ingredient_exclusions', [
            'order_item_id' => $line->id,
            'ingredient_id' => $ingredient->id,
            'name_snapshot' => 'Patty',
        ]);

        $this->getJson(route('customer.track.data', ['session' => $this->session->token]))
            ->assertOk()
            ->assertJsonPath('orders.0.items.0.exclusions.0', 'Patty');
    }

    public function test_cart_remove_speaks_json_now(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id, 'quantity' => 1,
        ]))->assertOk();

        $row = $this->getJson(route('customer.cart.view', ['session' => $this->session->token]))->json('cart.0');

        $this->postJson(route('customer.cart.remove'), $this->sessionParam(['row_id' => $row['id']]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson(route('customer.cart.view', ['session' => $this->session->token]))
            ->assertJsonCount(0, 'cart');
    }

    public function test_submit_still_creates_a_pending_order_and_redirects_to_track(): void
    {
        $this->table->update(['status' => 'available']);

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id, 'quantity' => 1,
        ]))->assertOk();

        $this->post(route('customer.cart.submit'), $this->sessionParam([
            'customer_phone' => '0599000771',
        ]))
            ->assertRedirect(route('customer.track'));

        $order = $this->session->orders()->first();
        $customer = Customer::query()->sole();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status, 'QR orders must wait for approval — never auto-fire');
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($customer->id, $this->session->fresh()->customer_id);
        $this->assertNotNull($customer->loyalty_customer_id);
        $this->assertSame('زبون 0771', $customer->name);
        $this->assertSame('occupied', $this->table->fresh()->status, 'the first real order claims the table');
    }

    public function test_mobile_submit_stays_on_the_menu_ignores_qr_cover_count_and_returns_the_saved_round(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 2,
        ]))->assertOk();

        $token = 'mobile-round-one';
        $response = $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => $token,
            'cover_count' => 17,
        ]));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('orders.0.roundNumber', 1)
            ->assertJsonPath('orders.0.status', 'pending')
            ->assertJsonPath('orders.0.items.0.name', 'برجر')
            ->assertJsonPath('orders.0.items.0.qty', 2)
            ->assertJsonPath('sessionInfo.canOrder', true)
            ->assertJsonStructure([
                'submitToken',
                'orders' => [['statusLabel', 'total']],
            ]);

        $this->assertNotSame($token, $response->json('submitToken'));
        $this->assertArrayNotHasKey('coverCount', $response->json('sessionInfo'));
        $this->assertSame(2, $this->session->fresh()->cover_count, 'QR ordering must not manage covers');
        $this->assertSame([], session('cart.'.$this->session->token, []));

        $this->openMenu()->assertInertia(fn (Assert $page) => $page
            ->has('sessionOrders', 1)
            ->where('sessionOrders.0.roundNumber', 1)
            ->where('sessionOrders.0.items.0.name', 'برجر'));
    }

    public function test_mobile_can_send_a_second_round_on_the_same_table_session(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();

        $first = $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => 'mobile-first-round',
        ]))->assertOk();

        // Food may already be on the table. Drinks and dessert still belong
        // to this same visit and will be billed on the same invoice.
        $this->session->orders()->sole()->update(['status' => 'delivered']);

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();

        $second = $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => $first->json('submitToken'),
        ]));

        $second->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'orders')
            ->assertJsonPath('orders.0.roundNumber', 2)
            ->assertJsonPath('orders.1.roundNumber', 1);

        $this->assertSame(2, $this->session->orders()->count());
        $this->assertSame($this->session->id, $this->session->orders()->latest()->first()->table_session_id);
    }

    public function test_all_rounds_in_the_same_visit_are_combined_into_one_invoice(): void
    {
        foreach (['invoice-round-one', 'invoice-round-two'] as $token) {
            $this->postJson(route('customer.cart.add'), $this->sessionParam([
                'menu_item_id' => $this->burger->id,
                'quantity' => 1,
            ]))->assertOk();

            $this->postJson(route('customer.cart.submit'), $this->sessionParam([
                '_idem' => $token,
            ]))->assertOk();
        }

        $cashier = User::factory()->create([
            'username' => 'round-invoice-cashier',
            'role' => 'cashier',
        ]);
        $cashier->branches()->attach($this->branch->id);
        $invoice = app(BillingService::class)->issueInvoice($this->session->fresh(), $cashier->id);

        $this->assertSame($this->session->id, $invoice->table_session_id);
        $this->assertSame(20.0, (float) $invoice->subtotal);
        $this->assertSame(20.0, (float) $invoice->total);
        $this->assertSame(1, $this->session->invoice()->where('status', '!=', 'cancelled')->count());
        $this->assertSame(2, $this->session->orders()->count());
    }

    public function test_mobile_retry_of_the_same_round_is_acknowledged_without_duplication(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();

        $token = 'mobile-retry-round';
        $this->postJson(route('customer.cart.submit'), $this->sessionParam(['_idem' => $token]))
            ->assertOk();

        $this->postJson(route('customer.cart.submit'), $this->sessionParam(['_idem' => $token]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('replayed', true)
            ->assertJsonCount(1, 'orders');

        $this->assertSame(1, $this->session->orders()->count());
    }

    public function test_qr_submit_allows_anonymous_order_without_name_or_phone(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();

        $this->post(route('customer.cart.submit'), $this->sessionParam())
            ->assertRedirect(route('customer.track'));

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 1);
        $this->assertNull($this->session->orders()->sole()->customer_id);
        $this->assertNull($this->session->fresh()->customer_id);
    }

    public function test_optional_phone_is_validated_only_when_supplied(): void
    {
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();

        $this->post(route('customer.cart.submit'), $this->sessionParam([
            'customer_phone' => '123',
        ]))->assertSessionHasErrors('customer_phone');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cart_asks_only_for_an_optional_phone(): void
    {
        $source = file_get_contents(resource_path('js/Components/CustomerMenu/CartSheet.vue'));

        $this->assertStringNotContainsString('customerName', $source);
        $this->assertStringNotContainsString("add('customer_name'", $source);
        $this->assertStringNotContainsString('coverCount', $source);
        $this->assertStringNotContainsString('cover_count', $source);
        $this->assertStringNotContainsString('ct-covers', $source);
        $this->assertStringContainsString('رقم الجوال <small>(اختياري)</small>', $source);
        $this->assertStringContainsString('يمكنك تركه فارغاً وإرسال الطلب مباشرة', $source);
    }

    public function test_mobile_round_submission_stays_in_place_and_keeps_previous_orders_visible(): void
    {
        $menu = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));
        $cart = file_get_contents(resource_path('js/Components/CustomerMenu/CartSheet.vue'));

        $this->assertStringContainsString('const submitOrder = async', $menu);
        $this->assertStringContainsString('@submit="submitOrder"', $menu);
        $this->assertStringContainsString('v-for="order in submittedOrders"', $menu);
        $this->assertStringContainsString('طلبات جلستك', $menu);
        $this->assertStringNotContainsString("document.createElement('form')", $cart);
        $this->assertStringContainsString('جولة جديدة للمطبخ، وتُجمع تلقائياً مع طلباتك السابقة في فاتورة واحدة.', $cart);
        $this->assertStringContainsString('وجميع الجولات على فاتورة واحدة', $menu);
    }

    public function test_tracking_opens_inside_the_menu_without_navigating_to_a_new_page(): void
    {
        $menu = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));
        $sheet = file_get_contents(resource_path('js/Components/CustomerMenu/OrderTrackingSheet.vue'));
        $cart = file_get_contents(resource_path('js/Components/CustomerMenu/CartSheet.vue'));

        $this->assertStringContainsString('trackingOpen = true', $menu);
        $this->assertStringContainsString('<OrderTrackingSheet', $menu);
        $this->assertStringNotContainsString(':href="urls.track"', $menu);
        $this->assertStringContainsString('fetch(props.urls.trackData', $sheet);
        $this->assertStringContainsString('window.setInterval(checkPulse, 5000)', $sheet);
        $this->assertStringContainsString('<TrackCard', $sheet);
        $this->assertStringContainsString('window.setInterval(checkOrderPulse, 5000)', $menu);
        $this->assertStringContainsString('trackingOpen.value || document.hidden', $menu);
        $this->assertStringContainsString('announceOrderTransitions(nextOrders)', $menu);
        $this->assertStringContainsString('aria-live="polite"', $menu);
        $this->assertStringContainsString('أرسل الطلب', $cart);
        $this->assertStringContainsString('أرسل الجولة الجديدة', $cart);
        $this->assertStringNotContainsString('أرسل الطلب للمطبخ', $cart);
    }

    public function test_mobile_featured_menu_keeps_a_native_swipe_slider_contract(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));

        $this->assertStringContainsString('ref="featuredTrack"', $source);
        $this->assertStringContainsString('scroll-snap-type: x mandatory', $source);
        $this->assertStringContainsString('goToFeatured', $source);
    }

    public function test_every_mobile_category_is_a_large_native_swipe_track(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));

        $this->assertStringContainsString('class="qm-grid qm-category-track"', $source);
        $this->assertStringContainsString('class="qm-swipe-hint"', $source);
        $this->assertStringContainsString('اسحب للتصفح', $source);
        $this->assertStringContainsString('flex: 0 0 min(320px, 84vw)', $source);
    }

    public function test_menu_cards_and_details_keep_recipe_ingredients_visible(): void
    {
        $card = file_get_contents(resource_path('js/Components/CustomerMenu/DishCard.vue'));
        $sheet = file_get_contents(resource_path('js/Components/CustomerMenu/ItemSheet.vue'));

        $this->assertStringContainsString("item.ingredients.join('، ')", $card);
        $this->assertStringContainsString("t('dish_ingredients')", $card);
        $this->assertStringContainsString('v-for="ing in item.ingredients"', $sheet);
        $this->assertStringContainsString("t('dish_ingredients')", $sheet);
    }

    public function test_sold_out_dishes_still_open_their_ingredients_without_allowing_an_order(): void
    {
        $card = file_get_contents(resource_path('js/Components/CustomerMenu/DishCard.vue'));
        $sheet = file_get_contents(resource_path('js/Components/CustomerMenu/ItemSheet.vue'));

        $this->assertStringContainsString('@click="$emit(\'open\', item)"', $card);
        $this->assertStringNotContainsString('@click="item.can_order && $emit(\'open\', item)"', $card);
        $this->assertStringContainsString('عرض مكونات وتفاصيل', $card);
        $this->assertStringContainsString('<footer v-if="item.can_order && orderingEnabled" class="cs-foot">', $sheet);
        $this->assertStringContainsString('رجوع للمنيو', $sheet);
    }

    public function test_first_ordering_phone_owns_diner_mutations_while_other_phones_can_browse(): void
    {
        $this->withSession(['qr_order_device' => 'owner-phone']);

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();
        $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => 'owner-first-round',
        ]))->assertOk();

        $order = $this->session->orders()->sole();
        $this->assertSame(hash('sha256', 'owner-phone'), $this->session->fresh()->ordering_device_hash);

        $this->withSession(['qr_order_device' => 'second-phone']);
        $this->openMenu()->assertInertia(fn (Assert $page) => $page
            ->where('sessionInfo.canOrder', false)
            ->has('sessionOrders', 1));

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'ordering_device_locked');

        $this->postJson(route('customer.orders.cancel', $order), $this->sessionParam())
            ->assertStatus(409)
            ->assertJsonPath('error', 'ordering_device_locked');
        $this->assertSame(1, $this->session->orders()->count());

        $this->withSession(['qr_order_device' => 'owner-phone']);
        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))->assertOk();
        $this->postJson(route('customer.cart.submit'), $this->sessionParam([
            '_idem' => 'owner-later-round',
        ]))->assertOk()->assertJsonCount(2, 'orders');

        $this->assertSame(2, $this->session->orders()->count());
    }

    public function test_customer_menu_explains_read_only_mode_and_disables_order_controls(): void
    {
        $menu = file_get_contents(resource_path('js/Pages/Customer/Menu.vue'));
        $card = file_get_contents(resource_path('js/Components/CustomerMenu/DishCard.vue'));
        $sheet = file_get_contents(resource_path('js/Components/CustomerMenu/ItemSheet.vue'));
        $cart = file_get_contents(resource_path('js/Components/CustomerMenu/CartSheet.vue'));
        $tracking = file_get_contents(resource_path('js/Components/CustomerMenu/OrderTrackingSheet.vue'));
        $trackCard = file_get_contents(resource_path('js/Components/CustomerTrack/TrackCard.vue'));

        $this->assertStringContainsString('orderingEnabled', $menu);
        $this->assertStringContainsString('الطلب مفتوح من هاتف آخر', $menu);
        $this->assertStringContainsString(':ordering-enabled="orderingEnabled"', $menu);
        $this->assertStringContainsString('item.can_order && orderingEnabled', $card);
        $this->assertStringContainsString('item.can_order && orderingEnabled', $sheet);
        $this->assertStringContainsString('! orderingEnabled', $cart);
        $this->assertStringContainsString(':can-manage="orderingEnabled"', $tracking);
        $this->assertStringContainsString('canManage && (canStillCancel || order.canRequestChange)', $trackCard);
    }

    public function test_qr_browsing_does_not_clear_the_table_cleaning_flag(): void
    {
        $table = Table::create([
            'branch_id' => $this->branch->id,
            'number' => '10',
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
            'needs_cleaning_since' => now()->subMinutes(5),
        ]);

        $this->get(route('customer.menu.open', $table->qr_token))->assertRedirect();

        $this->assertNotNull($table->fresh()->needs_cleaning_since);
        $this->assertNull($table->activeSession?->engaged_at);
    }

    public function test_later_round_reuses_the_session_and_reopens_an_unissued_bill_request(): void
    {
        $this->session->update(['bill_requested_at' => now(), 'bill_request_note' => 'كاش']);

        foreach (['round-one', 'round-two'] as $token) {
            $this->postJson(route('customer.cart.add'), $this->sessionParam([
                'menu_item_id' => $this->burger->id,
                'quantity' => 1,
            ]))->assertOk();

            $this->post(route('customer.cart.submit'), $this->sessionParam(['_idem' => $token]))
                ->assertRedirect(route('customer.track'));
        }

        $this->assertSame(2, $this->session->orders()->count());
        $this->assertNull($this->session->fresh()->bill_requested_at);
        $this->assertNotNull($this->session->fresh()->engaged_at);
    }

    public function test_pending_bank_transfer_locks_the_bill_against_new_items(): void
    {
        PendingTransfer::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'amount' => 20,
            'sender_name' => 'زبون',
            'status' => PendingTransfer::STATUS_PENDING,
        ]);

        $this->postJson(route('customer.cart.add'), $this->sessionParam([
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'pending_transfer');

        $this->assertSame([], session('cart.'.$this->session->token, []));
    }
}
