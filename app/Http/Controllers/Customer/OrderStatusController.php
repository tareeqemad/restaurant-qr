<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderStatusController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    public function index(Request $request)
    {
        return redirect()->route('customer.track');
    }

    public function track(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $orders = Order::with(['items.modifiers', 'items.station'])
            ->where('table_session_id', $session->id)
            ->latest()
            ->get();
        return view('customer.track', compact('orders', 'session'));
    }

    public function saveProfile(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:100'],
            'cover_count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $session->update(array_filter($data, fn($v) => $v !== null));
        return back();
    }

    public function cancel(Request $request, Order $order)
    {
        $session = $request->attributes->get('table_session');
        abort_unless($order->table_session_id === $session->id, 403);

        if (! $order->isCancellable()) {
            return back()->with('error', 'لا يمكن إلغاء هذا الطلب');
        }

        $reason = $request->input('reason') ?: 'إلغاء من الزبون';
        if ($order->status !== OrderStatus::Pending->value) {
            $reason = "إلغاء من الزبون بعد بدء التحضير: {$reason}";
        }

        $this->orders->cancel($order, null, $reason);
        return back()->with('success', 'تم إلغاء الطلب. المطبخ والبار تم إعلامهم');
    }
}
