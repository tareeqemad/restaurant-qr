<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\OrderChangeRequest;
use App\Models\OrderItem;
use App\Models\Station;
use App\Services\NotifyService;
use App\Services\OrderService;
use App\Support\LiveRefreshPulse;
use App\Support\OrderRoundContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * KDS v4 — the ⚡kitchen-board reborn as Inertia/Vue (Wave 3).
 *
 * Every behavioral decision preserves the KDS workflow: three equal stages
 * (waiting, cooking, ready for waiter hand-off), station-tuned urgency
 * thresholds, per-card ETA anchored to the card's OWN lines, the orphan
 * safety net on the primary board, the batching contract (mute per-item
 * broadcasts, ONE order-level refresh), and the undo windows (60s start /
 * 120s ready) — shipped as REMAINING seconds so device clocks can't
 * stretch them.
 *
 * The Vue page renders chrome-free on the Dashtic root (wall-mode is now
 * the ONLY mode — kitchen tablets never needed the admin nav), with the
 * kb-* design system coming from relax-components.css unchanged.
 */
class KitchenBoardController extends Controller
{
    protected ?Station $station = null;

    protected ?bool $primaryBoard = null;

    public function show(Request $request, string $code)
    {
        $station = $this->resolveStation($code);

        $sort = (string) $request->query('sort', 'time');
        if (! in_array($sort, ['time', 'urgency', 'table'], true)) {
            $sort = 'time';
        }
        $filterTable = trim((string) $request->query('table', ''));

        Inertia::setRootView('inertia-admin');

        return Inertia::render('Admin/Kitchen/Board', [
            'station' => [
                'code' => $station->code,
                'name' => $station->name,
                'color' => $station->color ?: '#1f4733',
                'emoji' => match ($station->code) {
                    'kitchen' => '🍳',
                    'bar' => '🍹',
                    'dessert' => '🍰',
                    'grill' => '🔥',
                    default => '🍽️',
                },
            ],
            'board' => $this->boardPayload($station, $sort, $filterTable),
            'filters' => ['sort' => $sort, 'table' => $filterTable],
            'live' => [
                'version' => LiveRefreshPulse::version($this->stationBranchId($station)),
            ],
            'urls' => [
                'self' => route('admin.station.show', $station->code),
                'pulse' => route('admin.station.pulse', $station->code),
                'action' => route('admin.station.action', $station->code),
                'home' => route('admin.dashboard'),
            ],
        ]);
    }

    /** Cheap poll target, gated by the same station permission. */
    public function pulse(string $code)
    {
        $station = $this->resolveStation($code);

        return response()->json(['version' => LiveRefreshPulse::version($this->stationBranchId($station))]);
    }

