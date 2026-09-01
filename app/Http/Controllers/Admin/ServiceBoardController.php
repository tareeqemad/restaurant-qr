<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\InventoryService;
use App\Services\OrderChangeRequestService;
use App\Services\OrderService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\Duration;
use App\Support\LiveRefreshPulse;
use App\Support\OrderRoundContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * مركز خدمة الجرسون — the ⚡waiter-orders-board reborn as Inertia/Vue
 * (Wave 3.ب). ONE prioritized task list merged from four kinds — pending
 * (needs approval), production (monitoring only), ready (serve now), and
 * billing (the guest asked for the check) — with the change-request queue
 * on top.
 *
 * Every priority tier is a verbatim port: red-ready (5200) always outranks
 * a late pending (4000 + capped age), monitoring sits at 700 so it never
 * buries tappable work, and age contributes at most AGE_PRIORITY_CAP so a
 * forgotten ticket can't camp at #1 forever. Lateness has ONE definition
 * (LATE_AFTER_MINUTES) shared by the hero stat and the «المتأخر» tab, so
 * the number and the list can never disagree again.
 *
 * Unused peakMode / togglePeakMode and the unrendered `served` group are
 * deliberately absent.
 */
class ServiceBoardController extends Controller
{
    /** One lateness definition for the whole board. */
    public const LATE_AFTER_MINUTES = 5;

    public const PRODUCTION_LATE_AFTER_MINUTES = 15;

    /** Age stops adding priority points past 4h. */
    private const AGE_PRIORITY_CAP_MINUTES = 240;

    protected ?array $myZonesMemo = null;

    public function show(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $this->myZonesMemo = null;

        $focus = (string) $request->query('focus', 'all');
        if (! in_array($focus, ['all', 'urgent', 'help', 'pending', 'production', 'ready', 'billing'], true)) {
            $focus = 'all';
        }
        $tableId = $request->query('table_id') ? (int) $request->query('table_id') : null;

        $groups = $this->groups($tableId);
        $tasks = $this->buildTasks($groups);
        $stockReports = $this->stockReports($groups['pending']);

        $visible = $tasks->filter(fn ($t) => $focus === 'all'
            || $t['kind'] === $focus
            || ($focus === 'urgent' && $t['urgent']))
            ->values();

        return AdminShell::render('Admin/Service/Board', [
            'tasks' => $visible->all(),
            'stats' => $this->stats($groups, $tasks),
            'changeRequests' => $this->changeRequests($tableId),
            'stockReports' => (object) $stockReports,
            'filters' => [
                'focus' => $focus,
                'tableId' => $tableId,
                'tableLabel' => $tableId ? Table::find($tableId)?->number : null,
            ],
            'live' => [
                'version' => LiveRefreshPulse::version($this->pulseBranchId()),
            ],
            'urls' => [
                'self' => route('admin.orders.index'),
                'pulse' => route('admin.orders.board-pulse'),
                'action' => route('admin.orders.board-action'),
                // The classic list has no nav entry of its own — the board
                // is its only door, same as before the migration.
                'list' => route('admin.orders.list'),
            ],
        ]);
    }

    /** Cheap poll target — has anything changed on this branch? */
    public function pulse()
    {
        $this->authorize('viewAny', Order::class);

        return response()->json(['version' => LiveRefreshPulse::version($this->pulseBranchId())]);
    }

    /**
     * One endpoint per verb. Domain guards (RuntimeException) answer 409
     * with their Arabic message — the board refreshes and shows the real
     * state that caused the conflict.
     */
    public function action(Request $request, OrderService $orders, OrderChangeRequestService $changes)
    {
        $data = $request->validate([
            'verb' => ['required', 'in:ack-help,approve,cancel-item,serve-item,serve-ready,resolve-change'],
            'order_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'session_id' => ['nullable', 'integer'],
            'request_id' => ['nullable', 'integer'],
            'decision' => ['nullable', 'in:approve,reject'],
            'disposition' => ['nullable', 'in:return,waste'],
            'expected_started' => ['nullable', 'boolean'],
        ]);

        try {
            $message = $this->runVerb($data, $orders, $changes);

            return response()->json(['ok' => true, 'message' => $message]);
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Domain guards carry Arabic messages written for this UI.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }
    }

