<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\PendingTransfer;
use App\Services\CustomerIdentityService;
use App\Services\CustomerSubmissionService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PromotionService;
use App\Support\CustomerMenuOrderPresenter;
use App\Support\PhoneNumber;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
        protected CustomerIdentityService $identity,
        protected CustomerSubmissionService $submissions,
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

        $allowedExclusions = $item->removableRecipeItems()
            ->keyBy(fn ($recipe) => (int) $recipe->ingredient_id);
        $excludedIngredientIds = collect($data['excluded_ingredient_ids'] ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique();
        if ($excludedIngredientIds->diff($allowedExclusions->keys()->map(fn ($id) => (int) $id))->isNotEmpty()) {
            return $this->rejectAdd($request, 'لا يمكن استبعاد أحد المكوّنات المحددة من هذا الصنف. أعد فتحه واختر من القائمة.');
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
        $session->loadMissing('customer');
        $promotion = app(PromotionService::class)->resolveForItem(
            $item, branchId: (int) $session->branch_id, channel: 'qr', customer: $session->customer,
        );
        // Draft rows survive quantity/removal edits. Keep cart-dependent
        // discounts out of their fixed unit price; the saved order applies
        // any earned threshold reduction using its authoritative totals.
        if ($promotion && ! $promotion->meetsMinSubtotal(null)) {
            $promotion = null;
        }
        $freeModifierIds = array_map('intval', $promotion?->free_modifier_ids ?? []);
        $modifierRows = $modifiers->map(fn ($modifier) => [
            'id' => (int) $modifier->id,
            'name' => $modifier->localizedName(),
            'price_delta' => in_array((int) $modifier->id, $freeModifierIds, true) ? 0.0 : (float) $modifier->price_delta,
        ])->values();

        $row = [
            'id' => uniqid(),
            'menu_item_id' => (int) $item->id,
            'name' => $item->localizedName(),
            'image' => $item->imageUrl(),
            'quantity' => (int) $data['quantity'],                // force int — avoids "1"+1="11" on client
            'unit_price' => $promotion ? $promotion->applyTo((float) $item->price) : (float) $item->price,
            'modifier_ids' => $data['modifier_ids'] ?? [],
            'modifiers' => $modifierRows->all(),
            'excluded_ingredient_ids' => $excludedIngredientIds->values()->all(),
            'excluded_ingredients' => $excludedIngredientIds->map(function ($id) use ($allowedExclusions) {
                $recipe = $allowedExclusions->get((int) $id);

                return [
                    'id' => (int) $id,
                    'name' => $recipe->ingredient->localizedName(),
                    'requires_confirmation' => ! (bool) $recipe->is_optional,
                ];
            })->values()->all(),
            'modifiers_total' => (float) $modifierRows->sum('price_delta'),
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
            'submission_scope' => ['sometimes', 'string', 'size:64'],
            'cart_snapshot' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'cart_snapshot.*' => ['array'],
            'cart_snapshot.*.id' => ['required', 'string', 'max:100'],
            'cart_snapshot.*.menu_item_id' => ['required', 'integer', 'min:1'],
            'cart_snapshot.*.quantity' => ['required', 'numeric', 'min:1'],
            'cart_snapshot.*.notes' => ['nullable', 'string', 'max:1000'],
            'cart_snapshot.*.modifier_ids' => ['sometimes', 'array'],
            'cart_snapshot.*.modifier_ids.*' => ['integer'],
            'cart_snapshot.*.excluded_ingredient_ids' => ['sometimes', 'array'],
            'cart_snapshot.*.excluded_ingredient_ids.*' => ['integer'],
        ]);

        $scope = CustomerSubmissionService::scope($session, $request->attributes->get('table_order_device_hash'));
        if (isset($data['submission_scope']) && ! hash_equals($scope, $data['submission_scope'])) {
            return response()->json(['ok' => false, 'submission_state' => 'unknown',
                'error' => 'submission_scope_changed', 'message' => 'هذا الإرسال يخص جلسة طاولة أخرى. افتح المنيو الأصلي للتحقق منه.'], 409);
        }
        $submitToken = $data['_idem']
            ?? session('cart_submit_token.'.$session->token)
            ?? (string) Str::uuid();
        $payload = [
            'customer_phone' => $data['customer_phone'] ?? null,
            'notes' => $data['notes'] ?? null,
            'cart_snapshot' => isset($data['cart_snapshot'])
                ? CustomerSubmissionService::cartSnapshot($data['cart_snapshot']) : null,
        ];
        try {
            $receipt = $this->submissions->run(
                $scope,
                $submitToken,
                $payload,
                function () use ($request, $session, $data, $payload) {
                    $session = $session->newQuery()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                    if ($session->status !== 'active') {
                        throw new HttpResponseException($this->submitRejected($request,
                            'انتهت جلسة الطاولة. لم يُنشأ طلب جديد؛ يمكنك التحقق من إرسال محفوظ فقط.', 'session_closed', status: 409));
                    }
                    if ($this->hasIssuedInvoice($session)) {
                        throw new HttpResponseException($this->submitRejected($request,
                            __('ui.customer_order.invoice_already_issued_extra'), 'invoice_already_issued', 'customer.bill'));
                    }
                    if ($this->hasPendingTransfer($session)) {
                        throw new HttpResponseException($this->submitRejected($request,
                            'يوجد تحويل بنكي بانتظار التحقق. راجع الكاشير قبل الإضافة.', 'pending_transfer', 'customer.bill'));
                    }
                    $cart = session('cart.'.$session->token, []);
                    if (empty($cart)) {
                        throw new HttpResponseException($this->submitRejected($request, __('ui.customer_order.empty_cart'), 'empty_cart'));
                    }
                    $cartHash = CustomerSubmissionService::fingerprint(CustomerSubmissionService::cartSnapshot($cart));
                    if ($payload['cart_snapshot'] !== null
                        && ! hash_equals($cartHash, CustomerSubmissionService::fingerprint($payload['cart_snapshot']))) {
                        throw new HttpResponseException($this->submitRejected($request,
                            'تغيّرت السلة. تم تحديثها؛ راجع الأصناف قبل الإرسال.', 'cart_changed', cart: array_values($cart)));
                    }
                    $issues = $this->inventory->checkStockForOrderPreview($cart);
                    if (! empty($issues)) {
                        $msg = collect($issues)->map(fn ($i) => $i['ingredient'].': '
                            .__('ui.customer_order.available_qty', ['qty' => $i['available']]).', '
                            .__('ui.customer_order.required_qty', ['qty' => $i['required']]))->join(' | ');
                        throw new HttpResponseException($this->submitRejected($request,
                            __('ui.customer_order.stock_shortage', ['items' => $msg]), 'stock_short'));
                    }
                    if (empty($session->customer_id) && filled($data['customer_phone'] ?? null)) {
                        $identity = $this->identity->resolveOrCreate(phone: $data['customer_phone'], name: null,
                            defaultBranchId: $session->branch_id, source: 'qr_menu');
                        $session = $this->identity->linkSession(session: $session, customer: $identity['customer']);
                    }
                    $order = $this->orders->createFromCart(session: $session, cart: $cart,
                        customerNotes: $data['notes'] ?? null,
                        orderingDeviceHash: $request->attributes->get('table_order_device_hash'));
                    DB::afterCommit(function () use ($order) {
                        try {
                            $this->orders->broadcastOrderRefresh($order);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    });

                    return ['order_id' => (int) $order->id, 'cart_hash' => $cartHash, 'next_token' => (string) Str::uuid()];
                },
            );
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        } catch (\DomainException $exception) {
            return response()->json(['ok' => false, 'submission_state' => 'unknown',
                'error' => 'submission_conflict', 'message' => $exception->getMessage()], 409);
        } catch (\PDOException $exception) {
            // A lost commit acknowledgement must stay unknown and use the receipt on retry.
            throw $exception;
        } catch (\RuntimeException $exception) {
            return $this->submitRejected($request, $exception->getMessage(),
                $exception->getCode() === 409 ? 'ordering_device_locked' : 'order_rejected',
                status: $exception->getCode() === 409 ? 409 : 422);
        }

        // A replay must never clear a newer draft prepared in another tab.
        $currentCart = session('cart.'.$session->token, []);
        $ownsCurrentDraft = ! $receipt['replayed'] || session('cart_submit_token.'.$session->token) === $submitToken;
        if ($ownsCurrentDraft && hash_equals($receipt['cart_hash'], CustomerSubmissionService::fingerprint(CustomerSubmissionService::cartSnapshot($currentCart)))) {
            session()->forget('cart.'.$session->token);
        }
        $order = $session->orders()->findOrFail($receipt['order_id']);

        return $this->submitAccepted($request, $session, $order,
            $receipt['replayed'] ? 'تم تأكيد الجولة المحفوظة دون تكرارها.' : __('ui.customer_order.order_sent_success', ['number' => $order->number]),
            $receipt['replayed'], $submitToken, $receipt['replayed'] ? (string) Str::uuid() : $receipt['next_token']);
    }

    /**
     * JSON keeps a phone diner on the menu; the classic redirect remains for
     * old clients and direct form submissions. Every accepted round rotates
     * the token so a later addition is independent from the one just sent.
     */
    protected function submitAccepted(
        Request $request,
        $session,
        Order $order,
        string $message,
        bool $replayed,
        string $submittedToken,
        string $nextSubmitToken,
    ) {
        session()->put('cart_submit_token.'.$session->token, $nextSubmitToken);

        if ($request->expectsJson() || $request->ajax()) {
            $session = $session->fresh(['customer', 'table.branch']);

            return response()->json([
                'ok' => true,
                'replayed' => $replayed,
                'submission_state' => 'committed',
                'receipt' => ['token' => $submittedToken, 'orderId' => (int) $order->id],
                'cart' => array_values(session('cart.'.$session->token, [])),
                'message' => $message,
                'orderNumber' => $order?->number,
                'orders' => CustomerMenuOrderPresenter::forSession((int) $session->id),
                'submitToken' => $nextSubmitToken,
                'sessionInfo' => [
                    'dinerName' => $session->customer?->name ?: $session->customer_name,
                    'known' => (bool) $session->customer_id,
                    'defaultPhone' => $session->customer_phone ?? '',
                    'active' => $session->status === 'active',
                    'canOrder' => $session->status === 'active' && $session->canOrderFromDevice(
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
        ?array $cart = null,
    ) {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => false,
                'error' => $code,
                'submission_state' => 'rejected',
                'message' => $message,
                'redirectUrl' => $redirectRoute ? route($redirectRoute) : null,
                ...($cart !== null ? ['cart' => $cart] : []),
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
