<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CustomerIdentityService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerIdentityLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['code' => 'id', 'name' => 'Identity', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->cashier = User::factory()->create([
            'username' => 'identity_cashier',
            'role' => 'cashier',
            'status' => 'active',
        ]);
        $this->cashier->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_all_phone_formats_resolve_to_one_customer_and_one_loyalty_profile(): void
    {
        $first = app(CustomerIdentityService::class)->resolveOrCreate(
            phone: '+970 599-111-222',
            name: 'زبون موحد',
            defaultBranchId: $this->branch->id,
            source: 'test',
        );
        $second = app(CustomerIdentityService::class)->resolveOrCreate(
            phone: '٠٥٩٩١١١٢٢٢',
            name: 'اسم لا يستبدل الموجود',
            defaultBranchId: $this->branch->id,
            source: 'test',
        );

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertArrayNotHasKey('pin', $first);
        $this->assertSame($first['customer']->id, $second['customer']->id);
        $this->assertSame('0599111222', $first['customer']->phone);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('loyalty_customers', 1);
    }

    public function test_full_payment_awards_points_once_and_refund_reconciles_them(): void
    {
        [$customer] = Customer::createFromCashier('زبون نقاط', '0599000333', defaultBranchId: $this->branch->id);
        $table = Table::create(['number' => 'L1', 'capacity' => 2, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'status' => 'active',
            'opened_at' => now(),
        ]);
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $session->id,
            'customer_id' => $customer->id,
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        app(BillingService::class)->addPayment($invoice, 100, 'cash', $this->cashier->id);
        $profile = $customer->fresh()->loyaltyCustomer;
        $this->assertSame(1000, $profile->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 1);

        app(RefundService::class)->issue($invoice->fresh(), 25, 'cash', 'إرجاع جزئي', $this->cashier->id);
        $this->assertSame(750, $profile->fresh()->points_balance);
        $this->assertSame(75.0, (float) $profile->fresh()->total_spent);
    }
}
