<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\Role;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpenseVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Lookup $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'expense-ui',
            'name' => 'فرع المصروفات',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        foreach (['manager', 'cashier'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName], [
                'label' => $roleName,
                'is_system' => true,
            ]);
        }
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->category = Lookup::create([
            'group' => 'expense_categories',
            'code' => 'rent-ui',
            'label' => 'إيجار',
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    public function test_manager_receives_the_vue_expense_workspace_with_inline_actions(): void
    {
        $manager = $this->staff('manager');
        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'description' => 'إيجار المخزن',
            'amount' => 125,
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'status' => 'pending_approval',
            'created_by_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Expenses/Index')
                ->where('expenses.data.0.description', 'إيجار المخزن')
                ->where('expenses.data.0.category.label', 'إيجار')
                ->where('expenses.data.0.status', 'pending_approval')
                ->where('expenses.data.0.can.approve', true)
                ->where('expenses.data.0.can.reject', true)
                ->where('expenses.data.0.urls.approve', route('admin.expenses.approve', $expense))
                ->where('stats.pending', 1)
                ->where('can.create', true)
                ->has('urls.index')
            );
    }

    public function test_cashier_can_log_an_expense_but_cannot_approve_it(): void
    {
        $cashier = $this->staff('cashier');
        Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $this->category->id,
            'description' => 'مستلزمات تنظيف',
            'amount' => 20,
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'status' => 'pending_approval',
            'created_by_user_id' => $cashier->id,
        ]);

        $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Expenses/Index')
                ->where('expenses.data.0.can.approve', false)
                ->where('expenses.data.0.can.reject', false)
                ->where('expenses.data.0.can.update', false)
                ->where('can.create', true)
            );
    }

    protected function staff(string $roleName): User
    {
        Role::firstOrCreate(['name' => $roleName], [
            'label' => $roleName,
            'is_system' => true,
        ]);

        $user = User::create([
            'name' => $roleName,
            'username' => $roleName.'_expense_ui',
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => $roleName,
            'primary_branch_id' => $this->branch->id,
        ]);
        $user->branches()->attach($this->branch->id);

        return $user;
    }
}