    protected function runVerb(array $data, OrderService $orders, OrderChangeRequestService $changes): string
    {
        switch ($data['verb']) {
            case 'ack-help':
                $session = TableSession::with('table')->findOrFail((int) $data['session_id']);
                abort_unless($session->table && auth()->user()->can('view', $session->table), 403);
                $session->update([
                    'help_requested_at' => null,
                    'help_request_note' => null,
                    'help_ack_by_user_id' => auth()->id(),
                ]);
                LiveRefreshPulse::touchSession((int) $session->id);

                return 'تم — أنا ذاهب إلى طاولة '.$session->table->number.'.';

            case 'approve':
                $order = Order::with('items')->findOrFail((int) $data['order_id']);
                $this->authorize('approve', $order);
                $orders->approve($order, auth()->id());

                return 'تم اعتماد الطلب وإرساله للمطبخ والبار.';

            case 'cancel-item':
                $item = OrderItem::with('order')->findOrFail((int) $data['item_id']);
                $this->authorize('cancel', $item->order);
                $this->assertItemHasNoPendingCustomerChange($item);
                $orders->cancelItem($item, auth()->id(), 'نقص في المكونات — أُلغي من لوحة الجرسون');

                return 'تم إلغاء الصنف "'.$item->name_snapshot.'".';

            case 'serve-item':
                $item = OrderItem::with('order')->findOrFail((int) $data['item_id']);
                $this->authorize('serve', $item->order);
                $orders->markItemServed($item, auth()->id());

                return 'تم تعليم الصنف كمقدّم.';

            case 'serve-ready':
                $order = Order::with(['items', 'changeRequests' => fn ($query) => $query
                    ->where('status', OrderChangeRequest::STATUS_PENDING)])
                    ->findOrFail((int) $data['order_id']);
                $this->authorize('serve', $order);

                $wholeOrderIsPaused = $order->changeRequests->contains(
                    fn (OrderChangeRequest $request) => $request->order_item_id === null
                );
                if ($wholeOrderIsPaused) {
                    throw new \RuntimeException('يوجد طلب تغيير معلّق على الجولة. راجعه أولاً قبل التسليم.');
                }

                $pausedItemIds = $order->changeRequests
                    ->pluck('order_item_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id);
                $readyItems = $order->items
                    ->where('status', OrderItemStatus::Ready->value)
                    ->reject(fn (OrderItem $item) => $pausedItemIds->contains((int) $item->id));

                if ($readyItems->isEmpty() && $pausedItemIds->isNotEmpty()) {
                    throw new \RuntimeException('الأصناف الجاهزة متوقفة مؤقتاً بانتظار قرار طلب التغيير.');
                }

                // Batching contract: per-item broadcasts muted, then ONE
                // order-level refresh — breaking this floods every screen.
                foreach ($readyItems as $item) {
                    $orders->markItemServed($item, auth()->id(), broadcast: false);
                }
                $orders->broadcastOrderRefresh($order);

                return 'تم تقديم كل الأصناف الجاهزة في الطلب.';

            case 'resolve-change':
                $changeRequest = OrderChangeRequest::with('order')->findOrFail((int) $data['request_id']);
                $this->authorize('cancel', $changeRequest->order);
                $decision = $data['decision'] ?? 'reject';
                $changes->resolve(
                    $changeRequest,
                    (int) auth()->id(),
                    $decision,
                    $data['disposition'] ?? 'return',
                    null,
                    array_key_exists('expected_started', $data) ? (bool) $data['expected_started'] : null,
                );

                return $decision === 'approve'
                    ? 'تم تنفيذ تعديل الزبون وتحديث الطلب والمخزون.'
                    : 'تم رفض التعديل وإبلاغ الزبون.';
        }

        return '';
    }

    protected function assertItemHasNoPendingCustomerChange(OrderItem $item): void
    {
        $hasPendingChange = OrderChangeRequest::query()
            ->where('order_id', $item->order_id)
            ->where('status', OrderChangeRequest::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereNull('order_item_id')
                ->orWhere('order_item_id', $item->id))
            ->exists();

        if ($hasPendingChange) {
            throw new \RuntimeException('هذا الصنف متوقف مؤقتاً بانتظار قرار طلب التغيير.');
        }
    }

    // ── Data ─────────────────────────────────────────────────────────

    protected function groups(?int $tableId): array
    {
        $since = now()->subHours(8);

        $ordersQuery = Order::with([
            'table.zone', 'customer', 'items.station', 'items.modifiers', 'items.exclusions',
            'tableSession.assignedWaiter', 'tableSession.customer', 'tableSession.orders', 'approver',
            'changeRequests' => fn ($query) => $query
                ->where('status', OrderChangeRequest::STATUS_PENDING),
        ])
            ->where(function ($q) use ($since) {
                $q->where('created_at', '>=', $since)
                    ->orWhereIn('status', [
                        OrderStatus::Pending->value,
                        OrderStatus::Approved->value,
                        OrderStatus::Preparing->value,
                        OrderStatus::Ready->value,
                        OrderStatus::Delivered->value,
                    ]);
            })
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value]);