    /**
     * One endpoint, one verb per request. A stale tap (RuntimeException from a workflow guard)
     * answers 409 with the Arabic domain message; real authorization
     * failures keep their status codes.
     */
    public function action(Request $request, string $code, OrderService $service)
    {
        $station = $this->resolveStation($code);

        $data = $request->validate([
            'verb' => ['required', 'in:start,ready,undo-start,undo-ready,start-all,ready-all,cancel-item'],
            'item_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'disposition' => ['nullable', 'in:return,waste'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $message = $this->runVerb($station, $data, $service);

            return response()->json(['ok' => true, 'message' => $message]);
        } catch (HttpExceptionInterface $e) {
            // Real authorization failures (403/404) keep their status page.
            throw $e;
        } catch (\RuntimeException $e) {
            // Stale tap — the line moved on another screen. Domain message
            // is written for the UI (Arabic); the client refreshes after.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'حدث خطأ غير متوقع — أعد المحاولة أو حدّث الصفحة.'], 500);
        }
    }

    protected function runVerb(Station $station, array $data, OrderService $service): ?string
    {
        $itemFor = fn () => $this->scopeToBoard($station, OrderItem::whereKey((int) ($data['item_id'] ?? 0)))->first();

        switch ($data['verb']) {
            case 'start':
                if ($item = $itemFor()) {
                    $this->ensureStationAccess($station, $item);
                    $service->startPreparing($item, auth()->id());
                }

                return null;

            case 'ready':
                if ($item = $itemFor()) {
                    $this->ensureStationAccess($station, $item);
                    $service->markItemReady($item);
                }

                return null;

            case 'undo-ready':
                if ($item = $itemFor()) {
                    $this->ensureStationAccess($station, $item);
                    $service->revertItemReady($item, auth()->id());
                }

                return null;

            case 'undo-start':
                if ($item = $itemFor()) {
                    $this->ensureStationAccess($station, $item);
                    $service->revertItemStart($item, auth()->id());
                }

                return null;

            case 'start-all':
                $this->ensureStationAccess($station);
                $items = $this->scopeToBoard($station, OrderItem::where('order_id', (int) $data['order_id']))
                    ->where('status', OrderItemStatus::Approved->value)
                    ->whereDoesntHave('changeRequests', fn ($query) => $query
                        ->where('status', OrderChangeRequest::STATUS_PENDING))
                    ->whereDoesntHave('order.changeRequests', fn ($query) => $query
                        ->where('status', OrderChangeRequest::STATUS_PENDING)
                        ->whereNull('order_item_id'))
                    ->get();

                // broadcast=false per line, ONE order-level refresh — the
                // batching contract (see OrderService::broadcastOrderRefresh).
                foreach ($items as $i) {
                    $service->startPreparing($i, auth()->id(), false);
                }
                if ($first = $items->first()) {
                    $service->broadcastOrderRefresh($first->order);
                }

                return null;

            case 'ready-all':
                $this->ensureStationAccess($station);
                // Approved lines included on purpose: they walk through
                // preparing first so timing stamps and the 'preparing'
                // deduction stage stay consistent.
                $items = $this->scopeToBoard($station, OrderItem::where('order_id', (int) $data['order_id']))
                    ->whereIn('status', [OrderItemStatus::Approved->value, OrderItemStatus::Preparing->value])
                    ->whereDoesntHave('changeRequests', fn ($query) => $query
                        ->where('status', OrderChangeRequest::STATUS_PENDING))
                    ->whereDoesntHave('order.changeRequests', fn ($query) => $query
                        ->where('status', OrderChangeRequest::STATUS_PENDING)
                        ->whereNull('order_item_id'))
                    ->get();

                foreach ($items as $i) {
                    if ($i->status === OrderItemStatus::Approved->value) {
                        $i = $service->startPreparing($i, auth()->id(), false);
                    }
                    $service->markItemReady($i, false);
                }
                if ($first = $items->first()) {
                    $service->broadcastOrderRefresh($first->order);
                    app(NotifyService::class)->orderReady(
                        $first->order->refresh()->load('items.station', 'table')
                    );
                }

                return null;

            case 'cancel-item':
                $item = $itemFor();
                if (! $item) {
                    return null;
                }
                $this->ensureStationAccess($station, $item);
                $this->assertItemHasNoPendingCustomerChange($item);

                $disposition = in_array($data['disposition'] ?? '', ['return', 'waste'], true)
                    ? $data['disposition'] : 'return';

                $service->cancelItem(
                    item: $item,
                    userId: auth()->id(),
                    reason: ($data['reason'] ?? null) ?: ($disposition === 'waste'
                        ? 'إلغاء من المطبخ — بدأ التحضير'
                        : 'إلغاء من المطبخ — لم يبدأ التحضير'),
                    disposition: $disposition,
                    wasteReason: $disposition === 'waste' ? 'إلغاء أثناء التحضير من المطبخ' : null,
                );

                return $disposition === 'waste'
                    ? "أُلغي «{$item->name_snapshot}» وسُجِّلت المكوّنات كهدر."
                    : "أُلغي «{$item->name_snapshot}» وأُعيدت المكوّنات للمخزون.";
        }

        return null;
    }

    // ── Board payload — verbatim v3 semantics ────────────────────────

    protected function boardPayload(Station $station, string $sort, string $filterTable): array
    {
        $rows = $this->scopeToBoard($station,
            OrderItem::with([
                'order.table',
                'order.customer',
                'order.tableSession.orders',
                'order.tableSession.assignedWaiter:id,name,role,status',
                'order.approver:id,name,role,status',
                'order.changeRequests' => fn ($query) => $query
                    ->where('status', OrderChangeRequest::STATUS_PENDING),
                'order.changeRequests.orderItem',
                'changeRequests' => fn ($query) => $query
                    ->where('status', OrderChangeRequest::STATUS_PENDING),
                'modifiers',
                'exclusions',
                'menuItem:id,prep_time_minutes',
            ])
        )
            ->whereIn('status', [
                OrderItemStatus::Approved->value,
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
            ])
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->orderBy('approved_at')
            ->get();

        // Instant lines (auto-readied, no station, no prep clock) are
        // fulfilled outside the kitchen — keep them off the board entirely.
        $rows = $rows->reject(fn ($it) => $it->status === OrderItemStatus::Ready->value
            && $it->station_id === null
            && $it->prep_started_at === null);

        if ($filterTable !== '') {
            // EXACT number match — substring matching caught tables 10-19
            // when filtering for table 1.
            $rows = $rows->filter(function ($it) use ($filterTable) {
                $num = trim((string) ($it->order?->table?->number ?? ''));

                return is_numeric($num) && is_numeric($filterTable)
                    ? (int) $num === (int) $filterTable
                    : $num === $filterTable;
            });
        }

        $waiting = collect();
        $cooking = collect();
        $ready = collect();

        foreach ($rows->groupBy('order_id') as $items) {
            $first = $items->first();
            $order = $first->order;
            if (! $order) {
                continue;
            }

            $statuses = $items->pluck('status')->unique()->values();
            $bucket = $statuses->contains(OrderItemStatus::Approved->value) ? 'waiting'
                : ($statuses->contains(OrderItemStatus::Preparing->value) ? 'cooking' : 'ready');

            // Ready age = time sitting on the pass (ready_at), not order age —
            // otherwise the strip stays green while food goes cold.
            if ($bucket === 'ready') {
                $oldestReadyAt = $items->pluck('ready_at')->filter()->min();
                $ageMin = $oldestReadyAt ? (int) $oldestReadyAt->diffInMinutes(now())
                    : (int) $first->approved_at?->diffInMinutes(now());
            } else {
                $ageMin = (int) $first->approved_at?->diffInMinutes(now());
            }

            // Per-card ETA from THIS card's own lines — a bar ticket must
            // never show the kitchen's deadline.
            $etaMin = null;
            $etaLabel = null;
            if ($bucket === 'cooking') {
                $started = $items->pluck('prep_started_at')->filter();
                $maxPrep = (int) $items->max(fn ($i) => (int) ($i->menuItem?->prep_time_minutes ?? 0));
                if ($started->isNotEmpty() && $maxPrep > 0) {
                    $etaAt = $started->min()->copy()->addMinutes($maxPrep);
                    $etaMin = (int) round(now()->diffInMinutes($etaAt, false));
                    $etaLabel = $etaAt->format('H:i');
                }
            }

            $urgency = $this->urgency($station, $ageMin, $bucket);
            $isOffTable = $order->isOffTable();
            $round = OrderRoundContext::for($order);
            $waiter = $order->tableSession?->assignedWaiter;
            if (! $waiter
                && $order->approver?->role === UserRole::Waiter->value
                && $order->approver?->status === 'active') {
                $waiter = $order->approver;
            }
            $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id);
            $pendingChange = $order->changeRequests->first(fn (OrderChangeRequest $request) => $request->order_item_id === null || $itemIds->contains((int) $request->order_item_id)
            );

            $card = [
                'orderId' => $order->id,
                'number' => $order->number,
                'tableNum' => (int) ($order->table?->number ?? 0),
                'roundNumber' => $round['number'],
                'roundLabel' => $round['label'],
                'isAddition' => $round['isAddition'],
                'pieceCount' => $this->fmtQty((float) $items->sum(fn ($item) => (float) $item->quantity)),
                'notes' => trim((string) ($order->customer_notes ?? '')),
                'ageMin' => $ageMin,
                'urgency' => $urgency,
                'urgencyRank' => ['red' => 4, 'orange' => 3, 'amber' => 2, 'green' => 1][$urgency] ?? 0,
                'etaMin' => $etaMin,
                'etaLabel' => $etaLabel,
                'flash' => (bool) $items->min('approved_at')?->gt(now()->subSeconds(15)),
                'handoff' => [
                    'waiterId' => $waiter?->id,
                    'waiterName' => $waiter?->name,
                    'recipientLabel' => $waiter
                        ? 'الجرسون '.$waiter->name
                        : ($order->table_id ? 'جرسون القسم' : 'فريق الصالة'),
                ],
                'changeRequest' => $pendingChange ? [
                    'id' => $pendingChange->id,
                    'type' => $pendingChange->type,
                    'typeLabel' => $pendingChange->typeLabel(),
                    'itemId' => $pendingChange->order_item_id ? (int) $pendingChange->order_item_id : null,
                    'note' => $pendingChange->request_note,
                    'requestedQuantity' => $pendingChange->requested_quantity,
                    'itemName' => $pendingChange->orderItem?->name_snapshot,
                    'wholeOrder' => $pendingChange->order_item_id === null,
                ] : null,
                'external' => $isOffTable ? [
                    'sourceLabel' => $order->sourceLabel(),
                    'sourceIcon' => $order->sourceIcon(),
                    'sourceColor' => $order->sourceColor(),
                    'typeLabel' => $order->order_source === 'phone'
                        ? 'طلب هاتفي'
                        : ($order->order_type === 'delivery' ? 'طلب خارجي' : 'استلام من المطعم'),
                    'customerName' => trim((string) ($order->customer_name ?: $order->customer?->name ?: '')),
                    'customerPhone' => trim((string) ($order->customer_phone ?: $order->customer?->phone ?: '')),
                    'address' => $order->order_type === 'delivery'
                        ? Str::limit(trim((string) ($order->delivery_address ?? '')), 90)
                        : '',
                    'scheduledAt' => $order->scheduled_for?->format('H:i'),
                ] : null,
                'items' => $items->map(fn ($it) => $this->itemPayload($it, $pendingChange))->values()->all(),
            ];

            match ($bucket) {
                'waiting' => $waiting->push($card),
                'cooking' => $cooking->push($card),
                'ready' => $ready->push($card),
            };
        }

        $waiting = $this->sortColumn($waiting, $sort);
        $cooking = $this->sortColumn($cooking, $sort);
        $ready = $this->sortColumn($ready, $sort);

        $active = $waiting->merge($cooking);
        $cards = $active->merge($ready);

        // All-day batching: identical dishes summed across open tickets.
        $allDay = $active->flatMap(fn ($c) => $c['items'])
            ->filter(fn ($i) => ! $i['changePending'] && in_array($i['status'], ['approved', 'preparing'], true))
            ->groupBy('name')
            ->map(fn ($g, $name) => ['name' => $name, 'qty' => $this->fmtQty($g->sum(fn ($i) => (float) $i['rawQty']))])
            ->values()->all();

        // Tables with >1 open ticket get the follow-up chip.
        $followUp = $active->pluck('tableNum')->filter()
            ->countBy()->filter(fn ($n) => $n > 1)->keys()->all();

        $activeItems = $cards->sum(fn ($c) => count($c['items']));
        $activeTickets = $active->count();
        $totalTickets = $cards->count();

        return [
            'waiting' => $waiting->values()->all(),
            'cooking' => $cooking->values()->all(),
            'ready' => $ready->values()->all(),
            'followUpTables' => $followUp,
            'allDay' => $allDay,
            'activeTables' => $cards->pluck('tableNum')->filter()->unique()->sort()->values()->all(),
            'orderIds' => $active->pluck('orderId')->values()->all(),
            'readyIds' => $ready->pluck('orderId')->values()->all(),
            'changeRequestIds' => $cards->pluck('changeRequest.id')->filter()->unique()->values()->all(),
            'load' => [
                // Load tracks TICKET count — 8/14 is where one cook drowns.
                'level' => match (true) {
                    $activeTickets >= 14 => 'red',
                    $activeTickets >= 8 => 'amber',
                    default => 'green',
                },
                'activeItems' => $activeItems,
                'activeTickets' => $activeTickets,
                'totalTickets' => $totalTickets,
                'waitingTickets' => $waiting->count(),
                'cookingTickets' => $cooking->count(),
                'readyTickets' => $ready->count(),
                'oldestAge' => (int) ($cards->max('ageMin') ?? 0),
                'redCards' => $cards->where('urgency', 'red')->count(),
            ],
        ];
    }

