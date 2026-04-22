<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function open(Request $request, string $token)
    {
        $table = Table::where('qr_token', $token)->firstOrFail();

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
            $session = TableSession::create([
                'table_id' => $table->id,
                'cover_count' => 1,
                'status' => 'active',
                'opened_at' => now(),
            ]);
            $table->update(['status' => 'occupied']);
        }

        return redirect()->route('customer.menu')->withCookie(cookie('table_session', $session->token, 240));
    }

    public function menu(Request $request)
    {
        $session = $request->attributes->get('table_session');
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

        return view('customer.menu', compact('categories', 'session', 'cart', 'cartTotal', 'featured'));
    }
}
