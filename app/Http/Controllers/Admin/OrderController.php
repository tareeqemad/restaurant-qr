<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Services\OrderService;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(protected OrderService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $q = Order::with(['table', 'items', 'tableSession', 'branch']);
        if ($s = $request->get('status')) {
            $q->where('status', $s);
        }
        if ($t = $request->get('table_id')) {
            $q->where('table_id', $t);
        }
        if ($d = $request->get('date')) {
            $q->whereDate('created_at', $d);
        }
        if ($search = $request->get('search')) {
            $q->where('number', 'like', "%$search%");
        }
        $orders = $q->latest()->paginate(25)->withQueryString();

        // Quick KPI snapshot for the stat-rail (today).
        $today = Order::whereDate('created_at', today());
        $stats = [
            'pending' => Order::where('status', 'pending')->count(),
            'today_count' => (clone $today)->count(),
            'today_active' => (clone $today)->whereIn('status', ['approved', 'preparing', 'ready'])->count(),
            'today_revenue' => (clone $today)->where('status', '!=', 'cancelled')->sum('total'),
        ];

        return AdminShell::render('Admin/Orders/Index', [
            'orders' => [
                'data' => $orders->getCollection()->map(fn (Order $o) => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'tableLabel' => $o->tableLabel(),
                    'branchName' => $o->branch?->name,
                    'itemCount' => $o->items->count(),
                    'total' => Money::format($o->total),
                    'status' => $o->status,
                    'statusLabel' => $o->statusLabel(),
                    'statusColor' => $o->statusColor(),
                    'createdAgo' => $o->created_at->diffForHumans(),
                    'canApprove' => $o->status === 'pending' && auth()->user()->can('approve', $o),
                    'canUnapprove' => $o->canUnapprove() && auth()->user()->can('approve', $o),
                    'urls' => [
                        'show' => route('admin.orders.show', $o),
                        'approve' => route('admin.orders.approve', $o),
                        'unapprove' => route('admin.orders.unapprove', $o),
                    ],
                ])->all(),
                'links' => $orders->linkCollection()->toArray(),
                'total' => $orders->total(),
            ],
            'stats' => [
                'pending' => $stats['pending'],
                'todayCount' => $stats['today_count'],
                'todayActive' => $stats['today_active'],
                'todayRevenue' => Money::format($stats['today_revenue']),
            ],
            'filters' => $request->only(['search', 'status', 'date']),
            'showBranchColumn' => (bool) session('view_all_branches'),
            'urls' => [
                'index' => route('admin.orders.index'),
                'list' => route('admin.orders.list'),
                'board' => route('admin.orders.index'),
            ],
        ]);
    }

    /**
     * Comprehensive orders archive — searchable + filterable history view.
     *
     * Distinct from index() (the manager's daily list) and the service
     * board (live triage). The archive is the one place you go to find that
     * specific old order: free-text search, date range, multi-select status,
     * table, total range, and preparation performance.
     *
     * RESTORED in Wave 6 — the method was lost when this controller was
     * rewritten in Wave 3, so route `admin.orders.archive` (and the sidebar
     * leaf that points at it) had been a hard 500. Recovered from the
     * pre-migration controller with two deliberate corrections:
     *
     *  1. Every aggregate now clones the filtered ELOQUENT builder. The
     *     original cloned `$query->getQuery()`, which hands back the raw
     *     query builder with NO global scopes applied — so the KPI cards
     *     were computed across every branch and soft-deleted orders while the list
     *     under them was scoped correctly. A branch manager could read
     *     another branch's revenue off the cards.
     *  2. Prep-time arithmetic is centralized so every aggregate uses the
     *     same signed MySQL expression and early finishes cannot overflow.
     */
    public function archive(Request $request)
    {
        $this->authorize('archive', Order::class);

        $from = $this->archiveDate($request->get('from'), now()->subDays(30)->toDateString());
        $to = $this->archiveDate($request->get('to'), now()->toDateString());

        // ONE filtered builder. The list query and every aggregate are
        // clones of it, so the cards can never disagree with the table.
        $filtered = Order::query()
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);

        $search = trim((string) $request->get('search'));
        if ($search !== '') {
            $filtered->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_notes', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        // Status — multi-select: ?status[]=approved&status[]=ready
        $validStatuses = array_column(OrderStatus::cases(), 'value');
        $statuses = array_values(array_filter(
            (array) $request->get('status', []),
            fn ($s) => in_array($s, $validStatuses, true)
        ));
        if ($statuses) {
            $filtered->whereIn('status', $statuses);
        }

        if ($tableId = $request->get('table_id')) {
            $filtered->where('table_id', (int) $tableId);
        }

        $min = $request->get('min_total');
        if ($min !== null && $min !== '') {
            $filtered->where('total', '>=', (float) $min);
        }
        $max = $request->get('max_total');
        if ($max !== null && $max !== '') {
            $filtered->where('total', '<=', (float) $max);
        }

        $actualSql = $this->prepMinutesSql();
        $actualSignedSql = $this->prepMinutesSql(signed: true);
        $estSignedSql = $this->estimateMinutesSql();

        // Delayed-only — actual prep time exceeded the estimate by ≥ 1
        // minute. Done in SQL (index-friendly) rather than after paging.
        $delayedOnly = $request->boolean('delayed_only');
        if ($delayedOnly) {
            $filtered->whereNotNull('prep_started_at')
                ->whereNotNull('ready_at')
                ->whereNotNull('estimated_prep_minutes')
                ->where('estimated_prep_minutes', '>', 0)
                // Skip rows where ready_at predates prep_started_at (the
                // order was re-stamped after completion) — they would read
                // as an extreme delay.
                ->whereColumn('ready_at', '>=', 'prep_started_at')
                ->whereRaw("{$actualSignedSql} > {$estSignedSql}");
        }

        // An unrecognized ?sort= falls back to newest-first and IGNORES ?dir=,
        // matching the pre-migration screen. Normalizing the key and then
        // honouring $dir anyway would hand a typo'd URL the OLDEST orders in
        // the window — the opposite of what the manager asked for.
        $sort = $request->get('sort', 'created_at');
        $sortRecognized = in_array($sort, ['created_at', 'total', 'number'], true);
        if (! $sortRecognized) {
            $sort = 'created_at';
        }
        $dir = ($sortRecognized && $request->get('dir', 'desc') === 'asc') ? 'asc' : 'desc';

        // ─── Stats — same filtered set, so the cards describe exactly
        //     what the operator is looking at.
        $stats = [
            'count' => (int) (clone $filtered)->count(),
            'gross' => (float) (clone $filtered)->sum('total'),
            'avg' => (float) ((clone $filtered)->avg('total') ?? 0),
            'cancelled' => (int) (clone $filtered)->where('status', OrderStatus::Cancelled->value)->count(),
        ];

        // ─── Prep-timing KPIs — one query, four numbers:
        //     measured (denominator) · avg actual · avg variance vs the
        //     estimate (positive = late) · on-time / late counts.
        $timingRow = (clone $filtered)
            ->whereNotNull('prep_started_at')
            ->whereNotNull('ready_at')
            ->whereNotNull('estimated_prep_minutes')
            ->where('estimated_prep_minutes', '>', 0)
            // Same guard as delayed_only — one corrupted historical row
            // must not tank the on-time KPI.
            ->whereColumn('ready_at', '>=', 'prep_started_at')
            ->toBase()
            ->select([
                DB::raw('COUNT(*) as total_measured'),
                DB::raw("AVG({$actualSql}) as avg_actual_min"),
                DB::raw("AVG({$actualSignedSql} - {$estSignedSql}) as avg_delay_min"),
                DB::raw("SUM(CASE WHEN {$actualSignedSql} <= {$estSignedSql} THEN 1 ELSE 0 END) as on_time_count"),
                DB::raw("SUM(CASE WHEN {$actualSignedSql} > {$estSignedSql} THEN 1 ELSE 0 END) as late_count"),
            ])
            ->first();

        $measured = (int) ($timingRow->total_measured ?? 0);
        $avgActual = (float) ($timingRow->avg_actual_min ?? 0);
        $avgDelay = (float) ($timingRow->avg_delay_min ?? 0);
        $onTimePct = $measured > 0
            ? round(((int) $timingRow->on_time_count / $measured) * 100, 1)
            : null;
        $pct = (float) ($onTimePct ?? 0);

        $timing = [
            'show' => $measured > 0,
            'measured' => $measured,
            'measuredLabel' => number_format($measured),
            'onTime' => (int) ($timingRow->on_time_count ?? 0),
            'late' => (int) ($timingRow->late_count ?? 0),
            'onTimePct' => $onTimePct,
            'onTimePctLabel' => $pct.'%',
            // Traffic light: ≥80% green · 50-79% amber · <50% red.
            'onTimeColor' => $pct >= 80 ? 'success' : ($pct >= 50 ? 'warning' : 'danger'),
            'avgActual' => $avgActual,
            'avgActualLabel' => number_format($avgActual, 1).' د',
            'avgDelay' => $avgDelay,
            'avgDelayLabel' => ($avgDelay >= 0 ? '+' : '').number_format($avgDelay, 1).' د',
            'avgDelayColor' => $avgDelay <= 0 ? 'success' : ($avgDelay <= 5 ? 'warning' : 'danger'),
            'avgDelayIcon' => $avgDelay > 0 ? 'bi-arrow-up-circle' : 'bi-arrow-down-circle',
        ];

        $orders = (clone $filtered)
            ->with(['table', 'branch'])
            ->withCount('items')
            ->orderBy($sort, $dir)
            ->paginate(25)
            ->withQueryString();

        return AdminShell::render('Admin/Orders/Archive', [
            'orders' => [
                'data' => $orders->getCollection()->map(function (Order $o) {
                    $st = OrderStatus::tryFrom((string) $o->status);
                    $isTableOrder = filled($o->table_session_id) || filled($o->table_id);

                    return [
                        'id' => $o->id,
                        'number' => $o->number,
                        'branchName' => $o->branch?->name,
                        // Deterministic per-branch hue — matches the header
                        // switcher avatar and the old <x-admin.branch-tag>.
                        'branchHue' => $o->branch ? ($o->branch->id * 47) % 360 : null,
                        'dateLabel' => $o->created_at->format('Y/m/d'),
                        'timeLabel' => $o->created_at->format('H:i'),
                        'customerName' => $o->customer_name ?: null,
                        'customerPhone' => $o->customer_phone ?: null,
                        'channelLabel' => $isTableOrder ? 'طلب طاولة' : 'طلب هاتفي',
                        'channelIcon' => $isTableOrder ? 'bi-grid-3x3-gap' : 'bi-telephone',
                        'tableLabel' => $o->tableLabel(),
                        'itemsCount' => (int) ($o->items_count ?? 0),
                        'total' => (float) $o->total,
                        'totalLabel' => Money::format($o->total),
                        'statusValue' => $o->status,
                        'statusLabel' => $st?->label(),
                        'statusColor' => $st?->color(),
                        'timing' => $this->archiveRowTiming($o),
                        'urls' => ['show' => route('admin.orders.show', $o)],
                    ];
                })->all(),
                'links' => $orders->linkCollection()->toArray(),
                'total' => $orders->total(),
            ],
            'stats' => [
                'count' => $stats['count'],
                'countLabel' => number_format($stats['count']),
                'gross' => $stats['gross'],
                'grossLabel' => Money::format($stats['gross']),
                'avg' => $stats['avg'],
                'avgLabel' => Money::format($stats['avg']),
                'cancelled' => $stats['cancelled'],
                'cancelledLabel' => number_format($stats['cancelled']),
            ],
            'timing' => $timing,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'search' => $search !== '' ? $search : null,
                'status' => $statuses,
                'table_id' => $tableId ? (string) $tableId : null,
                'min_total' => ($min === null || $min === '') ? null : (string) $min,
                'max_total' => ($max === null || $max === '') ? null : (string) $max,
                'sort' => $sort,
                'dir' => $dir,
                'delayed_only' => $delayedOnly,
            ],
            'options' => [
                'statuses' => array_map(fn (OrderStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'color' => $s->color(),
                ], OrderStatus::cases()),
                'tables' => Table::orderBy('number')->get(['id', 'number'])
                    ->map(fn (Table $t) => ['id' => $t->id, 'number' => (string) $t->number])->all(),
            ],
            // Mirrors the old Blade's request()->hasAny([…]) so the "مسح"
            // button appears on exactly the same requests it used to.
            'hasQuery' => $request->hasAny([
                'search', 'status', 'table_id',
                'min_total', 'max_total', 'sort', 'dir', 'from', 'to', 'delayed_only',
            ]),
            'showBranchColumn' => (bool) session('view_all_branches'),
            'urls' => ['archive' => route('admin.orders.archive')],
        ]);
    }

    /**
     * The prep-timing cell, computed server-side (it used to be a 55-line
     *
     * @php block inside the Blade). Four mutually exclusive modes:
     *   measured — start + ready, ready ≥ start
     *   cooking  — start, no ready yet (wall-clock, never cache client-side)
     *   bogus    — both set but ready < start (inconsistent data)
     *   none     — never entered preparing
     *
     * NOTE: this rounds the minute diff while the delayed_only filter and
     * the timing KPIs truncate it (TIMESTAMPDIFF semantics). That
     * disagreement is inherited verbatim from the Blade — see the report.
     */
    private function archiveRowTiming(Order $o): array
    {
        $est = (int) ($o->estimated_prep_minutes ?? 0);
        $hasStart = $o->prep_started_at !== null;
        $hasReady = $o->ready_at !== null;

        $raw = ($hasStart && $hasReady)
            ? (int) round($o->prep_started_at->diffInMinutes($o->ready_at, false))
            : null;
        $actual = ($raw !== null && $raw >= 0) ? $raw : null;

        $mode = 'none';
        if ($actual !== null) {
            $mode = 'measured';
        } elseif ($hasStart && ! $hasReady) {
            $mode = 'cooking';
        } elseif ($raw !== null) {
            $mode = 'bogus';
        }

        $delta = ($actual !== null && $est > 0) ? $actual - $est : null;

        return [
            'mode' => $mode,
            'estMinutes' => $est,
            'estLabel' => $est > 0 ? $est.'د' : '—',
            'actualMinutes' => $actual,
            'actualLabel' => $actual !== null ? $actual.'د' : null,
            'delta' => $delta,
            'deltaLabel' => $delta === null
                ? null
                : ($delta === 0 ? 'في الوقت' : ($delta > 0 ? '+'.$delta : (string) $delta).'د'),
            // ≤ 0 on time / early · 1-5 minor · > 5 significant
            'deltaClass' => $delta === null
                ? 'arx-var--none'
                : ($delta <= 0 ? 'arx-var--good' : ($delta <= 5 ? 'arx-var--warn' : 'arx-var--bad')),
            'deltaIcon' => $delta === null
                ? null
                : ($delta > 0 ? 'bi-arrow-up-short' : ($delta < 0 ? 'bi-arrow-down-short' : 'bi-check2')),
            'deltaTitle' => $delta === null
                ? null
                : ($delta > 0 ? 'متأخر بـ' : 'مبكّر بـ').' '.abs($delta).' دقيقة',
            'cookingMinutes' => $mode === 'cooking'
                ? (int) max(1, round($o->prep_started_at->diffInMinutes(now())))
                : null,
        ];
    }

    /**
     * Actual prep minutes as a MySQL expression.
     *
     * $signed is required when subtracting the unsigned estimate; without
     * the cast an order that finished early can overflow in strict mode.
     */
    private function prepMinutesSql(bool $signed = false): string
    {
        $expr = 'TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at)';

        return $signed ? "CAST({$expr} AS SIGNED)" : $expr;
    }

    private function estimateMinutesSql(): string
    {
        return 'CAST(estimated_prep_minutes AS SIGNED)';
    }

    /**
     * The archive did no date validation at all: `?from=drop` became
     * 'drop 00:00:00', which silently matched nothing. Fall back to the
     * default window instead of showing an unexplained empty page.
     */
    private function archiveDate(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return $fallback;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return $fallback;
        }

        return $date->format('Y-m-d');
    }

    /**
     * Move an order to a later workflow stage by hand. The live triage
     * work moved to ServiceBoardController in Wave 3; this stays as the
     * manual override the detail page and integrations lean on.
     */
    public function transition(Request $request, Order $order)
    {
        $this->authorize('approve', $order);   // same permission as approval
        $data = $request->validate([
            'target' => ['required', 'in:ready,delivered,completed'],
        ]);
        try {
            $this->service->transitionTo($order, $data['target'], auth()->id());

            return back()->with('success', "تم تحديث حالة الطلب {$order->number}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Bulk-approve all pending orders (power action for busy times) */
    public function bulkApprove(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $ids = $request->validate([
            'order_ids' => ['required', 'array'],
            'order_ids.*' => ['exists:orders,id'],
        ])['order_ids'];

        $approved = 0;
        $failed = [];
        foreach (Order::whereIn('id', $ids)->where('status', 'pending')->get() as $order) {
            try {
                $this->service->approve($order, auth()->id());
                $approved++;
            } catch (\Throwable $e) {
                $failed[] = $order->number.' ('.substr($e->getMessage(), 0, 80).')';
            }
        }

        $msg = "تم اعتماد {$approved} طلب.";
        if (! empty($failed)) {
            $msg .= ' فشل: '.implode(' · ', array_slice($failed, 0, 3));
        }

        return back()->with($failed ? 'error' : 'success', $msg);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);
        $order->load(
            'items.modifiers',
            'items.station',
            'table',
            'tableSession.table',
            'tableSession.orders.items',
            'tableSession.invoice',
            'approver',
            'creator'
        );

        $session = $order->tableSession;
        $invoice = $session?->invoice;
        $activeItems = $order->items->where('status', '!=', 'cancelled');
        $readyCount = $activeItems->where('status', 'ready')->count();
        $servedCount = $activeItems->where('status', 'served')->count();
        $preparingCount = $activeItems->where('status', 'preparing')->count();
        $canTransfer = $session
            && $session->status === 'active'
            && $order->table
            && auth()->user()->can('transfer', $order->table);

        $transferBlockReason = match (true) {
            ! $session => 'هذا الطلب غير مرتبط بجلسة طاولة.',
            $session->status !== 'active' => 'الجلسة مغلقة ولا يمكن نقلها.',
            ! $order->table => 'الطاولة الحالية غير موجودة.',
            $invoice && in_array($invoice->status, ['paid', 'unpaid_writeoff'], true) => 'تم إقفال حساب الجلسة؛ صحّح التحصيل قبل النقل.',
            ! $canTransfer => 'ليس لديك صلاحية نقل الجلسة.',
            default => null,
        };

        $transferTables = $canTransfer && $transferBlockReason === null
            ? Table::query()
                ->with('zone')
                ->where('branch_id', $order->branch_id)
                ->whereKeyNot($order->table_id)
                ->where('active', true)
                ->where('status', 'available')
                ->whereNull('needs_cleaning_since')
                ->whereDoesntHave('activeSession')
                ->get()
                ->sort(fn (Table $left, Table $right) => strnatcasecmp((string) $left->number, (string) $right->number))
                ->map(fn (Table $table) => [
                    'id' => $table->id,
                    'number' => $table->number,
                    'name' => $table->name,
                    'capacity' => (int) $table->capacity,
                    'zone' => $table->zone?->label,
                ])
                ->values()
                ->all()
            : [];

        return AdminShell::render('Admin/Orders/Show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'statusLabel' => $order->statusLabel(),
                'statusColor' => $order->statusColor(),
                'tableLabel' => $order->tableLabel(),
                'typeLabel' => match ($order->order_type) {
                    'dine_in' => 'جلوس',
                    'takeaway' => 'تيك أواي',
                    'delivery' => 'توصيل',
                    default => $order->order_type,
                },
                'placedAt' => $order->created_at->format('Y-m-d H:i'),
                'placedAgo' => $order->created_at->diffForHumans(),
                'approverName' => $order->approver?->name,
                'creatorName' => $order->creator?->name,
                'customerNotes' => $order->customer_notes,
                'cancelledReason' => $order->cancelled_reason,
                'progress' => [
                    'total' => $activeItems->count(),
                    'ready' => $readyCount,
                    'served' => $servedCount,
                    'preparing' => $preparingCount,
                    'done' => $activeItems->count() > 0 && $servedCount === $activeItems->count(),
                ],
                'session' => $session ? [
                    'id' => $session->id,
                    'status' => $session->status,
                    'tableId' => $session->table_id,
                    'tableLabel' => $session->table?->number ?? $order->table?->number ?? $session->tableLabel(),
                    'coverCount' => (int) $session->cover_count,
                    'openedAt' => $session->opened_at?->format('H:i'),
                    'openedAgo' => $session->opened_at?->diffForHumans(),
                    'ordersCount' => $session->orders->count(),
                    'itemsCount' => $session->orders->sum(fn (Order $sessionOrder) => $sessionOrder->items->count()),
                    'invoice' => $invoice ? [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'status' => $invoice->status,
                        'statusLabel' => $invoice->statusLabel(),
                    ] : null,
                    'canTransfer' => $canTransfer && $transferBlockReason === null,
                    'transferBlockReason' => $transferBlockReason,
                    'transferTables' => $transferTables,
                    'transferUrl' => $order->table ? route('admin.tables.transfer', $order->table) : null,
                ] : null,
                'items' => $order->items->map(fn (OrderItem $it) => [
                    'id' => $it->id,
                    'name' => $it->name_snapshot,
                    'qty' => (float) $it->quantity,
                    'unitPrice' => Money::format($it->unit_price + $it->modifiers_total),
                    'subtotal' => Money::format($it->subtotal),
                    'stationName' => $it->station?->name,
                    'stationColor' => $it->station?->color,
                    'status' => $it->status,
                    'statusLabel' => $it->statusLabel(),
                    'statusColor' => $it->statusColor(),
                    'notes' => $it->notes,
                    'mods' => $it->modifiers->map(fn ($m) => [
                        'name' => $m->name_snapshot,
                        'delta' => (float) $m->price_delta > 0 ? '+'.$m->price_delta : null,
                    ])->values()->all(),
                    'urls' => [
                        'serve' => route('admin.orders.items.serve', $it),
                        'cancel' => route('admin.orders.items.cancel', $it),
                    ],
                ])->values()->all(),
                'totals' => [
                    'subtotal' => Money::format($order->subtotal),
                    'discount' => (float) $order->discount_total > 0 ? Money::format($order->discount_total) : null,
                    'tax' => (float) $order->tax_total > 0 ? Money::format($order->tax_total) : null,
                    'taxRate' => $order->tax_rate,
                    'service' => (float) $order->service_total > 0 ? Money::format($order->service_total) : null,
                    'serviceRate' => $order->service_rate,
                    'total' => Money::format($order->total),
                ],
                'can' => [
                    'approve' => $order->status === 'pending' && auth()->user()->can('approve', $order),
                    'unapprove' => $order->canUnapprove() && auth()->user()->can('approve', $order),
                    // Once the kitchen fired the ticket the right tool is
                    // per-item cancellation — the controller guards this too.
                    'cancelOrder' => $order->canCancelEntireOrder() && auth()->user()->can('cancel', $order),
                    'cancelItems' => auth()->user()->can('cancel', $order),
                    'serve' => auth()->user()->can('serve', $order),
                ],
                'urls' => [
                    'approve' => route('admin.orders.approve', $order),
                    'unapprove' => route('admin.orders.unapprove', $order),
                    'cancel' => route('admin.orders.cancel', $order),
                    'index' => route('admin.orders.list'),
                    'tables' => route('admin.tables.index'),
                ],
            ],
        ]);
    }

    public function approve(Request $request, Order $order)
    {
        $this->authorize('approve', $order);
        try {
            $this->service->approve($order, auth()->id());

            return back()->with('success', 'تم اعتماد الطلب وخصم المخزون');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reverse an approval back to Pending — allowed only before the kitchen
     * starts (the service enforces that). Returns any deducted stock.
     */
    public function unapprove(Request $request, Order $order)
    {
        $this->authorize('approve', $order);
        try {
            $this->service->unapprove($order, auth()->id());

            return back()->with('success', 'تم فك الاعتماد وإرجاع الطلب لقائمة الانتظار');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorize('cancel', $order);

        // Once the kitchen has fired the ticket the right tool is per-item
        // cancellation, not a sweeping bulk cancel — guard the bulk path so
        // a stale browser tab can't blow away an order that's mid-prep.
        if (! $order->canCancelEntireOrder()) {
            return back()->with('error', 'لا يمكن إلغاء الطلب بالكامل بعد بدء التحضير. ألغِ الأصناف المتبقية بشكل فردي.');
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->cancel($order, auth()->id(), $data['reason']);

        return back()->with('success', 'تم إلغاء الطلب وإرجاع المخزون');
    }

    public function cancelItem(Request $request, OrderItem $item)
    {
        $this->authorize('cancel', $item->order);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            // 'return' (default) — kitchen never touched it; put
            // ingredients back. 'waste' — chef started prep, food
            // can't be reused, count it as a loss for the waste
            // report. The radio in the cancel modal posts this.
            'disposition' => ['nullable', 'in:return,waste'],
            'waste_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $this->service->cancelItem(
            item: $item,
            userId: auth()->id(),
            reason: $data['reason'],
            disposition: $data['disposition'] ?? 'return',
            wasteReason: $data['waste_reason'] ?? null,
        );

        $msg = ($data['disposition'] ?? 'return') === 'waste'
            ? 'تم إلغاء الصنف وتسجيل المكوّنات كهدر.'
            : 'تم إلغاء الصنف وإرجاع المكوّنات للمخزون.';

        return back()->with('success', $msg);
    }

    public function serveItem(OrderItem $item)
    {
        $this->authorize('serve', $item->order);
        $this->service->markItemServed($item, auth()->id());

        return back()->with('success', 'تم تسليم الصنف');
    }
}