    protected function itemPayload(OrderItem $it, ?OrderChangeRequest $pendingChange = null): array
    {
        $prepMin = (int) ($it->menuItem?->prep_time_minutes ?? 0);
        $elapsedMin = null;
        $delay = '';
        if ($it->status === 'preparing' && $it->prep_started_at) {
            $elapsedMin = (int) max(0, round($it->prep_started_at->diffInMinutes(now())));
            if ($prepMin > 0) {
                $ratio = $elapsedMin / $prepMin;
                $delay = match (true) {
                    $ratio >= 1.0 => 'red',
                    $ratio >= 0.8 => 'amber',
                    default => 'ok',
                };
            }
        }

        // Undo windows shipped as REMAINING seconds (device-anchored client-side).
        $undoStart = null;
        if ($it->status === 'preparing' && $it->prep_started_at) {
            $undoStart = max(0, 60 - (int) $it->prep_started_at->diffInSeconds(now()));
        }
        $undoReady = null;
        if ($it->status === 'ready') {
            $readyAgo = ($it->ready_at ?? $it->updated_at)?->diffInSeconds(now());
            $undoReady = $readyAgo !== null ? max(0, 120 - (int) $readyAgo) : null;
        }

        return [
            'id' => $it->id,
            'name' => $it->name_snapshot,
            'qty' => $this->fmtQty((float) $it->quantity),
            'rawQty' => (float) $it->quantity,
            'status' => $it->status,
            'changePending' => (bool) ($pendingChange
                && ($pendingChange->order_item_id === null || (int) $pendingChange->order_item_id === (int) $it->id)),
            'notes' => $it->notes,
            'exclusions' => $it->exclusions->pluck('name_snapshot')->filter()->values()->all(),
            'orphan' => $it->station_id === null,
            'prepMin' => $prepMin,
            'elapsedMin' => $elapsedMin,
            'delay' => $delay,
            'undoStartRemaining' => $undoStart,
            'undoReadyRemaining' => $undoReady,
            // Removals tint red, additions green — a wrong chip is a remade
            // plate or an allergy incident.
            'mods' => $it->modifiers->map(fn ($m) => [
                'name' => trim((string) $m->name_snapshot),
                'kind' => preg_match('/^(بدون|بلا|من غير)/u', trim((string) $m->name_snapshot)) ? 'remove'
                    : (preg_match('/^(زيادة|اضافة|إضافة)/u', trim((string) $m->name_snapshot)) ? 'extra' : null),
            ])->values()->all(),
        ];
    }

