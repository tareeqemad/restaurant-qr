<?php

namespace Tests\Feature;

use App\Enums\OrderItemStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
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
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closure tests for the 5 minor gaps from the comprehensive audit:
 *
 *   G4 — Refund credit note and payout must stay balanced without false debt
 *   G5 — Splits prorate to the new total when discount changes post-issue
 *   G6 — Convert-to-waste posts DR 5400 / CR 5000 reclassification
 *   G7 — Customer relation is loaded once (no per-line query in addItem)
 *   G8 — ActivityLog gets a row when staff_meal service strip kicks in
 */
class MinorAuditGapsClosureTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected MenuItem $burger;
    protected Table $table;
    protected Ingredient $patty;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('tax_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'g', 'name' => 'G', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'Cashier', 'is_system' => true]);
        Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager', 'is_system' => true]);

        $this->cashier = User::create([
            'name' => 'C', 'username' => 'cg', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->cashier->branches()->attach($this->branch->id, ['is_primary' => true]);

        $g = Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $cat = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);

        $this->patty = Ingredient::create([
            'name' => 'Patty', 'base_unit_id' => $g->id, 'current_stock' => 5000,
            'reorder_threshold' => 0, 'cost_per_unit' => 0.5, 'track_stock' => true, 'active' => true,
        ]);
        IngredientStock::create(['ingredient_id' => $this->patty->id, 'storage_location_id' => $storage->id, 'quantity' => 5000, 'reorder_threshold' => 0]);

        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B', 'slug' => 'b', 'name' => 'Burger',
            'price' => 30, 'is_available' => true,
        ]);
        RecipeItem::create(['menu_item_id' => $this->burger->id, 'ingredient_id' => $this->patty->id, 'quantity' => 100, 'unit_id' => $g->id]);

        $this->table = Table::create(['number' => 'T', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────────
    // G4 — Refund recomputes balance + status
    // ───────────────────────────────────────────────────────────────

    public function test_g4_refund_partial_reduces_sale_without_creating_false_balance(): void
    {
        $invoice = $this->makeInvoice(total: 100);
        $invoice->update(['paid_total' => 100, 'balance' => 0, 'status' => 'paid', 'paid_at' => now()]);

        // Refund 30 of the 100. The credit note reduces the sale to 70
        // while the payout reduces net paid to 70; no customer debt remains.
        $payment = Payment::create([
            'branch_id' => $this->branch->id, 'invoice_id' => $invoice->id,
            'amount' => 100, 'method' => 'cash',
            'received_by_user_id' => $this->cashier->id,
            'paid_at' => now()->subMinute(),
        ]);
        app(RefundService::class)->issue(
            invoice: $invoice,
            amount:  30,
            method:  'cash',
            reason:  'wrong item',
            userId:  $this->cashier->id,
            opts:    ['payment_id' => $payment->id, 'status' => 'completed'],
        );

        $invoice->refresh();
        $this->assertEqualsWithDelta(30.0, (float) $invoice->refunded_total, 0.01);
        $this->assertEqualsWithDelta(70.0, $invoice->netPaid(), 0.01,
            'netPaid = paid_total - refunded_total.');
        $this->assertEqualsWithDelta(30.0, (float) $invoice->credited_total, 0.01);
        $this->assertEqualsWithDelta(70.0, $invoice->adjustedTotal(), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->balance, 0.01,
            'The credit note and payout move together, so no false debt is created.');
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
    }

    // ───────────────────────────────────────────────────────────────
    // G6 — Waste reclassification journal
    // ───────────────────────────────────────────────────────────────

    public function test_g6_convert_to_waste_posts_dr_5400_cr_5000_reclassification(): void
    {
        // Place an order so an `out` movement gets recorded.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g6-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        // Mark the order item served so deduction fires.
        $line = $order->items()->first();
        $line->update(['status' => OrderItemStatus::Served->value]);
        app(InventoryService::class)->ensureDeducted($line->fresh());

        // Convert that line's deduction into waste.
        app(InventoryService::class)->convertOrderItemToWaste($line, 'kitchen mistake', $this->cashier->id);

        // The new accounting entry must be present.
        $entry = JournalEntry::where('event_type', 'inventory_waste_reclassified')
            ->with('lines.account')
            ->first();
        $this->assertNotNull($entry, 'Reclassification journal MUST post (was silent before G6 fix).');

        $debit  = $entry->lines->firstWhere(fn ($l) => $l->debit > 0);
        $credit = $entry->lines->firstWhere(fn ($l) => $l->credit > 0);
        $this->assertSame('5400', $debit->account->code, 'Cost moves INTO waste expense.');
        $this->assertSame('5000', $credit->account->code, 'Cost moves OUT of COGS.');
    }

    // ───────────────────────────────────────────────────────────────
    // G7 — addItem does not N+1 on customer lookup
    // ───────────────────────────────────────────────────────────────

    public function test_g7_addItem_customer_queries_dont_scale_with_cart_size(): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'X', 'phone' => '999111', 'status' => 'active',
            'password' => bcrypt('x'),
        ]);

        // Run the same flow twice — once with 1 line, once with 10. If
        // there's an N+1, the 10-line run will fire 9 more customer
        // queries than the 1-line run. With the fix, both should hit
        // the customers table the same number of times (constant).
        $countAt = function (int $lines) use ($customer): int {
            $session = TableSession::create([
                'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
                'token' => 'g7-'.uniqid(), 'status' => 'active',
                'opened_at' => now(), 'cover_count' => 1,
                'customer_id' => $customer->id,
            ]);
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            app(OrderService::class)->createFromCart(
                $session,
                array_fill(0, $lines, ['menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => []]),
                createdByUserId: $this->cashier->id,
            );
            $count = collect(\DB::getQueryLog())
                ->filter(fn ($q) => str_contains(strtolower($q['query']), 'from "customers"'))
                ->count();
            \DB::disableQueryLog();
            return $count;
        };

        $countSmall = $countAt(1);
        $countLarge = $countAt(10);

        $this->assertSame($countSmall, $countLarge,
            "Customer queries must NOT grow with cart size (was {$countSmall} for 1 line vs {$countLarge} for 10 lines — N+1).");
    }

    // ───────────────────────────────────────────────────────────────
    // G8 — Staff meal service strip is logged
    // ───────────────────────────────────────────────────────────────

    public function test_g8_staff_meal_service_strip_is_logged_in_activity_feed(): void
    {
        Setting::put('service_enabled', true, 'billing', 'bool');
        Setting::put('service_rate',    10, 'billing', 'float');
        Setting::put('staff_meal_include_service', false, 'staff_meals', 'bool');

        $employee = User::create([
            'name' => 'E', 'username' => 'emp_g8', 'password' => bcrypt('x'),
            'role' => 'cashier', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
            'monthly_meal_allowance' => 500,
        ]);
        $employee->branches()->attach($this->branch->id, ['is_primary' => true]);

        $this->actingAs($this->cashier);

        // Build a staff-consumed order with service charge on.
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'g8-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);
        $order->update(['staff_consumer_user_id' => $employee->id]);

        $this->assertGreaterThan(0, (float) $order->fresh()->service_total,
            'Sanity: order opened with service on so the strip is meaningful.');

        app(\App\Services\StaffMealService::class)->chargeOrder($order->fresh(), $this->cashier->id);

        // ActivityLog row must be present, with the stripped amount in metadata.
        $log = \App\Models\ActivityLog::where('event', 'staff_meal.service_stripped')
            ->where('subject_id', $order->id)
            ->first();
        $this->assertNotNull($log,
            'staff_meal.service_stripped log must exist so managers can audit the difference.');
        $this->assertSame(10.0, (float) $log->properties['service_rate_before']);
        $this->assertGreaterThan(0, (float) $log->properties['service_total_before']);
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function makeInvoice(float $total): Invoice
    {
        $session = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'inv-'.uniqid(), 'status' => 'active',
            'opened_at' => now()->subHour(), 'cover_count' => 1,
        ]);
        $order = app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [],
        ]], createdByUserId: $this->cashier->id);

        return Invoice::create([
            'branch_id'         => $this->branch->id,
            'order_id'          => $order->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal'          => $total, 'discount_total' => 0,
            'tax_total'         => 0, 'service_total' => 0,
            'delivery_fee'      => 0, 'tip' => 0,
            'total'             => $total, 'balance' => $total,
            'status'            => 'issued',
            'issued_at'         => now()->subMinutes(30),
        ]);
    }
}
