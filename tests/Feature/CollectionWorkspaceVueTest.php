<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingTransfer;
use App\Models\Refund;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CollectionWorkspaceVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected Customer $customer;

    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'collection-ui',
            'name' => 'فرع التحصيل',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'مدير التحصيل',
            'username' => 'collection_admin',
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => 'admin',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        [$this->customer] = Customer::createFromCashier(
            name: 'زبون مدين',
            phone: '0599000999',
            defaultBranchId: $this->branch->id,
        );

        $this->invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'is_opening_balance' => true,
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'subtotal' => 80,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_total' => 0,
            'total' => 80,
            'paid_total' => 0,
            'refunded_total' => 0,
            'balance' => 80,
            'status' => 'issued',
            'issued_at' => now()->subDays(12),
            'settled_on_account_at' => now()->subDays(12),
            'settled_on_account_by_user_id' => $this->admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_debt_board_and_customer_statement_are_inertia_workspaces(): void
    {
        Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $this->invoice->id,
            'method' => 'cash',
            'amount' => 10,
            'received_by_user_id' => $this->admin->id,
            'paid_at' => now()->subDays(13),
        ]);
        Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $this->invoice->id,
            'method' => 'transfer',
            'amount' => 5,
            'reference' => 'BANK-55',
            'received_by_user_id' => $this->admin->id,
            'paid_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.customers.debts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CustomerDebts/Index')
                ->where('debts.data.0.name', 'زبون مدين')
                ->where('debts.data.0.debt', 80)
                ->where('debts.data.0.canCollect', true)
                ->where('stats.openInvoices', 1)
                ->where('collectionNav.0.key', 'debts')
            );

        $this->get(route('admin.customers.debts.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CustomerDebts/Show')
                ->where('customer.name', 'زبون مدين')
                ->where('stats.outstanding', 80)
                ->where('openInvoices.0.number', $this->invoice->number)
                ->where('openInvoices.0.registeredBy', 'مدير التحصيل')
                ->where('can.collect', true)
                ->where('timeline.0.type', 'payment')
                ->where('timeline.0.performedBy', 'مدير التحصيل')
                ->where('timeline.0.reference', 'BANK-55')
                ->where('timeline.1.type', 'debt_opened')
                ->has('timeline', 2)
            );
    }

    public function test_transfer_queue_and_daily_reconciliation_are_inertia_workspaces(): void
    {
        $table = Table::create([
            'number' => 'T-1',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);
        $session = TableSession::create([
            'table_id' => $table->id,
            'customer_id' => $this->customer->id,
            'cover_count' => 2,
            'status' => 'active',
        ]);
        $transfer = PendingTransfer::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $session->id,
            'invoice_id' => $this->invoice->id,
            'customer_id' => $this->customer->id,
            'amount' => 80,
            'sender_name' => 'صاحب التحويل',
            'customer_name_snapshot' => $this->customer->name,
            'customer_phone_snapshot' => $this->customer->phone,
            'status' => PendingTransfer::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.cashier.transfers.queue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Transfers/Queue')
                ->where('pending.0.id', $transfer->id)
                ->where('pending.0.senderName', 'صاحب التحويل')
                ->where('stats.pendingCount', 1)
                ->has('pending.0.urls.verify')
                ->has('pending.0.urls.reject')
            );

        $this->get(route('admin.cashier.transfers.report', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Transfers/Report')
                ->where('rows.0.id', $transfer->id)
                ->where('summary.pending.count', 1)
                ->where('summary.verified.count', 0)
            );
    }

    public function test_refund_review_is_an_inertia_workspace_with_policy_actions(): void
    {
        $refund = Refund::create([
            'branch_id' => $this->branch->id,
            'number' => Refund::generateNumber(),
            'invoice_id' => $this->invoice->id,
            'amount' => 20,
            'method' => 'cash',
            'status' => 'pending',
            'reason' => 'استرداد تجريبي',
            'processed_by' => $this->admin->id,
            'refunded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Refunds/Index')
                ->where('refunds.data.0.id', $refund->id)
                ->where('refunds.data.0.status', 'pending')
                ->where('refunds.data.0.can.complete', true)
                ->where('refunds.data.0.can.cancel', true)
                ->where('stats.pending', 1)
            );
    }
}
