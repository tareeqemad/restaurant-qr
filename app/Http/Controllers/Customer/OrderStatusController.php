<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Events\TableStatusChanged;
use App\Helpers\Money;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Setting;
use App\Services\NotifyService;
use App\Services\OrderService;
use App\Support\BranchContext;
use App\Support\LiveRefreshPulse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderStatusController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    /**
     * The tracking page is an Inertia/Vue screen. Orders arrive fully
     * decorated server-side (snapshot names, step index, ETA, the cancel
     * window as REMAINING seconds so device clocks can't cheat it); the
     * client refreshes via the public session channel + a 5s visible poll
     * that checks the cheap pulse endpoint first.
     */
    public function track(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing('table');

        return Inertia::render('Customer/Track', [
            'sessionInfo' => [
                'tableNumber' => $session->table->number ?? '—',
                'helpPending' => filled($session->help_requested_at),
            ],
            'orders' => $this->ordersPayload((int) $session->id),
            'live' => [
                'version' => LiveRefreshPulse::sessionVersion((int) $session->id),
            ],
            'urls' => [
                'menu' => route('customer.menu.open', $session->table->qr_token),
                'bill' => route('customer.bill'),
                'help' => route('customer.help.request'),
                'pulse' => route('customer.track.pulse'),
            ],
        ]);
    }

    /**
     * The menu owns the primary customer journey, so its tracking sheet asks
     * for the same complete payload without leaving the table's QR URL.
     */
    public function data(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing('table');

        return response()->json([
            'sessionInfo' => [
                'tableNumber' => $session->table->number ?? '—',
                'helpPending' => filled($session->help_requested_at),
            ],
            'orders' => $this->ordersPayload((int) $session->id),
            'version' => LiveRefreshPulse::sessionVersion((int) $session->id),
        ]);
    }

    /** Cheap poll target for the tracker (session-scoped pulse version). */
    public function pulse(Request $request)
    {
        $session = $request->attributes->get('table_session');

        return response()->json([
            'version' => LiveRefreshPulse::sessionVersion((int) $session->id),
        ]);
    }

    /**
     * Every order on this session, decorated the way the retired
     * track-card partial did — snapshot names (locale-aware), 3-step
     * progress, ETA, change-request state, and the cancel window.
     */
    protected function ordersPayload(int $sessionId): array
    {
        $localized = fn ($row) => $row?->name_snapshot;

        $cancelWindow = (int) Setting::get('customer_cancel_window_seconds', config('restaurant.order.customer_cancel_window_seconds', 120));

        $orders = Order::with([
            'items.modifiers',
            'items.exclusions',
            'items.station',
            'changeRequests.orderItem',
        ])
            ->where('table_session_id', $sessionId)
            ->latest()
            ->get();
        $roundCount = $orders->count();

        return $orders->values()
            ->map(function (Order $order, int $index) use ($localized, $cancelWindow, $roundCount) {
                $title = $localized($order->items->firstWhere('status', '!=', 'cancelled'))
                    ?? $localized($order->items->first())
                    ?? __('ui.customer_order.your_order');
                if ($order->items->count() > 1) {
                    $title .= ' + '.($order->items->count() - 1);
                }

                $latestChange = $order->changeRequests->sortByDesc('id')->first();
                $pendingChange = $order->changeRequests
                    ->firstWhere('status', OrderChangeRequest::STATUS_PENDING);
                $changeable = $order->items->whereNotIn('status', ['served', 'cancelled']);

                // Mirror of cancel(): same setting, same submitted_at base.
                // REMAINING seconds — the client anchors to its own clock.
                $cancelBase = $order->submitted_at ?? $order->created_at;
                $cancelUntil = ($cancelWindow > 0 && $cancelBase) ? $cancelBase->getTimestamp() + $cancelWindow : 0;
                $canCancel = $order->canCancelEntireOrder() && $cancelUntil > now()->getTimestamp();

                return [
                    'id' => $order->id,
                    'number' => $order->number,
                    'roundNumber' => $roundCount - $index,
                    'status' => $order->status,
                    'statusLabel' => $order->statusLabel(),
                    'title' => $title,
                    'createdAgo' => $order->created_at->diffForHumans(),
                    'itemCount' => $order->items->count(),
                    'stepIndex' => match ($order->status) {
                        'pending' => 0,
                        'approved', 'preparing' => 1,
                        'ready', 'delivered', 'completed' => 2,
                        'cancelled' => -1,
                        default => 0,
                    },
                    'cancelledReason' => $order->cancelled_reason,
                    'etaSeconds' => ($order->status === 'preparing' && $order->estimated_ready_at)
                        ? max(0, $order->estimated_ready_at->getTimestamp() - now()->getTimestamp())
                        : null,
                    'total' => Money::format($order->total),
                    'totalRaw' => (float) $order->total,
                    'items' => $order->items->map(fn ($it) => [
                        'id' => $it->id,
                        'name' => $localized($it),
                        'modifiers' => $it->modifiers->map(fn ($m) => $localized($m))->filter()->values()->all(),
                        'exclusions' => $it->exclusions->pluck('name_snapshot')->filter()->values()->all(),
                        'notes' => $it->notes,
                        'qty' => (int) $it->quantity,
                        'subtotal' => Money::format($it->subtotal),
                        'status' => $it->status,
                        'cancelledReason' => $it->cancelled_reason,
                    ])->values()->all(),
                    'changeRequest' => $latestChange ? [
                        'typeLabel' => $latestChange->typeLabel(),
                        'status' => $latestChange->status,
                        'requestNote' => $latestChange->request_note,
                        'resolutionNote' => $latestChange->status !== 'pending' ? $latestChange->resolution_note : null,
                    ] : null,
                    'hasPendingChange' => (bool) $pendingChange,
                    'canRequestChange' => in_array($order->status, ['pending', 'approved', 'preparing', 'ready'], true)
                        && $changeable->isNotEmpty(),
                    'changeableItems' => $changeable->map(fn ($it) => [
                        'id' => $it->id,
                        'name' => $localized($it),
                        'qty' => (float) $it->quantity,
                        'label' => $localized($it).' ×'.(int) $it->quantity,
                        'status' => $it->status,
                        'statusLabel' => match ($it->status) {
                            'pending' => 'بانتظار اعتماد الجرسون',
                            'approved' => 'وصل للمحطة ولم يبدأ',
                            'preparing' => 'بدأ التحضير',
                            'ready' => 'جاهز',
                            default => $it->statusLabel(),
                        },
                        'stationName' => $it->station?->name ?? 'خارج محطات التحضير',
                        'started' => in_array($it->status, ['preparing', 'ready'], true),
                        'ready' => $it->status === 'ready',
                    ])->values()->all(),
                    'canCancel' => $canCancel,
                    'cancelRemaining' => max(0, $cancelUntil - now()->getTimestamp()),
                    'urls' => [
                        'cancel' => route('customer.orders.cancel', $order),
                        'changeRequest' => route('customer.orders.change-requests.store', $order),
                    ],
                ];
            })->values()->all();
    }

    /**
     * "I need the waiter." The twin of BillController@requestBill — the floor
     * could already hear "I want to pay" but had no way to hear "I need you".
     *
     * Rate-limited the same way: a diner tapping twice shouldn't re-fire the
     * clock, or the waiter's card would keep resetting to "asked 0 minutes ago"
     * and never look urgent.
     */
    public function requestHelp(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $session->loadMissing('table');
        // Calling the waiter is a real seating signal; unlike a QR scan it
        // engages the visit and clears any cleaning flag.
        $previousTableStatus = $session->engage();

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($session->help_requested_at && $session->help_requested_at->gt(now()->subMinutes(2))) {
            if ($previousTableStatus !== 'occupied' && $session->table) {
                BranchContext::forBranch($session->branch_id, fn () => SafeBroadcast::dispatch(
                    new TableStatusChanged($session->table->refresh(), $previousTableStatus)
                ));
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'pending' => true,
                    'already_pending' => true,
                    'message' => 'طلبك وصل — الجرسون جاي.',
                ]);
            }

            return back()->with('info', 'طلبك وصل — الجرسون جاي.');
        }

        $session->update([
            'help_requested_at' => now(),
            'help_request_note' => $data['note'] ?? null,
            'help_ack_by_user_id' => null,
        ]);
        $session->touch();

        ActivityLog::log(
            'session.help_requested',
            'طلب مساعدة من طاولة '.($session->table?->number ?? '—'),
            $session,
            ['note' => $data['note'] ?? null]
        );

        // Customer requests run outside the admin BranchContext, so pin the
        // session's branch before broadcasting to the floor.
        BranchContext::forBranch($session->branch_id, function () use ($session, $previousTableStatus) {
            app(NotifyService::class)->waiterHelp($session->fresh()->load('table'));
            LiveRefreshPulse::touchSession((int) $session->id);
            if ($session->table) {
                SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $previousTableStatus));
            }
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'pending' => true,
                'already_pending' => false,
                'requested_at' => $session->help_requested_at?->toIso8601String(),
                'message' => 'وصل طلبك — الجرسون جاي.',
            ]);
        }

        return back()->with('success', 'وصل طلبك — الجرسون جاي.');
    }

    public function cancel(Request $request, Order $order)
    {
        $session = $request->attributes->get('table_session');
        abort_unless($order->table_session_id === $session->id, 403);

        $cancelWindow = (int) Setting::get('customer_cancel_window_seconds', config('restaurant.order.customer_cancel_window_seconds', 120));
        $submittedAt = $order->submitted_at ?? $order->created_at;
        $windowExpired = $cancelWindow <= 0 || ($submittedAt && $submittedAt->lt(now()->subSeconds($cancelWindow)));

        if (! $order->canCancelEntireOrder() || $windowExpired) {
            $message = __('ui.customer_order.cannot_cancel_order');

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 422)
                : back()->with('error', $message);
        }

        $reason = $request->input('reason') ?: __('ui.customer_order.customer_cancel_reason');
        if ($order->status !== OrderStatus::Pending->value) {
            $reason = __('ui.customer_order.customer_cancel_before_prep', ['reason' => $reason]);
        }

        $this->orders->cancel($order, null, $reason);

        $message = __('ui.customer_order.order_cancelled_notified');

        return $request->expectsJson()
            ? response()->json([
                'ok' => true,
                'message' => $message,
                'orders' => $this->ordersPayload((int) $session->id),
                'version' => LiveRefreshPulse::sessionVersion((int) $session->id),
            ])
            : back()->with('success', $message);
    }
}
