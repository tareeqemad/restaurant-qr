<?php

namespace App\Providers;

use App\Http\Middleware\SetActiveBranch;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\RecipeItem;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Station;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Table;
use App\Models\User;
use App\Observers\IngredientObserver;
use App\Observers\RecipeItemObserver;
use App\Observers\StationObserver;
use App\Policies\AccountPolicy;
use App\Policies\ActivityLogPolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\AttendancePolicy;
use App\Policies\BranchPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InventoryPolicy;
use App\Policies\LookupPolicy;
use App\Policies\MenuPolicy;
use App\Policies\MenuPromotionPolicy;
use App\Policies\OrderDiscountPolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\RefundPolicy;
use App\Policies\ReservationPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\RolePolicy;
use App\Policies\ShiftPolicy;
use App\Policies\StockCountPolicy;
use App\Policies\SupplierInvoicePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TablePolicy;
use App\Policies\UserPolicy;
use App\Support\MarketProfile;
use App\Support\RuntimeConfig;
use App\Sync\SyncUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RuntimeConfig::apply();

        app()->setLocale(MarketProfile::lang());
        date_default_timezone_set(MarketProfile::timezone());

        Schema::defaultStringLength(191);
        Paginator::useBootstrapFive();

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        if ($trustedProxies = config('app.trusted_proxies')) {
            TrustProxies::at($trustedProxies);
        }

        // Livewire's update endpoint sits outside the `branch` middleware,
        // so without this any computed property that calls Lookup::for(...)
        // sees a NULL branch context and returns no rows. Marking
        // SetActiveBranch as persistent makes Livewire run it on every
        // wire:click round-trip — keeping zone chips, branch-scoped
        // dropdowns, etc. stable after the initial page render.
        Livewire::addPersistentMiddleware([
            SetActiveBranch::class,
        ]);

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(MenuItem::class, MenuPolicy::class);
        Gate::policy(MenuPromotion::class, MenuPromotionPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Table::class, TablePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(OrderDiscount::class, OrderDiscountPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Ingredient::class, InventoryPolicy::class);
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);
        Gate::policy(SupplierInvoice::class, SupplierInvoicePolicy::class);
        Gate::policy(StockCount::class, StockCountPolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Reservation::class, ReservationPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(Lookup::class, LookupPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Shift::class, ShiftPolicy::class);

        // Model observers — keep menu-item costs in sync with recipes.
        Ingredient::observe(IngredientObserver::class);
        RecipeItem::observe(RecipeItemObserver::class);
        // Keep a permission row in sync with each station so role admins can
        // grant per-station screen access from the normal role-edit page.
        Station::observe(StationObserver::class);

        Event::listen('eloquent.creating: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if ($model instanceof Model) {
                SyncUuid::assignIfMissing($model);
            }
        });
    }
}
