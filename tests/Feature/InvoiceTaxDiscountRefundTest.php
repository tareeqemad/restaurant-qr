<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\JournalLine;
use App\Models\MenuItem;
use App\Models\OrderDiscount;
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
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * #9  Refund relieves the output VAT (2100) it contained, not just sales-returns.
 * #4  Stacked discounts beyond the subtotal are capped so the invoice can issue.
 * #6  Split shares must sum EXACTLY to the total (no 0.01 residue on A/R).
 */
class InvoiceTaxDiscountRefundTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected MenuItem $menuItem;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        \App\Models\Setting::put('tax_enabled', true, 'billing', 'bool');
        \App\Models\Setting::put('tax_rate', 16, 'billing', 'int');
        \App\Models\Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 'tdr', 'name' => 'TDR', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::create(['name' => 'cashier', 'label' => 'Cashier', 'is_system' => true]);
        $this->cashier = User::create([
            'name' => 'C', 'username' => 'c_tdr', 'password' => bcrypt('x'),
            'status' => 'active', 'primary_branch_id' => $this->branch->id, 'role' => 'cashier',
        ]);
        $this->cashier->branches()->attach($this->branch->id);

        $unit = Unit::create(['code'=>'pcs','name'=>'pcs','unit_type'=>'count','factor_to_base'=>1,'is_base'=>true]);
        $storage = StorageLocation::create(['code'=>'k','name'=>'K','is_default'=>true,'active'=>true]);
        $station = Station::create(['code'=>'kitchen','name'=>'K','storage_location_id'=>$storage->id,'active'=>true]);
        $category = Category::create(['slug'=>'m','name'=>'M','default_station_id'=>$station->id,'active'=>true]);
        $ing = Ingredient::create(['sku'=>'I1','name'=>'S','base_unit_id'=>$unit->id,'current_stock'=>500,'reorder_threshold'=>0,'cost_per_unit'=>1,'track_stock'=>true,'active'=>true]);
        IngredientStock::create(['ingredient_id'=>$ing->id,'storage_location_id'=>$storage->id,'quantity'=>500,'reorder_threshold'=>0]);
        $this->menuItem = MenuItem::create(['category_id'=>$category->id,'station_id'=>$station->id,'sku'=>'M1','slug'=>'m','name'=>'Meal','price'=>100,'cost'=>10,'prep_time_minutes'=>5,'is_available'=>true]);
        RecipeItem::create(['menu_item_id'=>$this->menuItem->id,'ingredient_id'=>$ing->id,'quantity'=>1,'unit_id'=>$unit->id]);

        [$this->customer] = Customer::createFromCashier(name: 'ز', phone: '0599000444', defaultBranchId: $this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function acctNet(string $code): float
    {
        $lines = JournalLine::whereHas('account', fn ($q) => $q->where('code', $code))->get();
        return round((float) $lines->sum('debit') - (float) $lines->sum('credit'), 2);
    }

    private function openSession(): TableSession
    {
        $table = Table::create(['number'=>(string) random_int(1,9999),'capacity'=>4,'status'=>'occupied','active'=>true]);
        return TableSession::create(['table_id'=>$table->id,'customer_id'=>$this->customer->id,'cover_count'=>1,'status'=>'active']);
    }

    private function issueOneMeal(): \App\Models\Invoice
    {
        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->approve($order, $this->cashier->id);
        return app(BillingService::class)->issueInvoice($session->fresh(), $this->cashier->id);
    }

    /** #9 — a full refund relieves the output VAT it contained. */
    public function test_refund_relieves_output_vat(): void
    {
        $this->actingAs($this->cashier);

        $invoice = $this->issueOneMeal();          // 100 + 16% = 116, VAT 16 credited to 2100
        $this->assertSame(116.0, (float) $invoice->total);
        $this->assertSame(-16.0, $this->acctNet('2100'), 'Issuance credits output VAT 16.');

        app(BillingService::class)->addPayment($invoice, 116.0, 'cash', $this->cashier->id);
        app(RefundService::class)->issue($invoice->fresh(), 116.0, 'cash', 'مرتجع كامل', $this->cashier->id);

        // Output VAT must be fully relieved (16 credited at issue − 16 debited on
        // refund = 0). BEFORE the fix the full 116 hit 4100 and 2100 stayed at 16.
        $this->assertSame(0.0, $this->acctNet('2100'), 'Refund must relieve the VAT — 2100 nets to zero.');
        // Only the NET revenue (100) lands in sales-returns, not the gross 116.
        $this->assertSame(100.0, $this->acctNet('4100'), 'Sales-returns holds only the net revenue portion.');
    }

    /** #4 — stacked discounts beyond the subtotal are capped; the invoice still issues. */
    public function test_stacked_discounts_are_capped_and_invoice_issues(): void
    {
        $this->actingAs($this->cashier);

        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);

        // Two discounts that together (40 + 80 = 120) exceed the 100 subtotal.
        OrderDiscount::create(['branch_id'=>$this->branch->id,'order_id'=>$order->id,'name_snapshot'=>'خصم أ','type'=>'fixed','value'=>40,'amount'=>40,'reason'=>'أ','applied_by_user_id'=>$this->cashier->id]);
        OrderDiscount::create(['branch_id'=>$this->branch->id,'order_id'=>$order->id,'name_snapshot'=>'خصم ب','type'=>'fixed','value'=>80,'amount'=>80,'reason'=>'ب','applied_by_user_id'=>$this->cashier->id]);
        app(OrderService::class)->recalculateTotals($order->fresh());

        $order->refresh();
        $this->assertSame(100.0, (float) $order->discount_total, 'Discount is capped at the 100 subtotal.');
        $this->assertSame(0.0, (float) $order->total, 'Fully discounted → zero total, no negative.');

        app(OrderService::class)->approve($order->fresh(), $this->cashier->id);
        // BEFORE the fix the issuance entry was unbalanced (Dr discounts > Cr revenue)
        // and this threw — leaving the table stuck. It must now issue cleanly.
        $invoice = app(BillingService::class)->issueInvoice($session->fresh(), $this->cashier->id);
        $this->assertSame('paid', $invoice->fresh()->status, 'A zero-total comped invoice auto-pays and issues.');
    }

    /** #7 — tax-inclusive prices are not charged tax twice. */
    public function test_inclusive_tax_is_not_charged_twice(): void
    {
        config()->set('restaurant.tax.inclusive', true);
        $this->actingAs($this->cashier);

        $session = $this->openSession();
        $order = app(OrderService::class)->createFromCart($session, [['menu_item_id'=>$this->menuItem->id,'quantity'=>1,'modifier_ids'=>[]]]);
        app(OrderService::class)->recalculateTotals($order->fresh());

        // The 100 menu price is tax-INCLUSIVE, so the customer pays 100 (net 86.21
        // + tax 13.79), NOT 100 + an extra 16 extracted on top.
        $order->refresh();
        $this->assertEqualsWithDelta(100.0,  (float) $order->total,     0.01, 'Inclusive price must not be charged tax on top.');
        $this->assertEqualsWithDelta(86.21,  (float) $order->subtotal,  0.01);
        $this->assertEqualsWithDelta(13.79,  (float) $order->tax_total, 0.01);
    }

    /** #6 — split shares must sum exactly to the total. */
    public function test_split_shares_must_sum_exactly(): void
    {
        $this->actingAs($this->cashier);
        $invoice = $this->issueOneMeal();   // 116.00

        // 38.66 × 3 = 115.98 → 0.02 short. Old tolerance (0.01) would still reject
        // this, but 57.99 + 57.99 = 115.98 vs 116.00... use a 0.01-short split which
        // the OLD code accepted and the NEW code must reject.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يساوي إجمالي الفاتورة/');
        app(BillingService::class)->splitInvoice($invoice->fresh(), [
            ['label' => 'أ', 'amount' => 58.00, 'method' => 'cash'],
            ['label' => 'ب', 'amount' => 57.99, 'method' => 'cash'],   // sum 115.99, 0.01 short
        ]);
    }
}
