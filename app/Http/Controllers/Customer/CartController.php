<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\PendingTransfer;
use App\Services\CustomerIdentityService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\CustomerMenuOrderPresenter;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
        protected CustomerIdentityService $identity,
    ) {}

    /**
     * The standalone cart page died in Wave 2 — the Vue menu carries the
     * cart as a bottom sheet. The route stays for old links/bookmarks
     * (redirects home) and doubles as a JSON hydration endpoint.
     */
    public function view(Request $request)
    {
        $session = $request->attributes->get('table_session');

        if ($request->expectsJson() || $request->ajax()) {
            $submitToken = session('cart_submit_token.'.$session->token);
            if (! $submitToken) {
                $submitToken = (string) Str::uuid();
                session()->put('cart_submit_token.'.$session->token, $submitToken);
            }

            return response()->json([
                'ok' => true,
                'cart' => array_values(session('cart.'.$session->token, [])),
                'submitToken' => $submitToken,
            ]);
        }

        return redirect()->route('customer.menu.open', $session->table->qr_token);
    }

    public function add(Request $request)
    {
        $session = $request->attributes->get('table_session');
        if ($blocked = $this->pendingTransferResponse($request, $session)) {
            return $blocked;
        }
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
            'excluded_ingredient_ids' => ['array'],
            'excluded_ingredient_ids.*' => ['integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = MenuItem::with('recipeItems.ingredient')->findOrFail($data['menu_item_id']);
        if (! $item->is_available) {
            return $this->rejectAdd($request, __('ui.customer_order.item_currently_unavailable'));
        }

        $allowedExclusions = $item->recipeItems
            ->filter(fn ($recipe) => $recipe->ingredient !== null)
            ->keyBy(fn ($recipe) => (int) $recipe->ingredient_id);
        $excludedIngredientIds = collect($data['excluded_ingredient_ids'] ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique();
        if ($excludedIngredientIds->diff($allowedExclusions->keys()->map(fn ($id) => (int) $id))->isNotEmpty()) {
            return $this->rejectAdd($request, 'أحد المكوّنات المستبعدة لا ينتمي إلى هذا الصنف. أعد فتحه واختر من القائمة.');
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
            'excluded_ingredient_ids' => $excludedIngredientIds->all(),
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
            'excluded_ingredient_ids' => $excludedIngredientIds->values()->all(),
            'excluded_ingredients' => $excludedIngredientIds->map(function ($id) use ($allowedExclusions) {
                $recipe = $allowedExclusions->get((int) $id);

                return [
                    'id' => (int) $id,
                    'name' => $recipe->ingredient->localizedName(),
                    'requires_confirmation' => ! (bool) $recipe->is_optional,
                ];
            })->values()->all(),
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
        if ($blocked = $this->pendingTransferResponse($request, $session)) {
            return $blocked;
        }
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
                    'excluded_ingredient_ids' => $row['excluded_ingredient_ids'] ?? [],
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
        if ($blocked = $this->pendingTransferResponse($request, $session)) {
            return $blocked;
        }
        $data = $request->validate(['row_id' => ['required', 'string']]);
        $cart = collect(session('cart.'.$session->token, []))->reject(fn ($r) => $r['id'] === $data['row_id'])->values()->toArray();
        session()->put('cart.'.$session->token, $cart);
        $session->touch();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function submit(Request $request)
    {
        $session = $request->attributes->get('table_session');
        if ($this->hasIssuedInvoice($session)) {
            return $this->submitRejected(
                request: $request,
                message: __('ui.customer_order.invoice_already_issued_extra'),
                code: 'invoice_already_issued',
                redirectRoute: 'customer.bill',
            );
        }
        if ($this->hasPendingTransfer($session)) {
            return $this->submitRejected(
                request: $request,
                message: 'يوجد تحويل بنكي بانتظار التحقق. لا يمكن إضافة طلب جديد حتى يراجعه الكاشير.',
                code: 'pending_transfer',
                redirectRoute: 'customer.bill',
            );
        }

        $data = $request->validate([
            'customer_phone' => [
                'nullable',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (filled($value) && ! PhoneNumber::isValid((string) $value)) {
                        $fail('أدخل رقم جوال صحيحاً من 7 إلى 15 رقماً.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            '_idem' => ['nullable', 'string', 'max:100'],
        ]);

        $submitToken = $data['_idem']
            ?? session('cart_submit_token.'.$session->token)
            ?? (string) Str::uuid();
        $idempotencyKey = 'idem:qr-order:'.hash('sha256', $session->token.'|'.$submitToken);

        // A mobile retry can arrive after the first request already emptied
        // the cart. Recognise it before the empty-cart guard and return the
        // authoritative session rounds instead of showing a false error.
        if (Cache::has($idempotencyKey)) {
            return $this->submitAccepted(
                request: $request,
                session: $session,
                order: $session->orders()->latest()->first(),
                message: 'تم استلام هذه الجولة مسبقاً، وهي ظاهرة ضمن طلبات جلستك.',
                replayed: true,
            );
        }

        $cart = session('cart.'.$session->token, []);
        if (empty($cart)) {
            return $this->submitRejected($request, __('ui.customer_order.empty_cart'), 'empty_cart');
        }

        $issues = $this->inventory->checkStockForOrderPreview($cart);
        if (! empty($issues)) {
            $msg = collect($issues)
                ->map(fn ($i) => $i['ingredient'].': '.__('ui.customer_order.available_qty', ['qty' => $i['available']]).', '.__('ui.customer_order.required_qty', ['qty' => $i['required']]))
                ->join(' | ');

            return $this->submitRejected(
                $request,
                __('ui.customer_order.stock_shortage', ['items' => $msg]),
                'stock_short',
            );
        }

        $shouldLinkCustomer = empty($session->customer_id) && filled($data['customer_phone'] ?? null);
        if ($shouldLinkCustomer) {
            $identity = $this->identity->resolveOrCreate(
                phone: $data['customer_phone'],
                name: null,
                defaultBranchId: $session->branch_id,
                source: 'qr_menu',
            );

            $session = $this->identity->linkSession(
                session: $session,
                customer: $identity['customer'],
            );
        }

        if (! Cache::add($idempotencyKey, true, now()->addMinutes(15))) {
            return $this->submitAccepted(
                request: $request,
                session: $session,
                order: $session->orders()->latest()->first(),
                message: 'تم استلام هذه الجولة مسبقاً، وهي ظاهرة ضمن طلبات جلستك.',
                replayed: true,
            );
        }

        try {
            $order = $this->orders->createFromCart(
                session: $session,
                cart: $cart,
                customerNotes: $data['notes'] ?? null,
                orderingDeviceHash: $request->attributes->get('table_order_device_hash'),
            );
        } catch (\RuntimeException $e) {
            Cache::forget($idempotencyKey);

            $orderingDeviceLocked = $e->getCode() === 409;

            return $this->submitRejected(
                request: $request,
                message: $e->getMessage(),
                code: $orderingDeviceLocked ? 'ordering_device_locked' : 'order_rejected',
                status: $orderingDeviceLocked ? 409 : 422,
            );
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }

        session()->forget('cart.'.$session->token);

        return $this->submitAccepted(
            request: $request,
            session: $session,
            order: $order,
            message: __('ui.customer_order.order_sent_success', ['number' => $order->number])
                .' يمكنك إضافة جولة جديدة؛ وستُجمع كل الجولات في فاتورة جلستك نفسها.',
        );
    }

    /**
     * JSON keeps a phone diner on the menu; the classic redirect remains for
     * old clients and direct form submissions. Every accepted round rotates
     * the token so a later addition is independent from the one just sent.
     */
    protected function submitAccepted(
        Request $request,
        $session,
        ?Order $order,
        string $message,
        bool $replayed = false,
    ) {
        $nextSubmitToken = (string) Str::uuid();
        session()->put('cart_submit_token.'.$session->token, $nextSubmitToken);

        if ($request->expectsJson() || $request->ajax()) {
            $session = $session->fresh(['customer', 'table.branch']);

            return response()->json([
                'ok' => true,
                'replayed' => $replayed,
                'message' => $message,
                'orderNumber' => $order?->number,
                'orders' => CustomerMenuOrderPresenter::forSession((int) $session->id),
                'submitToken' => $nextSubmitToken,
                'sessionInfo' => [
                    'dinerName' => $session->customer?->name ?: $session->customer_name,
                    'known' => (bool) $session->customer_id,
                    'defaultPhone' => $session->customer_phone ?? '',
                    'canOrder' => $session->canOrderFromDevice(
                        $request->attributes->get('table_order_device_hash'),
                    ),
                ],
            ]);
        }

        return redirect()->route('customer.track')->with(
            $replayed ? 'info' : 'success',
            $message,
        );
    }

    protected function submitRejected(
        Request $request,
        string $message,
        string $code,
        ?string $redirectRoute = null,
        int $status = 422,
    ) {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => $code,
                'message' => $message,
                'redirectUrl' => $redirectRoute ? route($redirectRoute) : null,
            ], $status);
        }

        if ($redirectRoute) {
            return redirect()->route($redirectRoute)->with('error', $message);
        }

        return back()->with('error', $message);
    }

    protected function hasIssuedInvoice($session): bool
    {
        $session->loadMissing('invoice');

        return $session->invoice
            && ! in_array($session->invoice->status, ['cancelled'], true);
    }

    protected function hasPendingTransfer($session): bool
    {
        return PendingTransfer::where('table_session_id', $session->id)
            ->pending()
            ->exists();
    }

    protected function pendingTransferResponse(Request $request, $session)
    {
        if (! $this->hasPendingTransfer($session)) {
            return null;
        }

        $message = 'يوجد تحويل بنكي بانتظار التحقق. لا يمكن تغيير الحساب حتى يراجعه الكاشير.';
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => 'pending_transfer',
                'message' => $message,
            ], 422);
        }

        return redirect()->route('customer.bill')->with('error', $message);
    }
}
