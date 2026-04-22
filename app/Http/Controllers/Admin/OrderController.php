<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected OrderService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        $q = Order::with(['table', 'items', 'tableSession']);
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
    public function board(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        // Only show recent activity — a 6-hour window captures current service
        // without dragging in yesterday's data.
        $since = now()->subHours(6);

        $q = Order::with(['table', 'items', 'tableSession'])
            ->where(function ($qq) use ($since) {
                $qq->where('created_at', '>=', $since)
                   ->orWhereIn('status', ['pending', 'approved', 'preparing', 'ready']);
            });

        if ($t = $request->get('table_id')) $q->where('table_id', $t);
        if ($request->filled('urgent'))     $q->where('status', 'pending')->where('created_at', '<=', now()->subMinutes(5));

        $orders = $q->orderBy('created_at')->get();

        $columns = [
            'pending'    => $orders->where('status', 'pending'),
            'in_kitchen' => $orders->whereIn('status', ['approved', 'preparing']),
            'ready'      => $orders->where('status', 'ready'),
            'finished'   => $orders->whereIn('status', ['delivered', 'completed'])->take(20),
        ];

        // Urgency — pending > 5 min is concerning, > 10 min is critical
        $urgentCount = $orders->filter(fn($o) =>
            $o->status === 'pending' && $o->created_at->lt(now()->subMinutes(5))
        )->count();

        $stats = [
            'pending'    => $columns['pending']->count(),
            'in_kitchen' => $columns['in_kitchen']->count(),
            'ready'      => $columns['ready']->count(),
            'urgent'     => $urgentCount,
        ];

        return view('admin.orders.board', compact('columns', 'stats'));
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
            'order_source'             => ['required', 'in:dine_in,talabat,careem,uber_eats,phone,other'],
            'external_reference'       => ['nullable', 'string', 'max:80'],
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
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->cancel($order, auth()->id(), $data['reason']);
        return back()->with('success', 'تم إلغاء الطلب وإرجاع المخزون');
    }

    public function cancelItem(Request $request, OrderItem $item)
    {
        $this->authorize('cancel', $item->order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->cancelItem($item, auth()->id(), $data['reason']);
        return back()->with('success', 'تم إلغاء الصنف');
    }

    public function serveItem(OrderItem $item)
    {
        $this->authorize('edit', $item->order);
        $this->service->markItemServed($item, auth()->id());
        return back()->with('success', 'تم تسليم الصنف');
    }
}
