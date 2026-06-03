<?php

namespace App\Http\Controllers\Admin;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use Illuminate\Http\Request;

/**
 * Waiter-facing flow for taking an order from a walk-in customer who
 * can't scan the table QR (no phone, elderly diner, kid…).
 *
 * Behavior mirrors the customer cart but runs under the staff auth
 * guard and lets the waiter optionally link a regular customer's phone
 * for the loyalty/debt-ledger flows downstream.
 *
 * State (the in-progress cart) lives in the user's session keyed by
 * `waiter_cart.{table_session_id}` so a waiter can juggle multiple
 * tables in different tabs without state collision.
 */
class WaiterOrderController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
        protected StaffMealService $staffMeals,
    ) {}

    /**
     * Table picker — shows every active table in the current branch
     * context. Click → starts (or resumes) a session and lands the
     * waiter in the order builder.
     */
    public function index()
    {
        $this->authorize('create', Order::class);

        $tables = Table::query()
            ->where('active', true)
            ->with([
                'activeSession.orders' => fn ($q) => $q->whereIn('status', ['ready', 'preparing', 'pending']),
                'zone',
            ])
            ->orderBy('number')
            ->get()
            ->map(function (Table $t) {
                $session = $t->activeSession;
                $orders = $session?->orders ?? collect();
                // Per-table service signals the waiter scans for at a glance.
                $t->ready_count = $orders->where('status', 'ready')->count();
                $t->preparing_count = $orders->whereIn('status', ['preparing', 'pending'])->count();
                $t->has_session = (bool) $session;
                // A unified "service state" drives the colour + the filter chips.
                $t->service_state = match (true) {
                    $t->status === 'disabled' => 'disabled',
                    $t->ready_count > 0 => 'ready',        // food waiting to be served
                    $t->has_session => 'occupied',
                    $t->status === 'reserved' => 'reserved',
                    default => 'available',
                };

                return $t;
            });

        $counts = [
            'all' => $tables->count(),
            'available' => $tables->where('service_state', 'available')->count(),
            'occupied' => $tables->where('service_state', 'occupied')->count(),
            'ready' => $tables->where('service_state', 'ready')->count(),
            'reserved' => $tables->where('service_state', 'reserved')->count(),
        ];

        return view('admin.waiter-orders.index', compact('tables', 'counts'));
    }

    /**
     * Open the order builder for a specific table. Creates an active
     * session on demand so the waiter doesn't have to "open" the table
     * separately first.
     */
    public function create(Table $table)
    {
        $this->authorize('create', Order::class);

        $session = $this->ensureActiveSession($table);

        $categories = Category::where('active', true)
            ->with(['menuItems' => fn ($q) => $q
                ->where('is_available', true)
                ->orderBy('display_order')
                ->with('recipeItems.ingredient', 'modifierGroups.modifiers')])
            ->orderBy('display_order')
            ->get()
            ->filter(fn ($c) => $c->menuItems->count() > 0)
            ->values();

        $cart = $this->cart($session);
        $staffMode = $this->staffMode($session);
        $staffMember = $staffMode['user_id']
            ? User::with('branches')->find($staffMode['user_id'])
            : null;

        // Eligible staff: active users WITH a configured allowance, on
        // the same branch as the table. Picker stays short for fast
        // peripheral-vision selection by a busy waiter.
        $eligibleStaff = User::query()
            ->where('status', 'active')
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->when($table->branch_id, fn ($q, $bid) => $q
                ->whereHas('branches', fn ($b) => $b->where('branches.id', $bid)))
            ->orderBy('name')
            ->get(['id', 'name', 'monthly_meal_allowance']);

        return view('admin.waiter-orders.create', compact(
            'table', 'session', 'categories', 'cart',
            'staffMode', 'staffMember', 'eligibleStaff',
        ));
    }

    /**
     * Flip the order into / out of staff-meal mode. When ON the
     * customer panel is hidden and the order is charged against the
     * chosen employee's monthly tab instead of being invoiced.
     */
    public function setStaffMode(Request $request, TableSession $session)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'staff_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($data['staff_user_id'])) {
            $staff = User::find($data['staff_user_id']);
            if (! $staff || $staff->monthly_meal_allowance === null || (float) $staff->monthly_meal_allowance <= 0) {
                return back()->with('error', 'الموظف المختار ليس له بدل وجبات مفعّل. عدّل ملفه أولاً.');
            }
            $this->saveStaffMode($session, ['user_id' => $staff->id]);

            return back()->with('success', "تفعيل وضع طلب موظف للموظف «{$staff->name}».");
        }

        $this->saveStaffMode($session, ['user_id' => null]);

        return back()->with('success', 'تم إلغاء وضع طلب موظف.');
    }

    /**
     * Add an item to the waiter's draft cart for this session. Mirrors
     * the customer CartController::add() validation including the live
     * stock check, so a waiter can't accidentally push an out-of-stock
     * dish either.
     */
    public function addItem(Request $request, TableSession $session)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'menu_item_id' => ['required', 'exists:menu_items,id'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['exists:modifiers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = MenuItem::with('recipeItems.ingredient')->findOrFail($data['menu_item_id']);
        if (! $item->is_available) {
            return back()->with('error', "الصنف «{$item->name}» غير متوفر حالياً.");
        }

        // Cumulative stock check — same as customer flow.
        $virtualCart = $this->cart($session);
        $virtualCart[] = [
            'menu_item_id' => (int) $item->id,
            'quantity' => (float) $data['quantity'],
            'modifier_ids' => $data['modifier_ids'] ?? [],
        ];
        $issues = $this->inventory->checkStockForOrderPreview($virtualCart);
        if (! empty($issues)) {
            $short = collect($issues)
                ->map(fn ($i) => $i['ingredient'].' (متاح '.rtrim(rtrim(number_format($i['available'], 2), '0'), '.').')')
                ->take(3)
                ->join('، ');

            return back()->with('error', "نفد المخزون من: {$short}.");
        }

        $modifiers = Modifier::whereIn('id', $data['modifier_ids'] ?? [])->get();
        $modifiersTotal = (float) $modifiers->sum('price_delta');
        $unitPrice = (float) $item->price;

        $cart = $this->cart($session);
        $cart[] = [
            'id' => uniqid(),
            'menu_item_id' => (int) $item->id,
            'name' => $item->name,
            'quantity' => (int) $data['quantity'],
            'unit_price' => $unitPrice,
            'modifier_ids' => $data['modifier_ids'] ?? [],
            'modifiers' => $modifiers->map(fn ($m) => [
                'id' => (int) $m->id,
                'name' => $m->name,
                'price_delta' => (float) $m->price_delta,
            ])->values()->all(),
            'modifiers_total' => $modifiersTotal,
            'subtotal' => ($unitPrice + $modifiersTotal) * (int) $data['quantity'],
            'notes' => $data['notes'] ?? null,
        ];
        $this->saveCart($session, $cart);

        return back()->with('success', "أُضيف «{$item->name}» للسلة.");
    }

    public function removeItem(Request $request, TableSession $session)
    {
        $this->authorize('create', Order::class);
        $data = $request->validate(['row_id' => ['required', 'string']]);

        $cart = collect($this->cart($session))
            ->reject(fn ($r) => $r['id'] === $data['row_id'])
            ->values()
            ->all();
        $this->saveCart($session, $cart);

        return back()->with('success', 'تم حذف الصنف من السلة.');
    }

    /**
     * Attach (or detach) a customer to the session. The phone-search
     * matches the same `findForLogin` rule used by the portal so a
     * customer registered as "0599…" still resolves when the waiter
     * types "+970-59-9…".
     *
     * If `create_if_missing` is on and no match is found, a new
     * Customer row is created via the existing `createFromCashier`
     * helper (returns the PIN — surfaced as a flash message so the
     * waiter can read it to the diner if they ever want to log in).
     */
    public function linkCustomer(Request $request, TableSession $session)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
            'create_if_missing' => ['sometimes', 'boolean'],
            'detach' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['detach'])) {
            $session->update([
                'customer_id' => null,
                'customer_name' => null,
                'customer_phone' => null,
            ]);

            return back()->with('success', 'تم إلغاء ربط الزبون.');
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            return back()->with('error', 'أدخل رقم الهاتف.');
        }

        $customer = Customer::findForLogin($phone);

        if (! $customer && $request->boolean('create_if_missing')) {
            $name = trim((string) ($data['name'] ?? '')) ?: 'زبون '.substr(preg_replace('/\D+/', '', $phone) ?: $phone, -4);
            [$customer, $pin] = Customer::createFromCashier(
                name: $name,
                phone: $phone,
                defaultBranchId: $session->branch_id,
            );
            session()->flash('info', "أُنشئ زبون جديد ({$customer->name}). كلمة المرور المؤقتة: {$pin}");
        }

        if (! $customer) {
            return back()->with('error', 'لم يُعثر على زبون بهذا الرقم. فعّل «أضف الزبون إن لم يوجد» لتسجيله.');
        }

        $session->update([
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        return back()->with('success', "تم ربط الزبون «{$customer->name}» بالطاولة.");
    }

    /**
     * Materialise the draft cart as a real Order via OrderService —
     * same path the customer's QR flow uses, so the kitchen / KDS /
     * billing pipeline downstream is identical.
     */
    public function submit(Request $request, TableSession $session)
    {
        $this->authorize('create', Order::class);

        $cart = $this->cart($session);
        if (empty($cart)) {
            return back()->with('error', 'السلة فاضية.');
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            // Reuse the cart-shape the OrderService already understands.
            $orderInput = collect($cart)->map(fn ($r) => [
                'menu_item_id' => (int) $r['menu_item_id'],
                'quantity' => (float) $r['quantity'],
                'modifier_ids' => $r['modifier_ids'] ?? [],
                'notes' => $r['notes'] ?? null,
            ])->all();

            $order = $this->orders->createFromCart(
                session: $session,
                cart: $orderInput,
                createdByUserId: auth()->id(),
                customerNotes: $data['notes'] ?? null,
            );

            // Staff meal mode: stamp the consumer + record the charge
            // immediately. The order still goes through the regular
            // kitchen flow (Pending → Approved → Preparing → Ready →
            // Served); the charge row is the bookkeeping.
            $staffMode = $this->staffMode($session);
            $staffMember = null;
            if (! empty($staffMode['user_id'])) {
                $order->update(['staff_consumer_user_id' => $staffMode['user_id']]);
                $charge = $this->staffMeals->chargeOrder($order->fresh(), auth()->id());
                $staffMember = User::find($staffMode['user_id']);
                $this->saveStaffMode($session, ['user_id' => null]);   // reset for next order
            }

            // Auto-approve: when the operator trusts waiters to push straight to
            // the kitchen, skip the manual approval step (and the stock deduct
            // happens now). Controlled by the `auto_approve` setting; if stock
            // is short under strict mode the order stays Pending so nothing is
            // silently lost — the waiter just sees the kitchen note.
            $autoApproved = false;
            if (Setting::get('auto_approve', config('restaurant.order.auto_approve', false))) {
                try {
                    $this->orders->approve($order->fresh(), auth()->id());
                    $autoApproved = true;
                } catch (\Throwable $e) {
                    // Leave it Pending; surface why it couldn't auto-approve.
                    session()->flash('warning', 'تعذّر الاعتماد التلقائي: '.$e->getMessage());
                }
            }

            $this->clearCart($session);

            $statusNote = $autoApproved ? 'وأُرسل للمطبخ.' : 'بانتظار الاعتماد.';
            $msg = $staffMember
                ? "تم إنشاء طلب موظف #{$order->number} للموظف «{$staffMember->name}» بقيمة "
                    .number_format((float) $order->total, 2).' ش.إ. متبقي هذا الشهر: '
                    .number_format($staffMember->fresh()->staffMealRemainingThisMonth() ?? 0, 2).' ش.إ.'
                : "تم إنشاء الطلب #{$order->number} على طاولة {$session->table?->number}. {$statusNote}";

            return redirect()->route('admin.waiter-orders.index')->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ─── helpers ──────────────────────────────────────────────────────

    /**
     * Resolve (or create) the active session for a table. The session
     * is what every downstream concept (orders, invoice, KDS broadcast)
     * keys off — a waiter shouldn't have to wait for the customer to
     * scan a QR before being able to take their order.
     */
    protected function ensureActiveSession(Table $table): TableSession
    {
        $session = $table->activeSession;
        if ($session) {
            return $session;
        }

        $session = TableSession::create([
            'branch_id' => $table->branch_id ?? BranchContext::current(),
            'table_id' => $table->id,
            'token' => \Str::uuid()->toString(),
            'status' => 'active',
            'opened_at' => now(),
            'cover_count' => 1,
            'assigned_waiter_id' => auth()->id(),
        ]);

        if ($table->status !== 'occupied') {
            $previousStatus = $table->status;
            $table->update(['status' => 'occupied']);
            SafeBroadcast::dispatch(
                new TableStatusChanged($table->refresh(), $previousStatus)
            );
        }

        return $session;
    }

    protected function cartKey(TableSession $session): string
    {
        // Tag the cart with the user id too so two waiters sharing a
        // tablet (same browser session) don't see each other's draft.
        return 'waiter_cart.'.auth()->id().'.'.$session->id;
    }

    protected function cart(TableSession $session): array
    {
        return session($this->cartKey($session), []);
    }

    protected function saveCart(TableSession $session, array $cart): void
    {
        session()->put($this->cartKey($session), $cart);
    }

    protected function clearCart(TableSession $session): void
    {
        session()->forget($this->cartKey($session));
    }

    // ─── Staff-mode session helpers ───────────────────────────────────

    protected function staffModeKey(TableSession $session): string
    {
        return 'waiter_staff_mode.'.auth()->id().'.'.$session->id;
    }

    protected function staffMode(TableSession $session): array
    {
        return session($this->staffModeKey($session), ['user_id' => null]);
    }

    protected function saveStaffMode(TableSession $session, array $state): void
    {
        session()->put($this->staffModeKey($session), $state);
    }
}
