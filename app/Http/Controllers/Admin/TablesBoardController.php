<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\SectionAssignment;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\OrderService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\Duration;
use App\Support\LiveRefreshPulse;
use App\Support\OrderRoundContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Tables board v5 — the v4 triage board (⚡tables-board.blade.php) reborn as
 * Inertia/Vue. Wave 1 of the migration (MIGRATION-PILOT.md §13).
 *
 * Behavioral contract:
 * one fully visible, server-ordered priority feed, the KDS-graded load chip,
 * roster-first "mine" scoping with claim-based fallback, the stale lens,
 * and zero-exposure idle closing.
 * The comments explaining WHY live with the original decisions — see the
 * git history of the Volt component for the war stories; this file keeps
 * only what the reader needs to not break parity.
 *
 * The screen refreshes via Inertia partial reloads: Echo events and the 15s
 * poll both funnel through the client's useLiveRefresh throttle, and the
 * poll checks GET …/board/pulse (LiveRefreshPulse version) first so an idle
 * floor costs one tiny JSON read instead of a full payload rebuild.
 */
class TablesBoardController extends Controller
{
    /** KDS-borrowed floor-load thresholds for the load chip. */
    public const LOAD_BUSY = 8;

    public const LOAD_SLAMMED = 14;

    protected ?array $myZonesMemo = null;

    protected array $permMemo = [];

    protected ?bool $canServeMemo = null;

    /**
     * The router caches controller instances per Route within one process
     * (tests, Octane) — so per-REQUEST memos must be reset at every entry
     * point that uses them, or one user's resolved permissions leak into
     * the next request's rows. Controller reuse makes an explicit reset
     * necessary.
     */
    protected function resetRequestMemos(): void
    {
        $this->myZonesMemo = null;
        $this->permMemo = [];
        $this->canServeMemo = null;
    }

