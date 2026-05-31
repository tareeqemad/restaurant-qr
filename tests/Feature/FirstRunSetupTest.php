<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_setup_when_system_has_no_required_records(): void
    {
        $this->get('/')->assertRedirect(route('setup.show'));
    }

    public function test_existing_install_is_not_forced_into_setup(): void
    {
        Branch::create(['code' => 'main', 'name' => 'Main Branch', 'is_active' => true]);
        User::create([
            'name' => 'Owner',
            'username' => 'owner',
            'role' => 'super_admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        Setting::put('setup_completed', true, 'system', 'bool');

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/setup')->assertRedirect(route('login'));
    }

    public function test_demo_install_can_be_used_before_optional_setup(): void
    {
        Setting::query()->where('key', 'setup_completed')->delete();
        Cache::forget('setting.setup_completed');

        $branch = Branch::create(['code' => 'demo', 'name' => 'Demo Branch', 'is_active' => true]);
        $owner = User::create([
            'name' => 'Demo Owner',
            'username' => 'admin',
            'role' => 'super_admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $owner->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/login')->assertOk();

        $this->post('/login', [
            'username' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->actingAs($owner)
            ->get(route('admin.profile.show'))
            ->assertOk();

        $this->actingAs($owner)
            ->get('/setup')
            ->assertOk()
            ->assertSeeText(__('setup.demo_reset.title'))
            ->assertSeeText(__('setup.actions.continue_demo'));
    }

    public function test_authenticated_admin_setup_wipes_demo_data_and_creates_real_owner(): void
    {
        Setting::query()->where('key', 'setup_completed')->delete();
        Cache::forget('setting.setup_completed');

        Branch::create(['code' => 'demo', 'name' => 'Demo Branch', 'is_active' => true]);
        $owner = User::create([
            'name' => 'Owner',
            'username' => 'owner',
            'role' => 'super_admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        User::create([
            'name' => 'Demo Cashier',
            'username' => 'cashier_demo',
            'role' => 'cashier',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        Supplier::create(['name' => 'Demo Supplier']);

        $this->actingAs($owner)
            ->get('/setup')
            ->assertOk()
            ->assertSeeText(__('setup.demo_reset.title'))
            ->assertSeeText(__('setup.fields.admin_name'));

        $response = $this->actingAs($owner)->post('/setup', $this->payload([
            'branch_code' => 'main',
            'branch_name' => 'Main Branch',
            'admin_name' => 'Real Owner',
            'admin_username' => 'owner',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'secure-pass',
            'admin_password_confirmation' => 'secure-pass',
        ]));

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertSame(1, Branch::count());
        $this->assertSame(1, User::count());
        $this->assertTrue(Setting::get('setup_completed'));
        $this->assertDatabaseHas('branches', ['code' => 'main', 'name' => 'Main Branch']);
        $this->assertDatabaseHas('users', [
            'name' => 'Real Owner',
            'username' => 'owner',
            'email' => 'owner@example.com',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('users', ['username' => 'cashier_demo']);
        $this->assertDatabaseMissing('suppliers', ['name' => 'Demo Supplier']);
    }

    public function test_setup_creates_first_branch_owner_and_runtime_settings(): void
    {
        Carbon::setTestNow('2026-05-31 10:00:00');
        config(['market.profile' => 'us']);
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.cloud_url' => 'https://cloud.example.com',
            'license.key' => 'LIC-123',
        ]);

        $response = $this->post('/setup', $this->payload([
            'market_profile' => 'us',
            'restaurant_name' => 'Atlas Diner',
            'currency_symbol' => '$',
            'sales_currency' => 'USD',
            'accounting_base_currency' => 'USD',
            'accounting_currency_symbol' => '$',
            'sales_to_accounting_rate' => '1',
            'fiscal_year_start_month' => '1',
            'fiscal_year_start_day' => '1',
            'tax_rate' => '8.25',
            'service_rate' => '0',
            'branch_code' => 'main',
            'branch_name' => 'Main Branch',
            'admin_name' => 'System Owner',
            'admin_username' => 'owner',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'secure-pass',
            'admin_password_confirmation' => 'secure-pass',
            'sync_enabled' => '1',
            'sync_role' => 'branch',
            'sync_cloud_url' => 'https://cloud.example.com',
            'sync_token' => 'shared-secret',
        ]));

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'username' => 'owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'main',
            'name' => 'Main Branch',
            'is_active' => true,
        ]);

        $this->assertTrue(Setting::get('setup_completed'));
        $this->assertSame('us', Setting::get('market_profile'));
        $this->assertSame('Atlas Diner', Setting::get('site_name'));
        $this->assertSame('$', Setting::get('currency_symbol'));
        $this->assertSame('USD', Setting::get('sales_currency'));
        $this->assertSame('USD', Setting::get('accounting_base_currency'));
        $this->assertSame(1.0, Setting::get('sales_to_accounting_rate'));
        $this->assertSame(8.25, Setting::get('tax_rate'));
        $this->assertSame('#1d4ed8', Setting::get('theme_primary'));
        $this->assertSame('#06b6d4', Setting::get('theme_accent'));
        $this->assertSame('color', Setting::get('theme_header_style'));
        $this->assertTrue(Setting::get('sync_enabled'));
        $this->assertSame('branch', Setting::get('sync_role'));
        $this->assertSame(Branch::first()->uuid, Setting::get('sync_branch_uuid'));
        $this->assertTrue(Setting::get('license_enabled'));
        $this->assertSame('branch', Setting::get('license_role'));
        $this->assertSame('LIC-123', Setting::get('license_key'));
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => true]);
        $fiscalYear = FiscalYear::where('branch_id', Branch::first()->id)->firstOrFail();
        $this->assertSame('2026-01-01', $fiscalYear->starts_on->toDateString());
        $this->assertSame('2026-12-31', $fiscalYear->ends_on->toDateString());
        $this->assertSame('open', $fiscalYear->status);
        Carbon::setTestNow();
    }

    public function test_setup_hides_and_ignores_customer_submitted_license_settings(): void
    {
        config(['license.enabled' => true, 'license.role' => 'branch', 'license.key' => 'PROVIDER-LIC']);

        $this->get('/setup')
            ->assertOk()
            ->assertDontSeeText(__('setup.fields.license_enabled'))
            ->assertDontSeeText(__('setup.fields.license_key'))
            ->assertDontSee('license_key');

        $response = $this->post('/setup', $this->payload([
            'license_enabled' => '0',
            'license_role' => 'standalone',
            'license_key' => 'CUSTOMER-LIC',
        ]));

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(Setting::get('license_enabled'));
        $this->assertSame('branch', Setting::get('license_role'));
        $this->assertSame('PROVIDER-LIC', Setting::get('license_key'));
    }

    public function test_setup_keeps_preconfigured_sync_branch_uuid_when_present(): void
    {
        config(['market.profile' => 'us']);

        $response = $this->post('/setup', $this->payload([
            'market_profile' => 'us',
            'sync_branch_uuid' => '01JZBRANCH00000000000001',
        ]));

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertSame('01JZBRANCH00000000000001', Setting::get('sync_branch_uuid'));
    }

    public function test_pending_setup_uses_configured_market_before_saved_settings(): void
    {
        config(['market.profile' => 'palestine']);
        Setting::put('market_profile', 'us', 'system');
        Setting::query()->where('key', 'setup_completed')->delete();
        Cache::forget('setting.setup_completed');

        RuntimeConfig::apply();

        $this->assertSame('palestine', config('market.profile'));

        Setting::put('setup_completed', true, 'system', 'bool');
        RuntimeConfig::apply();

        $this->assertSame('us', config('market.profile'));
    }

    public function test_admin_routes_redirect_to_setup_when_branch_is_missing(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'username' => 'owner',
            'role' => 'super_admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($owner)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('setup.show'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'market_profile' => 'palestine',
            'restaurant_name' => 'Relax',
            'legal_name' => null,
            'tax_number' => null,
            'receipt_footer' => null,
            'currency_symbol' => '₪',
            'sales_currency' => 'ILS',
            'accounting_base_currency' => 'ILS',
            'accounting_currency_symbol' => '₪',
            'sales_to_accounting_rate' => '1',
            'fiscal_year_start_month' => '1',
            'fiscal_year_start_day' => '1',
            'tax_enabled' => '1',
            'tax_rate' => '16',
            'customer_tax_display' => 'exclusive',
            'service_enabled' => '0',
            'service_rate' => '10',
            'theme_primary' => '#1d4ed8',
            'theme_dark' => '#0f172a',
            'theme_header' => '#1e40af',
            'theme_accent' => '#06b6d4',
            'theme_menu' => '#eff6ff',
            'theme_header_style' => 'color',
            'theme_menu_style' => 'brand',
            'branch_code' => 'main',
            'branch_name' => 'Main Branch',
            'branch_name_en' => 'Main Branch',
            'branch_phone' => null,
            'branch_email' => null,
            'branch_city' => null,
            'branch_address' => null,
            'delivery_estimated_minutes' => '30',
            'delivery_fee' => '0',
            'prep_buffer_minutes' => '5',
            'admin_name' => 'Owner',
            'admin_username' => 'admin',
            'admin_email' => null,
            'admin_phone' => null,
            'admin_password' => 'password',
            'admin_password_confirmation' => 'password',
            'strict_stock' => '1',
            'inventory_deduction_stage' => 'approve',
            'customer_cancel_window_seconds' => '120',
            'session_ttl_minutes' => '240',
            'menu_base_url' => null,
            'sync_enabled' => '0',
            'sync_role' => 'standalone',
            'sync_cloud_url' => null,
            'sync_token' => null,
            'sync_branch_uuid' => null,
        ], $overrides);
    }
}