        $this->applyOrderRosterScope($ordersQuery);

        $orders = $ordersQuery
            ->when($tableId, fn ($q) => $q->where('table_id', $tableId))
            ->orderBy('created_at')
            ->get();

        $helpQuery = TableSession::with(['table.zone', 'customer', 'assignedWaiter'])
            ->where('status', 'active')
            ->whereNotNull('help_requested_at');
        $this->applySessionRosterScope($helpQuery);

        $billingQuery = TableSession::with(['table.zone', 'customer', 'assignedWaiter', 'orders.items', 'invoice'])
            ->where('status', 'active')
            ->whereNotNull('bill_requested_at');
        $this->applySessionRosterScope($billingQuery);

        $hasReady = fn (Order $o) => $o->items->contains(
            fn (OrderItem $i) => $i->status === OrderItemStatus::Ready->value
        );

        return [
            'help' => $helpQuery
                ->when($tableId, fn ($q) => $q->where('table_id', $tableId))
                ->orderBy('help_requested_at')
                ->get(),
            'pending' => $orders->where('status', OrderStatus::Pending->value)->values(),
            // Ready membership is ITEM-based: an approved order with one
            // ready line belongs here, not in production.
            'ready' => $orders
                ->reject(fn (Order $o) => $o->status === OrderStatus::Pending->value)
                ->filter($hasReady)
                ->values(),
            'production' => $orders
                ->whereIn('status', [OrderStatus::Approved->value, OrderStatus::Preparing->value])
                ->reject($hasReady)
                ->values(),
            // Billing cards are SESSIONS and vanish the moment a live
            // invoice exists — the cashier owns it from there.
            'billing' => $billingQuery
                ->when($tableId, fn ($q) => $q->where('table_id', $tableId))
                ->whereDoesntHave('invoice', fn ($q) => $q->whereNotIn('status', ['cancelled']))
                ->orderBy('bill_requested_at')
                ->get(),
        ];
    }

    protected function buildTasks(array $groups)
    {
        $tasks = collect();
        $cap = self::AGE_PRIORITY_CAP_MINUTES;

        foreach ($groups['help'] as $session) {
            $ageMin = (int) $session->help_requested_at->diffInMinutes(now());
            $tasks->push([
                'key' => 'help-'.$session->id,
                'kind' => 'help',
                'label' => 'نداء مباشر',
                'title' => 'طاولة '.($session->table?->number ?? '—'),
                'subtitle' => $session->help_request_note ?: 'الزبون يحتاج الجرسون الآن',
                'ageMin' => $ageMin,
                'ageLabel' => Duration::short($ageMin),
                'urgent' => true,
                'priority' => 6000 + min($ageMin, $cap),
                'sessionId' => $session->id,
                'zoneLabel' => $session->table?->zone?->label,
                'customerName' => $session->customer?->name ?: $session->customer_name,
                'waiterName' => $session->assignedWaiter?->name,
                'total' => null,
                'orderCount' => 0,
                'items' => [],
                'canApprove' => false,
                'canServe' => false,
                'canCancel' => false,
                'canAck' => true,
            ]);
        }

        foreach ($groups['pending'] as $order) {
            $ageMin = (int) $order->created_at->diffInMinutes(now());
            $urgent = $ageMin >= self::LATE_AFTER_MINUTES;
            $tasks->push($this->orderTask($order, [
                'kind' => 'pending',
                'label' => 'قبول الطلب',
                'subtitle' => $urgent ? 'متأخر عن الاعتماد' : 'طلب جديد بانتظار الاعتماد',
                'ageMin' => $ageMin,
                'urgent' => $urgent,
                'priority' => ($urgent ? 4000 : 1600) + min($ageMin, $cap),
            ]));
        }

        foreach ($groups['production'] as $order) {
            $ageMin = (int) ($order->approved_at ?: $order->created_at)->diffInMinutes(now());
            $urgent = $ageMin >= self::PRODUCTION_LATE_AFTER_MINUTES;
            $preparing = $order->items->where('status', OrderItemStatus::Preparing->value)->count();
            $approved = $order->items->where('status', OrderItemStatus::Approved->value)->count();
            $stations = $order->items->pluck('station.name')->filter()->unique()->take(2)->implode('، ');

            $tasks->push($this->orderTask($order, [
                'kind' => 'production',
                'label' => 'قيد التحضير',
                'subtitle' => trim(($stations ?: 'المحطات').' · '.($preparing > 0 ? $preparing.' قيد العمل' : $approved.' بانتظار البدء')),
                'ageMin' => $ageMin,
                'urgent' => $urgent,
                // Monitoring stays visible but never buries tappable work.
                'priority' => ($urgent ? 2700 : 700) + min($ageMin, $cap),
            ]));
        }

        foreach ($groups['ready'] as $order) {
            $readyItems = $order->items->where('status', OrderItemStatus::Ready->value);
            $readyCount = $readyItems->count();
            if ($readyCount < 1) {
                continue;
            }

            $oldestReadyAt = $readyItems->pluck('ready_at')->filter()->min();
            $ageMin = $oldestReadyAt
                ? (int) $oldestReadyAt->diffInMinutes(now())
                : (int) $order->created_at->diffInMinutes(now());

            // Same colour story as the kitchen (3/8 min). Cold food outranks
            // a late pending: the order can wait, a steak going cold can't.
            $urgency = $ageMin >= 8 ? 'red' : ($ageMin >= 3 ? 'amber' : 'green');
            $base = match ($urgency) {
                'red' => 5200,
                'amber' => 3400,
                default => 3000,
            };

            $names = $readyItems->pluck('name_snapshot');
            $namesLine = $names->take(3)->implode('، ').($names->count() > 3 ? ' +'.($names->count() - 3) : '');

            $tasks->push($this->orderTask($order, [
                'kind' => 'ready',
                'label' => $urgency === 'red' ? 'قدّم فوراً — يبرد!' : 'قدّم الآن',
                'subtitle' => $namesLine.($urgency === 'red' ? ' · على الباس منذ '.Duration::short($ageMin) : ''),
                'ageMin' => $ageMin,
                'urgent' => $ageMin >= self::LATE_AFTER_MINUTES,
                'priority' => $base + $readyCount * 10 + min($ageMin, $cap),
                'readyCount' => $readyCount,
                'readyUrgency' => $urgency,
            ]));
        }

        foreach ($groups['billing'] as $session) {
            $waitMin = (int) $session->bill_requested_at->diffInMinutes(now());
            $urgent = $waitMin >= self::LATE_AFTER_MINUTES;
            $sessionOrders = $session->orders->whereNotIn('status', ['cancelled']);

            $tasks->push([
                'key' => 'bill-'.$session->id,
                'kind' => 'billing',
                'label' => 'فاتورة',
                'title' => 'طاولة '.($session->table?->number ?? '—'),
                'subtitle' => $session->bill_request_note ?: 'الزبون ينتظر إنهاء الحساب',
                'ageMin' => $waitMin,
                'ageLabel' => Duration::short($waitMin),
                'urgent' => $urgent,
                'priority' => ($urgent ? 3800 : 2200) + min($waitMin, $cap),
                'sessionId' => $session->id,
                'zoneLabel' => $session->table?->zone?->label,
                'customerName' => $session->customer?->name ?: $session->customer_name,
                'waiterName' => $session->assignedWaiter?->name,
                'total' => Money::format($sessionOrders->sum('total')),
                'orderCount' => $sessionOrders->count(),
                'cashierUrl' => route('admin.cashier.show', $session),
                'items' => [],
                'canApprove' => false,
                'canServe' => false,
            ]);
        }

        return $tasks->sortByDesc('priority')->values();
    }

    /** Shared shape for the three order-backed task kinds. */
    protected function orderTask(Order $order, array $extra): array
    {
        $round = OrderRoundContext::for($order);
        $activeItems = $order->items
            ->reject(fn ($item) => $item->status === OrderItemStatus::Cancelled->value);
        $pieceCount = $activeItems->sum(fn ($item) => (float) $item->quantity);
        $stations = $activeItems
            ->groupBy(fn ($item) => $item->station?->name ?: 'خدمة مباشرة')
            ->map(fn ($items, $name) => [
                'name' => $name,
                'pieces' => $this->fmtQty((float) $items->sum(fn ($item) => (float) $item->quantity)),
            ])
            ->values()
            ->all();

        return array_merge([
            'key' => 'order-'.$order->id,
            'orderId' => $order->id,
            'number' => $order->number,
            'title' => $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel(),
            'ageLabel' => Duration::short($extra['ageMin'] ?? 0),
            'zoneLabel' => $order->table?->zone?->label,
            'customerName' => $order->customer_name ?: $order->customer?->name,
            'waiterName' => $order->tableSession?->assignedWaiter?->name,
            'roundNumber' => $round['number'],
            'roundLabel' => $round['label'],
            'isAddition' => $round['isAddition'],
            'lineCount' => $activeItems->count(),
            'pieceCount' => $this->fmtQty((float) $pieceCount),
            'stations' => $stations,
            'hasPendingChange' => $order->changeRequests->isNotEmpty(),
            'notes' => trim((string) ($order->customer_notes ?? '')),
            'total' => Money::format($order->total),
            'external' => $order->isOffTable() ? [
                'sourceLabel' => $order->sourceLabel(),
                'sourceIcon' => $order->sourceIcon(),
                'typeLabel' => $order->order_source === 'phone'
                    ? 'طلب هاتفي'
                    : ($order->order_type === 'delivery' ? 'طلب خارجي' : 'استلام من المطعم'),
            ] : null,
            // Policy AND status: OrderPolicy::approve is role+branch only, so
            // without the status check an already-approved card would ship
            // canApprove=true — a flag that reads as "you may approve this"
            // on a ticket the service would reject.
            'canApprove' => $order->status === OrderStatus::Pending->value
                && auth()->user()->can('approve', $order),
            'reviewUrl' => $order->table_id
                ? route('admin.waiter-orders.create', [
                    'table' => $order->table_id,
                    'review_order' => $order->id,
                ])
                : route('admin.orders.show', $order),
            'canServe' => auth()->user()->can('serve', $order),
            'canCancel' => auth()->user()->can('cancel', $order),
            'canAck' => false,
            'readyCount' => 0,
            'readyUrgency' => null,
            'sessionId' => null,
            'items' => $activeItems
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name_snapshot,
                    'qty' => $this->fmtQty((float) $i->quantity),
                    'status' => $i->status,
                    'stationName' => $i->station?->name,
                    'notes' => $i->notes,
                    'mods' => $i->modifiers->pluck('name_snapshot')->filter()->values()->all(),
                    'exclusions' => $i->exclusions->pluck('name_snapshot')->filter()->values()->all(),
                    'subtotal' => Money::format($i->subtotal),
                ])->values()->all(),
        ], $extra);
    }

    protected function stats(array $groups, $tasks): array
    {
        // Ready ORDERS counted by their oldest ready item's age, on the
        // kitchen's 3/8 thresholds — the sound system chimes on transitions.
        $cold = 0;
        $hot = 0;
        foreach ($groups['ready'] as $order) {
            $oldest = $order->items->where('status', OrderItemStatus::Ready->value)
                ->pluck('ready_at')->filter()->min();
            if (! $oldest) {
                continue;
            }
            $ageMin = (int) $oldest->diffInMinutes(now());
            if ($ageMin >= 8) {
                $hot++;
            } elseif ($ageMin >= 3) {
                $cold++;
            }
        }

        return [
            'help' => $groups['help']->count(),
            'pending' => $groups['pending']->count(),
            // Same definition as the «المتأخر» tab — they must always agree.
            'urgent' => $tasks->filter(fn ($t) => $t['urgent'])->count(),
            'production' => $groups['production']->count(),
            'readyItems' => $groups['ready']->sum(fn ($o) => $o->items
                ->where('status', OrderItemStatus::Ready->value)->count()),
            'readyOrders' => $groups['ready']->count(),
            'readyCold' => $cold,
            'readyHot' => $hot,
            'billing' => $groups['billing']->count(),
        ];
    }

    /**
     * Shortages across the pending column, keyed by order id — lets the
     * board block «اعتماد» BEFORE the tap and point at the exact lines to
     * cancel. The service re-validates on approve anyway (a shortage can
     * appear between render and click).
     */
    protected function stockReports($pending): array
    {
        $reports = [];
        foreach ($pending as $order) {
            $report = app(InventoryService::class)->orderStockReport($order);
            if (! empty($report['issues'])) {
                $reports[$order->id] = [
                    'issues' => collect($report['issues'])->map(fn ($i) => [
                        'ingredient' => $i['ingredient'],
                        'available' => $i['available'],
                        'required' => $i['required'],
                    ])->values()->all(),
                    'shortItemIds' => $report['short_item_ids'] ?? [],
                ];
            }
        }

        return $reports;
    }

    protected function changeRequests(?int $tableId): array
    {
        $query = OrderChangeRequest::with([
            'order.table.zone', 'order.tableSession.customer', 'order.tableSession.orders',
            'order.items.station', 'orderItem.station',
        ])
            ->where('status', OrderChangeRequest::STATUS_PENDING);

        if ($this->isRosteredWaiter()) {
            $query->whereHas('order', fn ($order) => $this->applyOrderRosterScope($order));
        }

        return $query
            ->when($tableId, fn ($q) => $q->whereHas('order', fn ($o) => $o->where('table_id', $tableId)))
            ->oldest()
            ->get()
            ->map(function (OrderChangeRequest $cr) {
                $round = OrderRoundContext::for($cr->order);
                // Prep started → the ingredients may not be returnable, so
                // the waiter must choose return vs waste.
                $scope = $cr->type === 'cancel_order'
                    ? $cr->order->items
                    : collect([$cr->orderItem])->filter();
                $started = $scope->contains(fn ($i) => in_array($i->status, ['preparing', 'ready', 'served'], true));
                $ready = $scope->contains(fn ($i) => in_array($i->status, ['ready', 'served'], true));
                $stationNames = $scope->pluck('station.name')->filter()->unique()->values();
                $statusLabels = $scope->pluck('status')->unique()->map(fn ($status) => match ($status) {
                    'pending' => 'بانتظار الاعتماد',
                    'approved' => 'وصل للمحطة ولم يبدأ',
                    'preparing' => 'بدأ التحضير',
                    'ready' => 'جاهز',
                    'served' => 'تم تقديمه',
                    default => $status,
                });

                return [
                    'id' => $cr->id,
                    'typeLabel' => $cr->typeLabel(),
                    'orderNumber' => $cr->order->number,
                    'roundNumber' => $round['number'],
                    'roundLabel' => $round['label'],
                    'title' => $cr->order->table ? 'طاولة '.$cr->order->table->number : $cr->order->sourceLabel(),
                    'askedAgo' => $cr->created_at->diffForHumans(),
                    'itemName' => $cr->orderItem?->name_snapshot,
                    'itemQty' => $cr->orderItem ? $this->fmtQty((float) $cr->orderItem->quantity) : null,
                    'requestedQuantity' => $cr->type === 'change_item' ? $cr->requested_quantity : null,
                    'note' => $cr->request_note,
                    'started' => $started,
                    'ready' => $ready,
                    'stationName' => $stationNames->isEmpty() ? 'خارج محطات التحضير' : $stationNames->join(' + '),
                    'statusLabel' => $statusLabels->join(' + '),
                    'canResolve' => auth()->user()->can('cancel', $cr->order),
                ];
            })->values()->all();
    }

    /**
     * Roster scope is a workload lens, not an authorization boundary. Phone
     * orders stay visible to every waiter because they have no floor section;
     * a previously claimed session also follows its waiter after a table move.
     */
    protected function applyOrderRosterScope($query): void
    {
        if (! $this->isRosteredWaiter()) {
            return;
        }

        $zoneIds = $this->myZoneIds();
        $me = (int) auth()->id();

        $query->where(function ($scope) use ($zoneIds, $me) {
            $scope->whereNull('table_id')
                ->orWhereHas('table', fn ($table) => $table->whereIn('zone_lookup_id', $zoneIds))
                ->orWhereHas('tableSession', fn ($session) => $session->where('assigned_waiter_id', $me));
        });
    }

    protected function applySessionRosterScope($query): void
    {
        if (! $this->isRosteredWaiter()) {
            return;
        }

        $zoneIds = $this->myZoneIds();
        $me = (int) auth()->id();

        $query->where(function ($scope) use ($zoneIds, $me) {
            $scope->where('assigned_waiter_id', $me)
                ->orWhereHas('table', fn ($table) => $table->whereIn('zone_lookup_id', $zoneIds));
        });
    }

    protected function isRosteredWaiter(): bool
    {
        return auth()->user()?->role === UserRole::Waiter->value
            && $this->myZoneIds() !== [];
    }

    protected function myZoneIds(): array
    {
        return $this->myZonesMemo ??= SectionAssignment::zoneIdsFor((int) auth()->id());
    }

    protected function pulseBranchId(): ?int
    {
        $branchId = BranchContext::current();

        return $branchId ? (int) $branchId : null;
    }

    protected function fmtQty(float $qty): string
    {
        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}
