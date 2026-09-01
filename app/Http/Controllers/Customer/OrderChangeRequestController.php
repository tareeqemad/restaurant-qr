<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderChangeRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderChangeRequestController extends Controller
{
    public function store(Request $request, Order $order, OrderChangeRequestService $service)
    {
        $session = $request->attributes->get('table_session');
        abort_unless((int) $order->table_session_id === (int) $session->id, 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['change_item', 'cancel_item', 'cancel_order'])],
            'order_item_id' => [
                Rule::requiredIf(in_array($request->input('type'), ['change_item', 'cancel_item'], true)),
                'nullable',
                'integer',
            ],
            'requested_quantity' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'request_note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $service->request($order, $session, $data);
        } catch (\RuntimeException $e) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        $message = 'وصل طلبك للجرسون. ستظهر لك النتيجة هنا فور معالجته.';

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('success', $message);
    }
}
