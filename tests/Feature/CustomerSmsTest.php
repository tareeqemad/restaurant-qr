<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerSmsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'sms', 'name' => 'SMS', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->customer = Customer::create([
            'name' => 'زبون الرسائل',
            'phone' => '0599000123',
            'status' => 'active',
        ]);

        Setting::put('sms_enabled', true, 'sms', 'bool');
        Setting::put('sms_api_url', 'https://sms.test/api', 'sms');
        Setting::put('sms_username', 'user', 'sms');
        Setting::put('sms_password', Crypt::encryptString('secret'), 'sms');
        Setting::put('sms_sender', 'Restaurant', 'sms');
    }

    public function test_cashier_can_send_a_customer_sms_from_the_customer_file(): void
    {
        Http::fake(['sms.test/*' => Http::response('1:SMS-77:972599000123')]);

        $cashier = $this->staff('cashier');
        $this->actingAs($cashier)
            ->post(route('admin.customers.sms', $this->customer), ['message' => 'طلبك جاهز للاستلام.'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request['comm'] === 'sendsms'
            && $request['to'] === '972599000123'
            && $request['message'] === 'طلبك جاهز للاستلام.');
        $this->assertDatabaseHas('activity_logs', ['event' => 'customer.sms_sent']);
    }

    public function test_customer_directory_and_file_use_the_vue_workspace(): void
    {
        $this->customer->update(['default_branch_id' => $this->branch->id]);
        $cashier = $this->staff('cashier');

        $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Index')
                ->where('customers.data.0.name', 'زبون الرسائل')
                ->where('customers.data.0.phone', '0599000123')
                ->where('scope.branchName', 'SMS')
            );

        $this->actingAs($cashier)
            ->get(route('admin.customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Show')
                ->where('customer.name', 'زبون الرسائل')
                ->where('can.notify', true)
                ->where('can.update', false)
                ->has('urls.sms')
            );
    }

    public function test_waiter_cannot_send_customer_sms(): void
    {
        Http::fake();

        $this->actingAs($this->staff('waiter'))
            ->post(route('admin.customers.sms', $this->customer), ['message' => 'رسالة غير مخولة'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_sms_provider_failure_returns_a_friendly_error(): void
    {
        Http::fake(['sms.test/*' => Http::response('-113')]);

        $this->actingAs($this->staff('cashier'))
            ->from(route('admin.customers.show', $this->customer))
            ->post(route('admin.customers.sms', $this->customer), ['message' => 'تذكير بالحجز'])
            ->assertRedirect(route('admin.customers.show', $this->customer))
            ->assertSessionHas('error', 'لا يوجد رصيد كافٍ في حساب الرسائل.');
    }

    protected function staff(string $roleName): User
    {
        Role::firstOrCreate(['name' => $roleName], ['label' => $roleName, 'is_system' => true]);
        $user = User::create([
            'name' => $roleName,
            'username' => $roleName.'_sms',
            'password' => bcrypt('x'),
            'status' => 'active',
            'role' => $roleName,
            'primary_branch_id' => $this->branch->id,
        ]);
        $user->branches()->attach($this->branch->id);

        return $user;
    }
}
