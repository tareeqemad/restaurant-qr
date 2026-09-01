<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableOrderingDeviceMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('table_session');
        $deviceHash = $request->attributes->get('table_order_device_hash');

        if ($session?->canOrderFromDevice($deviceHash)) {
            return $next($request);
        }

        $message = 'هذه الجلسة تُدار من الهاتف الذي أرسل أول طلب. يمكنك التصفح ومتابعة طلبات الطاولة، ولأي إضافة اطلب من صاحب الهاتف أو الجرسون.';

        if ($request->expectsJson() || $request->ajax() || $request->isXmlHttpRequest() || $request->hasHeader('X-Inertia')) {
            return response()->json([
                'ok' => false,
                'error' => 'ordering_device_locked',
                'message' => $message,
            ], 409);
        }

        return redirect()
            ->route('customer.menu.open', $session->table->qr_token)
            ->with('warning', $message);
    }
}
