<?php

namespace App\Http\Controllers\Portal;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer-facing remote ordering — pickup or delivery, placed from the
 * portal without scanning a QR. Cart lives in the session keyed by
 * customer + branch, so the same diner can build separate carts at two
 * different branches without them clobbering each other.
 *
 * Why no Livewire here (unlike the admin boards): the customer flow is
 * mostly stateless POST forms with full page reloads — Livewire wouldn't
 * add much beyond complexity, and the portal layout already has its own
 * vanilla Alpine for tiny client interactions.
 *
 * NOT a substitute for the QR table flow — that one runs anonymously
 * and lives at /menu/{token}. This is exclusively for logged-in
 * customers who want food without coming to a table first.
 */
class OrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
    ) {}

    /**
     * Branch picker. If the customer has a default branch and no other
     * active branches exist, we redirect straight to the menu — saves a
     * pointless click for single-branch chains.
     */
    public function pickBranch()
    {
        $branches = Branch::where('is_active', true)->orderBy('display_order')->get();

        if ($branches->isEmpty()) {
            return view('portal.order.no-branches');
        }

        if ($branches->count() === 1) {
            return redirect()->route('portal.order.menu', $branches->first());
        }

        $customer = auth('customer')->user();
        // Default branch shortcut — only kicks in if the customer set one
        // and the branch is still active. A stale default redirects to the
        // picker, not 404.
        if ($customer->default_branch_id) {
            $default = $branches->firstWhere('id', $customer->default_branch_id);
            if ($default) {
                // Don't auto-redirect — show as highlighted on the picker.
                // Auto-redirect would hide the choice from a customer who
                // wants to order from a different branch this time.
            }
        }

        return view('portal.order.pick-branch', [
            'branches' => $branches,
            'defaultBranchId' => $customer->default_branch_id,
        ]);
    }

    public function menu(Request $request, Branch $branch)
    {
        abort_unless($branch->is_active, 404);

        // Pin context so BelongsToBranch shows this branch's menu only.
        BranchContext::set($branch->id);

        $categories = Category::where('active', true)
            ->with(['menuItems' => fn ($q) => $q->where('is_available', true)
                ->orderBy('display_order')
                ->with('allergens', 'modifierGroups.modifiers')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn ($c) => $c->menuItems->isNotEmpty())
            ->values();

        $cart = $this->cart($branch);
        $cartTotal = collect($cart)->sum('subtotal');
        $cartCount = collect($cart)->sum('quantity');

        return view('portal.order.menu', compact('branch', 'categories', 'cart', 'cartTotal', 'cartCount'));
    }

    public function addToCart(Request $request, Branch $branch)
    {
        abort_unless($branch->is_active, 404);
        BranchContext::set($branch->id);

        $data = $request->validate([
            'menu_item_id' => ['required', 'exists:menu_items,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['integer', 'exists:modifiers,id'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $item = MenuItem::findOrFail($data['menu_item_id']);
        if (! $item->is_available) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => __('portal.order.item_unavailable')], 422);
            }

            return back()->with('error', __('portal.order.item_unavailable'));
        }

        $modifiers = Modifier::whereIn('id', $data['modifier_ids'] ?? [])->get();

        $row = [
            'id' => uniqid('row_'),
            'menu_item_id' => (int) $item->id,
            'name' => $item->localizedName(),
            'image' => $item->imageUrl(),
            'quantity' => (int) $data['quantity'],
            'unit_price' => (float) $item->price,
            'modifier_ids' => $data['modifier_ids'] ?? [],
            'modifiers' => $modifiers->map(fn ($m) => [
                'id' => (int) $m->id,
                'name' => $m->localizedName(),
                'price_delta' => (float) $m->price_delta,
            ])->all(),
            'modifiers_total' => (float) $modifiers->sum('price_delta'),
            'notes' => $data['notes'] ?? null,
        ];
        $row['subtotal'] = ($row['unit_price'] + $row['modifiers_total']) * $row['quantity'];

        $cart = $this->cart($branch);
        $cart[] = $row;
        $this->saveCart($branch, $cart);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => __('portal.order.added_to_cart'),
                'cart' => $this->cartPayload($branch, $cart),
            ]);
        }

        return back()->with('success', __('portal.order.added_to_cart'));
    }

    public function removeFromCart(Request $request, Branch $branch)
    {
        $rowId = (string) $request->input('row_id');
        $cart = collect($this->cart($branch))
            ->reject(fn ($r) => $r['id'] === $rowId)
            ->values()
            ->all();
        $this->saveCart($branch, $cart);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'cart' => $this->cartPayload($branch, $cart),
            ]);
        }

        return back();
    }

    /** Shape used by the AJAX cart response — keeps the JS tiny. */
    protected function cartPayload(Branch $branch, array $cart): array
    {
        $count = collect($cart)->sum('quantity');
        $total = collect($cart)->sum('subtotal');

        return [
            'rows' => $cart,
            'count' => (int) $count,
            'total' => round((float) $total, 2),
        ];
    }

    public function checkout(Branch $branch)
    {
        abort_unless($branch->is_active, 404);
        BranchContext::set($branch->id);

        $cart = $this->cart($branch);
        if (empty($cart)) {
            return redirect()->route('portal.order.menu', $branch)
                ->with('error', __('portal.order.empty_cart_add_items'));
        }

        return view('portal.order.checkout', [
            'branch' => $branch,
            'customer' => auth('customer')->user(),
            'cart' => $cart,
            'cartTotal' => collect($cart)->sum('subtotal'),
        ]);
    }

    /**
     * Place the order. Validates the type-specific fields (delivery needs
     * an address; both can carry an optional scheduled time) then hands
     * the cart to OrderService::createRemoteOrder which deals with the
     * branch context + transaction.
     */
    public function place(Request $request, Branch $branch)
    {
        abort_unless($branch->is_active, 404);
        BranchContext::set($branch->id);

        $data = $request->validate([
            'order_type' => ['required', 'in:takeaway,delivery'],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'string', 'max:500'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
        ], [
            'order_type.required' => __('portal.order.validation_order_type_required'),
            'order_type.in' => __('portal.order.validation_order_type_invalid'),
            'delivery_address.required_if' => __('portal.order.validation_delivery_required'),
            'delivery_address.max' => __('portal.order.validation_delivery_max'),
            'scheduled_for.date' => __('portal.order.validation_scheduled_date'),
            'scheduled_for.after' => __('portal.order.validation_scheduled_after'),
            'customer_notes.max' => __('portal.order.validation_notes_max'),
        ]);

        $cart = $this->cart($branch);
        if (empty($cart)) {
            return redirect()->route('portal.order.menu', $branch)
                ->with('error', __('portal.order.empty_cart'));
        }

        $issues = $this->inventory->checkStockForOrderPreview($cart);
        if (! empty($issues)) {
            $msg = collect($issues)
                ->map(fn ($i) => __('portal.order.stock_line', [
                    'ingredient' => $i['ingredient'],
                    'available' => $i['available'],
                    'required' => $i['required'],
                ]))
                ->join(' | ');

            return back()->with('error', __('portal.order.stock_shortage', ['items' => $msg]));
        }

        $customer = auth('customer')->user();

        $order = $this->orders->createRemoteOrder(
            customer: $customer,
            branch: $branch,
            type: $data['order_type'],
            cart: $cart,
            opts: [
                'customer_notes' => $data['customer_notes'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'scheduled_for' => $data['scheduled_for'] ?? null,
            ],
        );

        $this->saveCart($branch, []);   // cart cleared on success only

        return redirect()->route('portal.order.placed', $order)
            ->with('success', __('portal.order.order_received', ['number' => $order->number]));
    }

    /**
     * Confirmation + tracking page for the just-placed (or any past) order.
     * Authorisation: the order must belong to the logged-in customer —
     * otherwise 404 (we don't reveal that the order exists).
     */
    public function placed(Order $order)
    {
        $customer = auth('customer')->user();
        abort_unless($order->customer_id === $customer->id, 404);

        $order->load(['items.modifiers', 'branch']);

        return view('portal.order.placed', compact('order'));
    }

    public function index()
    {
        $customer = auth('customer')->user();

        // Use unscoped — the customer's orders span every branch they ever
        // ordered from, and BranchScope would hide branches they're not
        // currently switched into.
        $orders = BranchContext::unscoped(fn () => $customer->orders()->with(['branch:id,name', 'items'])->paginate(15)
        );

        return view('portal.order.history', compact('orders'));
    }

    // ── Customer self-edit (only while pending) ───────────────────

    /**
     * Edit form. Available only while the order is still `pending`; once a
     * waiter approves it the kitchen has it on the line and we won't accept
     * further changes from the diner. We don't try to cleverly merge edits
     * with an in-progress order — too many failure modes.
     */
    public function editForm(Order $order)
    {
        $this->ensureOwnedAndEditable($order);
        $order->load('items.modifiers', 'branch');

        return view('portal.order.edit', compact('order'));
    }

    /**
     * Apply qty changes + removals. Posted shape:
     *   items[ITEM_ID][qty] = INT  (0 means remove)
     *
     * Removals are HARD-deleted (not cancelled) because the order is still
     * pending — nothing has hit the kitchen, no stock was deducted, no
     * audit trail is meaningful at this point. If the diner empties the
     * basket, we treat that as "I've changed my mind" and cancel the
     * whole order via the standard service path.
     */
    public function update(Request $request, Order $order, OrderService $service)
    {
        $this->ensureOwnedAndEditable($order);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        BranchContext::set($order->branch_id);

        DB::transaction(function () use ($data, $order) {
            $order->loadMissing('items');
            foreach ($order->items as $item) {
                $row = $data['items'][$item->id] ?? null;
                if (! $row) {
                    continue;
                }

                $qty = (int) $row['qty'];
                if ($qty <= 0) {
                    $item->modifiers()->delete();
                    $item->delete();

                    continue;
                }

                $unitWithMods = (float) $item->unit_price + (float) $item->modifiers_total;
                $item->update([
                    'quantity' => $qty,
                    'subtotal' => $unitWithMods * $qty,
                ]);
            }
        });

        $order->load('items');

        if ($order->items->isEmpty()) {
            $service->cancel($order, null, __('portal.order.customer_removed_all'));

            return redirect()->route('portal.order.history')
                ->with('success', __('portal.order.cancelled_empty', ['number' => $order->number]));
        }

        $service->recalculateTotals($order);
        $service->refreshEta($order);

        ActivityLog::log('order.customer_edited',
            __('portal.order.customer_edited_log', ['number' => $order->number]),
            $order
        );

        return redirect()->route('portal.order.placed', $order)
            ->with('success', __('portal.order.changes_saved'));
    }

    /**
     * Customer cancels their own pending order. Once approved, this 403s —
     * if they need to cancel an active order at that point, the cashier or
     * a manager has to do it from admin (so they can ask why and offer a
     * refund if anything was charged).
     */
    public function cancel(Order $order, OrderService $service)
    {
        $this->ensureOwnedAndEditable($order);

        BranchContext::set($order->branch_id);
        $service->cancel($order, null, __('portal.order.cancelled_by_customer'));

        return redirect()->route('portal.order.history')
            ->with('success', __('portal.order.order_cancelled', ['number' => $order->number]));
    }

    /**
     * Guard used by the three customer-edit endpoints. Splits ownership
     * (404 — don't reveal the order exists) from editability (403 — show
     * the diner why they can't proceed). Centralised so the message stays
     * consistent across edit/update/cancel.
     */
    protected function ensureOwnedAndEditable(Order $order): void
    {
        $customer = auth('customer')->user();
        abort_unless($order->customer_id === $customer->id, 404);

        if ($order->status !== OrderStatus::Pending->value) {
            abort(403, __('portal.order.cannot_edit_after_approval'));
        }
    }

    // ── Cart storage ──────────────────────────────────────────────

    /**
     * Cart key is namespaced by customer + branch so the same diner can
     * shop at two branches in two tabs without one stomping the other.
     */
    protected function cartKey(Branch $branch): string
    {
        $customerId = auth('customer')->id() ?? 'guest';

        return "portal_cart.{$customerId}.{$branch->id}";
    }

    protected function cart(Branch $branch): array
    {
        return session($this->cartKey($branch), []);
    }

    protected function saveCart(Branch $branch, array $cart): void
    {
        session()->put($this->cartKey($branch), $cart);
    }
}