    // ── Helpers — verbatim ports ─────────────────────────────────────

    protected function resolveStation(string $code): Station
    {
        if (! $this->station || $this->station->code !== $code) {
            $this->station = Station::where('code', $code)->firstOrFail();
            $this->primaryBoard = null;
        }

        // OUTSIDE the memo guard: the router caches controller instances per
        // route (tests, Octane), so a memoized station from user A's request
        // must never skip user B's authorization. (primaryBoard is safe to
        // memo — it depends on the station alone, never on the user.)
        abort_unless(auth()->user()?->canAccessStation($this->station->code), 403);

        return $this->station;
    }

    /**
     * The branch's PRIMARY board ('kitchen' by convention, else the first
     * active station) doubles as the safety net for orphan tickets.
     */
    protected function isPrimaryBoard(Station $station): bool
    {
        if ($this->primaryBoard !== null) {
            return $this->primaryBoard;
        }

        if ($station->code === 'kitchen') {
            return $this->primaryBoard = true;
        }

        if (Station::where('code', 'kitchen')->where('active', true)->exists()) {
            return $this->primaryBoard = false;
        }

        $firstId = Station::where('active', true)
            ->orderBy('display_order')->orderBy('id')
            ->value('id');

        return $this->primaryBoard = ((int) $firstId === (int) $station->id);
    }