    public function show(Request $request)
    {
        $this->authorize('viewAny', Table::class);
        $this->resetRequestMemos();

        $search = trim((string) $request->query('q', ''));
        $view = (string) $request->query('view', '');
        $lens = (string) $request->query('lens', '');

        if (! in_array($view, ['mine', 'all'], true) && ! ctype_digit($view)) {
            $view = '';
        }
        if ($view === '') {
            // An unrostered waiter opens honestly on the whole floor — "قسمي"
            // without a roster is a lie (claim-fallback ≈ the whole floor).
            $view = ($this->isWaiter() && $this->myZoneIds() !== []) ? 'mine' : 'all';
        }
        if ($lens !== 'stale') {
            $lens = '';
        }

        $rows = $this->rows($search, $view);

        $triage = $rows
            ->filter(fn ($r) => $r['triage'] !== null)
            ->filter(fn ($r) => $lens === 'stale'
                ? $this->isHousekeeping($r)
                : ! $this->isHousekeeping($r))
            ->sort(function ($a, $b) {
                $rank = $b['triage']['rank'] <=> $a['triage']['rank'];
                if ($rank !== 0) {
                    return $rank;
                }
                $at = $a['triage']['sinceTs'] ?? PHP_INT_MAX;
                $bt = $b['triage']['sinceTs'] ?? PHP_INT_MAX;

                return $at <=> $bt; // oldest waiting first
            })
            ->values();

        $byUrgency = $triage->groupBy(fn ($r) => $r['triage']['urgency']);

        $actionCount = $rows->filter(fn ($r) => $r['triage'] && ! $this->isHousekeeping($r))->count();
        $staleCount = $rows->filter(fn ($r) => $r['triage'] && $this->isHousekeeping($r))->count();
        $staleClosable = $rows->filter(fn ($r) => ($r['triage']['type'] ?? null) === 'idle'
            && ($r['triage']['action']['kind'] ?? null) === 'close')->count();

        $zoneCounts = Table::query()
            ->whereNotNull('zone_lookup_id')
            ->selectRaw('zone_lookup_id, COUNT(*) as cnt')
            ->groupBy('zone_lookup_id')
            ->pluck('cnt', 'zone_lookup_id');

        return AdminShell::render('Admin/Tables/Board', [
            'board' => [
                'rows' => $rows->values()->all(),
                'priorityIds' => $triage->pluck('id')->values()->all(),
                'hotIds' => $byUrgency->get('red', collect())->pluck('id')->values()->all(),
                'sections' => $rows
                    ->groupBy(fn ($r) => $r['zoneLabel'] ?? 'بدون قسم')
                    ->map(fn ($group, $label) => ['label' => $label, 'ids' => $group->pluck('id')->all()])
                    ->values()->all(),
                'redIds' => $rows
                    ->filter(fn ($r) => ($r['triage']['urgency'] ?? null) === 'red')
                    ->pluck('id')->values()->all(),
                'soundIds' => $rows
                    ->filter(fn ($r) => in_array(($r['triage']['type'] ?? null), [
                        'help',
                        'food_ready',
                        'approval',
                        'bill',
                    ], true))
                    ->pluck('id')->values()->all(),
                'actionCount' => $actionCount,
                'loadLevel' => $actionCount === 0 ? 'idle' : match (true) {
                    $actionCount >= self::LOAD_SLAMMED => 'slammed',
                    $actionCount >= self::LOAD_BUSY => 'busy',
                    default => 'calm',
                },
                'staleCount' => $staleCount,
                'staleClosableCount' => $staleClosable,
            ],
            'filters' => ['view' => $view, 'lens' => $lens, 'q' => $search],
            'tabs' => [
                'sections' => Lookup::for('zones')->map(fn ($z) => [
                    'id' => $z->id,
                    'label' => $z->label,
                    'color' => $z->color,
                    'count' => (int) ($zoneCounts[$z->id] ?? 0),
                ])->values()->all(),
                'showsMineTab' => $this->myZoneIds() !== [],
                'myZoneLabels' => $this->myZoneIds() === []
                    ? []
                    : Lookup::whereIn('id', $this->myZoneIds())->pluck('label')->all(),
                'myZoneIds' => $this->myZoneIds(),
                'rosterCarried' => $this->myZoneIds() !== []
                    && SectionAssignment::effectiveDate() !== now()->toDateString(),
                'needsRosterNudge' => $this->isWaiter() && $this->myZoneIds() === [],
                'mineCount' => $this->mineCount(),
                'allCount' => Table::count(),
                'canManageRoster' => (bool) auth()->user()?->hasPermission('tables.assign_sections'),
            ],
            'transferTables' => Table::where('active', true)
                ->where('status', 'available')
                ->whereDoesntHave('activeSession')
                ->orderBy('number')
                ->get(['id', 'number', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'name' => $t->name])
                ->all(),
            'live' => [
                'version' => LiveRefreshPulse::version($this->pulseBranchId()),
            ],
            'meta' => [
                'canCreate' => Gate::allows('create', Table::class),
                'canSweepStale' => (bool) auth()->user()?->hasAnyRole(['admin', 'manager']),
            ],
            'urls' => [
                'board' => route('admin.tables.index'),
                'pulse' => route('admin.tables.board-pulse'),
                'closeStale' => route('admin.tables.close-stale'),
                'create' => route('admin.tables.create'),
                'roster' => route('admin.section-assignments.index'),
            ],
        ]);
    }

    /** Cheap poll target: has anything on this branch changed since `version`? */
    public function pulse()
    {
        $this->authorize('viewAny', Table::class);

        return response()->json(['version' => LiveRefreshPulse::version($this->pulseBranchId())]);
    }

