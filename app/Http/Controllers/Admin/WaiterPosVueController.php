<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\TableStatusChanged;
use App\Exceptions\DuplicatePendingTransferException;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\CustomerIdentityService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PendingTransferService;
use App\Services\StaffMealService;
use App\Support\BranchContext;
use App\Support\OrderRoundContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Waiter POS — Inertia/Vue pilot (MIGRATION-PILOT.md §5 المرحلة 1).
 *
 * Serves the §6 props contract and the POST contracts (§7). The read
 * model and submit semantics preserve the review-verified safeguards
 * ($committed idempotency flag,
 * promo short-circuit, in-memory stock gating) and the pilot must not
 * regress any of them. When behavior here and there diverge, THERE wins.
 *
 * Error shape: structural validation uses Laravel's standard 422; BUSINESS
 * refusals (stock, modifier groups, closed session, replayed token) return
 * the §7 contract shape { ok: false, message } with 422.
 *
 * Out of pilot scope on purpose (§9): staff meals, customer linking,
 * transfer claims — the old screen remains available for those.
 */
class WaiterPosVueController extends Controller
{
    public function show(Request $request, Table $table)
    {
        $this->authorize('create', Order::class);

        $session = $this->ensureActiveSession($table);

        return BranchContext::forBranch($session->branch_id, function () use ($request, $table, $session) {
            $menu = $this->menuPayload();

            $orders = Order::query()
                ->where('table_session_id', $session->id)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->with(['items.modifiers', 'items.exclusions'])
                ->latest()
                ->get();

            $invoice = $session->invoice()->where('status', '!=', 'cancelled')->latest()->first();
            $priorTotal = (float) $orders->sum('total');
            $sessionTotal = $invoice ? (float) $invoice->total : $priorTotal;
            $sessionOutstanding = $invoice ? (float) $invoice->balance : $priorTotal;
            $reviewOrder = null;
            $reviewOrderId = (int) $request->query('review_order', 0);

            if ($reviewOrderId > 0) {
                $pending = Order::with([
                    'items.modifiers',
                    'items.exclusions',
                    'changeRequests' => fn ($query) => $query
                        ->where('status', OrderChangeRequest::STATUS_PENDING)
                        ->with('orderItem'),
                    'tableSession.orders',
                ])
                    ->whereKey($reviewOrderId)
                    ->where('table_session_id', $session->id)
                    ->where('status', OrderStatus::Pending->value)
                    ->firstOrFail();

                $this->authorize('approve', $pending);
                $reviewOrder = $this->reviewPayload($pending);
            }

            return Inertia::render('WaiterPos/Show', [
                'table' => ['id' => $table->id, 'number' => (string) $table->number],
                'session' => [
                    'id' => $session->id,
                    'covers' => max(1, (int) $session->cover_count),
                    'customer_id' => $session->customer_id,
                    'customer' => $session->customer?->name,
                    'debt' => (float) ($session->customer?->outstandingDebt() ?? 0),
                ],
                'carryOver' => [
                    'has_prior' => $orders->isNotEmpty(),
                    'orders_count' => $orders->count(),
                    'total' => $sessionTotal,
                    'settled' => max(0, round($sessionTotal - $sessionOutstanding, 2)),
                    'outstanding' => $sessionOutstanding,
                ],
                'sessionOrders' => $orders->map(function (Order $order, int $index) use ($orders, $invoice) {
                    $mayCancelItems = ! $invoice && auth()->user()?->can('cancel', $order);

                    return [
                        'id' => $order->id,
                        'number' => $order->number,
                        'round' => $orders->count() - $index,
                        'status' => $order->status,
                        'statusLabel' => $order->statusLabel(),
                        'createdAt' => $order->created_at?->toISOString(),
                        'createdTime' => $order->created_at?->format('H:i'),
                        'subtotal' => (float) $order->subtotal,
                        'discountTotal' => (float) $order->discount_total,
                        'taxTotal' => (float) $order->tax_total,
                        'serviceTotal' => (float) $order->service_total,
                        'total' => (float) $order->total,
                        'notes' => $order->customer_notes,
                        'items' => $order->items
                            ->sortBy('id')
                            ->map(fn ($item) => [
                                'id' => $item->id,
                                'name' => (string) $item->name_snapshot,
                                'quantity' => (float) $item->quantity,
                                'unitPrice' => (float) $item->unit_price,
                                'modifiersTotal' => (float) $item->modifiers_total,
                                'subtotal' => (float) $item->subtotal,
                                'status' => $item->status,
                                'statusLabel' => $item->statusLabel(),
                                'notes' => $item->notes,
                                'cancelledReason' => $item->cancelled_reason,
                                'canCancel' => $mayCancelItems && in_array($item->status, [
                                    OrderItemStatus::Pending->value,
                                    OrderItemStatus::Approved->value,
                                    OrderItemStatus::Preparing->value,
                                    OrderItemStatus::Ready->value,
                                ], true),
                                'cancelMode' => in_array($item->status, [
                                    OrderItemStatus::Preparing->value,
                                    OrderItemStatus::Ready->value,
                                ], true) ? 'waste' : 'return',
                                'modifiers' => $item->modifiers
                                    ->pluck('name_snapshot')
                                    ->filter()
                                    ->values(),
                                'exclusions' => $item->exclusions
                                    ->pluck('name_snapshot')
                                    ->filter()
                                    ->values(),
                            ])
                            ->values(),
                    ];
                })->values(),
                'categories' => $menu['categories'],
                'menu' => $menu['items'],
                'quickPicks' => $this->quickPicks(),
                'lastRound' => $this->lastRound($orders),
                'reviewOrder' => $reviewOrder,
                'submitToken' => (string) Str::uuid(),
                // §10 (sol request, 2026-08-11): the client renders every price
                // itself, so it needs the display currency instead of assuming
                // the shekel. Mirrors Money::format exactly — the rendered
                // shape is `number_format(amount, decimals) + ' ' + symbol`.
                'currency' => [
                    'code' => strtoupper((string) config('restaurant.currency', 'ILS')),
                    'symbol' => (string) Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
                    'decimals' => 2,
                ],
                // Ported from the classic screen (redesigned, same semantics):
                // staff-meal picker + bank-transfer claim context.
                'eligibleStaff' => $this->eligibleStaff($table),
                'transfer' => [
                    'enabled' => trim((string) Setting::get('bank_transfer_details', '')) !== '',
                    'details' => trim((string) Setting::get('bank_transfer_details', '')),
                    // Classic rule: a claim only makes sense once the session
                    // has at least one order past Pending.
                    'eligible' => $orders->contains(fn (Order $o) => $o->status !== OrderStatus::Pending->value),
                ],
                'notificationUrls' => [
                    'recent' => route('admin.notifications.recent'),
                    'readAll' => route('admin.notifications.read-all'),
                    'base' => url('admin/notifications'),
                    'index' => route('admin.notifications.index'),
                ],
            ]);
        });
    }

