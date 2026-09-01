<?php

namespace App\Http\Controllers\Customer;

use App\Helpers\Brand;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\SalesTaxService;
use App\Support\BranchContext;
use App\Support\CustomerMenuOrderPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MenuController extends Controller
{
    public function open(Request $request, string $token)
    {
        // Customers are guests — no auth, so BranchContext isn't auto-set by
        // SetActiveBranch middleware. Pin it from the table's own branch_id
        // so the BelongsToBranch trait can stamp child rows correctly
        // (TableSession, Order, OrderItem, …) and BranchScope filters reads.
        // Without this, INSERTs into branch-scoped tables fail with
        // "branch_id doesn't have a default value".
        $table = Table::withoutGlobalScopes()->where('qr_token', $token)->firstOrFail();
        BranchContext::set($table->branch_id);

        if ($table->status === 'out_of_service') {
            return response()->view('customer.out-of-service', ['table' => $table], 503);
        }

        $session = TableSession::where('table_id', $table->id)->where('status', 'active')->latest('opened_at')->first();
        $existingCookieToken = $request->cookie('table_session');

        $browseTtl = max(5, (int) Setting::get(
            'browsing_session_idle_minutes',
            config('restaurant.order.browsing_session_idle_minutes', 20),
        ));
        if ($session && $session->isBrowsing()) {
            $lastActivity = $session->last_activity_at ?? $session->opened_at;
            if ($lastActivity && $lastActivity->lte(now()->subMinutes($browseTtl))) {
                $session->update(['status' => 'abandoned', 'closed_at' => now()]);
                $session = null;
            }
        }

        if ($session && $existingCookieToken && $existingCookieToken === $session->token) {
            $session->touch();

            return $this->menu($request);
        }

        // The printed QR is the physical key to the table's current visit.
        // Every phone at that table must rejoin the same active session so
        // guests can add another round and keep one shared order history.
        // A scan by itself still creates only a browsing session below and
        // does not mark the physical table as occupied.
        $isBrowsingSession = $session && $session->isBrowsing();

        if ($isBrowsingSession && $existingCookieToken !== $session->token) {
            // The previous scanner may have walked away. Do not carry their
            // identity into the next person's browsing session.
            $session->update([
                'customer_id' => null,
                'customer_name' => null,
                'customer_phone' => null,
            ]);
            $session->touch();
        }

        if (! $session) {
            // Browsing is anonymous. The diner identifies by name and phone
            // only when the first order is submitted.
            $session = TableSession::create([
                'table_id' => $table->id,
                'cover_count' => 1,
                'status' => 'active',
                'opened_at' => now(),
            ]);
            // Intentionally leave the table status unchanged. Merely opening
            // the menu is browsing; OrderService promotes it to occupied on
            // the first submitted order, while an explicit waiter call does
            // the same from OrderStatusController.
        } else {
            // Refresh the visit while a diner is actively returning through
            // the table QR; the cookie below attaches this browser to it.
            $session->touch();
        }

        $ttl = (int) Setting::get('session_ttl_minutes', config('restaurant.order.session_ttl_minutes', 240));

        return redirect()
            ->route('customer.menu.open', $table->qr_token)
            ->withCookie(cookie('table_session', $session->token, $ttl));
    }

    /**
     * The customer menu — Inertia/Vue since Wave 2. The old /cart page is
     * absorbed into this screen as a bottom sheet; every pricing decision
     * (promo effectivePrice, live stock, tax/service display mode) is
     * resolved HERE so the client only ever renders what the server said.
     */
    public function menu(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing(['table.branch', 'customer']);

        $categories = Category::where('active', true)
            ->with(['menuItems' => fn ($q) => $q->where('is_available', true)->orderBy('display_order')
                ->with('allergens', 'modifierGroups.modifiers', 'recipeItems.ingredient')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn ($c) => $c->menuItems->count() > 0)
            ->values();

        $featured = MenuItem::where('is_available', true)
            ->where('is_featured', true)
            ->with('category', 'allergens', 'modifierGroups.modifiers', 'recipeItems.ingredient')
            ->limit(6)
            ->get();

        $cart = array_values(session('cart.'.$session->token, []));
        $submitToken = session('cart_submit_token.'.$session->token);
        if (! $submitToken) {
            $submitToken = (string) Str::uuid();
            session()->put('cart_submit_token.'.$session->token, $submitToken);
        }

        // A known diner is linked to the current table visit only. There is
        // no customer account, login, or cross-visit screen.
        $linkedCustomer = $session->customer;
        $sessionOrders = CustomerMenuOrderPresenter::forSession((int) $session->id);

        $taxRate = app(SalesTaxService::class)->rateForBranch(
            $session->table?->branch_id,
            now()->toDateString(),
        );
        $serviceEnabled = (bool) Setting::get('service_enabled', config('restaurant.service_charge.enabled'));
        $taxDisplay = $session->table?->branch?->customerTaxDisplayMode()
            ?? Setting::get('customer_tax_display', 'exclusive');

        return Inertia::render('Customer/Menu', [
            'brand' => [
                'name' => Brand::name(),
                'logo' => Brand::logoUrl(),
            ],
            'sessionInfo' => [
                'tableNumber' => $session->table->number ?? '—',
                'branchName' => $session->table?->branch?->name,
                'dinerName' => $linkedCustomer?->name ?: $session->customer_name,
                'known' => (bool) $linkedCustomer,
                'defaultName' => $session->customer_name ?? '',
                'defaultPhone' => $session->customer_phone ?? '',
                'canOrder' => $session->canOrderFromDevice(
                    $request->attributes->get('table_order_device_hash'),
                ),
                'helpPending' => filled($session->help_requested_at),
            ],
            'money' => [
                'symbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol')),
                'taxEnabled' => $taxRate > 0,
                'taxRate' => (float) $taxRate,
                'serviceEnabled' => $serviceEnabled,
                'serviceRate' => $serviceEnabled ? (float) Setting::get('service_rate', config('restaurant.service_charge.rate')) : 0.0,
                'taxDisplay' => in_array($taxDisplay, ['exclusive', 'inclusive'], true) ? $taxDisplay : 'exclusive',
            ],
            'activeOrdersCount' => collect($sessionOrders)
                ->whereIn('status', ['pending', 'approved', 'preparing', 'ready', 'delivered'])
                ->count(),
            'sessionOrders' => $sessionOrders,
            'featured' => $featured->map(fn ($i) => $this->itemPayload($i))->values()->all(),
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->localizedName(),
                'icon' => $c->icon ?: 'bi-tag',
                'color' => $c->color,
                'items' => $c->menuItems->map(fn ($i) => $this->itemPayload($i))->values()->all(),
            ])->values()->all(),
            'cart' => $cart,
            'submitToken' => $submitToken,
            'i18n' => array_merge(__('ui.customer_menu'), ['dish' => __('ui.dish')]),
            'urls' => [
                'menu' => route('customer.menu.open', $session->table->qr_token),
                'cartAdd' => route('customer.cart.add'),
                'cartUpdate' => route('customer.cart.update'),
                'cartRemove' => route('customer.cart.remove'),
                'cartSubmit' => route('customer.cart.submit'),
                'track' => route('customer.track'),
                'trackData' => route('customer.track.data'),
                'trackPulse' => route('customer.track.pulse'),
                'bill' => route('customer.bill'),
                'callWaiter' => route('customer.help.request'),
            ],
        ]);
    }

    /**
     * One dish, decorated exactly the way the retired dish.blade.php did:
     * promo effectivePrice (the cart charges what the badge promised), a
     * LIVE stock check on top of the manual flag (with distinct reasons),
     * ingredients, allergens, and the modifier-group contract.
     */
    protected function itemPayload(MenuItem $item): array
    {
        $ingredients = $item->relationLoaded('recipeItems')
            ? $item->recipeItems->map(fn ($r) => $r->ingredient?->localizedName())->filter()->values()
            : collect();
        $removableIngredients = $item->relationLoaded('recipeItems')
            ? $item->recipeItems
                ->filter(fn ($recipe) => $recipe->ingredient !== null)
                ->unique(fn ($recipe) => (int) $recipe->ingredient_id)
                ->map(fn ($recipe) => [
                    'id' => (int) $recipe->ingredient_id,
                    'name' => $recipe->ingredient->localizedName(),
                    'requires_confirmation' => ! (bool) $recipe->is_optional,
                ])->values()
            : collect();

        $manuallyAvailable = (bool) $item->is_available;
        $shortages = $manuallyAvailable ? $item->stockShortages(1.0) : [];
        $inStock = empty($shortages);
        $canOrder = $manuallyAvailable && $inStock;

        $promo = $item->activePromotion();
        $basePrice = (float) $item->price;
        $effectivePrice = $promo ? $promo->applyTo($basePrice) : $basePrice;
        $hasPriceDiscount = $promo !== null && $effectivePrice < $basePrice;

        return [
            'id' => $item->id,
            'name' => $item->localizedName(),
            'description' => $item->localizedDescription(),
            'price' => $effectivePrice,
            'original_price' => $hasPriceDiscount ? $basePrice : null,
            'discount_pct' => $promo ? $item->discountPct() : null,
            'image' => $item->imageUrl(),
            'prep_minutes' => $item->prep_time_minutes,
            'featured' => (bool) $item->is_featured,
            'can_order' => $canOrder,
            'unavailable_reason' => ! $manuallyAvailable
                ? __('ui.dish.not_available')
                : (! $inStock ? __('ui.dish.out_of_stock') : null),
            'ingredients' => $ingredients->all(),
            'removable_ingredients' => $removableIngredients->all(),
            'allergens' => $item->allergens->map(fn ($a) => $a->localizedName())->values()->all(),
            'has_modifiers' => $item->modifierGroups->count() > 0,
            'modifier_groups' => $item->modifierGroups->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->localizedName(),
                'min_select' => $g->min_select,
                'max_select' => $g->max_select,
                'required' => (bool) $g->required,
                'modifiers' => $g->modifiers->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->localizedName(),
                    'price_delta' => (float) $m->price_delta,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
