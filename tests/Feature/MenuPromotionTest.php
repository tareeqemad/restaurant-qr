<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\OrderService;
use App\Services\PromotionService;
use App\Support\BranchContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Menu-item promotions — covers the three patterns the system supports:
 *
 *   1. Permanent sale_price
 *   2. Scheduled (starts_at/ends_at)
 *   3. Happy hour (time_from/time_to + days_of_week)
 *
 * Plus the parts the rest of the app cares about:
 *   - conflict resolution (priority → specificity → recency)
 *   - OrderService snapshots effective price + original + promotion_id
 *   - placed orders survive promo expiry / mutation (snapshot integrity)
 */
class MenuPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $manager;

    protected MenuItem $burger;

    protected Category $mains;

    protected Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable tax/service to keep totals readable in assertions.
        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'p', 'name' => 'P', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);
        $this->manager = $this->user('mgr_p', 'manager');

        $g = Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $this->mains = Category::create(['slug' => 'mains', 'name' => 'Mains', 'default_station_id' => $kitchen->id, 'active' => true]);

        $this->burger = MenuItem::create([
            'category_id' => $this->mains->id,
            'station_id' => $kitchen->id,
            'sku' => 'B-1', 'slug' => 'burger', 'name' => 'Burger',
            'price' => 30, 'is_available' => true,
        ]);

        $this->table = Table::create(['number' => 'T1', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        app(PromotionService::class)->flush();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Discount expression
    // ────────────────────────────────────────────────────────────────

    public function test_sale_price_returns_absolute_value_ignoring_menu_price(): void
    {
        $promo = $this->makePromo(['type' => 'sale_price', 'value' => 25]);
        $this->assertSame(25.0, $promo->applyTo(30.0));
        $this->assertSame(25.0, $promo->applyTo(100.0), 'sale_price is absolute — ignores the input price.');
    }

    public function test_percent_off_clamps_to_zero(): void
    {
        $promo = $this->makePromo(['type' => 'percent', 'value' => 20]);
        $this->assertSame(24.0, $promo->applyTo(30.0));
    }

    public function test_fixed_off_clamps_to_zero(): void
    {
        $promo = $this->makePromo(['type' => 'fixed_off', 'value' => 5]);
        $this->assertSame(25.0, $promo->applyTo(30.0));

        // Bigger than the base → clamps at 0, never negative.
        $big = $this->makePromo(['type' => 'fixed_off', 'value' => 999]);
        $this->assertSame(0.0, $big->applyTo(30.0));
    }

    // ────────────────────────────────────────────────────────────────
    // Schedule windows
    // ────────────────────────────────────────────────────────────────

    public function test_schedule_window_blocks_lookup_before_start(): void
    {
        $promo = $this->makePromo([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);
        $this->assertFalse($promo->isLiveAt(now()), 'Promo not yet started → not live.');
        $this->assertTrue($promo->isLiveAt(now()->addDays(2)), 'Inside window → live.');
        $this->assertFalse($promo->isLiveAt(now()->addMonth()), 'After end → not live.');
    }

    public function test_happy_hour_honors_time_window(): void
    {
        $promo = $this->makePromo([
            'time_from' => '15:00',
            'time_to' => '17:00',
        ]);
        // Carbon::parse with explicit times is timezone-stable here.
        $this->assertTrue($promo->isLiveAt(Carbon::parse('2026-06-01 16:00')),
            '16:00 falls inside 15:00-17:00.');
        $this->assertFalse($promo->isLiveAt(Carbon::parse('2026-06-01 14:30')),
            '14:30 is before the window.');
        $this->assertFalse($promo->isLiveAt(Carbon::parse('2026-06-01 18:00')),
            '18:00 is after the window.');
    }

    public function test_day_of_week_filter_excludes_off_days(): void
    {
        // 0 = Sun, 1 = Mon, 2 = Tue (Carbon's dayOfWeek). Allow only Mon+Tue.
        $promo = $this->makePromo(['days_of_week' => [1, 2]]);

        // 2026-06-01 is a Monday → in.
        $this->assertTrue($promo->isLiveAt(Carbon::parse('2026-06-01 12:00')));
        // 2026-06-03 is a Wednesday → out.
        $this->assertFalse($promo->isLiveAt(Carbon::parse('2026-06-03 12:00')));
    }

    // ────────────────────────────────────────────────────────────────
    // Conflict resolution
    // ────────────────────────────────────────────────────────────────

    public function test_higher_priority_wins(): void
    {
        $low = $this->makePromo(['name' => 'low',  'type' => 'percent', 'value' => 10, 'priority' => 1]);
        $high = $this->makePromo(['name' => 'high', 'type' => 'percent', 'value' => 50, 'priority' => 10]);

        $winner = $this->burger->activePromotion();
        $this->assertSame($high->id, $winner->id, 'Higher priority wins regardless of definition order.');
    }

    public function test_item_target_beats_category_target_at_same_priority(): void
    {
        $cat = $this->makePromo([
            'target_type' => 'category', 'target_id' => $this->mains->id,
            'type' => 'percent', 'value' => 10,
        ]);
        $item = $this->makePromo([
            'target_type' => 'menu_item', 'target_id' => $this->burger->id,
            'type' => 'percent', 'value' => 5,
        ]);

        $winner = $this->burger->activePromotion();
        $this->assertSame($item->id, $winner->id,
            'Same priority → most-specific (item-level) wins over category-level.');
    }

    public function test_inactive_master_switch_excludes_promo(): void
    {
        $this->makePromo(['active' => false, 'type' => 'sale_price', 'value' => 1]);
        $this->assertNull($this->burger->activePromotion(),
            'active=false promos are skipped even when the window matches.');
    }

    // ────────────────────────────────────────────────────────────────
    // Order integration
    // ────────────────────────────────────────────────────────────────

    public function test_order_item_snapshots_promo_price_and_original(): void
    {
        $promo = $this->makePromo(['type' => 'sale_price', 'value' => 25]);
        $order = $this->placeOrder(qty: 2);

        $line = $order->items->first();
        $this->assertSame(25.0, (float) $line->unit_price, 'Order takes the promoted price.');
        $this->assertSame(30.0, (float) $line->unit_price_original, 'Original menu price preserved.');
        $this->assertSame($promo->id, $line->promotion_id, 'promotion_id snapshot for audit.');
        $this->assertSame(50.0, (float) $line->subtotal, '2 × 25 = 50.');
        $this->assertTrue($line->wasDiscounted());
        $this->assertSame(10.0, $line->discountSavings(), '2 × (30-25) = 10 saved.');
    }

    public function test_order_keeps_snapshot_price_after_promo_expires(): void
    {
        $promo = $this->makePromo(['type' => 'percent', 'value' => 20]);
        $order = $this->placeOrder(qty: 1);
        $line = $order->items->first();
        $this->assertSame(24.0, (float) $line->unit_price);

        // Promo ends. Re-fetch the line — its prices MUST NOT drift.
        $promo->update(['active' => false]);
        $line->refresh();
        $this->assertSame(24.0, (float) $line->unit_price,
            'Once placed, the order keeps its snapshot price forever.');
        $this->assertSame(30.0, (float) $line->unit_price_original);
    }

    public function test_full_price_order_carries_no_promo_columns(): void
    {
        $order = $this->placeOrder(qty: 1);   // no promo created
        $line = $order->items->first();

        $this->assertSame(30.0, (float) $line->unit_price);
        $this->assertNull($line->unit_price_original,
            'No promo → no original snapshot (null signals full price).');
        $this->assertNull($line->promotion_id);
        $this->assertFalse($line->wasDiscounted());
    }

    public function test_category_targeted_promo_applies_to_items_in_that_category(): void
    {
        $this->makePromo([
            'target_type' => 'category',
            'target_id' => $this->mains->id,
            'type' => 'percent', 'value' => 10,
        ]);

        $this->assertSame(27.0, $this->burger->effectivePrice(),
            'Burger is in the Mains category — gets the 10% off automatically.');
    }

    public function test_branch_scoped_promo_only_applies_to_matching_branch(): void
    {
        $other = Branch::create(['code' => 'p2', 'name' => 'P2', 'is_active' => true]);
        // Promo bound to OTHER branch only.
        $this->makePromo([
            'branch_id' => $other->id,
            'type' => 'sale_price', 'value' => 15,
        ]);

        // We're in branch P (set by setUp), so the resolver should ignore
        // the other-branch promo and return full menu price.
        $this->assertSame(30.0, $this->burger->effectivePrice(),
            'Branch-scoped promo from another branch must not leak into this branch.');

        // Switching to the OTHER branch → promo applies.
        BranchContext::set($other->id);
        app(PromotionService::class)->flush();
        $this->assertSame(15.0, $this->burger->effectivePrice(),
            'Same promo IS active in its own branch.');
        BranchContext::set($this->branch->id);
    }

    public function test_chain_wide_promo_applies_everywhere(): void
    {
        $other = Branch::create(['code' => 'p3', 'name' => 'P3', 'is_active' => true]);
        $this->makePromo([
            'branch_id' => null,    // chain-wide
            'type' => 'percent', 'value' => 50,
        ]);

        $this->assertSame(15.0, $this->burger->effectivePrice(), 'Live in branch P.');
        BranchContext::set($other->id);
        app(PromotionService::class)->flush();
        $this->assertSame(15.0, $this->burger->effectivePrice(), 'Live in branch P3 too — null branch = everyone.');
        BranchContext::set($this->branch->id);
    }

    // ────────────────────────────────────────────────────────────────
    // New flexibility rules: min_subtotal, channels, category exclusions
    // ────────────────────────────────────────────────────────────────

    public function test_min_subtotal_blocks_promo_until_cart_meets_threshold(): void
    {
        // 10% off ONLY when cart subtotal ≥ 60.
        $this->makePromo([
            'type' => 'percent', 'value' => 10,
            'min_subtotal' => 60,
        ]);

        // Order with 1 burger: 30 < 60 → no discount.
        $order = $this->placeOrder(qty: 1);
        $line = $order->items->first();
        $this->assertSame(30.0, (float) $line->unit_price,
            'Single burger cart (30) is below threshold (60) → full price.');
        $this->assertNull($line->promotion_id, 'No promotion attached.');

        // Order with 3 burgers: 90 ≥ 60 → discount on the line that
        // pushed total over the threshold (and any subsequent lines).
        $order2 = $this->placeOrder(qty: 3);
        $line2 = $order2->items->first();
        $this->assertSame(27.0, (float) $line2->unit_price,
            'Quantity=3 → projected 90 ≥ 60 → 10% off kicks in.');
        $this->assertNotNull($line2->promotion_id);
    }

    public function test_channel_filter_excludes_disallowed_sources(): void
    {
        // QR-only promo. Cashier orders should NOT see it.
        $this->makePromo([
            'type' => 'percent', 'value' => 50,
            'channels' => ['qr'],
        ]);

        // First confirm it applies for QR via direct resolver.
        $this->assertSame(15.0, $this->burger->effectivePrice(null, 'qr'),
            'QR channel matches → 50% off lands.');
        $this->assertSame(30.0, $this->burger->effectivePrice(null, 'cashier'),
            'Cashier channel excluded → full price.');
    }

    public function test_null_channels_means_every_source_eligible(): void
    {
        // No channels filter — equivalent to "all".
        $this->makePromo(['type' => 'sale_price', 'value' => 20]);

        $this->assertSame(20.0, $this->burger->effectivePrice(null, 'qr'));
        $this->assertSame(20.0, $this->burger->effectivePrice(null, 'cashier'));
        $this->assertSame(20.0, $this->burger->effectivePrice(null, 'delivery'));
    }

    // ────────────────────────────────────────────────────────────────
    // Advanced flexibility: usage limit, birthday, free modifier, BXGY
    // ────────────────────────────────────────────────────────────────

    public function test_usage_limit_blocks_after_quota_exhausted(): void
    {
        $promo = $this->makePromo([
            'type' => 'percent', 'value' => 50,
            'usage_limit' => 2,    // only the first 2 orders get the discount
        ]);

        // Order #1 claims a slot.
        $o1 = $this->placeOrder(qty: 1);
        $this->assertSame(15.0, (float) $o1->items->first()->unit_price);
        $promo->refresh();
        $this->assertSame(1, (int) $promo->usage_count);

        // Order #2 claims a slot.
        app(PromotionService::class)->flush();
        $o2 = $this->placeOrder(qty: 1);
        $this->assertSame(15.0, (float) $o2->items->first()->unit_price);
        $promo->refresh();
        $this->assertSame(2, (int) $promo->usage_count);

        // Order #3: quota exhausted → full price.
        app(PromotionService::class)->flush();
        $o3 = $this->placeOrder(qty: 1);
        $this->assertSame(30.0, (float) $o3->items->first()->unit_price,
            'Order #3 hits the exhausted promo — falls back to menu price.');
        $promo->refresh();
        $this->assertSame(2, (int) $promo->usage_count,
            'Counter stays at the limit; no over-claim.');
    }

    public function test_usage_count_only_increments_once_per_order(): void
    {
        $promo = $this->makePromo([
            'type' => 'percent', 'value' => 10,
            'usage_limit' => 100,
        ]);

        // Single order with quantity=3 of the same item → counts as 1 use.
        $this->placeOrder(qty: 3);
        $promo->refresh();
        $this->assertSame(1, (int) $promo->usage_count,
            'Multi-line orders consume ONE redemption per order, not per line.');
    }

    public function test_birthday_audience_only_fires_for_matching_customer(): void
    {
        $birthdayGuy = Customer::create([
            'name' => 'B', 'phone' => '555111', 'status' => 'active',
            'password' => bcrypt('x'),
            'birthday' => now()->subYears(30)->setDay(15),  // birthday this month
        ]);
        $other = Customer::create([
            'name' => 'O', 'phone' => '555222', 'status' => 'active',
            'password' => bcrypt('x'),
            'birthday' => now()->addMonth()->setDay(1),     // next month
        ]);

        $this->makePromo([
            'type' => 'percent', 'value' => 30,
            'audience' => 'birthday_month',
        ]);

        $service = app(PromotionService::class);
        $this->assertNotNull(
            $service->resolveForItem($this->burger, null, null, null, $birthdayGuy),
            'Birthday customer must get the promo.'
        );
        $this->assertNull(
            $service->resolveForItem($this->burger, null, null, null, $other),
            'Other customer must NOT get the birthday promo.'
        );
        $this->assertNull(
            $service->resolveForItem($this->burger, null, null, null, null),
            'Guests (no customer) must NOT get the birthday promo either.'
        );
    }

    public function test_free_modifier_zeroes_out_the_modifier_price(): void
    {
        // Set up a cheese modifier on the burger.
        $cheeseGroup = ModifierGroup::create([
            'menu_item_id' => $this->burger->id,
            'name' => 'Add-ons', 'min_select' => 0, 'max_select' => 5, 'required' => false,
        ]);
        $cheese = Modifier::create([
            'modifier_group_id' => $cheeseGroup->id,
            'name' => 'Cheese', 'price_delta' => 5,
        ]);

        // "Burger sale — cheese FREE with it"
        $this->makePromo([
            'type' => 'sale_price', 'value' => 25,
            'free_modifier_ids' => [$cheese->id],
        ]);

        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'fmod-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity' => 1,
            'modifier_ids' => [$cheese->id],
        ]], createdByUserId: $this->manager->id);

        $line = $order->items()->with('modifiers')->first();
        $this->assertSame(25.0, (float) $line->unit_price, 'Burger drops to sale price 25.');
        $this->assertSame(0.0, (float) $line->modifiers_total,
            'Cheese is listed in free_modifier_ids — its price_delta is zeroed.');
        $mod = $line->modifiers->first();
        $this->assertSame(0.0, (float) $mod->price_delta);
        $this->assertStringContainsString('هدية', $mod->name_snapshot,
            'Free modifier is tagged on the receipt so the customer sees the perk.');
    }

    public function test_bxgy_buy_2_get_1_free_discounts_cheapest(): void
    {
        $this->makePromo([
            'type' => 'percent', 'value' => 0,    // value irrelevant for BXGY
            'bxgy_buy_qty' => 2,
            'bxgy_get_qty' => 1,
            'target_type' => 'category', 'target_id' => $this->mains->id,
        ]);

        // 3 burgers (price 30 each). Customer should pay for 2, get 1 free.
        $order = $this->placeOrder(qty: 3);
        $order->refresh();

        $this->assertSame(90.0, (float) $order->subtotal,
            'Subtotal stays at the gross — 3 × 30 — before the BXGY discount.');

        $discount = $order->discounts()->first();
        $this->assertNotNull($discount, 'BXGY must add an OrderDiscount row.');
        $this->assertSame(30.0, (float) $discount->amount,
            'One burger goes free → 30 off.');
        $this->assertSame(60.0, (float) $order->total,
            'Final total = 90 gross - 30 free = 60.');
    }

    public function test_bxgy_picks_cheapest_when_mixed_prices(): void
    {
        $expensive = MenuItem::create([
            'category_id' => $this->mains->id, 'station_id' => $this->burger->station_id,
            'sku' => 'EX', 'slug' => 'ex', 'name' => 'Ex', 'price' => 80, 'is_available' => true,
        ]);

        $this->makePromo([
            'type' => 'percent', 'value' => 0,
            'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1,
            'target_type' => 'category', 'target_id' => $this->mains->id,
        ]);

        // 2 expensive (80 each) + 1 burger (30) — order matters because we
        // need to verify the discount picks the cheapest (burger), not one
        // of the expensives.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'bxgy-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [
            ['menu_item_id' => $expensive->id, 'quantity' => 2, 'modifier_ids' => []],
            ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []],
        ], createdByUserId: $this->manager->id);

        $order->refresh();
        $discount = $order->discounts()->first();
        $this->assertSame(30.0, (float) $discount->amount,
            'Cheapest of the 3 pieces (the burger at 30) goes free, not the 80.');
    }

    // ────────────────────────────────────────────────────────────────
    // Accounting integration: promo savings show up on 4090
    // ────────────────────────────────────────────────────────────────

    public function test_promo_savings_post_to_sales_discounts_and_gross_revenue(): void
    {
        // 25% off on the burger (30 → 22.5). Order qty=4 → savings = 30.
        $this->makePromo(['type' => 'percent', 'value' => 25]);

        $order = $this->placeOrder(qty: 4);
        $this->assertEqualsWithDelta(30.0, $order->promoSavings(), 0.01,
            'Order::promoSavings should sum per-item snapshot deltas (4 × 7.5).');

        // Issue invoice mirroring the order's subtotal (90 = 4 × 22.5).
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'issued_by_user_id' => $this->manager->id,
            'subtotal' => 90,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 90,
            'balance' => 90,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);
        $entry->load('lines.account');

        // 4000 (revenue) should carry the GROSS = 120, not the net 90.
        $rev = $entry->lines->first(fn ($l) => $l->account->code === '4000');
        $this->assertEqualsWithDelta(120.0, (float) $rev->credit, 0.01,
            'Revenue is recognised at GROSS — promo savings DO NOT silently shrink it.');

        // 4090 (sales discount) should carry the promo savings.
        $disc = $entry->lines->first(fn ($l) => $l->account->code === '4090');
        $this->assertEqualsWithDelta(30.0, (float) $disc->debit, 0.01,
            'Sales discount picks up the 30 in promo savings.');

        // 1100 (receivable) stays the customer-facing total.
        $rec = $entry->lines->first(fn ($l) => $l->account->code === '1100');
        $this->assertEqualsWithDelta(90.0, (float) $rec->debit, 0.01,
            'Receivable still equals the actual amount owed.');

        // Entry balances: debits = credits.
        $totalDebit = (float) $entry->lines->sum('debit');
        $totalCredit = (float) $entry->lines->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01,
            'Adding promo savings to BOTH legs keeps the entry balanced.');
    }

    public function test_order_with_no_promo_posts_unchanged_journal(): void
    {
        // Sanity: an order with no active promo behaves exactly like before
        // — no spurious entries on 4090, revenue = subtotal as always.
        $order = $this->placeOrder(qty: 2);
        $this->assertSame(0.0, $order->promoSavings(),
            'No promo → no savings (the baseline that proves the change is opt-in).');

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id, 'order_id' => $order->id,
            'issued_by_user_id' => $this->manager->id,
            'subtotal' => 60, 'discount_total' => 0, 'tax_total' => 0,
            'service_total' => 0, 'delivery_fee' => 0, 'tip' => 0,
            'total' => 60, 'balance' => 60, 'status' => 'issued',
            'issued_at' => now(),
        ]);
        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);
        $entry->load('lines.account');

        $rev = $entry->lines->first(fn ($l) => $l->account->code === '4000');
        $disc = $entry->lines->first(fn ($l) => $l->account->code === '4090');
        $this->assertEqualsWithDelta(60.0, (float) $rev->credit, 0.01);
        // 4090 line is filtered out of the journal entirely when the
        // amount is zero (AccountingService::normalizeLines drops them),
        // so we expect NO discount line at all.
        $this->assertNull($disc, 'No-promo invoice should not insert a 4090 line.');
    }

    public function test_category_exclusion_skips_listed_items(): void
    {
        // Build a second item in the same category.
        $kebab = MenuItem::create([
            'category_id' => $this->mains->id,
            'station_id' => $this->burger->station_id,
            'sku' => 'KX', 'slug' => 'kebab', 'name' => 'Kebab',
            'price' => 50, 'is_available' => true,
        ]);

        // "All Mains 20% off — except Kebab".
        $this->makePromo([
            'type' => 'percent', 'value' => 20,
            'target_type' => 'category', 'target_id' => $this->mains->id,
            'excluded_item_ids' => [$kebab->id],
        ]);

        $this->assertSame(24.0, $this->burger->effectivePrice(),
            'Burger gets the category 20% off.');
        $this->assertSame(50.0, $kebab->effectivePrice(),
            'Kebab is on the exclusion list → no discount.');
    }

    public function test_bulk_resolver_returns_winning_promo_per_item(): void
    {
        $kebab = MenuItem::create([
            'category_id' => $this->mains->id, 'station_id' => $this->burger->station_id,
            'sku' => 'K-1', 'slug' => 'kebab', 'name' => 'Kebab', 'price' => 40, 'is_available' => true,
        ]);

        $this->makePromo([
            'target_type' => 'menu_item', 'target_id' => $this->burger->id,
            'type' => 'sale_price', 'value' => 20,
        ]);

        $items = MenuItem::whereIn('id', [$this->burger->id, $kebab->id])->get();
        $map = app(PromotionService::class)->resolveBulk($items);

        $this->assertSame(20.0, $map[$this->burger->id]['price'], 'Burger picks up its promo.');
        $this->assertInstanceOf(MenuPromotion::class, $map[$this->burger->id]['promotion'],
            'Burger entry carries the resolved promotion object.');
        $this->assertSame(40.0, $map[$kebab->id]['price'], 'Kebab has no promo → menu price.');
        $this->assertNull($map[$kebab->id]['promotion'], 'Kebab carries null (no promo).');
    }

    public function test_qr_catalogue_cart_and_committed_order_share_channel_and_free_modifier_prices(): void
    {
        $this->withoutVite();
        $group = ModifierGroup::create(['menu_item_id' => $this->burger->id, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'required' => false]);
        $this->burger->modifierGroups()->attach($group->id);
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese', 'price_delta' => 5]);
        $promo = $this->makePromo(['channels' => ['qr'], 'type' => 'sale_price', 'value' => 18, 'free_modifier_ids' => [$modifier->id]]);
        $this->makePromo(['channels' => ['cashier'], 'type' => 'sale_price', 'value' => 1, 'priority' => 100]);
        $session = $this->priceSession('qr');
        $this->withCookie('table_session', $session->token)->get(route('customer.menu.open', $this->table->qr_token))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('categories.0.items.0.price', 18)
            ->where('categories.0.items.0.modifier_groups.0.modifiers.0.price_delta', 0));
        $added = $this->postJson(route('customer.cart.add'), ['session' => $session->token, 'menu_item_id' => $this->burger->id, 'quantity' => 2, 'modifier_ids' => [$modifier->id]])
            ->assertOk()->assertJsonPath('row.unit_price', 18)->assertJsonPath('row.modifiers_total', 0)->assertJsonPath('row.subtotal', 36);
        $submitted = $this->postJson(route('customer.cart.submit'), ['session' => $session->token, '_idem' => 'qr-price-flow', 'cart_snapshot' => [$added->json('row')]])
            ->assertOk()->assertJsonPath('submission_state', 'committed');
        $order = Order::findOrFail($submitted->json('receipt.orderId'));
        $this->assertSame('qr', $order->promotion_channel);
        $this->assertSame('dine_in', $order->order_source);
        $this->assertSame(36.0, (float) $order->total);
        $this->assertSame($promo->id, $order->items()->sole()->promotion_id);
        $this->assertSame(0.0, (float) $order->items()->sole()->modifiers_total);
    }

    public function test_waiter_catalogue_price_matches_the_submitted_ticket(): void
    {
        $this->withoutVite();
        $this->makePromo(['channels' => ['waiter'], 'type' => 'sale_price', 'value' => 21]);
        $this->makePromo(['channels' => ['qr'], 'type' => 'sale_price', 'value' => 2, 'priority' => 100]);
        $waiter = $this->user('price_waiter', 'waiter');
        $this->actingAs($waiter)->get(route('admin.waiter-orders.create', $this->table))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('menu.0.price', 21));
        $this->postJson(route('admin.waiter-orders.submit', $this->table), [
            'token' => 'waiter-price-flow', 'cart' => [['menu_item_id' => $this->burger->id, 'quantity' => 2, 'modifier_ids' => []]],
        ])->assertOk()->assertJsonPath('fired', true);
        $order = Order::query()->sole();
        $this->assertSame('waiter', $order->promotion_channel);
        $this->assertSame('approved', $order->status);
        $this->assertSame(21.0, (float) $order->items()->sole()->unit_price);
        $this->assertSame(42.0, (float) $order->total);
    }

    public function test_cashier_phone_order_uses_the_same_entry_channel_and_legacy_phone_offers_as_catalogue(): void
    {
        $this->withoutVite();
        $this->makePromo(['channels' => ['cashier'], 'type' => 'sale_price', 'value' => 19]);
        $phonePromo = $this->makePromo(['channels' => ['phone'], 'type' => 'sale_price', 'value' => 17, 'priority' => 5]);
        $this->makePromo(['channels' => ['waiter'], 'type' => 'sale_price', 'value' => 1, 'priority' => 100]);
        $this->actingAs($this->manager)->get(route('admin.cashier.index'))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('catalog.items.0.price', 17));
        $this->postJson(route('admin.cashier.api.orders.store'), [
            'token' => 'cashier-price-flow', 'customer_phone' => '0598123456',
            'cart' => [['menu_item_id' => $this->burger->id, 'quantity' => 2, 'modifier_ids' => []]],
        ])->assertOk()->assertJsonPath('ok', true);
        $order = Order::query()->sole();
        $this->assertSame('cashier', $order->promotion_channel);
        $this->assertSame('phone', $order->order_source);
        $this->assertSame(34.0, (float) $order->total);
        $this->assertSame($phonePromo->id, $order->items()->sole()->promotion_id);
        $phonePromo->update(['active' => false]);
        app(PromotionService::class)->flush();
        $cashierOrder = app(OrderService::class)->createCashierOrder(null, $this->branch, 'takeaway', 'phone', [['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []]], createdByUserId: $this->manager->id);
        $this->assertSame(19.0, (float) $cashierOrder->items()->sole()->unit_price);
    }

    public function test_new_channel_bxgy_applies_only_to_its_origin_and_claims_the_cap_once(): void
    {
        $promo = $this->makePromo(['name' => 'QR 2+1', 'channels' => ['qr'], 'type' => 'percent', 'value' => 0, 'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1, 'usage_limit' => 1]);
        $cart = [['menu_item_id' => $this->burger->id, 'quantity' => 3, 'modifier_ids' => []]];
        $qr = app(OrderService::class)->createFromCart($this->priceSession('bxgy-qr'), $cart);
        $this->assertSame(60.0, (float) $qr->total);
        $this->assertSame(1, (int) $promo->fresh()->usage_count);
        app(OrderService::class)->recalculateTotals($qr);
        $this->assertSame(60.0, (float) $qr->fresh()->total);
        $this->assertSame(1, (int) $promo->fresh()->usage_count);
        $line = $qr->items()->sole();
        $line->update(['quantity' => 2, 'subtotal' => 60]);
        app(OrderService::class)->recalculateTotals($qr);
        $this->assertSame(0, $qr->discounts()->count());
        $line->update(['quantity' => 3, 'subtotal' => 90]);
        app(OrderService::class)->recalculateTotals($qr);
        $this->assertSame(60.0, (float) $qr->fresh()->total);
        $this->assertSame(1, (int) $promo->fresh()->usage_count);
        $waiter = app(OrderService::class)->createFromCart($this->priceSession('bxgy-waiter'), $cart, $this->manager->id);
        $this->assertSame(90.0, (float) $waiter->total);
        $secondQr = app(OrderService::class)->createFromCart($this->priceSession('bxgy-later'), $cart);
        $this->assertSame(90.0, (float) $secondQr->total);
    }

    public function test_historical_order_and_staff_meal_do_not_acquire_cashier_promotions(): void
    {
        $this->makePromo(['channels' => ['cashier'], 'type' => 'sale_price', 'value' => 1]);
        $this->makePromo(['name' => 'Phone 2+1', 'channels' => ['phone'], 'type' => 'percent', 'value' => 0, 'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1]);
        $legacy = Order::create(['branch_id' => $this->branch->id, 'order_type' => 'takeaway', 'order_source' => 'phone', 'status' => 'approved', 'created_by_user_id' => $this->manager->id, 'tax_rate' => 0, 'service_rate' => 0]);
        $line = OrderItem::create(['order_id' => $legacy->id, 'menu_item_id' => $this->burger->id, 'station_id' => $this->burger->station_id, 'name_snapshot' => 'Burger', 'quantity' => 3, 'unit_price' => 30, 'modifiers_total' => 0, 'subtotal' => 90, 'status' => 'approved']);
        app(OrderService::class)->recalculateTotals($legacy);
        $this->assertNull($legacy->promotion_channel);
        $this->assertSame(90.0, (float) $legacy->fresh()->total);
        $this->assertSame(30.0, (float) $line->fresh()->unit_price);
        $staffOrder = Order::create(['branch_id' => $this->branch->id, 'order_type' => 'takeaway', 'order_source' => 'other', 'status' => 'pending', 'created_by_user_id' => $this->manager->id]);
        $staffLine = app(OrderService::class)->addItem($staffOrder, ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []]);
        $this->assertSame(30.0, (float) $staffLine->unit_price);
        $this->assertNull($staffOrder->promotion_channel);
    }

    public function test_bxgy_redemption_identity_survives_rename_and_never_matches_a_different_same_named_offer(): void
    {
        $promo = $this->makePromo(['name' => 'Shared', 'channels' => ['qr'], 'type' => 'percent', 'value' => 0, 'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1, 'usage_limit' => 1]);
        $exhausted = $this->makePromo(['name' => 'Shared', 'channels' => ['qr'], 'type' => 'percent', 'value' => 0, 'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1, 'usage_limit' => 1, 'usage_count' => 1]);
        $order = app(OrderService::class)->createFromCart($this->priceSession('bxgy-identity'), [['menu_item_id' => $this->burger->id, 'quantity' => 3, 'modifier_ids' => []]]);
        $this->assertSame(60.0, (float) $order->total);
        app(OrderService::class)->recalculateTotals($order);
        $this->assertSame(60.0, (float) $order->fresh()->total, 'An exhausted campaign cannot borrow another campaign redemption.');
        $this->assertSame(1, $order->discounts()->count());
        $promo->update(['name' => 'Renamed']);
        app(OrderService::class)->recalculateTotals($order);
        $this->assertSame(60.0, (float) $order->fresh()->total);
        $this->assertStringStartsWith('BXGY:#'.$promo->id.' ', $order->discounts()->sole()->reason);
        $this->assertSame(1, (int) $promo->fresh()->usage_count);
        $this->assertSame(1, (int) $exhausted->fresh()->usage_count);
    }

    public function test_channel_bxgy_normalizes_tax_inclusive_prices_before_net_discount(): void
    {
        config(['restaurant.tax.inclusive' => true]);
        Setting::put('tax_enabled', true, 'billing', 'bool');
        Setting::put('tax_rate', 20, 'billing', 'float');
        $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 0, 'bxgy_buy_qty' => 2, 'bxgy_get_qty' => 1]);
        $order = app(OrderService::class)->createFromCart($this->priceSession('bxgy-vat'), [['menu_item_id' => $this->burger->id, 'quantity' => 3, 'modifier_ids' => []]]);
        $this->assertSame(75.0, (float) $order->subtotal);
        $this->assertSame(25.0, (float) $order->discount_total);
        $this->assertSame(10.0, (float) $order->tax_total);
        $this->assertSame(60.0, (float) $order->total, 'Three gross-price30 items with one free must charge60, including VAT.');
    }

    public function test_conditional_discount_is_not_an_unconditional_catalogue_price(): void
    {
        $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 10]);
        $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 50, 'min_subtotal' => 60, 'priority' => 10]);
        $map = app(PromotionService::class)->resolveBulk(MenuItem::whereKey($this->burger->id)->get(), channel: 'qr');
        $this->assertSame(30.0, $map[$this->burger->id]['price']);
        $this->assertNull($map[$this->burger->id]['promotion']);
        $session = $this->priceSession('threshold');
        $this->postJson(route('customer.cart.add'), ['session' => $session->token, 'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []])
            ->assertOk()->assertJsonPath('row.unit_price', 30);
        $this->postJson(route('customer.cart.add'), ['session' => $session->token, 'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []])
            ->assertOk()->assertJsonPath('row.unit_price', 30);
        $this->postJson(route('customer.cart.submit'), ['session' => $session->token, '_idem' => 'threshold-price-flow'])
            ->assertOk()->assertJsonPath('submission_state', 'committed');
        $this->assertSame(45.0, (float) Order::query()->sole()->total);
    }

    public function test_draft_quantity_reduction_cannot_leave_a_conditional_price_below_the_saved_price(): void
    {
        $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 50, 'min_subtotal' => 60]);
        $session = $this->priceSession('threshold-reduce');
        $added = $this->postJson(route('customer.cart.add'), ['session' => $session->token, 'menu_item_id' => $this->burger->id, 'quantity' => 3, 'modifier_ids' => []])
            ->assertOk()->assertJsonPath('row.unit_price', 30)->assertJsonPath('row.subtotal', 90);
        $this->postJson(route('customer.cart.update'), ['session' => $session->token, 'row_id' => $added->json('row.id'), 'quantity' => 1])
            ->assertOk()->assertSessionHas('cart.'.$session->token.'.0.subtotal', 30);
        $this->postJson(route('customer.cart.submit'), ['session' => $session->token, '_idem' => 'threshold-reduced-price-flow'])
            ->assertOk()->assertJsonPath('submission_state', 'committed');
        $this->assertSame(30.0, (float) Order::query()->sole()->total);
    }

    public function test_free_modifier_only_capped_offer_is_claimed_once_for_all_lines_in_one_order(): void
    {
        $group = ModifierGroup::create(['name' => 'Free extras', 'min_select' => 0, 'max_select' => 1, 'required' => false]);
        $this->burger->modifierGroups()->attach($group->id);
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Cheese', 'price_delta' => 5]);
        $promo = $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 0, 'usage_limit' => 1, 'free_modifier_ids' => [$modifier->id]]);
        $cart = array_fill(0, 2, ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$modifier->id]]);
        $order = app(OrderService::class)->createFromCart($this->priceSession('free-only'), $cart);
        $this->assertSame(60.0, (float) $order->total);
        $this->assertSame(2, $order->items()->where('promotion_id', $promo->id)->where('modifiers_total', 0)->count());
        $this->assertSame(1, (int) $promo->fresh()->usage_count);
        $this->assertDatabaseCount('order_promotion_redemptions', 1);
        $later = app(OrderService::class)->createFromCart($this->priceSession('free-only-next'), $cart);
        $this->assertSame(70.0, (float) $later->total);
    }

    public function test_rolled_back_order_does_not_consume_a_promotion_redemption(): void
    {
        $promo = $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 50, 'usage_limit' => 1]);
        $session = $this->priceSession('rollback-claim');
        try {
            \DB::transaction(function () use ($session) {
                app(OrderService::class)->createFromCart($session, [['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []]]);
                throw new \RuntimeException('Injected failure after pricing.');
            });
            $this->fail('The injected failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure after pricing.', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_promotion_redemptions', 0);
        $this->assertSame(0, (int) $promo->fresh()->usage_count);
    }

    public function test_review_replacement_does_not_count_the_retired_original_toward_a_promotion_minimum(): void
    {
        $group = ModifierGroup::create(['name' => 'Review extras', 'min_select' => 0, 'max_select' => 1, 'required' => false]);
        $this->burger->modifierGroups()->attach($group->id);
        $modifier = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Sauce', 'price_delta' => 2]);
        $this->makePromo(['channels' => ['qr'], 'type' => 'percent', 'value' => 50, 'min_subtotal' => 60]);
        $order = app(OrderService::class)->createFromCart($this->priceSession('review-minimum'), [['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []]]);
        $original = $order->items()->sole();
        $reviewed = app(OrderService::class)->reviewAndApprovePending($order, [[
            'order_item_id' => $original->id, 'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$modifier->id],
        ]], null, $this->manager->id, $order->updated_at->toAtomString());
        $this->assertSame('approved', $reviewed->status);
        $this->assertSame(32.0, (float) $reviewed->total, 'The old30 plus replacement32 must not manufacture a60 minimum.');
        $this->assertSame(30.0, (float) $reviewed->items()->sole()->unit_price);
        $this->assertNull($reviewed->items()->sole()->promotion_id);
        $this->assertDatabaseMissing('order_items', ['id' => $original->id]);
    }

    private function priceSession(string $token): TableSession
    {
        return TableSession::create(['branch_id' => $this->branch->id, 'table_id' => $this->table->id, 'token' => 'price-'.$token, 'status' => 'active', 'opened_at' => now(), 'last_activity_at' => now(), 'cover_count' => 1]);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function user(string $username, string $role): User
    {
        $u = User::create([
            'name' => ucfirst($username), 'username' => $username,
            'password' => bcrypt('x'), 'status' => 'active',
            'role' => $role, 'primary_branch_id' => $this->branch->id,
        ]);
        $u->branches()->attach($this->branch->id, ['is_primary' => true]);

        return $u;
    }

    protected function makePromo(array $overrides = []): MenuPromotion
    {
        app(PromotionService::class)->flush();

        return MenuPromotion::create(array_merge([
            'branch_id' => null,
            'name' => 'Test promo',
            'type' => 'percent',
            'value' => 10,
            'target_type' => 'menu_item',
            'target_id' => $this->burger->id,
            'active' => true,
            'priority' => 0,
        ], $overrides));
    }

    protected function placeOrder(int $qty): Order
    {
        app(PromotionService::class)->flush();   // pick up newest promos
        $session = TableSession::create([
            'branch_id' => $this->branch->id,
            'table_id' => $this->table->id,
            'token' => 'p-'.uniqid(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
        ]);

        return app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity' => $qty,
            'modifier_ids' => [],
        ]], createdByUserId: $this->manager->id)->load('items');
    }
}