    /** Final at-table review of a QR round before any station sees it. */
    public function review(Request $request, Table $table, Order $order, OrderService $orders)
    {
        $this->authorize('approve', $order);

        $data = $request->validate([
            'expected_version' => ['required', 'date'],
            'change_request_ids' => ['array'],
            'change_request_ids.*' => ['integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.order_item_id' => ['nullable', 'integer', 'distinct'],
            'cart.*.menu_item_id' => ['required', 'integer'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'cart.*.modifier_ids' => ['array'],
            'cart.*.modifier_ids.*' => ['integer', 'distinct'],
            'cart.*.excluded_ingredient_ids' => ['array'],
            'cart.*.excluded_ingredient_ids.*' => ['integer', 'distinct'],
            'cart.*.line_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $table->activeSession;
        if (! $session
            || $session->status !== 'active'
            || (int) $order->table_session_id !== (int) $session->id
            || (int) $order->table_id !== (int) $table->id) {
            return $this->refuse('تغيّرت جلسة الطاولة. ارجع للوحة الصالة وافتح الطلب الحالي.', 409);
        }

        return BranchContext::forBranch($session->branch_id, function () use ($data, $order, $orders) {
            $cart = collect($data['cart'])->map(fn ($line) => [
                'order_item_id' => isset($line['order_item_id']) ? (int) $line['order_item_id'] : null,
                'menu_item_id' => (int) $line['menu_item_id'],
                'quantity' => (int) $line['quantity'],
                'modifier_ids' => array_map('intval', $line['modifier_ids'] ?? []),
                'excluded_ingredient_ids' => array_map('intval', $line['excluded_ingredient_ids'] ?? []),
                'notes' => isset($line['line_notes'])
                    ? (trim(mb_substr((string) $line['line_notes'], 0, 500)) ?: null)
                    : null,
            ])->values();

            if ($problem = $this->firstLineProblem($cart)) {
                return $this->refuse($problem);
            }

            $issues = app(InventoryService::class)->checkStockForOrderPreview($cart->all());
            if (! empty($issues)) {
                $short = collect($issues)
                    ->map(fn ($issue) => $issue['ingredient'].' (متاح '.rtrim(rtrim(number_format($issue['available'], 2), '0'), '.').')')
                    ->take(3)
                    ->join('، ');

                return $this->refuse("لا يمكن اعتماد النسخة النهائية. الناقص: {$short}.");
            }

            try {
                $approved = $orders->reviewAndApprovePending(
                    order: $order,
                    cart: $cart->all(),
                    customerNotes: trim((string) ($data['notes'] ?? '')) ?: null,
                    userId: (int) auth()->id(),
                    expectedVersion: (string) $data['expected_version'],
                    expectedChangeRequestIds: array_map('intval', $data['change_request_ids'] ?? []),
                );
            } catch (\RuntimeException $exception) {
                $status = $exception->getCode() === 409 ? 409 : 422;

                return $this->refuse($exception->getMessage(), $status);
            }

            return response()->json([
                'ok' => true,
                'order_number' => $approved->number,
                'total' => (float) $approved->total,
                'message' => 'تم اعتماد النسخة النهائية وإرسالها للمطبخ والبار.',
            ]);
        });
    }

    /**
     * Staff with an active meal allowance on this branch — the classic
     * screen's picker query, plus each person's REMAINING balance so the
     * waiter sees «ما بقي له» before charging (the old screen only showed
     * it after submitting).
     */
    protected function eligibleStaff(Table $table)
    {
        Employee::importLegacyMealUsers();

        return Employee::query()
            ->where('status', 'active')
            ->whereNotNull('monthly_meal_allowance')
            ->where('monthly_meal_allowance', '>', 0)
            ->when($table->branch_id, fn ($q, $bid) => $q
                ->whereHas('branches', fn ($b) => $b->where('branches.id', $bid)))
            ->orderBy('name')
            ->get(['id', 'name', 'job_title', 'user_id', 'monthly_meal_allowance'])
            ->map(fn (Employee $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'job_title' => $u->roleLabel(),
                'has_login' => (bool) $u->user_id,
                'remaining' => (float) ($u->staffMealRemainingThisMonth() ?? 0),
            ])
            ->values();
    }

    /**
     * §10 (sol request, 2026-08-11) — SessionBar's covers stepper.
     * Delta-based like the live screen's changeCovers(): buttons bake their
     * wire-call at render time, so absolute values lose fast taps; a delta
     * applied to the CURRENT stored value makes every tap count.
     */
    public function covers(Request $request, Table $table)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-10,10'],
        ]);

        $session = $table->activeSession;
        if (! $session || $session->status !== 'active') {
            return $this->refuse('انتهت جلسة هذه الطاولة — ارجع للصالة وافتحها من جديد.');
        }

        return BranchContext::forBranch($session->branch_id, function () use ($session, $data) {
            $covers = min(50, max(1, (int) $session->cover_count + $data['delta']));
            $session->update(['cover_count' => $covers]);

            return response()->json(['ok' => true, 'covers' => $covers]);
        });
    }

    /**
     * Cancel one visible line from the waiter's current table session.
     *
     * The line is never deleted: OrderService keeps the actor and reason,
     * recalculates the bill, broadcasts the change, and either returns
     * untouched ingredients or records prepared food as waste.
     */
    public function cancelItem(Request $request, Table $table, OrderItem $item, OrderService $orders)
    {
        $item->loadMissing('order');
        $this->authorize('cancel', $item->order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $session = $table->activeSession;
        if (! $session
            || $session->status !== 'active'
            || (int) $item->order?->table_session_id !== (int) $session->id
            || (int) $item->order?->table_id !== (int) $table->id) {
            return $this->refuse('تغيّرت جلسة الطاولة. حدّث الشاشة وتحقق من الطلب الحالي.', 409);
        }

        if (! in_array($item->status, [
            OrderItemStatus::Pending->value,
            OrderItemStatus::Approved->value,
            OrderItemStatus::Preparing->value,
            OrderItemStatus::Ready->value,
        ], true)) {
            return $this->refuse(
                $item->status === OrderItemStatus::Served->value
                    ? 'تم تسليم هذا الصنف. أي تصحيح مالي بعد التسليم يتم من الكاشير حتى يبقى الحساب موثقاً.'
                    : 'هذا الصنف ملغي أو لم يعد قابلاً للإلغاء.'
            );
        }

        $disposition = in_array($item->status, [
            OrderItemStatus::Preparing->value,
            OrderItemStatus::Ready->value,
        ], true) ? 'waste' : 'return';

        try {
            $orders->cancelItem(
                item: $item,
                userId: auth()->id(),
                reason: trim($data['reason']),
                disposition: $disposition,
                wasteReason: $disposition === 'waste' ? trim($data['reason']) : null,
            );
        } catch (\Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'item_id' => $item->id,
            'disposition' => $disposition,
            'message' => $disposition === 'waste'
                ? 'تم إلغاء الصنف واحتساب مكوناته كهدر لأنه دخل التحضير.'
                : 'تم إلغاء الصنف وتحديث حساب الطاولة.',
        ]);
    }

    /** §7 — { cart: CartLine[] } → { issues: [{ingredient, available}] }. */
    public function previewStock(Request $request, Table $table)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'cart' => ['required', 'array'],
            'cart.*.menu_item_id' => ['required', 'integer'],
            'cart.*.quantity' => ['required', 'numeric', 'min:1', 'max:99'],
            'cart.*.modifier_ids' => ['array'],
            'cart.*.excluded_ingredient_ids' => ['array'],
            'cart.*.excluded_ingredient_ids.*' => ['integer', 'distinct'],
        ]);

        $issues = BranchContext::forBranch($table->branch_id, fn () => app(InventoryService::class)
            ->checkStockForOrderPreview(collect($data['cart'])->map(fn ($line) => [
                'menu_item_id' => (int) $line['menu_item_id'],
                'quantity' => (float) $line['quantity'],
                'modifier_ids' => array_map('intval', $line['modifier_ids'] ?? []),
                'excluded_ingredient_ids' => array_map('intval', $line['excluded_ingredient_ids'] ?? []),
            ])->all()));

        return response()->json([
            'issues' => collect($issues)->map(fn ($i) => [
                'ingredient' => $i['ingredient'],
                'available' => (float) $i['available'],
            ])->values(),
        ]);
    }

    /**
     * §7 — the one write: atomic token, authoritative whole-cart stock gate
     * that RELEASES the token on refusal, per-line group enforcement,
     * server-side pricing via OrderService, direct fire behind
     * `waiter_direct_fire` + OrderPolicy, and the $committed rule — once
     * createFromCart commits, the token is never released again.
     */
    public function submit(Request $request, Table $table)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'staff_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // Backward-compatible API input for older cached waiter clients.
            'staff_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.menu_item_id' => ['required', 'integer'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'cart.*.modifier_ids' => ['array'],
            'cart.*.excluded_ingredient_ids' => ['array'],
            'cart.*.excluded_ingredient_ids.*' => ['integer', 'distinct'],
            'cart.*.line_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $table->activeSession;
        if (! $session || $session->status !== 'active') {
            return $this->refuse('انتهت جلسة هذه الطاولة — ارجع للصالة وافتحها من جديد.');
        }

        // Staff-meal eligibility is checked BEFORE the token is consumed —
        // a bad pick must leave an honest retry possible (classic rule:
        // active allowance + this branch).
        $staff = null;
        if (! empty($data['staff_employee_id'])) {
            $staff = Employee::find($data['staff_employee_id']);
        } elseif (! empty($data['staff_user_id'])) {
            $legacy = User::find($data['staff_user_id']);
            $staff = $legacy ? Employee::fromUser($legacy) : null;
        }
        if ($staff) {
            $eligible = $staff
                && $staff->monthly_meal_allowance !== null
                && (float) $staff->monthly_meal_allowance > 0
                && $staff->branches()->where('branches.id', $session->branch_id)->exists();
            if (! $eligible) {
                return $this->refuse('الموظف المختار غير مفعّل لبدل الوجبات في هذا الفرع.');
            }
        }

        $tokenKey = 'waiter_pos.submit.'.$data['token'];
        if (! Cache::add($tokenKey, 1, 900)) {
            return $this->refuse('الطلب انبعت من قبل — ما انبعت مرتين.');
        }

        return BranchContext::forBranch($session->branch_id, function () use ($data, $session, $tokenKey, $staff) {
            $cart = collect($data['cart'])->map(fn ($line) => [
                'menu_item_id' => (int) $line['menu_item_id'],
                'quantity' => (float) $line['quantity'],
                'modifier_ids' => array_map('intval', $line['modifier_ids'] ?? []),
                'excluded_ingredient_ids' => array_map('intval', $line['excluded_ingredient_ids'] ?? []),
                'notes' => isset($line['line_notes']) ? (trim(mb_substr((string) $line['line_notes'], 0, 500)) ?: null) : null,
            ]);

            // Per-line business validation: sellable item + its REAL modifier
            // ids only + group min/max — the client checks these for politeness,
            // the server checks them for truth.
            if ($problem = $this->firstLineProblem($cart)) {
                Cache::forget($tokenKey);

                return $this->refuse($problem);
            }

            // Authoritative whole-cart stock gate — createFromCart never
            // re-checks stock, so this is the last gate before commit.
            $issues = app(InventoryService::class)->checkStockForOrderPreview($cart->all());
            if (! empty($issues)) {
                Cache::forget($tokenKey);
                $short = collect($issues)
                    ->map(fn ($i) => $i['ingredient'].' (متاح '.rtrim(rtrim(number_format($i['available'], 2), '0'), '.').')')
                    ->take(3)->join('، ');

                return $this->refuse("نفد المخزون من: {$short}.");
            }

            $committed = false;

            try {
                $order = app(OrderService::class)->createFromCart(
                    session: $session,
                    cart: $cart->all(),
                    createdByUserId: auth()->id(),
                    customerNotes: trim((string) ($data['notes'] ?? '')) ?: null,
                );
                $committed = true;

                // Staff meal (classic order of operations: stamp + charge
                // BEFORE the fire). Runs inside the committed zone — if the
                // charge hits the monthly ceiling, the outer catch returns
                // ok:true + warning («راجعه، لا تعد الإرسال») instead of an
                // error that used to breed twin orders.
                $staffMealWarning = null;
                if ($staff) {
                    $order->update([
                        'staff_consumer_employee_id' => $staff->id,
                        'staff_consumer_user_id' => $staff->user_id,
                    ]);
                    $mealCheck = app(StaffMealService::class)
                        ->previewLimitCheck($staff, (float) $order->fresh()->total);
                    $actorCanOverride = auth()->user()->isManagementLevel()
                        || auth()->user()->hasPermission('staff_meals.waive');

                    if ($mealCheck['status'] === 'blocked'
                        || ($mealCheck['status'] === 'requires_approval' && ! $actorCanOverride)) {
                        app(OrderService::class)->cancel(
                            $order->fresh(),
                            auth()->id(),
                            'أُلغي تلقائياً قبل الإرسال: تجاوز سياسة بدل وجبات الموظفين.',
                        );

                        return $this->refuse($mealCheck['reason'] ?? 'تحتاج وجبة الموظف إلى موافقة مدير قبل إرسالها.');
                    }

                    if ($mealCheck['status'] === 'warn') {
                        $staffMealWarning = $mealCheck['reason'];
                    }
                }

                // ONE-STEP FIRE — same rule as the live screen: staff orders
                // go straight to the kitchen unless the operator turned the
                // ceremony back on.
                $fired = false;
                $directFire = Setting::get('waiter_direct_fire', true)
                    || Setting::get('auto_approve', config('restaurant.order.auto_approve', false));
                if ($directFire && auth()->user()->can('approve', $order)) {
                    try {
                        app(OrderService::class)->approve($order->fresh(), auth()->id());
                        $fired = true;
                    } catch (\Throwable $e) {
                        // The order EXISTS — degrade to a warning, never to an
                        // error that reads as "nothing was sent".
                        return response()->json([
                            'ok' => true,
                            'order_number' => $order->number,
                            'fired' => false,
                            'warning' => 'أُنشئ الطلب لكن تعذّر إرساله للمطبخ تلقائياً: '.$e->getMessage(),
                        ]);
                    }
                }

                return response()->json([
                    'ok' => true,
                    'order_number' => $order->number,
                    'fired' => $fired,
                    'warning' => $staffMealWarning,
                ]);
            } catch (\Throwable $e) {
                if (! $committed) {
                    // Nothing was created — release the token so an honest
                    // retry works.
                    Cache::forget($tokenKey);

                    return $this->refuse($e->getMessage());
                }

                // Post-commit failure with the order already real: keep the
                // token consumed (releasing it here was the duplicate-order
                // source of duplicate orders caught during review).
                return response()->json([
                    'ok' => true,
                    'order_number' => null,
                    'fired' => false,
                    'warning' => 'أُنشئ الطلب لكن تعطّلت خطوة بعده: '.$e->getMessage().' — راجعه من لوحة الطلبات ولا تعد إرساله.',
                ]);
            }
        });
    }

    /**
     * ربط/فك زبون — semantics copied from the classic linkCustomer():
     * Format-tolerant phone matching creates an internal diner file when
     * «create_if_missing» is set; detach clears the visit snapshot.
     * JSON in/out instead of the classic flash-and-redirect.
     */
    public function customer(Request $request, Table $table, CustomerIdentityService $identity)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
            'create_if_missing' => ['sometimes', 'boolean'],
            'detach' => ['sometimes', 'boolean'],
        ]);

        $session = $table->activeSession;
        if (! $session || $session->status !== 'active') {
            return $this->refuse('انتهت جلسة هذه الطاولة — ارجع للصالة وافتحها من جديد.');
        }

        return BranchContext::forBranch($session->branch_id, function () use ($request, $data, $session, $identity) {
            if (! empty($data['detach'])) {
                $session->update([
                    'customer_id' => null,
                    'customer_name' => null,
                    'customer_phone' => null,
                ]);

                return response()->json(['ok' => true, 'customer' => null]);
            }

            $phone = trim((string) ($data['phone'] ?? ''));
            if ($phone === '') {
                return $this->refuse('أدخل رقم الهاتف.');
            }

            $existingCustomer = Customer::findByPhone($phone);
            if (! $existingCustomer && ! $request->boolean('create_if_missing')) {
                return $this->refuse('لم يُعثر على زبون بهذا الرقم. فعّل «أضف الزبون إن لم يوجد» لتسجيله.');
            }

            $name = $existingCustomer?->name
                ?: (trim((string) ($data['name'] ?? '')) ?: 'زبون '.substr(preg_replace('/\D+/', '', $phone) ?: $phone, -4));
            $resolved = $identity->resolveOrCreate(
                name: $name,
                phone: $phone,
                defaultBranchId: $session->branch_id,
                source: 'waiter_pos',
            );
            $customer = $resolved['customer'];
            $session = $identity->linkSession($session, $customer);

            return response()->json([
                'ok' => true,
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'debt' => (float) $customer->outstandingDebt(),
                ],
            ]);
        });
    }

    /**
     * إعلان حوالة بنكية — DELEGATION ONLY: transfers are sol's exclusive
     * domain (§4), so this endpoint calls PendingTransferService::record
     * exactly as the classic PendingTransferController@store does, changing
     * nothing about its behavior. (Proof-image upload deliberately stays on
     * the cashier's flow for now.)
     */
    public function transfer(Request $request, Table $table, PendingTransferService $transfers)
    {
        $this->authorize('create', Order::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'sender_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $table->activeSession;
        if (! $session || $session->status !== 'active') {
            return $this->refuse('انتهت جلسة هذه الطاولة — ارجع للصالة وافتحها من جديد.');
        }

        try {
            BranchContext::forBranch($session->branch_id, fn () => $transfers->record(
                session: $session,
                amount: (float) $data['amount'],
                senderName: $data['sender_name'],
                recordedByUserId: auth()->id(),
                notes: $data['notes'] ?? null,
                phone: $data['customer_phone'] ?? null,
                typedName: $data['customer_name'] ?? null,
                proofPath: null,
            ));
        } catch (DuplicatePendingTransferException) {
            return $this->refuse('يوجد تحويل معلّق لهذه الطاولة بالفعل — أكّده أو ارفضه أولاً.');
        }

        return response()->json(['ok' => true]);
    }

    // ─── Read model ───────────────────────────────────────────────────────

    /** Menu + categories decorated for the waiter screen. */
    protected function menuPayload(): array
    {
        $items = MenuItem::query()
            ->where('is_available', true)
            ->with(['category', 'station:id,storage_location_id', 'modifierGroups.modifiers', 'recipeItems.ingredient'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        // Promo short-circuit: one existence query instead of one promo
        // lookup PER item (the "seconds to open" scar from the old screen).
        $hasPromos = MenuPromotion::where('active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', BranchContext::current()))
            ->exists();

        $inventory = app(InventoryService::class);

        $payload = $items->map(function (MenuItem $item) use ($inventory, $hasPromos) {
            $promo = $hasPromos ? $item->activePromotion() : null;
            $price = $promo ? (float) $promo->applyTo((float) $item->price) : (float) $item->price;

            return [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'name' => $item->name,
                'price' => $price,
                'original_price' => (float) $item->price,
                'has_promo' => $promo && $price < (float) $item->price,
                'in_stock' => $this->itemInStock($inventory, $item),
                'has_mods' => $item->modifierGroups->isNotEmpty(),
                'modifier_groups' => $item->modifierGroups->map(fn ($g) => [
                    'id' => (int) $g->id,
                    'name' => $g->name,
                    'required' => (bool) $g->required,
                    'min_select' => (int) $g->min_select,
                    'max_select' => (int) $g->max_select,
                    'modifiers' => $g->modifiers->map(fn ($m) => [
                        'id' => (int) $m->id,
                        'name' => $m->name,
                        'price_delta' => (float) $m->price_delta,
                    ])->values(),
                ])->values(),
                'removable_ingredients' => $item->recipeItems
                    ->filter(fn ($recipe) => $recipe->ingredient !== null)
                    ->unique(fn ($recipe) => (int) $recipe->ingredient_id)
                    ->map(fn ($recipe) => [
                        'id' => (int) $recipe->ingredient_id,
                        'name' => $recipe->ingredient->localizedName(),
                        'requires_confirmation' => ! (bool) $recipe->is_optional,
                    ])->values(),
            ];
        })->values();

        $categories = $items->groupBy('category_id')
            ->map(fn ($group) => [
                'id' => (int) $group->first()->category_id,
                'name' => $group->first()->category?->name ?? '',
                'count' => $group->count(),
            ])->values()
            ->sortBy('name')->values();

        return ['items' => $payload, 'categories' => $categories];
    }

    /** In-memory recipe check — zero extra queries per item (see the live screen). */
    protected function itemInStock(InventoryService $inventory, MenuItem $item): bool
    {
        $need = [];
        foreach ($inventory->previewDeductionForItem($item, 1.0) as $line) {
            $id = $line['ingredient_id'];
            $need[$id] ??= ['ingredient' => $line['ingredient'], 'qty' => 0.0];
            $need[$id]['qty'] += (float) $line['quantity_in_base'];
        }

        foreach ($need as $n) {
            $locationId = $item->station?->storage_location_id;
            $available = $locationId
                ? $n['ingredient']->usableStockAtLocation((int) $locationId)
                : $n['ingredient']->usableStockAtBranch((int) ($item->branch_id ?: BranchContext::current()));
            if ($n['ingredient']->track_stock && $n['qty'] > $available) {
                return false;
            }
        }

        return true;
    }

    /** Same ranking + cache key as the live screen — the two share one cache. */
    protected function quickPicks()
    {
        $branchId = (int) BranchContext::current();

        $ids = Cache::remember('waiter_pos.quick_picks.'.$branchId, 600, function () use ($branchId) {
            return DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.branch_id', $branchId)
                ->where('orders.created_at', '>=', now()->subDays(14))
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->where('order_items.status', '!=', OrderItemStatus::Cancelled->value)
                ->whereNotNull('order_items.menu_item_id')
                ->groupBy('order_items.menu_item_id')
                ->orderByRaw('SUM(order_items.quantity) DESC')
                ->limit(8)
                ->pluck('order_items.menu_item_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        });

        $items = $ids === []
            ? collect()
            : MenuItem::whereIn('id', $ids)->where('is_available', true)->get(['id', 'name'])
                ->sortBy(fn ($m) => array_search($m->id, $ids))->values();

        if ($items->isEmpty()) {
            $items = MenuItem::where('is_featured', true)
                ->where('is_available', true)
                ->orderBy('display_order')
                ->limit(8)
                ->get(['id', 'name']);
        }

        return $items->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values();
    }

    /** «الجولة الماضية» — newest non-cancelled order, flattened (§6). */
    protected function lastRound($orders): ?array
    {
        $order = $orders->first();
        if (! $order) {
            return null;
        }

        $order->loadMissing('items.modifiers', 'items.exclusions');

        $lines = $order->items
            ->filter(fn ($it) => $it->status !== OrderItemStatus::Cancelled->value && $it->menu_item_id)
            ->map(fn ($it) => [
                'menu_item_id' => (int) $it->menu_item_id,
                'name' => (string) $it->name_snapshot,
                'quantity' => max(1, (int) $it->quantity),
                'modifier_ids' => $it->modifiers->pluck('modifier_id')
                    ->filter()->map(fn ($id) => (int) $id)->values()->all(),
                'modifier_labels' => $it->modifiers->pluck('name_snapshot')->filter()->values()->all(),
                'excluded_ingredient_ids' => $it->exclusions->pluck('ingredient_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
                'excluded_ingredient_labels' => $it->exclusions->pluck('name_snapshot')->filter()->values()->all(),
                'line_notes' => $it->notes,
            ])
            ->values()
            ->all();

        return $lines === [] ? null : ['number' => $order->number, 'lines' => $lines];
    }

    /** The editable snapshot shown while the waiter confirms the round. */
    protected function reviewPayload(Order $order): array
    {
        $round = OrderRoundContext::for($order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'roundNumber' => $round['number'],
            'roundLabel' => $round['label'],
            'updatedAt' => $order->updated_at?->toISOString(),
            'notes' => $order->customer_notes,
            'changes' => $order->changeRequests->map(fn ($change) => [
                'id' => $change->id,
                'type' => $change->type,
                'label' => $change->typeLabel(),
                'itemName' => $change->orderItem?->name_snapshot,
                'quantity' => $change->requested_quantity !== null
                    ? (float) $change->requested_quantity
                    : null,
                'note' => $change->request_note,
            ])->values(),
            'lines' => $order->items
                ->filter(fn ($item) => $item->status === OrderItemStatus::Pending->value && $item->menu_item_id)
                ->map(fn ($item) => [
                    'id' => 'order-'.$item->id,
                    'order_item_id' => $item->id,
                    'menu_item_id' => (int) $item->menu_item_id,
                    'name' => (string) $item->name_snapshot,
                    'unit_price' => (float) $item->unit_price,
                    'modifiers_total' => (float) $item->modifiers_total,
                    'quantity' => max(1, (int) $item->quantity),
                    'modifier_ids' => $item->modifiers->pluck('modifier_id')
                        ->filter()->map(fn ($id) => (int) $id)->values()->all(),
                    'modifier_labels' => $item->modifiers->pluck('name_snapshot')->filter()->values()->all(),
                    'excluded_ingredient_ids' => $item->exclusions->pluck('ingredient_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
                    'excluded_ingredient_labels' => $item->exclusions->pluck('name_snapshot')->filter()->values()->all(),
                    'line_notes' => $item->notes,
                    'subtotal' => (float) $item->subtotal,
                ])->values(),
        ];
    }

    /** First business problem in the cart, or null. Mirrors buildConfiguredRow's gates. */
    protected function firstLineProblem($cart): ?string
    {
        foreach ($cart as $line) {
            $item = MenuItem::with('modifierGroups.modifiers', 'recipeItems')
                ->where('is_available', true)
                ->find($line['menu_item_id']);

            if (! $item) {
                return 'صنف بالسلة لم يعد متاحاً — حدّث الصفحة.';
            }

            $allowed = $item->modifierGroups->flatMap(fn ($g) => $g->modifiers->pluck('id'))
                ->map(fn ($id) => (int) $id);
            $selected = collect($line['modifier_ids'])->intersect($allowed);
            $allowedExclusions = $item->recipeItems->pluck('ingredient_id')->map(fn ($id) => (int) $id)->unique();
            $requestedExclusions = collect($line['excluded_ingredient_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            if ($requestedExclusions->diff($allowedExclusions)->isNotEmpty()) {
                return "«{$item->name}»: مكوّن مستبعد غير موجود في الوصفة. أعد فتح الصنف.";
            }

            foreach ($item->modifierGroups as $group) {
                $count = $group->modifiers->pluck('id')->map(fn ($id) => (int) $id)
                    ->intersect($selected)->count();
                if ($group->required && $count < (int) $group->min_select) {
                    return "«{$item->name}»: اختر {$group->min_select} من {$group->name}.";
                }
                if ((int) $group->max_select > 0 && $count > (int) $group->max_select) {
                    return "«{$item->name}»: الحد الأعلى في {$group->name} هو {$group->max_select}.";
                }
            }
        }

        return null;
    }

    // ─── Session plumbing for the per-table order builder ─────────────

    protected function ensureActiveSession(Table $table): TableSession
    {
        $waiterId = auth()->user()?->role === UserRole::Waiter->value
            ? (int) auth()->id()
            : null;
        $session = $table->activeSession;
        if (! $session) {
            $session = TableSession::create([
                'branch_id' => $table->branch_id ?? BranchContext::current(),
                'table_id' => $table->id,
                'token' => Str::uuid()->toString(),
                'status' => 'active',
                'opened_at' => now(),
                'cover_count' => 1,
                'assigned_waiter_id' => $waiterId,
            ]);
        } elseif (! $session->assigned_waiter_id && $waiterId) {
            // A diner may have scanned only to browse, then asked the waiter
            // to take the order. Reuse that same session and claim it for the
            // waiter instead of creating a second bill.
            $session->update(['assigned_waiter_id' => $waiterId]);
        }

        $previousStatus = $session->engage($waiterId);
        if ($previousStatus && $previousStatus !== 'occupied') {
            $table->refresh();
            SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $previousStatus));
        }

        return $session;
    }

    /** §7 refusal shape. */
    protected function refuse(string $message, int $status = 422)
    {
        return response()->json(['ok' => false, 'message' => $message], $status);
    }
}
