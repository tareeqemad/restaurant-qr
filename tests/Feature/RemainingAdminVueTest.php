<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\LookupGroup;
use App\Models\Permission;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\StaffMealCharge;
use App\Models\StaffMealMonthClosure;
use App\Models\SyncState;
use App\Models\Table;
use App\Models\User;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RemainingAdminVueTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'remaining', 'name' => 'الفرع الأول', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'مدير النظام', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'مدير الاختبار',
            'username' => 'remaining_admin',
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => 'super_admin',
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_requested_admin_crud_pages_are_served_by_vue(): void
    {
        $category = Lookup::create([
            'group' => 'expense_categories', 'code' => 'utilities',
            'label' => 'خدمات', 'is_active' => true,
        ]);
        Lookup::create([
            'group' => 'zones', 'code' => 'inside',
            'label' => 'داخلي', 'is_active' => true,
        ]);
        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $category->id,
            'description' => 'فاتورة كهرباء',
            'amount' => 25,
            'currency_code' => 'ILS',
            'exchange_rate' => 1,
            'payment_method' => 'cash',
            'expense_date' => today(),
            'status' => 'pending_approval',
            'created_by_user_id' => $this->admin->id,
        ]);
        $table = Table::create([
            'branch_id' => $this->branch->id, 'number' => '7',
            'capacity' => 4, 'status' => 'available', 'active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'ضيف', 'phone' => '0599000111',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $reservation = Reservation::create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'table_id' => $table->id,
            'reference' => 'RV-VUE01',
            'party_size' => 3,
            'reserved_for' => now()->addHour(),
            'duration_minutes' => 90,
            'status' => ReservationStatus::Confirmed->value,
        ]);

        $this->actingAs($this->admin)->get(route('admin.expenses.create'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Expenses/Form')
            ->where('expense.id', null)
            ->where('urls.submit', route('admin.expenses.store')));
        $this->get(route('admin.expenses.edit', $expense))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Expenses/Form')
            ->where('expense.description', 'فاتورة كهرباء'));
        $this->get(route('admin.users.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Index')
            ->has('users.data', 1)
            ->has('editor.roles')
            ->where('editor.urls.create', route('admin.users.store'))
            ->where('users.data.0.account.urls.submit', route('admin.users.update', $this->admin)));
        $this->get(route('admin.tables.create'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tables/Form')->where('table.capacity', 4));
        $this->get(route('admin.tables.edit', $table))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Tables/Form')->where('table.number', '7'));
        $this->get(route('admin.reservations.edit', $reservation))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reservations/Edit')
            ->where('reservation.reference', 'RV-VUE01')
            ->where('can.seat', true));
        $this->get(route('admin.notifications.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Notifications/Index')->has('types'));
        $this->get(route('admin.profile.show'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Profile/Show')->where('profile.username', 'remaining_admin'));
    }

    public function test_staff_meal_workspace_pages_are_vue_and_keep_the_branch_context(): void
    {
        $employee = User::create([
            'name' => 'موظف الوجبات', 'username' => 'meal_employee',
            'password' => bcrypt('x'), 'status' => 'active', 'role' => 'waiter',
            'monthly_meal_allowance' => 100,
        ]);
        $employee->branches()->attach($this->branch->id, ['is_primary' => true]);
        StaffMealCharge::create([
            'branch_id' => $this->branch->id,
            'user_id' => $employee->id,
            'amount' => 12,
            'charged_at' => now(),
        ]);
        $closure = StaffMealMonthClosure::create([
            'branch_id' => $this->branch->id,
            'month' => now()->startOfMonth(),
            'method' => 'payroll_deduction',
            'total_amount' => 0,
            'staff_count' => 0,
            'charge_count' => 0,
            'closed_by_user_id' => $this->admin->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.staff-meals.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/StaffMeals/Index')
            ->has('rows', 1)
            ->where('rows.0.name', 'موظف الوجبات')
            ->where('rows.0.outstandingValue', 12));
        $this->get(route('admin.staff-meals.quick_consume'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/StaffMeals/QuickConsume')->has('employees', 1));
        $employeeRecord = Employee::where('user_id', $employee->id)->firstOrFail();
        $this->get(route('admin.staff-meals.show', $employeeRecord))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/StaffMeals/Show')
            ->where('summary.outstanding', 12)
            ->has('charges', 1));
        $this->get(route('admin.staff-meals.closures'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/StaffMeals/Closures')->has('closures.data', 1));
        $this->get(route('admin.staff-meals.closures.show', $closure))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/StaffMeals/ClosureShow')
            ->where('closure.branch', 'الفرع الأول'));
    }

    public function test_month_summary_can_be_separated_for_a_shared_employee_by_branch(): void
    {
        $otherBranch = Branch::create(['code' => 'other', 'name' => 'الفرع الثاني', 'is_active' => true]);
        $employee = User::create([
            'name' => 'موظف مشترك', 'username' => 'shared_meal_employee',
            'password' => bcrypt('x'), 'status' => 'active', 'role' => 'waiter',
            'monthly_meal_allowance' => 100,
        ]);
        $employee->branches()->attach([$this->branch->id, $otherBranch->id]);
        StaffMealCharge::create(['branch_id' => $this->branch->id, 'user_id' => $employee->id, 'amount' => 15, 'charged_at' => now()]);
        StaffMealCharge::create(['branch_id' => $otherBranch->id, 'user_id' => $employee->id, 'amount' => 40, 'charged_at' => now()]);

        $service = app(StaffMealService::class);
        $this->assertSame(15.0, $service->monthSummary($employee, now(), $this->branch->id)['outstanding']);
        $this->assertSame(40.0, $service->monthSummary($employee, now(), $otherBranch->id)['outstanding']);
        $this->assertSame(55.0, $service->monthSummary($employee, now())['outstanding']);
    }

    public function test_non_owner_cannot_open_another_branch_meal_closure(): void
    {
        $otherBranch = Branch::create(['code' => 'closed-other', 'name' => 'فرع آخر', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'manager'], ['label' => 'مدير', 'is_system' => true]);
        $manager = User::create([
            'name' => 'مدير فرع', 'username' => 'branch_manager_closure',
            'password' => bcrypt('x'), 'status' => 'active', 'role' => 'manager',
        ]);
        $manager->branches()->attach($this->branch->id, ['is_primary' => true]);
        $permission = Permission::where('name', 'staff_meals.viewAny')->firstOrFail();
        $manager->permissions()->syncWithoutDetaching([$permission->id => ['granted' => true]]);
        $closure = StaffMealMonthClosure::create([
            'branch_id' => $otherBranch->id, 'month' => now()->startOfMonth(),
            'method' => 'cash', 'total_amount' => 0, 'staff_count' => 0,
            'charge_count' => 0, 'closed_by_user_id' => $this->admin->id, 'closed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('admin.staff-meals.closures.show', $closure))
            ->assertForbidden();
    }

    public function test_remaining_live_admin_utilities_are_served_by_vue(): void
    {
        Lookup::create([
            'group' => 'expense_categories',
            'code' => 'repairs',
            'label' => 'صيانة',
            'color' => '#256f4b',
            'is_active' => true,
        ]);
        SyncState::create([
            'stream' => 'orders',
            'direction' => 'up',
            'last_status' => 'ok',
            'last_count' => 3,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard/Index')
                ->has('quickActions')
                ->has('stats'));

        $this->get(route('admin.lookups.index', ['group' => 'expense_categories']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Lookups/Index')
                ->where('activeGroup', 'expense_categories')
                ->has('groups', 4)
                ->has('rowsByGroup.expense_categories', 1)
                ->where('rows.0.label', 'صيانة'));

        $this->get(route('admin.sync.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Sync/Index')
                ->has('states', 1)
                ->where('states.0.stream', 'orders')
                ->where('states.0.status', 'ok'));
    }

    public function test_lookup_workspace_returns_every_tab_and_supports_json_crud_without_page_visits(): void
    {
        Lookup::create([
            'group' => 'zones',
            'code' => 'inside',
            'label' => 'داخلي',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.lookups.index', ['group' => 'expense_categories']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Lookups/Index')
                ->has('rowsByGroup.expense_categories', 0)
                ->has('rowsByGroup.zones', 1)
                ->where('rowsByGroup.zones.0.label', 'داخلي'));

        $created = $this->postJson(route('admin.lookups.store'), [
            'group' => 'expense_categories',
            'code' => 'internet',
            'label' => 'إنترنت',
            'color' => '#256f4b',
            'icon' => 'bi-wifi',
            'display_order' => 12,
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('row.group', 'expense_categories')
            ->assertJsonPath('row.label', 'إنترنت');

        $lookupId = $created->json('row.id');

        $this->putJson(route('admin.lookups.update', $lookupId), [
            'group' => 'expense_categories',
            'code' => 'internet',
            'label' => 'خدمة الإنترنت',
            'color' => '#1f6f50',
            'icon' => 'bi-router',
            'display_order' => 10,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('row.label', 'خدمة الإنترنت')
            ->assertJsonPath('row.active', false);

        $this->deleteJson(route('admin.lookups.destroy', $lookupId))
            ->assertOk()
            ->assertJsonPath('row.deleted', true);

        $this->postJson(route('admin.lookups.restore', $lookupId))
            ->assertOk()
            ->assertJsonPath('row.deleted', false);
    }

    public function test_lookup_workspace_reads_group_identity_and_order_from_database(): void
    {
        $zones = LookupGroup::where('code', 'zones')->firstOrFail();
        $zones->update([
            'label' => 'تقسيم الصالة',
            'icon' => 'bi-grid-3x3-gap-fill',
            'subtitle' => 'تعريف حي قادم من قاعدة البيانات.',
            'display_order' => 1,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.lookups.index', ['group' => 'zones']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeGroup', 'zones')
                ->where('groups.0.key', 'zones')
                ->where('groups.0.label', 'تقسيم الصالة')
                ->where('groups.0.icon', 'bi-grid-3x3-gap-fill')
                ->where('groups.0.subtitle', 'تعريف حي قادم من قاعدة البيانات.')
            );
    }

    public function test_user_management_uses_one_shared_inline_editor_in_both_workspaces(): void
    {
        $usersPage = file_get_contents(resource_path('js/Pages/Admin/Users/Index.vue'));
        $permissionsPage = file_get_contents(resource_path('js/Pages/Admin/Permissions/Index.vue'));
        $sheet = file_get_contents(resource_path('js/Components/Users/UserAccountSheet.vue'));

        $this->assertStringContainsString('UserAccountSheet', $usersPage);
        $this->assertStringContainsString('@click="createUser"', $usersPage);
        $this->assertStringContainsString('@click="editUser(user)"', $usersPage);
        $this->assertStringContainsString('UserAccountSheet', $permissionsPage);
        $this->assertStringContainsString('تعديل بيانات المستخدم وفروعه هنا', $permissionsPage);
        $this->assertStringContainsString('_inline: true', $sheet);
        $this->assertStringContainsString('<style scoped>', $sheet);
    }
}
