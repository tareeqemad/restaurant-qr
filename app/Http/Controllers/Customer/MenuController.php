<?php

namespace App\Http\Controllers\Customer;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if ($session && $existingCookieToken && $existingCookieToken === $session->token) {
            return redirect()->route('customer.menu');
        }

        if ($session && ! $request->query('join')) {
            $activeOrders = $session->orders()->whereIn('status', ['pending', 'approved', 'preparing', 'ready', 'delivered'])->count();

            return response()->view('customer.busy', [
                'table' => $table,
                'session' => $session,
                'active_orders' => $activeOrders,
                'token' => $token,
            ]);
        }

        if (! $session) {
            // Recognise the diner before we even render the menu. Two signals,
            // in priority order:
            //   1. Portal auth on this browser → straightforward.
            //   2. `qr_customer_id` cookie set on a previous QR submit → the
            //      "soft remember-me" path: someone who scanned, gave their
            //      phone last time, and is now back from the same device.
            // Neither requires the diner to lift a finger; they get greeted
            // by name and the cashier sees a returning-customer label.
            $portalCustomerId = Auth::guard('customer')->id();
            $rememberedCustomerId = null;
            $rememberedCustomer = null;

            // Skip the soft-remember-me path when a STAFF user is signed in
            // on this browser (admin / waiter / cashier opening the table
            // URL to QA the customer flow). Without this guard the table
            // would inherit whoever last scanned from this device, and the
            // admin would be greeted as a regular — confusing during tests
            // and wrong in spirit (admin is not a customer).
            $staffSignedIn = Auth::guard('web')->check();

            if (! $portalCustomerId && ! $staffSignedIn
                && $cookieId = (int) $request->cookie('qr_customer_id')) {
                $rememberedCustomer = Customer::where('status', 'active')->find($cookieId);
                $rememberedCustomerId = $rememberedCustomer?->id;
            }

            $resolvedCustomerId = $portalCustomerId ?? $rememberedCustomerId;

            $session = TableSession::create([
                'table_id' => $table->id,
                'customer_id' => $resolvedCustomerId,   // null for true walk-ins
                // Stamp the diner's name on the session so the menu hero,
                // waiter board, and cashier all show the right greeting
                // without an extra round-trip.
                'customer_name' => $rememberedCustomer?->name,
                'customer_phone' => $rememberedCustomer?->phone,
                'cover_count' => 1,
                'status' => 'active',
                'opened_at' => now(),
            ]);
            $previousStatus = $table->status;
            $table->update(['status' => 'occupied']);
            SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $previousStatus));
        }

        $ttl = (int) Setting::get('session_ttl_minutes', config('restaurant.order.session_ttl_minutes', 240));

        return redirect()->route('customer.menu')->withCookie(cookie('table_session', $session->token, $ttl));
    }

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

        $cart = session('cart.'.$session->token, []);
        $cartTotal = collect($cart)->sum('subtotal');

        // Surface the linked portal customer (if any) for the menu greeting
        // strip — handles the case where the session was opened anonymously
        // and a customer logged in on a different tab afterwards.
        $portalCustomer = Auth::guard('customer')->user();
        if ($portalCustomer && ! $session->customer_id) {
            $session->update(['customer_id' => $portalCustomer->id]);
            $session->refresh();
        }

        return view('customer.menu', compact(
            'categories', 'session', 'cart', 'cartTotal', 'featured', 'portalCustomer'
        ));
    }

    /**
     * Dismiss the guest-targeted promo banner for the rest of this session.
     * Uses the session flag (not a cookie) so it auto-resets the next time
     * the diner scans a fresh QR — admins still get the conversion chance
     * on each new visit.
     *
     * The id is a plain int from the URL because the banner targets
     * anonymous sessions; we don't want to require the announcement to
     * pass branch context here. We sanity-check it exists + is the guests
     * channel before stamping the flag.
     */
    public function dismissGuestPromo(Request $request, int $id)
    {
        $exists = Announcement::withoutGlobalScopes()
            ->where('id', $id)
            ->where('audience_type', 'guests')
            ->exists();

        if ($exists) {
            session()->put('guest_promo_dismissed_'.$id, true);
        }

        return back();
    }
}
