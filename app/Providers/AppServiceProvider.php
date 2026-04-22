<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\RecipeItem;
use App\Models\Refund;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Table;
use App\Models\User;
use App\Observers\IngredientObserver;
use App\Observers\RecipeItemObserver;
use App\Policies\ActivityLogPolicy;
use App\Policies\InventoryPolicy;
use App\Policies\MenuPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\RefundPolicy;
use App\Policies\RolePolicy;
use App\Policies\StockCountPolicy;
use App\Policies\SupplierInvoicePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TablePolicy;
use App\Policies\UserPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Paginator::useBootstrapFive();

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(MenuItem::class, MenuPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Table::class, TablePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Ingredient::class, InventoryPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);
        Gate::policy(SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(StockCount::class, StockCountPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        // Model observers — keep menu-item costs in sync with recipes.
        Ingredient::observe(IngredientObserver::class);
        RecipeItem::observe(RecipeItemObserver::class);
    }
}
