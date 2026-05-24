<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(protected OrderService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $q = Order::with(['table', 'items', 'tableSession', 'branch']);
        if ($s = $request->get('status'))   $q->where('status', $s);
        if ($t = $request->get('table_id')) $q->where('table_id', $t);
        if ($d = $request->get('date'))     $q->whereDate('created_at', $d);
        if ($search = $request->get('search')) $q->where('number', 'like', "%$search%");
        $orders = $q->latest()->paginate(25)->withQueryString();

        // Quick KPI snapshot for the stat-rail (today).
        $today = Order::whereDate('created_at', today());
        $stats = [
            'pending'      => Order::where('status', 'pending')->count(),
            'today_count'  => (clone $today)->count(),
            'today_active' => (clone $today)->whereIn('status', ['approved', 'preparing', 'ready'])->count(),
            'today_revenue'=> (clone $today)->where('status', '!=', 'cancelled')->sum('total'),
        ];

        return view('admin.orders.index', [
            'orders'   => $orders,
            'statuses' => array_map(fn($s) => OrderStatus::from($s), OrderStatus::active()),
            'stats'    => $stats,
        ]);
    }

    /**
     * Kanban board view — orders grouped by workflow stage.
     * Much easier for busy staff to see what needs attention.
     *
     * Columns:
     *   🔴 Pending       — needs manager approval
     *   🟡 In Kitchen    — approved + preparing (kitchen working on it)
     *   🟢 Ready         — grab + deliver to table
     *   ✓  Finished      — delivered + completed (collapsed by default, last 20)
     */
    /**
     * Comprehensive orders archive — searchable + filterable history view.
     *
     * Distinct from `index()` (which is the manager's daily-board view) and
     * `board()` (the live kanban). The archive is the one place where you
     * find that specific old order: free-text search, date range, status,
     * channel, table, and total range. BranchScope filters automatically;
     * Super Admin in "all branches" mode sees everything with the branch
     * column rendered.
     */
    public function archive(Request $request)
    {
        $this->authorize('archive', Order::class);

        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $query = Order::with(['table', 'branch'])
            ->withCount('items')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);

          if ($search = trim((string) $request->get('search'))) {
              $query->where(function ($q) use ($search) {
                  $q->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_notes', 'like', "%{$search}%")
                    ->orWhere('external_reference', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('delivery_address', 'like', "%{$search}%");
              });
          }

        // Status — multi-select: ?status[]=approved&status[]=ready
        $statuses = (array) $request->get('status', []);
        $statuses = array_filter($statuses, fn ($s) =>
            in_array($s, array_column(OrderStatus::cases(), 'value'), true)
        );
        if ($statuses) {
            $query->whereIn('status', $statuses);
        }

          if ($source = $request->get('source')) {
              $query->where('order_source', $source);
          }

          $orderType = $request->get('order_type');
          if (in_array($orderType, ['dine_in', 'takeaway', 'delivery'], true)) {
              $query->where('order_type', $orderType);
          }

        if ($tableId = $request->get('table_id')) {
            $query->where('table_id', (int) $tableId);
        }

        if (($min = $request->get('min_total')) !== null && $min !== '') {
            $query->where('total', '>=', (float) $min);
        }
        if (($max = $request->get('max_total')) !== null && $max !== '') {
            $query->where('total', '<=', (float) $max);
        }

        // Delayed-only filter — show orders where the actual prep time
        // exceeded the estimate by ≥ 1 minute. Uses TIMESTAMPDIFF for an
        // index-friendly comparison vs. PHP-side filtering after pagination.
        // Only meaningful for orders that completed the preparing phase.
        if ($request->boolean('delayed_only')) {
            $query->whereNotNull('prep_started_at')
                  ->whereNotNull('ready_at')
                  ->whereNotNull('estimated_prep_minutes')
                  ->where('estimated_prep_minutes', '>', 0)
                  // Skip rows where ready_at < prep_started_at (the
                  // order was retroactively re-stamped after completion).
                  // These would otherwise show up as "extreme delay".
                  ->where('ready_at', '>=', DB::raw('prep_started_at'))
                  ->whereRaw(
                      'CAST(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) AS SIGNED) > CAST(estimated_prep_minutes AS SIGNED)'
                  );
        }

        $sort = $request->get('sort', 'created_at');
        $dir  = $request->get('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['created_at', 'total', 'number'], true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->latest();
        }

          // Stats — based on the same filtered set so the cards reflect what
          // the user is actually looking at, not the whole branch's history.
          $statsQuery = (clone $query)->reorder();
          $statsBase = clone $statsQuery->getQuery();
          $stats = [
              'count'       => (int) (clone $statsBase)->count(),
              'gross'       => (float) (clone $statsBase)->sum('total'),
              'avg'         => (float) ((clone $statsBase)->avg('total') ?? 0),
              'cancelled'   => (int) (clone $statsBase)->where('status', OrderStatus::Cancelled->value)->count(),
          ];

          $filteredStats = [
              'completed'   => (int) (clone $statsBase)->where('status', OrderStatus::Completed->value)->count(),
              'external'    => (int) (clone $statsBase)->whereNotIn('order_source', ['dine_in', 'portal'])->count(),
              'commission'  => (float) (clone $statsBase)->sum(DB::raw('total * platform_commission_pct / 100')),
              'net'         => (float) (clone $statsBase)->sum(DB::raw('total * (1 - platform_commission_pct / 100)')),
          ];

          // ─── Prep-timing KPIs ─────────────────────────────────────
          // Only orders that completed the preparing phase have meaningful
          // numbers. We compute three things in one query:
          //   - total measured orders (denominator)
          //   - avg actual prep minutes
          //   - avg variance vs estimate (positive = late, negative = early)
          //   - on-time count (variance ≤ 0)
          // All scoped to the same filtered window so KPIs match the table.
          // CAST both sides to SIGNED so the subtraction can yield negative
          // values (early-finished orders). Without the cast MySQL treats the
          // operands as BIGINT UNSIGNED and any negative delta overflows
          // with "Numeric value out of range".
          $timingRow = (clone $statsBase)
              ->whereNotNull('prep_started_at')
              ->whereNotNull('ready_at')
              ->whereNotNull('estimated_prep_minutes')
              ->where('estimated_prep_minutes', '>', 0)
              // Same guard as the delayed_only filter — exclude rows where
              // ready_at predates prep_started_at so a corrupted historical
              // order doesn't tank the on-time KPI.
              ->where('ready_at', '>=', DB::raw('prep_started_at'))
              ->select([
                  DB::raw('COUNT(*) as total_measured'),
                  DB::raw('AVG(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at)) as avg_actual_min'),
                  DB::raw('AVG(CAST(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) AS SIGNED) - CAST(estimated_prep_minutes AS SIGNED)) as avg_delay_min'),
                  DB::raw('SUM(CASE WHEN CAST(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) AS SIGNED) <= CAST(estimated_prep_minutes AS SIGNED) THEN 1 ELSE 0 END) as on_time_count'),
                  DB::raw('SUM(CASE WHEN CAST(TIMESTAMPDIFF(MINUTE, prep_started_at, ready_at) AS SIGNED) > CAST(estimated_prep_minutes AS SIGNED) THEN 1 ELSE 0 END) as late_count'),
              ])
              ->first();

          $measured = (int) ($timingRow->total_measured ?? 0);
          $timingStats = [
              'measured'      => $measured,
              'avg_actual'    => (float) ($timingRow->avg_actual_min ?? 0),
              'avg_delay'     => (float) ($timingRow->avg_delay_min ?? 0),
              'on_time'       => (int) ($timingRow->on_time_count ?? 0),
              'late'          => (int) ($timingRow->late_count ?? 0),
              'on_time_pct'   => $measured > 0
                  ? round(((int) $timingRow->on_time_count / $measured) * 100, 1)
                  : null,
          ];

          $sourceBreakdown = (clone $statsBase)
              ->select('order_source', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
              ->groupBy('order_source')
              ->orderByDesc('total')
              ->get();

          $orders = $query->paginate(25)->withQueryString();

        return view('admin.orders.archive', [
            'orders'   => $orders,
            'stats'    => $stats,
            'filters'  => [
                'from'      => $from,
                'to'        => $to,
                'search'    => $request->get('search'),
                'status'    => $statuses,
                  'source'    => $request->get('source'),
                  'order_type'=> $orderType,
                  'table_id'  => $request->get('table_id'),
                'min_total' => $request->get('min_total'),
                'max_total' => $request->get('max_total'),
                'sort'      => $sort,
                'dir'       => $dir,
                'delayed_only' => $request->boolean('delayed_only'),
              ],
              'timingStats' => $timingStats,
              'statuses' => OrderStatus::cases(),
              'sources'  => OrderSource::cases(),
              'orderTypes' => [
                  'dine_in'  => 'داخل المطعم',
                  'takeaway' => 'استلام خارجي',
                  'delivery' => 'توصيل',
              ],
              'tables'   => Table::orderBy('number')->get(['id', 'number']),
              'filteredStats' => $filteredStats,
              'sourceBreakdown' => $sourceBreakdown,
          ]);
      }

    public function board(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        return view('admin.orders.board');
    }

    /** Transition order to a later state (ready / delivered / completed) */
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

    /** Show form to tag an existing order with delivery platform info */
    public function editSource(Order $order)
    {
        $this->authorize('approve', $order);
        return view('admin.orders.edit-source', ['order' => $order]);
    }

    public function updateSource(Request $request, Order $order)
    {
        $this->authorize('approve', $order);
        $data = $request->validate([
            'order_source'             => ['required', 'in:'.implode(',', \App\Enums\OrderSource::options())],
            'external_reference'       => ['nullable', 'string', 'max:80'],
            'delivery_receiver'        => ['nullable', 'string', 'max:120'],
            'platform_commission_pct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $order->update($data);
        return redirect()->route('admin.orders.show', $order)
            ->with('success', 'تم تحديث مصدر الطلب.');
    }

    /** Bulk-approve all pending orders (power action for busy times) */
    public function bulkApprove(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $ids = $request->validate([
            'order_ids'   => ['required', 'array'],
            'order_ids.*' => ['exists:orders,id'],
        ])['order_ids'];

        $approved = 0; $failed = [];
        foreach (Order::whereIn('id', $ids)->where('status', 'pending')->get() as $order) {
            try {
                $this->service->approve($order, auth()->id());
                $approved++;
            } catch (\Throwable $e) {
                $failed[] = $order->number . ' (' . substr($e->getMessage(), 0, 80) . ')';
            }
        }

        $msg = "تم اعتماد {$approved} طلب.";
        if (!empty($failed)) {
            $msg .= " فشل: " . implode(' · ', array_slice($failed, 0, 3));
        }
        return back()->with($failed ? 'error' : 'success', $msg);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);
        $order->load('items.modifiers', 'items.station', 'table', 'tableSession', 'approver', 'creator');
        return view('admin.orders.show', compact('order'));
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
            'reason'       => ['required', 'string', 'max:500'],
            // 'return' (default) — kitchen never touched it; put
            // ingredients back. 'waste' — chef started prep, food
            // can't be reused, count it as a loss for the waste
            // report. The radio in the cancel modal posts this.
            'disposition'  => ['nullable', 'in:return,waste'],
            'waste_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $this->service->cancelItem(
            item:        $item,
            userId:      auth()->id(),
            reason:      $data['reason'],
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
