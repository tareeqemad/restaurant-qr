<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FiscalYear;
use App\Models\Ingredient;
use App\Models\IngredientUnit;
use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_setup_when_system_has_no_required_records(): void
    {
        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Show')
            ->where('mode', 'fresh'));
    }

    public function test_completed_install_is_not_forced_into_setup(): void
    {
        Branch::create(['code' => 'main', 'name' => 'Main Branch', 'is_active' => true]);
        User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'super_admin', 'status' => 'active', 'password' => 'password']);
        Setting::put('setup_completed', true, 'system', 'bool');

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/setup')->assertRedirect(route('login'));
    }

    public function test_demo_can_be_used_freely_before_optional_setup(): void
    {
        [$branch, $owner] = $this->demoInstall();

        $this->get('/')->assertRedirect(route('login'));
        $this->post('/login', ['username' => 'admin', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($owner)->get(route('admin.profile.show'))->assertOk();
        $this->actingAs($owner)->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Show')
            ->where('mode', 'demo')
            ->where('summary.branchName', $branch->name)
            ->where('defaults.admin_name', '')
            ->where('defaults.admin_email', '')
            ->where('defaults.admin_phone', '')
            ->where('routes.continueDemo', route('admin.dashboard')));
    }

    public function test_setup_accepts_only_local_palestinian_staff_mobile_numbers(): void
    {
        [, $owner] = $this->demoInstall();

        $this->actingAs($owner)->post('/setup', $this->payload([
            'admin_phone' => '0790000000',
            'confirm_reset' => true,
        ]))->assertSessionHasErrors('admin_phone');

        $this->assertDatabaseHas('branches', ['code' => 'demo']);
        $this->assertDatabaseHas('users', ['username' => 'admin']);
    }

    public function test_demo_handover_preserves_catalogue_and_wipes_operational_data(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        [$branch, $owner] = $this->demoInstall();
        $otherBranch = Branch::create(['code' => 'other', 'name' => 'Demo Other', 'is_active' => true]);
        $demoCashier = User::create(['name' => 'Demo Cashier', 'username' => 'cashier_demo', 'role' => 'cashier', 'status' => 'active', 'password' => 'password']);

        $unit = Unit::create(['code' => 'g-test', 'name' => 'غرام', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $supplier = Supplier::create(['name' => 'Demo Supplier', 'active' => true]);
        $ingredient = Ingredient::create([
            'name' => 'دجاج تجريبي', 'base_unit_id' => $unit->id, 'measurement_type' => 'weight',
            'supplier_id' => $supplier->id, 'current_stock' => 25, 'track_stock' => true, 'active' => true,
        ]);
        $ingredientUnit = IngredientUnit::create(['ingredient_id' => $ingredient->id, 'name' => 'كيلو', 'factor_to_base' => 1000, 'active' => true]);
        $category = Category::create(['branch_id' => $branch->id, 'name' => 'وجبات', 'active' => true]);
        $item = MenuItem::create(['branch_id' => $branch->id, 'category_id' => $category->id, 'name' => 'وجبة دجاج', 'price' => 20, 'is_available' => true]);
        $recipe = RecipeItem::create(['menu_item_id' => $item->id, 'ingredient_id' => $ingredient->id, 'unit_id' => $unit->id, 'quantity' => 300]);
        Category::create(['branch_id' => $otherBranch->id, 'name' => 'يجب حذفها', 'active' => true]);

        $response = $this->actingAs($owner)->post('/setup', $this->payload([
            'restaurant_name' => 'مطعم حقيقي',
            'branch_code' => 'main',
            'branch_name' => 'الفرع الرئيسي',
            'admin_name' => 'المالك الحقيقي',
            'admin_username' => 'owner',
            'admin_phone' => '0592632026',
            'staff' => [['name' => 'أحمد الجرسون', 'phone' => null, 'role' => 'waiter']],
            'confirm_reset' => true,
        ]));

        $response->assertRedirect(route('setup.complete'))->assertSessionHas('setup_credentials');
        $this->assertAuthenticatedAs($owner->fresh());
        $this->assertSame(1, Branch::count());
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'code' => 'main', 'name' => 'الفرع الرئيسي']);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id, 'current_stock' => 0, 'supplier_id' => null]);
        $this->assertDatabaseHas('ingredient_units', ['id' => $ingredientUnit->id]);
        $this->assertDatabaseHas('recipe_items', ['id' => $recipe->id, 'menu_item_id' => $item->id]);
        $this->assertDatabaseMissing('categories', ['branch_id' => $otherBranch->id]);
        $this->assertDatabaseMissing('users', ['id' => $demoCashier->id]);
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('users', ['name' => 'المالك الحقيقي', 'username' => 'owner', 'role' => 'super_admin']);
        $this->assertDatabaseHas('users', ['name' => 'أحمد الجرسون', 'role' => 'waiter']);
        $this->assertDatabaseHas('stations', ['branch_id' => $branch->id, 'code' => 'kitchen']);
        $this->assertDatabaseHas('stations', ['branch_id' => $branch->id, 'code' => 'bar']);
        $this->assertDatabaseHas('storage_locations', ['branch_id' => $branch->id, 'is_default' => true]);
        $this->assertTrue((bool) Setting::get('setup_completed'));
        $this->assertFalse((bool) Setting::get('tax_enabled'));
        $this->assertFalse((bool) Setting::get('service_enabled'));
        $this->assertFalse((bool) Setting::get('sync_enabled'));
        $this->assertSame('ILS', Setting::get('accounting_base_currency'));

        $this->get(route('setup.complete'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Setup/Complete')
            ->where('owner.username', 'owner')
            ->has('credentials', 1));
        Carbon::setTestNow();
    }

    public function test_fresh_setup_creates_branch_owner_skeleton_and_clean_defaults(): void
    {
        Carbon::setTestNow('2026-05-31 10:00:00');
        $response = $this->post('/setup', $this->payload([
            'restaurant_name' => 'Atlas Diner',
            'branch_name' => 'Main Branch',
            'admin_name' => 'System Owner',
            'business_owner_name' => 'System Owner',
            'admin_username' => 'owner',
            'admin_email' => 'owner@example.com',
        ]));

        $response->assertRedirect(route('setup.complete'));
        $this->assertAuthenticated();
        $branch = Branch::firstOrFail();
        $this->assertDatabaseHas('users', ['username' => 'owner', 'role' => 'super_admin', 'status' => 'active']);
        $this->assertDatabaseHas('branches', ['code' => 'main', 'name' => 'Main Branch', 'is_active' => true]);
        $this->assertDatabaseHas('business_owners', ['name' => 'System Owner', 'owner_type' => 'person']);
        $this->assertDatabaseHas('branch_ownerships', ['branch_id' => $branch->id, 'ownership_percentage' => 100]);
        $this->assertDatabaseHas('branch_legal_profiles', ['branch_id' => $branch->id]);
        $this->assertSame('Atlas Diner', Setting::get('site_name'));
        $this->assertSame('₪', Setting::get('currency_symbol'));
        $this->assertSame('ILS', Setting::get('sales_currency'));
        $this->assertFalse((bool) Setting::get('tax_enabled'));
        $this->assertFalse((bool) Setting::get('service_enabled'));
        $this->assertFalse((bool) Setting::get('sync_enabled'));
        $this->assertDatabaseHas('currencies', ['code' => 'ILS', 'is_base' => true]);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => false, 'is_active' => true]);
        $this->assertSame(1, StorageLocation::where('branch_id', $branch->id)->where('is_default', true)->count());
        $this->assertSame(2, Station::where('branch_id', $branch->id)->whereIn('code', ['kitchen', 'bar'])->count());
        $year = FiscalYear::where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame('2026-01-01', $year->starts_on->toDateString());
        $this->assertSame('2026-12-31', $year->ends_on->toDateString());
        Carbon::setTestNow();
    }

    public function test_admin_routes_redirect_to_setup_when_branch_is_missing(): void
    {
        $owner = User::create(['name' => 'Owner', 'username' => 'owner', 'role' => 'super_admin', 'status' => 'active', 'password' => 'password']);
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertRedirect(route('setup.show'));
    }

    private function demoInstall(): array
    {
        Setting::query()->where('key', 'setup_completed')->delete();
        Cache::forget('setting.setup_completed');
        $branch = Branch::create(['code' => 'demo', 'name' => 'Demo Branch', 'is_active' => true]);
        $owner = User::create([
            'name' => 'Demo Owner', 'username' => 'admin', 'email' => 'admin@demo.test',
            'phone' => '0790000000', 'role' => 'super_admin', 'status' => 'active', 'password' => 'password',
        ]);
        $owner->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return [$branch, $owner];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'restaurant_name' => 'Relax', 'legal_name' => null, 'receipt_footer' => 'شكراً لزيارتكم',
            'tax_number' => null, 'commercial_registration_number' => null, 'municipal_license_number' => null,
            'branch_code' => 'main', 'branch_name' => 'الفرع الرئيسي', 'branch_phone' => null,
            'branch_city' => null, 'branch_address' => null, 'admin_name' => 'Owner',
            'business_owner_type' => 'person', 'business_owner_name' => 'Owner',
            'business_owner_national_id' => null, 'business_owner_phone' => null, 'business_owner_percentage' => 100,
            'admin_username' => 'admin', 'admin_email' => null, 'admin_phone' => null,
            'admin_password' => 'password', 'admin_password_confirmation' => 'password', 'staff' => [],
        ], $overrides);
    }
}
