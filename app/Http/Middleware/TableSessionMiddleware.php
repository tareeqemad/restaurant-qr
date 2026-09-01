<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\TableSession;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TableSessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('table_session') ?? $request->input('session');
        $isQrEntry = $request->routeIs('customer.menu.open');

        if (! $token) {
            // The QR entry action creates or rejoins the table session, then
            // redirects once to the same tokenized URL with the visit cookie.
            if ($isQrEntry) {
                return $next($request);
            }

            return $this->reject($request, 'لم نجد جلسة طاولة. يرجى مسح QR مرة أخرى.');
        }

        // Look up the session WITHOUT BranchScope — the customer is a guest
        // and has no branch context yet; the session itself tells us which
        // branch this customer is in.
        $session = TableSession::withoutGlobalScopes()
            ->where('token', $token)
            ->where('status', 'active')
            ->with('table')
            ->first();

        if (! $session) {
            // A stale visit cookie must not block scanning another table QR.
            if ($isQrEntry) {
                return $next($request);
            }

            return $this->reject($request, 'انتهت جلسة الطاولة. يرجى مسح QR مرة أخرى.');
        }

        // Pin BranchContext so any branch-scoped INSERT/SELECT in the
        // downstream controllers (Order, OrderItem, …) sees the right
        // branch — the one this customer's table physically belongs to.
        BranchContext::set((int) $session->branch_id);

        $request->attributes->set('table_session', $session);

        // The QR code identifies the table, not the person holding a phone.
        // Give every browser a stable, opaque identity. The first browser to
        // submit an order becomes the only diner-side writer for this visit.
        $deviceToken = session('qr_order_device')
            ?? $request->cookie('qr_order_device')
            ?? Str::random(64);
        session()->put('qr_order_device', $deviceToken);
        $request->attributes->set('table_order_device_hash', hash('sha256', $deviceToken));

        $response = $next($request);

        // Keep the visit token alive while the diner uses the menu/track
        // screens. Server-side draft cleanup remains a separate short TTL.
        $ttl = (int) Setting::get(
            'session_ttl_minutes',
            config('restaurant.order.session_ttl_minutes', 240),
        );

        $response->headers->setCookie(cookie('table_session', $session->token, max(5, $ttl)));
        $response->headers->setCookie(cookie('qr_order_device', $deviceToken, max(5, $ttl)));

        return $response;
    }

    /**
     * Customer-appropriate rejection — NEVER the staff login redirect.
     * The diner is an anonymous guest: programmatic requests (fetch from
     * the menu JS, Inertia visits) get a 419 JSON payload the
     * frontend can detect, everything else gets the friendly
     * "session expired, rescan the QR" page.
     */
    protected function reject(Request $request, string $message): Response
    {
        // X-Inertia covers Inertia visits, which send neither X-Requested-With
        // nor an application/json Accept header — without it an expired session
        // would hand the Vue router a raw HTML page it cannot mount.
        if ($request->expectsJson() || $request->ajax() || $request->isXmlHttpRequest() || $request->hasHeader('X-Inertia')) {
            return response()->json([
                'ok' => false,
                'error' => 'session_expired',
                'message' => $message,
            ], 419);
        }

        return response()->view('customer.session-expired', [
            'message' => $message,
        ], 419);
    }
}