    /**
     * This board's tickets: the station's own items plus — on the PRIMARY
     * board only — orphans (station_id NULL) pinned to the station's own
     * branch. Outer whereHas('order') applies Order's BranchScope as
     * defense in depth for branch-scoped users.
     */
    protected function scopeToBoard(Station $station, $query)
    {
        return $query->where(function ($q) use ($station) {
            $q->where('station_id', $station->id);
            if ($this->isPrimaryBoard($station)) {
                $q->orWhere(function ($orphan) use ($station) {
                    $orphan->whereNull('station_id')
                        ->whereHas('order', fn ($o) => $o->where('branch_id', $this->stationBranchId($station)));
                });
            }
        })->whereHas('order');
    }

    protected function ensureStationAccess(Station $station, ?OrderItem $item = null): void
    {
        abort_unless(auth()->user()?->canAccessStation($station->code), 403);

        if ($item) {
            // Orphans are actionable from the primary board only.
            $ownsItem = (int) $item->station_id === (int) $station->id
                || ($item->station_id === null && $this->isPrimaryBoard($station));
            abort_unless($ownsItem, 404);
        }
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

    protected function stationBranchId(Station $station): int
    {
        return (int) Station::withoutGlobalScopes()->whereKey($station->id)->value('branch_id');
    }

    protected function urgency(Station $station, int $ageMin, string $bucket): string
    {
        // Ready escalates on a tighter clock — cooked food loses heat fast.
        if ($bucket === 'ready') {
            if ($ageMin >= 8) {
                return 'red';
            }

            return $ageMin >= 3 ? 'amber' : 'green';
        }

        $t = $station->code === 'bar'
            ? ['amber' => 2, 'orange' => 5, 'red' => 10]   // drinks
            : ['amber' => 3, 'orange' => 8, 'red' => 15];  // food

        if ($ageMin >= $t['red']) {
            return 'red';
        }
        if ($ageMin >= $t['orange']) {
            return 'orange';
        }

        return $ageMin >= $t['amber'] ? 'amber' : 'green';
    }

    protected function sortColumn($cards, string $sort)
    {
        return match ($sort) {
            'urgency' => $cards->sortBy([
                ['urgencyRank', 'desc'],
                ['ageMin', 'desc'],
            ])->values(),
            'table' => $cards->sortBy([
                ['tableNum', 'asc'],
                ['ageMin', 'desc'],
            ])->values(),
            default => $cards->sortByDesc('ageMin')->values(),
        };
    }

    /** «1.50» never leaks: whole numbers print «2», fractional keep decimals. */
    protected function fmtQty(float $qty): string
    {
        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }
}
