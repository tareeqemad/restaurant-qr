<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\User;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeMealIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'staff', 'name' => 'الفرع', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->admin = User::create([
            'name' => 'مدير النظام', 'username' => 'employee_admin',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_worker_without_login_can_be_created_and_receive_a_meal_charge(): void
    {
        $this->actingAs($this->admin)->post(route('admin.staff-meals.employees.store'), [
            'name' => 'عامل بلا حساب',
            'phone' => '0592632026',
            'job_title' => 'عامل مطبخ',
            'monthly_meal_allowance' => 10,
            'meal_debt_ceiling' => 100,
            'branch_ids' => [$this->branch->id],
        ])->assertSessionHasNoErrors();

        $employee = Employee::where('name', 'عامل بلا حساب')->firstOrFail();
        $this->assertNull($employee->user_id);
        $this->assertTrue($employee->branches()->whereKey($this->branch->id)->exists());

        $order = Order::create([
            'branch_id' => $this->branch->id,
            'staff_consumer_employee_id' => $employee->id,
            'order_type' => 'takeaway',
            'order_source' => 'other',
            'status' => OrderStatus::Delivered->value,
            'subtotal' => 20,
            'total' => 20,
            'tax_rate' => 0,
            'service_rate' => 0,
            'completed_at' => now(),
        ]);

        $charge = app(StaffMealService::class)->chargeOrder($order, $this->admin->id);

        $this->assertSame($employee->id, $charge->employee_id);
        $this->assertNull($charge->user_id);
        $this->assertSame(10.0, (float) $charge->amount);
        $this->assertSame(10.0, $employee->fresh()->staffMealOutstanding());

        $this->get(route('admin.staff-meals.quick_consume'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StaffMeals/QuickConsume')
                ->where('employees.0.name', 'عامل بلا حساب')
                ->where('employees.0.hasLogin', false));

        $this->get(route('admin.staff-meals.show', $employee))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/StaffMeals/Show')
                ->where('employee.access', 'بدون حساب دخول')
                ->where('summary.outstanding', 10));
    }

    public function test_linking_a_login_is_optional_and_does_not_change_employee_identity(): void
    {
        $login = User::create([
            'name' => 'موظف يستخدم النظام', 'username' => 'linked_employee',
            'password' => bcrypt('secret'), 'role' => 'waiter', 'status' => 'active',
        ]);
        $login->branches()->attach($this->branch->id, ['is_primary' => true]);

        $employee = Employee::create([
            'name' => 'الموظف التشغيلي', 'user_id' => $login->id,
            'monthly_meal_allowance' => 50, 'status' => 'active',
        ]);
        $employee->branches()->attach($this->branch->id, ['is_primary' => true]);

        $employeeId = $employee->id;
        $employee->update(['user_id' => null]);

        $this->assertSame($employeeId, $employee->fresh()->id);
        $this->assertNull($employee->fresh()->user_id);
    }
}