    /**
     * "Delivered." Marks every READY line served, even when another station
     * still has lines cooking on the same order. Per-item broadcasts stay
     * muted and each touched order gets one refresh. Items still cooking are
     * deliberately left alone.
     */
    public function serve(TableSession $session, OrderService $orders)
    {
        abort_unless($session->table && auth()->user()?->can('view', $session->table), 403);

        try {
            $readyOrders = $session->orders()
                ->whereHas('items', fn ($items) => $items
                    ->where('status', OrderItemStatus::Ready->value))
                ->with(['items' => fn ($items) => $items
                    ->where('status', OrderItemStatus::Ready->value)])
                ->get();

            $servedPieces = 0.0;

            foreach ($readyOrders as $order) {
                abort_unless(auth()->user()->can('serve', $order), 403);

                foreach ($order->items as $item) {
                    $servedPieces += (float) $item->quantity;
                    $orders->markItemServed($item, (int) auth()->id(), broadcast: false);
                }

                $orders->broadcastOrderRefresh($order);
            }

            return response()->json([
                'ok' => true,
                'served_count' => $this->fmtQty($servedPieces),
                'message' => $servedPieces > 0
                    ? "تم تسليم {$this->fmtQty($servedPieces)} من الجاهز لطاولة {$session->table->number}."
                    : "ما في أصناف جاهزة حاليًا لطاولة {$session->table->number}.",
            ]);
        } catch (\RuntimeException $e) {
            // A stale tap: the line moved on another screen between refreshes.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /** "I went." Clears the raised hand and records who answered. */
    public function ackHelp(TableSession $session)
    {
        abort_unless($session->table && auth()->user()?->can('view', $session->table), 403);

        if (! $session->help_requested_at) {
            return response()->json(['ok' => true, 'message' => 'تمت تلبية النداء.']);
        }

        $session->update([
            'help_requested_at' => null,
            'help_request_note' => null,
            'help_ack_by_user_id' => auth()->id(),
        ]);
        LiveRefreshPulse::touchSession((int) $session->id);

        return response()->json(['ok' => true, 'message' => "تم — نداء طاولة {$session->table->number} انحل."]);
    }

    /**
     * «أغلق كل الراكدة» — sweeps every zero-exposure zombie session in the
     * given view scope. Eligibility is recomputed server-side at POST time;
     * the client's list is never trusted.
     */
    public function closeStale(Request $request, BillingService $billing)
    {
        abort_unless((bool) auth()->user()?->hasAnyRole(['admin', 'manager']), 403);
        $this->resetRequestMemos();

        $view = (string) $request->input('view', 'all');
        if (! in_array($view, ['mine', 'all'], true) && ! ctype_digit($view)) {
            $view = 'all';
        }

        $closed = 0;
        foreach ($this->rows('', $view) as $r) {
            if (($r['triage']['type'] ?? null) !== 'idle' || ($r['triage']['action']['kind'] ?? null) !== 'close') {
                continue;
            }

            try {
                $session = TableSession::find($r['sessionId']);
                if ($session) {
                    $billing->closeZeroExposureSession($session, (int) auth()->id(), 'إغلاق جماعي للراكدة من اللوحة');
                    $closed++;
                }
            } catch (\Throwable) {
                // One stubborn row must not kill the sweep — it stays visible
                // in the stale lens for a manual look.
            }
        }

        return response()->json([
            'ok' => true,
            'closed' => $closed,
            'message' => $closed > 0
                ? "أُغلقت {$closed} جلسة راكدة. أي جلسة باقية عليها مستحقات — بتحتاج الكاشير."
                : 'ما في جلسات راكدة قابلة للإغلاق — الباقي عليه مستحقات.',
        ]);
    }

    // ── Row building — verbatim v4 semantics ─────────────────────────────

    protected function rows(string $search, string $view)
    {
        $todayStartsAt = now()->startOfDay();
        $tomorrowStartsAt = $todayStartsAt->copy()->addDay();

        $q = Table::with([
            'activeSession' => fn ($s) => $s->withCount([
                'orders as all_orders_count',
                'orders as cancelled_orders_count' => fn ($o) => $o
                    ->where('status', OrderStatus::Cancelled->value),
                'orders as received_orders_count' => fn ($o) => $o
                    ->whereNotNull('submitted_at')
                    ->where('status', '!=', OrderStatus::Cancelled->value),
            ]),
            'activeSession.assignedWaiter:id,name',
            'activeSession.invoice',
            'activeSession.orders' => fn ($orders) => $orders
                ->whereIn('status', OrderStatus::active())
                ->latest('updated_at'),
            'activeSession.orders.items.modifiers',
            'activeSession.orders.items.exclusions',
            'activeSession.orders.items.station:id,name',
            'zone',
        ])->withCount([
            'orders as today_received_orders_count' => fn ($orders) => $orders
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '>=', $todayStartsAt)
                ->where('submitted_at', '<', $tomorrowStartsAt)
                ->where('status', '!=', OrderStatus::Cancelled->value),
        ])->orderBy('number');

        if ($search !== '') {
            $q->where(function ($qq) use ($search) {
                $qq->where('number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('zone', fn ($z) => $z->where('label', 'like', "%{$search}%"));
            });
        }

        if (ctype_digit($view)) {
            $q->where('zone_lookup_id', (int) $view);
        } elseif ($view === 'mine') {
            $this->applyMineScope($q);
        }

        $attention = $this->attentionMinutes();
        $seatedMin = max(3, (int) round($attention / 15));

        return $q->get()
            // `number` is intentionally a string (A1 / terrace-2 are valid),
            // but SQL lexical ordering puts 10 before 2. Natural ordering is
            // the floor order a waiter expects under pressure.
            ->sort(fn (Table $a, Table $b) => strnatcasecmp((string) $a->number, (string) $b->number))
            ->values()
            ->map(fn (Table $t) => $this->buildRow($t, $attention, $seatedMin));
    }

    /**
     * "My tables": rostered sections first (planned), claim-based ownership
     * as the no-roster fallback. Unclaimed (assigned_waiter_id NULL) stays
     * load-bearing in the fallback — a QR session has no waiter until an
     * order is approved, and hiding it would deadlock approval.
     */
    protected function applyMineScope($q): void
    {
        $me = (int) auth()->id();
        $zoneIds = $this->myZoneIds();

        if ($zoneIds !== []) {
            $q->where(function ($w) use ($zoneIds, $me) {
                $w->whereIn('zone_lookup_id', $zoneIds)
                    ->orWhereHas('activeSession', fn ($s) => $s->where('assigned_waiter_id', $me));
            });

            return;
        }

        $q->where(function ($w) use ($me) {
            $w->whereDoesntHave('activeSession')
                ->orWhereHas('activeSession', fn ($s) => $s->where(
                    // Nested closure REQUIRED — a flat orWhereNull would OR out
                    // of the relation's status='active' constraint.
                    fn ($x) => $x->where('assigned_waiter_id', $me)->orWhereNull('assigned_waiter_id')
                ));
        });
    }

    protected function buildRow(Table $t, int $attention, int $seatedMin): array
    {
        $session = $t->activeSession;
        $orders = $session?->orders ?? collect();
        $activeCount = $orders->count();
        $pendingOrders = $orders
            ->where('status', OrderStatus::Pending->value)
            ->sortBy('created_at')
            ->values();
        $pendingCount = $pendingOrders->count();
        $pendingOrder = $pendingOrders->first();
        $pendingReview = $pendingOrder ? $this->pendingReviewPayload($pendingOrder, $pendingCount) : null;
        $kitchenCount = $orders->whereIn('status', [
            OrderStatus::Approved->value,
            OrderStatus::Preparing->value,
        ])->count();
        // An order spanning kitchen + bar remains `preparing` until BOTH
        // stations finish. Floor handoff must therefore be item-driven, not
        // order-driven, or a ready drink disappears behind a cooking meal.
        $readyItems = $orders->flatMap(fn (Order $order) => $order->items)
            ->where('status', OrderItemStatus::Ready->value)
            ->values();
        $readyCount = $readyItems->count();
        $readyHandoff = $readyCount > 0 ? $this->readyHandoffPayload($readyItems) : null;
        $openMinutes = $session?->opened_at ? (int) abs($session->opened_at->diffInMinutes(now())) : 0;
        $lastSeenAt = $session?->last_activity_at ?? $session?->opened_at;
        $idleMinutes = $lastSeenAt ? (int) abs($lastSeenAt->diffInMinutes(now())) : null;
        $invoice = $session?->invoice;
        $billRequested = filled($session?->bill_requested_at);

        // Idle-close eligibility — mirrors TableController@closeSession.
        $allOrdersCount = (int) ($session?->all_orders_count ?? 0);
        $cancelledCount = (int) ($session?->cancelled_orders_count ?? 0);
        $invoiceSettled = $invoice && $invoice->status !== 'cancelled' && (float) $invoice->balance <= 0;
        $zeroExposure = $allOrdersCount === 0 || $allOrdersCount === $cancelledCount || $invoiceSettled;
        $canCloseIdle = $session && $idleMinutes !== null && $idleMinutes >= $attention && $zeroExposure;

        $perms = $this->branchPerms($t);
        $helpRequested = filled($session?->help_requested_at);

        $triage = null;
        if ($session) {
            if ($helpRequested) {
                // Outranks even cold food: a hand is up RIGHT NOW.
                $triage = $this->triageEntry('help', 6, 'red', 'bi-hand-index-thumb-fill',
                    trim('الزبون طلب مساعدة'.($session->help_request_note ? ' — '.$session->help_request_note : '')),
                    $session->help_requested_at,
                    ['kind' => 'ack']);
            } elseif ($readyCount > 0) {
                // ready_at, NOT updated_at — the cold-food clock must not be
                // reset by unrelated writes to the order row.
                $since = $readyItems->pluck('ready_at')->filter()->min() ?? $session->opened_at;
                $stations = implode(' + ', array_slice($readyHandoff['stationNames'], 0, 2));
                $triage = $this->triageEntry('food_ready', 5, 'red', 'bi-bell-fill',
                    $readyHandoff['pieceCount'].' جاهز للتسليم'.($stations ? ' — '.$stations : '').'؛ سلّمه قبل ما يبرد', $since,
                    // A cashier can see the board but can't serve — link, not 403.
                    $this->canServe()
                        ? ['kind' => 'serve']
                        : ['kind' => 'link', 'url' => route('admin.orders.index', ['table_id' => $t->id]), 'label' => 'شوف الطلب', 'icon' => 'bi-list-check']);
            } elseif ($billRequested && ! $invoiceSettled) {
                $triage = $this->triageEntry('bill', 4, 'bill', 'bi-receipt-cutoff',
                    'الزبون طلب الفاتورة', $session->bill_requested_at,
                    ['kind' => 'link', 'url' => route('admin.cashier.show', $session), 'label' => 'افتح التحصيل', 'icon' => 'bi-cash-stack']);
            } elseif ($pendingCount > 0) {
                $since = $pendingOrders->pluck('created_at')->filter()->min() ?? $session->opened_at;
                $triage = $this->triageEntry('approval', 3, 'amber', 'bi-person-check',
                    $pendingCount === 1 ? 'جولة جديدة للمراجعة' : $pendingCount.' جولات للمراجعة', $since,
                    [
                        'kind' => 'link',
                        'url' => $pendingReview['url'],
                        'label' => 'راجع الجولة',
                        'icon' => 'bi-check2-square',
                    ]);
            } elseif ($canCloseIdle) {
                // MUST precede no_order — see v4: an abandoned QR scan would
                // otherwise mask idle-close forever.
                $triage = $this->triageEntry('idle', 1, 'grey', 'bi-hourglass-bottom',
                    'جلسة راكدة بلا مستحقات', $lastSeenAt,
                    $perms['update']
                        ? ['kind' => 'close']
                        : ['kind' => 'link', 'url' => route('admin.waiter-orders.create', $t), 'label' => 'تفقّد', 'icon' => 'bi-eye']);
            } elseif ($activeCount === 0 && $openMinutes >= $seatedMin) {
                $triage = $this->triageEntry('no_order', 2, 'amber', 'bi-clipboard-x',
                    'قاعدين بلا طلب', $session->opened_at,
                    ['kind' => 'link', 'url' => route('admin.waiter-orders.create', $t), 'label' => 'خُد الطلب', 'icon' => 'bi-clipboard-plus']);
            } elseif ($openMinutes >= $attention) {
                $triage = $this->triageEntry('long', 1, 'grey', 'bi-hourglass-split',
                    'جلسة طويلة تحتاج متابعة', $session->opened_at,
                    ['kind' => 'link', 'url' => route('admin.waiter-orders.create', $t), 'label' => 'تفقّد', 'icon' => 'bi-eye']);
            }
        } elseif ($t->needsCleaning()) {
            // A dirty table costs the NEXT seating — ranks below live guests.
            $triage = $this->triageEntry('cleaning', 2, 'amber', 'bi-stars',
                'تحتاج تنظيف', $t->needs_cleaning_since,
                ['kind' => 'clean']);
        }

        $tileState = match (true) {
            $triage && $triage['type'] === 'cleaning' => 'cleaning',
            $triage && $triage['urgency'] === 'red' => 'urgent',
            $triage && $triage['urgency'] === 'bill' => 'bill',
            $triage && $triage['urgency'] === 'amber' => 'attention',
            $triage && $triage['urgency'] === 'grey' => 'stale',
            (bool) $session => 'occupied',
            $t->status === 'reserved' => 'reserved',
            $t->status === 'out_of_service' => 'oos',
            default => 'available',
        };

        return [
            'id' => $t->id,
            'number' => (string) $t->number,
            'name' => $t->name,
            'capacity' => (int) $t->capacity,
            'status' => (string) $t->status,
            'activeFlag' => (bool) $t->active,
            'zoneId' => $t->zone_lookup_id,
            'zoneLabel' => $t->zone?->label,
            'zoneColor' => $t->zone?->color ?? '#94a3b8',
            'sessionId' => $session?->id,
            'counts' => [
                'active' => $activeCount,
                'pending' => $pendingCount,
                'kitchen' => $kitchenCount,
                'ready' => $readyCount,
                // "Session" prevents the waiter confusing earlier guests on
                // this table with the party currently sitting there. "Today"
                // is every actually submitted, non-cancelled round on it.
                'session' => (int) ($session?->received_orders_count ?? 0),
                'today' => (int) ($t->today_received_orders_count ?? 0),
            ],
            'waiterName' => $session?->assignedWaiter?->name,
            'idleShort' => ($session && $lastSeenAt)
                ? Duration::short((int) abs($lastSeenAt->diffInMinutes(now())))
                : null,
            'triage' => $triage,
            'readyHandoff' => $readyHandoff,
            'pendingReview' => $pendingReview,
            'tileState' => $tileState,
            'perms' => $perms,
            'urls' => [
                'order' => route('admin.waiter-orders.create', $t),
                'review' => $pendingReview['url'] ?? null,
                'orders' => route('admin.orders.index', ['table_id' => $t->id]),
                'cashier' => $session ? route('admin.cashier.show', $session) : null,
                'qrPrint' => route('admin.tables.qr-print', $t),
                'markClean' => route('admin.tables.mark-clean', $t),
                'closeSession' => route('admin.tables.close-session', $t),
                'transfer' => route('admin.tables.transfer', $t),
                'destroy' => route('admin.tables.destroy', $t),
                'quickUpdate' => route('admin.tables.quick-update', $t),
                'serve' => $session ? route('admin.tables.serve', $session) : null,
                'ackHelp' => $session ? route('admin.tables.ack-help', $session) : null,
            ],
        ];
    }

    /**
     * The floor card must answer «what am I reviewing?» before the waiter
     * leaves the map. Only the oldest pending round is actionable; later
     * rounds remain queued and become the next card after this one moves.
     */
    protected function pendingReviewPayload(Order $order, int $pendingCount): array
    {
        $round = OrderRoundContext::for($order);
        $items = $order->items
            ->reject(fn ($item) => $item->status === OrderItemStatus::Cancelled->value);

        return [
            'orderId' => (int) $order->id,
            'number' => (string) $order->number,
            'roundNumber' => (int) $round['number'],
            'roundLabel' => (string) $round['label'],
            'pendingCount' => $pendingCount,
            'lineCount' => $items->count(),
            'pieceCount' => $this->fmtQty((float) $items->sum(fn ($item) => (float) $item->quantity)),
            'notes' => trim((string) $order->customer_notes),
            'url' => route('admin.waiter-orders.create', [
                'table' => $order->table_id,
                'review_order' => $order->id,
            ]),
            'items' => $items->take(3)->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name_snapshot,
                'qty' => $this->fmtQty((float) $item->quantity),
                'stationName' => $item->station?->name,
                'notes' => trim((string) $item->notes),
                'mods' => $item->modifiers->pluck('name_snapshot')->filter()->values()->all(),
                'exclusions' => $item->exclusions->pluck('name_snapshot')->filter()->values()->all(),
            ])->values()->all(),
            'hiddenItems' => max(0, $items->count() - 3),
        ];
    }

