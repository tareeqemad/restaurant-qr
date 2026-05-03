<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
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
        // The view just hosts the Livewire <livewire:customer.order-tracker>
        // component which queries orders itself and refreshes every 5 s.
        // We no longer need to pass $orders from here.
        return view('customer.track', [
            'session' => $request->attributes->get('table_session'),
        ]);
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

        $cancelWindow = (int) Setting::get('customer_cancel_window_seconds', config('restaurant.order.customer_cancel_window_seconds', 120));
        $submittedAt = $order->submitted_at ?? $order->created_at;
        $windowExpired = $cancelWindow <= 0 || ($submittedAt && $submittedAt->lt(now()->subSeconds($cancelWindow)));

        if (! $order->isCancellable() || $order->status !== OrderStatus::Pending->value || $windowExpired) {
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
