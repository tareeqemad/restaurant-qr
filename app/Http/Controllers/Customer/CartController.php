<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
    ) {}

    public function view(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing('table.branch');
        $cart = session('cart.'.$session->token, []);

        return view('customer.cart', compact('cart', 'session'));
    }

    public function add(Request $request)
    {
        $session = $request->attributes->get('table_session');
        if ($this->hasIssuedInvoice($session)) {
            $message = __('ui.customer_order.invoice_already_issued_add');

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'invoice_already_issued',
                    'message' => $message,
                ], 422);
            }

            return redirect()->route('customer.bill')->with('error', $message);
        }

        $data = $request->validate([
            'menu_item_id' => ['required', 'exists:menu_items,id'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['exists:modifiers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = MenuItem::with('recipeItems.ingredient')->findOrFail($data['menu_item_id']);
        if (! $item->is_available) {
            return $this->rejectAdd($request, __('ui.customer_order.item_currently_unavailable'));
        }

        $cart = session('cart.'.$session->token, []);

        // Stock gate — block adding when the cumulative cart demand
        // (existing rows for this item PLUS the new one) would consume
        // more than what's currently on the shelf. Recursively expands
        // composite ingredients, so a "sauce" line correctly checks its
        // raw inputs. Customers should never get past this point with an
        // un-fulfillable cart — the previous "wait till submit to find
        // out" UX was the user-reported bug.
        $virtualCart = $cart;
        $virtualCart[] = [
            'menu_item_id' => (int) $item->id,
            'quantity' => (float) $data['quantity'],
            'modifier_ids' => $data['modifier_ids'] ?? [],
        ];
        $issues = $this->inventory->checkStockForOrderPreview($virtualCart);
        if (! empty($issues)) {
            $short = collect($issues)
                ->map(fn ($i) => $i['ingredient'].' ('.__('ui.customer_order.available_qty', ['qty' => rtrim(rtrim(number_format($i['available'], 2), '0'), '.')])
                    .', '.__('ui.customer_order.required_qty', ['qty' => rtrim(rtrim(number_format($i['required'], 2), '0'), '.')]).')')
                ->take(3)
                ->join(', ');

            return $this->rejectAdd($request,
                __('ui.customer_order.stock_out_detail', ['items' => $short]));
        }

        $modifiers = Modifier::whereIn('id', $data['modifier_ids'] ?? [])->get();

        $row = [
            'id' => uniqid(),
            'menu_item_id' => (int) $item->id,
            'name' => $item->localizedName(),
            'image' => $item->imageUrl(),
            'quantity' => (int) $data['quantity'],                // force int — avoids "1"+1="11" on client
            'unit_price' => (float) $item->price,
            'modifier_ids' => $data['modifier_ids'] ?? [],
            'modifiers' => $modifiers->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->localizedName(), 'price_delta' => (float) $m->price_delta])->values()->toArray(),
            'modifiers_total' => (float) $modifiers->sum('price_delta'),
            'notes' => $data['notes'] ?? null,
        ];
        $row['subtotal'] = ($row['unit_price'] + $row['modifiers_total']) * $row['quantity'];

        $cart[] = $row;
        session()->put('cart.'.$session->token, $cart);
        $session->touch();

        // AJAX requests get back the row (so the client can replace its tmp_id
        // with the server-generated id and future update/remove calls work).
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'row' => $row]);
        }

        return redirect()->route('customer.cart.view')->with('success', __('ui.customer_order.cart_item_added'));
    }

    public function update(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate([
            'row_id' => ['required', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $cart = session('cart.'.$session->token, []);

        // If the request is increasing a quantity, validate stock for
        // the WHOLE cart with the new value applied — otherwise a
        // hungry diner clicking + repeatedly could starve the kitchen
        // past the safety net.
        if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
            $virtualCart = collect($cart)->map(function ($row) use ($data) {
                if ($row['id'] === $data['row_id']) {
                    $row['quantity'] = (int) $data['quantity'];
                }

                return [
                    'menu_item_id' => (int) $row['menu_item_id'],
                    'quantity' => (float) $row['quantity'],
                    'modifier_ids' => $row['modifier_ids'] ?? [],
                ];
            })->all();

            $issues = $this->inventory->checkStockForOrderPreview($virtualCart);
            if (! empty($issues)) {
                $short = collect($issues)
                    ->map(fn ($i) => $i['ingredient'].' ('.__('ui.customer_order.available_qty', ['qty' => rtrim(rtrim(number_format($i['available'], 2), '0'), '.')]).')')
                    ->take(2)->join(', ');

                return response()->json([
                    'ok' => false,
                    'error' => 'stock_short',
                    'message' => __('ui.customer_order.cannot_increase_qty', ['items' => $short]),
                ], 422);
            }
        }

        foreach ($cart as $i => $row) {
            if ($row['id'] === $data['row_id']) {
                if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                    $qty = (int) $data['quantity'];                 // force int
                    $cart[$i]['quantity'] = $qty;
                    $cart[$i]['subtotal'] = ($row['unit_price'] + $row['modifiers_total']) * $qty;
                }
                if (array_key_exists('notes', $data)) {
                    $cart[$i]['notes'] = $data['notes'];
                }
                break;
            }
        }
        session()->put('cart.'.$session->token, $cart);
        $session->touch();

        return response()->json(['ok' => true]);
    }

    /**
     * Centralise the AJAX-or-redirect response for a rejected add so the
     * two call-sites (manual-unavailable and stock-short) stay aligned.
     */
    protected function rejectAdd(Request $request, string $message)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => false, 'error' => $message, 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    public function remove(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate(['row_id' => ['required', 'string']]);
        $cart = collect(session('cart.'.$session->token, []))->reject(fn ($r) => $r['id'] === $data['row_id'])->values()->toArray();
        session()->put('cart.'.$session->token, $cart);
        $session->touch();

        return back();
    }

    public function submit(Request $request)
    {
        $session = $request->attributes->get('table_session');
        if ($this->hasIssuedInvoice($session)) {
            return redirect()->route('customer.bill')
                ->with('error', __('ui.customer_order.invoice_already_issued_extra'));
        }

        $cart = session('cart.'.$session->token, []);
        if (empty($cart)) {
            return back()->with('error', __('ui.customer_order.empty_cart'));
        }

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'cover_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Auto-link a returning customer by phone — Customer::findForLogin
        // matches against both `phone` and `email`, normalising digits, so a
        // diner who typed 0599-123-456 still resolves to a record stored as
        // 0599123456. We only set customer_id if the session is currently
        // anonymous; an authenticated portal session already has a link from
        // MenuController::open() and we don't want to overwrite it.
        $linkedCustomer = null;
        if (! empty($data['customer_phone']) && empty($session->customer_id)) {
            $linkedCustomer = Customer::findForLogin($data['customer_phone']);
        }

        $update = array_filter([
            'customer_name' => $data['customer_name'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'cover_count' => $data['cover_count'] ?? null,
            'customer_id' => $linkedCustomer?->id,
        ], fn ($v) => $v !== null && $v !== '');
        if (! empty($update)) {
            $session->update($update);
        }

        // Resolve who this session ultimately belongs to: portal-authenticated
        // > phone-matched at submit > already-linked at session open.
        $customerForCookie = $linkedCustomer
            ?? $session->fresh('customer')->customer;

        $issues = $this->inventory->checkStockForOrderPreview($cart);
        if (! empty($issues)) {
            $msg = collect($issues)
                ->map(fn ($i) => $i['ingredient'].': '.__('ui.customer_order.available_qty', ['qty' => $i['available']]).', '.__('ui.customer_order.required_qty', ['qty' => $i['required']]))
                ->join(' | ');

            return back()->with('error', __('ui.customer_order.stock_shortage', ['items' => $msg]));
        }

        $order = $this->orders->createFromCart($session, $cart, null, $data['notes'] ?? null);

        session()->forget('cart.'.$session->token);

        $response = redirect()->route('customer.track')
            ->with('success', __('ui.customer_order.order_sent_success', ['number' => $order->number]));

        // Remember WHO this device belongs to so next QR scan recognises
        // them silently. Cookie is encrypted by Laravel (EncryptCookies
        // middleware) and good for a year. MenuController::open reads it on
        // a fresh session and pre-links, so the greeting appears the moment
        // they open the menu with zero clicks. Lost device cleanup
        // is the standard portal password reset flow.
        if ($customerForCookie) {
            $response->cookie(cookie(
                name: 'qr_customer_id',
                value: (string) $customerForCookie->id,
                minutes: 60 * 24 * 365,    // 1 year
                path: '/',
                secure: $request->secure(),
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        return $response;
    }

    protected function hasIssuedInvoice($session): bool
    {
        $session->loadMissing('invoice');

        return $session->invoice
            && ! in_array($session->invoice->status, ['cancelled'], true);
    }
}