    /**
     * Compact pickup list for the waiter. It intentionally contains READY
     * lines only: a mixed kitchen/bar ticket can be handed off in waves
     * without falsely marking the rest of the ticket delivered.
     */
    protected function readyHandoffPayload(Collection $items): array
    {
        $stationNames = $items
            ->map(fn ($item) => $item->station?->name ?: 'بدون محطة')
            ->unique()
            ->values();

        return [
            'lineCount' => $items->count(),
            'pieceCount' => $this->fmtQty((float) $items->sum(fn ($item) => (float) $item->quantity)),
            'stationNames' => $stationNames->all(),
            'items' => $items->take(4)->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name_snapshot,
                'qty' => $this->fmtQty((float) $item->quantity),
                'stationName' => $item->station?->name ?: 'بدون محطة',
            ])->values()->all(),
            'hiddenItems' => max(0, $items->count() - 4),
        ];
    }

    protected function fmtQty(float $quantity): string
    {
        return abs($quantity - round($quantity)) < 0.0001
            ? (string) (int) round($quantity)
            : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    protected function triageEntry(string $type, int $rank, string $urgency, string $icon, string $label, $since, array $action): array
    {
        return [
            'type' => $type,
            'rank' => $rank,
            'urgency' => $urgency,
            'icon' => $icon,
            'label' => $label,
            'since' => $since ? Duration::since($since) : null,
            'sinceTs' => $since?->timestamp,
            'action' => $action,
        ];
    }

    protected function isHousekeeping(array $row): bool
    {
        return ($row['triage']['urgency'] ?? null) === 'grey';
    }

    protected function mineCount(): int
    {
        $q = Table::query();
        $this->applyMineScope($q);

        return $q->count();
    }

    protected function myZoneIds(): array
    {
        return $this->myZonesMemo ??= SectionAssignment::zoneIdsFor((int) auth()->id());
    }

    protected function isWaiter(): bool
    {
        return auth()->user()?->role === UserRole::Waiter->value;
    }

    protected function attentionMinutes(): int
    {
        return (int) config('restaurant.order.session_attention_minutes', 75);
    }

    /**
     * Gates resolved once per branch, not per table — TablePolicy's
     * inUserBranch runs an un-memoized query for non-owners (~240
     * queries/render on a 40-table floor before this memo existed).
     */
    protected function branchPerms(Table $t): array
    {
        $branchId = (int) $t->branch_id;

        return $this->permMemo[$branchId] ??= [
            'update' => Gate::allows('update', $t),
            'transfer' => Gate::allows('transfer', $t),
            'delete' => Gate::allows('delete', $t),
        ];
    }

    /** Role-only check (no query) — serve() re-authorizes the real policy. */
    protected function canServe(): bool
    {
        return $this->canServeMemo ??= (bool) auth()->user()?->hasAnyRole(['admin', 'manager', 'waiter']);
    }

    protected function pulseBranchId(): ?int
    {
        $branchId = BranchContext::current();

        return $branchId ? (int) $branchId : null;
    }
}
