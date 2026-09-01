<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNav;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountantCashierPermissionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'permission-separation',
            'name' => 'Permission separation',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->accountant = $this->staff('permission-accountant', UserRole::Accountant->value);
        $this->cashier = $this->staff('permission-cashier', UserRole::Cashier->value);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_default_roles_have_a_small_intentional_overlap_only(): void
    {
        $accountantPermissions = Role::where('name', UserRole::Accountant->value)
            ->firstOrFail()->permissions()->pluck('name')->all();
        $cashierPermissions = Role::where('name', UserRole::Cashier->value)
            ->firstOrFail()->permissions()->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            'chart_of_accounts.viewAny', 'chart_of_accounts.create',
            'chart_of_accounts.update', 'chart_of_accounts.delete',
            'reports.viewAny', 'reports.export',
            'expenses.viewAny', 'expenses.view', 'expenses.create', 'expenses.update',
            'supplier_invoices.viewAny', 'supplier_invoices.view',
            'supplier_invoices.create', 'supplier_invoices.pay', 'supplier_invoices.cancel',
            'suppliers.viewAny', 'suppliers.view',
            'purchase_orders.viewAny', 'purchase_orders.view',
            'inventory.viewAny',
            'stock_counts.viewAny', 'stock_counts.view',
            'payments.viewAny', 'payments.refund', 'payments.void',
            'payments.writeoff', 'payments.cancel_invoice',
            'customers.manage_credit',
        ], $accountantPermissions);

        $this->assertEqualsCanonicalizing([
            'orders.viewAny', 'orders.view', 'orders.create',
            'orders.approve', 'orders.cancel', 'orders.edit',
            'payments.viewAny', 'payments.create', 'payments.settle_on_account',
            'payments.void_own', 'payments.cancel_invoice',
            'discounts.apply', 'discounts.remove',
            'tables.viewAny', 'tables.assign_sections',
            'expenses.viewAny', 'expenses.view', 'expenses.create',
            'customers.viewAny', 'customers.view', 'customers.create', 'customers.notify',
            'reservations.viewAny', 'reservations.view',
            'staff_meals.quick_consume',
        ], $cashierPermissions);

        $this->assertEqualsCanonicalizing([
            'expenses.viewAny', 'expenses.view', 'expenses.create',
            'payments.viewAny', 'payments.cancel_invoice',
        ], array_values(array_intersect($accountantPermissions, $cashierPermissions)));
    }

    public function test_cashier_is_denied_reports_refunds_and_corrective_money_actions_by_default(): void
    {
        foreach ([
            'reports.viewAny', 'reports.export', 'payments.refund',
            'payments.void', 'payments.writeoff',
            'chart_of_accounts.viewAny', 'supplier_invoices.viewAny',
            'purchase_orders.viewAny', 'inventory.viewAny',
        ] as $permission) {
            $this->assertFalse($this->cashier->hasPermission($permission), $permission);
        }

        $this->assertTrue($this->cashier->hasPermission('payments.void_own'));
        $this->assertTrue($this->cashier->hasPermission('payments.cancel_invoice'));

        $this->actingAs($this->cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.reports.index'))
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.refunds.index'))
            ->assertForbidden();
    }

    public function test_accountant_can_review_source_documents_but_not_operate_till_or_procurement(): void
    {
        foreach ([
            'purchase_orders.create', 'purchase_orders.update',
            'purchase_orders.approve', 'purchase_orders.send',
            'purchase_orders.receive', 'purchase_orders.cancel',
            'purchase_orders.delete', 'inventory.manage',
            'stock_counts.create', 'stock_counts.finalize',
            'payments.create',
            'expenses.approve', 'expenses.reject', 'expenses.delete',
        ] as $permission) {
            $this->assertFalse($this->accountant->hasPermission($permission), $permission);
        }

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.purchase-orders.index'))
            ->assertOk();

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.purchase-orders.create'))
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.cashier.index'))
            ->assertOk();
    }

    public function test_sensitive_cashier_action_can_be_granted_explicitly_to_a_trusted_user(): void
    {
        $this->assertFalse($this->cashier->can('create', Refund::class));

        $permission = Permission::where('name', 'payments.refund')->firstOrFail();
        $this->cashier->permissions()->syncWithoutDetaching([
            $permission->id => ['granted' => true],
        ]);

        $this->assertTrue($this->cashier->fresh()->can('create', Refund::class));
    }

    public function test_debt_ledger_is_shared_and_a_cashier_can_receive_accountant_capabilities(): void
    {
        foreach ([$this->accountant, $this->cashier] as $user) {
            $this->actingAs($user)
                ->withSession(['active_branch_id' => $this->branch->id])
                ->get(route('admin.customers.debts.index'))
                ->assertOk();
        }

        $this->actingAs($this->accountant);
        $operations = collect(AdminNav::build())->firstWhere('label', __('admin.nav.operations'));
        $customers = collect($operations['children'])->firstWhere('label', __('admin.nav.customers'));
        $this->assertTrue(
            collect($customers['children'])->contains('label', __('admin.nav.debt_ledger')),
            'The accountant must see the shared debt ledger in navigation.',
        );

        $customer = Customer::create([
            'name' => 'Hybrid customer',
            'phone' => '0591234567',
            'active' => true,
        ]);

        $this->assertTrue($this->accountant->can('manageCredit', $customer));
        $this->assertFalse($this->cashier->can('manageCredit', $customer));

        $permission = Permission::where('name', 'customers.manage_credit')->firstOrFail();
        $this->cashier->permissions()->syncWithoutDetaching([
            $permission->id => ['granted' => true],
        ]);

        $this->actingAs($this->cashier->fresh())
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.customers.debts.credit_limit', $customer), [
                'credit_limit' => 350,
            ])
            ->assertRedirect();

        $this->assertSame(350.0, (float) $customer->fresh()->credit_limit);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'customer.credit_limit_changed',
            'subject_id' => $customer->id,
            'causer_id' => $this->cashier->id,
        ]);
    }

    private function staff(string $username, string $role): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $user->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        return $user;
    }
}
