<?php

namespace App\Http\Middleware;

use App\Models\TableSession;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableSessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('table_session') ?? $request->input('session');

        if (! $token) {
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
            return $this->reject($request, 'انتهت جلسة الطاولة. يرجى مسح QR مرة أخرى.');
        }

        // Pin BranchContext so any branch-scoped INSERT/SELECT in the
        // downstream controllers (Order, OrderItem, …) sees the right
        // branch — the one this customer's table physically belongs to.
        BranchContext::set((int) $session->branch_id);

        $request->attributes->set('table_session', $session);

        return $next($request);
    }

    /**
     * Customer-appropriate rejection — NEVER the staff login redirect.
     * The diner is an anonymous guest: programmatic requests (fetch from
     * the menu JS, Livewire component calls) get a 419 JSON payload the
     * frontend can detect, everything else gets the friendly
     * "session expired, rescan the QR" page.
     */
    protected function reject(Request $request, string $message): Response
    {
        // X-Livewire covers Livewire's fetch() calls, which send neither
        // X-Requested-With nor an application/json Accept header.
        if ($request->expectsJson() || $request->ajax() || $request->isXmlHttpRequest() || $request->hasHeader('X-Livewire')) {
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
