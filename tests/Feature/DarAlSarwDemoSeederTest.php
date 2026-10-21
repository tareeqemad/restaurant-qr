<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\DemoResetService;
use App\Services\StaffSetupService;
use App\Support\BranchContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccountingSeeder;
use Database\Seeders\DemoDataset;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class DarAlSarwDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['restaurant.demo_password' => 'Only-for-isolated-fixtures!', 'cache.default' => 'array']);
        Cache::flush();
        BranchContext::clear();
        // The shared base fixture marks normal installations complete; this
        // suite starts from the empty pre-setup state used by a new demo.
        Setting::query()->where('key', 'setup_completed')->delete();
    }

    public function test_demo_is_a_coherent_fictional_catalogue_with_costed_recipes(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['branches' => 1, 'stations' => 2, 'tables' => 12, 'users' => 9, 'employees' => 7, 'categories' => 6, 'menu_items' => 24, 'ingredients' => 35, 'ingredient_stock' => 34, 'modifier_groups' => 4, 'modifiers' => 8] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        foreach (['orders', 'invoices', 'payments', 'journal_entries', 'inventory_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('branches', ['code' => DemoDataset::BRANCH, 'name' => 'صالة دار السرو']);
        $this->assertSame('مطعم دار السرو', Setting::get('site_name'));
        $this->assertSame('ILS', Setting::get('sales_currency'));
        $this->assertSame('#A3422B', Setting::get('theme_primary'));
        $this->assertSame(0, User::whereNotNull('phone')->count());
        $this->assertSame(9, User::where('email', 'like', '%@example.test')->count());
        foreach (MenuItem::withoutGlobalScopes()->with('recipeItems.ingredient', 'station', 'category')->get() as $item) {
            $this->assertNotEmpty($item->recipeItems);
            $this->assertSame((int) $item->branch_id, (int) $item->station->branch_id);
            $this->assertSame((int) $item->branch_id, (int) $item->category->branch_id);
            $cost = $item->recipeItems->sum(fn ($line) => (float) $line->quantity * (float) $line->ingredient->cost_per_unit);
            $this->assertEqualsWithDelta(round($cost, 2), (float) $item->cost, .001);
            $this->assertGreaterThan((float) $item->cost, (float) $item->price);
            $this->assertStringStartsWith('demo/menu/', $item->image);
            $this->assertFileExists(public_path($item->image));
            $this->assertStringContainsString('/demo/menu/', $item->imageUrl());
            $this->assertStringContainsString('/demo/menu/', $item->category->imageUrl());
            foreach ($item->recipeItems as $line) {
                $this->assertSame((int) $line->unit_id, (int) $line->ingredient->base_unit_id);
            }
        }
        $water = MenuItem::withoutGlobalScopes()->where('slug', 'water')->firstOrFail();
        $this->assertSame('DS-BOTTLED-WATER', $water->recipeItems->first()->ingredient->sku);
        $this->assertSame(1.0, (float) $water->recipeItems->first()->quantity);
        $this->assertFalse($water->recipeItems->first()->is_removable);
        $this->assertTrue($water->removableRecipeItems()->isEmpty());
        $this->assertSame(34, Ingredient::where('track_stock', true)->where('current_stock', '>', 0)->count());
        $this->assertSame(0, DB::table('suppliers')->whereNotNull('phone')->count());
        $this->assertSame(2, DB::table('branch_supplier')->count());
    }

    public function test_rerun_preserves_depleted_stock_password_prices_and_table_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $stock = IngredientStock::firstOrFail();
        $stock->update(['quantity' => 0]);
        Ingredient::findOrFail($stock->ingredient_id)->update(['current_stock' => 0]);
        $user = User::where('username', 'cashier1')->firstOrFail();
        $user->update(['password' => 'PrivatelyChangedPassword!']);
        $hash = $user->fresh()->password;
        $item = MenuItem::withoutGlobalScopes()->firstOrFail();
        $item->update(['price' => 29]);
        $table = Table::withoutGlobalScopes()->firstOrFail();
        $table->update(['status' => 'occupied']);
        $ids = DB::table('menu_items')->orderBy('id')->pluck('id')->all();
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('menu_items', 24);
        $this->assertDatabaseCount('ingredient_stock', 34);
        $this->assertSame($ids, DB::table('menu_items')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0.0, (float) $stock->fresh()->quantity);
        $this->assertSame(0.0, (float) Ingredient::findOrFail($stock->ingredient_id)->current_stock);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(Hash::check('PrivatelyChangedPassword!', $hash));
        $this->assertSame('29.00', $item->fresh()->price);
        $this->assertSame('occupied', $table->fresh()->getRawOriginal('status'));
    }

    public function test_accountant_starts_ready_without_postings_and_rerun_preserves_operator_choices(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11));
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        $year = FiscalYear::firstOrFail();
        $period = AccountingPeriod::whereDate('starts_on', '2026-10-01')->firstOrFail();
        $this->assertDatabaseCount('fiscal_years', 1);
        $this->assertDatabaseCount('accounting_periods', 12);
        $this->assertSame($branch->id, $year->branch_id);
        $this->assertSame($year->id, $period->fiscal_year_id);
        foreach (AccountingService::postingRoleDefinitions() as $role => $definition) {
            $mapping = AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)->where('key', $role)->firstOrFail();
            $account = Account::findOrFail($mapping->account_id);
            $this->assertTrue($account->is_active, $role);
            $this->assertContains($account->type, $definition['types'], $role);
        }
        $this->actingAs(User::where('username', 'accountant1')->firstOrFail())
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('admin.accounting.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Accounting/Index')
                ->where('health.currentPeriod.startsOn', '2026-10-01')
                ->where('health.currentPeriod.status', 'open')
                ->has('attention', 0));

        $cash = AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)->where('key', 'cash_account')->firstOrFail();
        $customAccount = Account::where('code', '1010')->firstOrFail();
        $cash->update(['account_id' => $customAccount->id]);
        Account::where('code', '2200')->update(['is_active' => false]);
        $period->update(['status' => 'closed']);
        $year->update(['name' => 'Owner configured name']);
        $this->seed(DemoAccountingSeeder::class);
        $this->assertSame($customAccount->id, $cash->fresh()->account_id);
        $this->assertFalse(Account::where('code', '2200')->firstOrFail()->is_active);
        $this->assertSame('closed', $period->fresh()->status);
        $this->assertSame('Owner configured name', $year->fresh()->name);
        $this->assertDatabaseCount('fiscal_years', 1);
        $this->assertDatabaseCount('accounting_periods', 12);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unmarked_existing_business_is_rejected_before_any_seed_writes(): void
    {
        $this->seed(ProductionSeeder::class);
        Branch::create(['code' => 'existing-owner', 'name' => 'Existing owner', 'is_active' => true]);
        $settings = DB::table('settings')->count();
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Existing data must prevent demo seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Existing [branches]', $exception->getMessage());
        }
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('settings', $settings);
        $this->assertDatabaseMissing('settings', ['key' => 'demo_dataset']);
    }

    public function test_explicit_opt_in_and_password_are_required_and_completed_setup_is_protected(): void
    {
        $environment = $this->app['env'];
        $this->app['env'] = 'production';
        config(['restaurant.demo' => false]);
        try {
            DemoDataset::assertCanSeed();
            $this->fail('A production environment needs explicit demo opt-in.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DEMO_MODE', $exception->getMessage());
        } finally {
            $this->app['env'] = $environment;
        }
        config(['restaurant.demo_password' => '']);
        try {
            DemoDataset::assertCanSeed();
            $this->fail('No usable password should ship in source.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DEMO_PASSWORD', $exception->getMessage());
        }
        config(['restaurant.demo_password' => 'Only-for-isolated-fixtures!']);
        Setting::put('setup_completed', true, 'general', 'bool');
        $this->expectExceptionMessage('Owner setup is complete');
        DemoDataset::assertCanSeed();
    }

    public function test_demo_staff_cleanup_requires_untouched_identity_and_preserves_used_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $actor = User::where('username', 'admin')->firstOrFail();
        $service = app(StaffSetupService::class);
        $this->assertCount(8, $service->demoCandidates($actor));
        User::where('username', 'cashier1')->update(['email' => 'owner-edited@example.test']);
        User::where('username', 'waiter1')->update(['last_login_at' => now()]);
        $this->assertCount(6, $service->demoCandidates($actor));
        $this->assertFalse($service->demoCandidates($actor)->contains('id', $actor->id));
    }

    public function test_handover_clears_trial_receipts_but_preserves_selected_catalogue(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['accounting_command_receipts', 'waiter_submission_receipts', 'customer_submission_receipts'] as $table) {
            DB::table($table)->insert(['token_hash' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64), 'created_at' => now()]);
        }
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        $actor = User::where('username', 'admin')->firstOrFail();
        $order = BranchContext::forBranch($branch->id, fn () => Order::create(['order_type' => 'takeaway', 'order_source' => 'phone', 'promotion_channel' => 'cashier', 'status' => 'pending']));
        $promotion = MenuPromotion::create(['name' => 'Trial redemption', 'target_type' => 'menu_item', 'target_id' => MenuItem::withoutGlobalScopes()->firstOrFail()->id, 'type' => 'percent', 'value' => 10, 'active' => true]);
        DB::table('order_promotion_redemptions')->insert(['order_id' => $order->id, 'promotion_id' => $promotion->id, 'redeemed_at' => now()]);
        app(DemoResetService::class)->reset($actor, true, $branch->id);
        $this->assertDatabaseCount('accounting_command_receipts', 0);
        $this->assertDatabaseCount('waiter_submission_receipts', 0);
        $this->assertDatabaseCount('customer_submission_receipts', 0);
        $this->assertDatabaseCount('order_promotion_redemptions', 0);
        $this->assertDatabaseCount('menu_items', 24);
        $this->assertDatabaseCount('ingredient_stock', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('branch_employee', 0);
    }
}
