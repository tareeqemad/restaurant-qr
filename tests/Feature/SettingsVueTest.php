<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsVueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'settings-vue',
            'name' => 'Settings Vue',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->superAdmin = $this->makeUser('settings-owner', 'super_admin');
        $this->admin = $this->makeUser('settings-admin', 'admin');
        $this->manager = $this->makeUser('settings-manager', 'manager');

        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'شيكل',
            'symbol' => '₪',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
            'display_order' => 1,
        ]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'دولار',
            'symbol' => '$',
            'rate_to_base' => 3.7,
            'is_base' => false,
            'is_active' => true,
            'display_order' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_admin_receives_the_complete_vue_settings_contract(): void
    {
        $this->as($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('can.edit', true)
                ->has('values.site_name')
                ->has('values.strict_stock')
                ->has('values.payment_method_cash_enabled')
                ->has('values.bank_account_number')
                ->has('values.bank_iban')
                ->has('values.palpay_wallet_number')
                ->has('paymentMethods', 5)
                ->has('statuses')
                ->has('currencies', 2)
                ->where('market.baseCurrency', 'ILS')
                ->missing('teamSetup')
                ->where('urls.update', route('admin.settings.update'))
            );
    }

    public function test_manager_can_review_settings_but_cannot_change_them(): void
    {
        $this->as($this->manager)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->where('can.edit', false)
            );

        $this->as($this->manager)
            ->put(route('admin.settings.update'), $this->validSettings())
            ->assertForbidden();
    }

    public function test_admin_can_save_settings_and_clear_optional_bank_details(): void
    {
        Setting::put('bank_transfer_details', 'بيانات قديمة', 'billing', 'string');

        $payload = $this->validSettings([
            'site_name' => 'مطعم ريلاكس',
            'service_enabled' => true,
            'service_rate' => 12.5,
            'bank_transfer_details' => '',
            'bank_name' => 'بنك فلسطين',
            'bank_account_holder' => 'مطعم ريلاكس',
            'bank_account_number' => '00123456789',
            'bank_iban' => 'PS92PALS000000000000000000000',
            'palpay_wallet_number' => '0592632026',
            'strict_stock' => false,
            'inventory_deduction_stage' => 'preparing',
        ]);

        $this->as($this->admin)
            ->put(route('admin.settings.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('مطعم ريلاكس', Setting::get('site_name'));
        $this->assertSame(12.5, (float) Setting::get('service_rate'));
        $this->assertFalse((bool) Setting::get('strict_stock'));
        $this->assertSame('00123456789', Setting::get('bank_account_number'));
        $this->assertSame('0592632026', Setting::get('palpay_wallet_number'));
        $this->assertDatabaseMissing('settings', ['key' => 'bank_transfer_details']);
    }

    public function test_at_least_one_payment_method_must_remain_enabled(): void
    {
        $payload = $this->validSettings([
            'payment_method_cash_enabled' => false,
            'payment_method_transfer_enabled' => false,
        ]);

        $this->as($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), $payload)
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors('payment_method_cash_enabled');

        $this->assertTrue((bool) Setting::get('payment_method_cash_enabled', true));
    }

    private function makeUser(string $username, string $role): User
    {
        $user = User::create([
            'name' => $username,
            'username' => $username,
            'role' => $role,
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $this->branch->id,
        ]);
        $user->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return $user;
    }

    private function as(User $user): self
    {
        return $this->actingAs($user)->withSession(['active_branch_id' => $this->branch->id]);
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'site_name' => 'Restaurant',
            'legal_name' => '',
            'tax_number' => '',
            'receipt_footer' => '',
            'bank_transfer_details' => 'Bank account',
            'bank_name' => '',
            'bank_account_holder' => '',
            'bank_account_number' => '',
            'bank_iban' => '',
            'palpay_wallet_number' => '',
            'jawwal_pay_wallet_number' => '',
            'customer_tax_display' => 'exclusive',
            'service_rate' => 0,
            'service_enabled' => false,
            'currency_symbol' => '₪',
            'customer_currency_switcher' => true,
            'customer_cancel_window_seconds' => 120,
            'session_ttl_minutes' => 240,
            'strict_stock' => true,
            'inventory_deduction_stage' => 'preparing',
            'auto_approve' => false,
            'staff_meal_include_service' => false,
            'staff_meal_over_limit_policy' => 'warn',
            'prep_time_buffer_pct' => 20,
            'sms_enabled' => false,
            'payment_method_cash_enabled' => true,
            'payment_method_transfer_enabled' => true,
        ], $overrides);
    }
}
