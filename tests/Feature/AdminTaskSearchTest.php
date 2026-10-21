<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\AdminTaskSearch;
use Illuminate\Foundation\Testing\TestCase;
use Mockery;

// No fixtures or database access: exercise the real route map with explicit grants.
class AdminTaskSearchTest extends TestCase
{
    private function destinations(array $models = [], array $permissions = []): array
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->andReturnUsing(fn ($ability, $model) => $ability === 'viewAny' && in_array($model, $models, true));
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($permission) => in_array($permission, $permissions, true));
        $this->app['auth']->guard()->setUser($user);
        config(['restaurant.nav.batch_expiry' => true, 'restaurant.nav.units_management' => true, 'restaurant.nav.vendor_price_compare' => true]);

        return array_column(AdminTaskSearch::build(), 'href');
    }

    public function test_workspace_children_are_individually_permission_filtered(): void
    {
        $urls = $this->destinations([Ingredient::class]);
        $this->assertContains(route('admin.ingredients.index'), $urls);
        $this->assertContains(route('admin.batches.index'), $urls);
        $this->assertNotContains(route('admin.supplier-invoices.index'), $urls);
        $this->assertNotContains(route('admin.stock-counts.index'), $urls);
        $this->assertNotContains(route('admin.accounting.journal'), $urls);

        $urls = $this->destinations([SupplierInvoice::class, MenuItem::class]);
        $this->assertContains(route('admin.supplier-invoices.index'), $urls);
        $this->assertContains(route('admin.modifiers.index'), $urls);
        $this->assertNotContains(route('admin.ingredients.index'), $urls);
        $this->assertNotContains(route('admin.promotions.index'), $urls);
    }

    public function test_accounting_readers_cannot_discover_write_tasks_or_report_only_routes(): void
    {
        $urls = $this->destinations([], ['chart_of_accounts.viewAny']);
        $this->assertContains(route('admin.accounting.journal'), $urls);
        $this->assertNotContains(route('admin.accounting.mappings'), $urls);
        $this->assertNotContains(route('admin.accounting.manual-entry.create'), $urls);
        $this->assertNotContains(route('admin.reports.profit-loss'), $urls);

        $urls = $this->destinations([], ['chart_of_accounts.viewAny', 'chart_of_accounts.update', 'reports.viewAny']);
        $this->assertContains(route('admin.accounting.mappings'), $urls);
        $this->assertContains(route('admin.reports.profit-loss'), $urls);
        $this->assertNotContains(route('admin.accounting.manual-entry.create'), $urls);
    }

    public function test_no_permission_grants_means_no_search_only_destinations(): void
    {
        $this->assertSame([], $this->destinations());
    }
}
