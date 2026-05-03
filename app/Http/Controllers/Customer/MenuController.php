<?php

namespace App\Http\Controllers\Customer;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Category;
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
            $activeOrders = $session->orders()->whereIn('status', ['pending','approved','preparing','ready','delivered'])->count();
            return response()->view('customer.busy', [
                'table' => $table,
                'session' => $session,
                'active_orders' => $activeOrders,
                'token' => $token,
            ]);
        }

        if (! $session) {
            // If the diner is signed in to the customer portal on this device,
            // link the new session to their account up-front. They get a hello
            // banner on the menu screen instead of being asked to register
            // again — and every order placed gets attached for loyalty +
            // history without the cashier doing anything.
            $portalCustomerId = Auth::guard('customer')->id();

            $session = TableSession::create([
                'table_id'    => $table->id,
                'customer_id' => $portalCustomerId,   // null for anonymous walk-ins
                'cover_count' => 1,
                'status'      => 'active',
                'opened_at'   => now(),
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
            ->with(['menuItems' => fn($q) => $q->where('is_available', true)->orderBy('display_order')->with('allergens', 'modifierGroups.modifiers')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn($c) => $c->menuItems->count() > 0)
            ->values();

        $featured = MenuItem::where('is_available', true)
            ->where('is_featured', true)
            ->with('category', 'allergens', 'modifierGroups.modifiers')
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
}
