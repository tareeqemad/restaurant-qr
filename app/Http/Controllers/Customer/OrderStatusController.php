<?php

namespace App\Http\Controllers\Customer;

use App\Enums\OrderStatus;
use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderService;
use App\Support\BranchContext;
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

    /**
     * One-click signup from the /track page. Triggered by the gold banner
     * shown to diners who left a phone number but aren't yet account holders.
     * After ordering they're already at the table waiting for the kitchen,
     * which is the calmest moment to ask — no pressure, no friction. We
     * reuse the phone number already stored on the session, so there's
     * literally nothing for them to type.
     *
     *   - Phone matches an existing account → just link, surface the name.
     *   - No match → create a fresh Customer + 6-digit PIN + link + show
     *     the PIN on the next page so they can write it down.
     * Either way, also drop the `qr_customer_id` cookie so future QR scans
     * recognise the device automatically.
     */
    public function signup(Request $request)
    {
        $session = $request->attributes->get('table_session');

        if ($session->customer_id) {
            return back()->with('error', __('ui.customer_order.account_already_linked'));
        }
        if (empty($session->customer_phone)) {
            return back()->with('error', __('ui.customer_order.no_phone_for_signup'));
        }

        $existing = Customer::findForLogin($session->customer_phone);
        if ($existing) {
            $session->update(['customer_id' => $existing->id]);
            $response = redirect()->route('customer.track')
                ->with('success', __('ui.customer_order.welcome_back_linked', ['name' => $existing->name]));
            $customer = $existing;
        } else {
            [$customer, $pin] = Customer::createFromCashier(
                name: $session->customer_name ?: __('ui.customer_order.guest_name_default'),
                phone: $session->customer_phone,
                defaultBranchId: $session->table?->branch_id,
            );
            $session->update(['customer_id' => $customer->id]);
            $response = redirect()->route('customer.track')
                ->with('signup_pin', [
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'pin' => $pin,
                ]);
        }

        return $response->cookie(cookie(
            name: 'qr_customer_id',
            value: (string) $customer->id,
            minutes: 60 * 24 * 365,
            path: '/',
            secure: $request->secure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /**
     * The diner waved off the signup banner. We remember that for this
     * session only — no point re-pestering them while they wait for the
     * food, and a fresh QR scan resets the slate anyway.
     */
    public function dismissSignup(Request $request)
    {
        $session = $request->attributes->get('table_session');
        session()->put('signup_dismissed_session_'.$session->id, true);

        return back();
    }

    public function saveProfile(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:100'],
            'cover_count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $session->update(array_filter($data, fn ($v) => $v !== null));

        return back();
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

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($session->help_requested_at && $session->help_requested_at->gt(now()->subMinutes(2))) {
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
        BranchContext::forBranch($session->branch_id, function () use ($session) {
            if ($session->table) {
                SafeBroadcast::dispatch(new TableStatusChanged($session->table->refresh(), $session->table->status));
            }
        });

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
            return back()->with('error', __('ui.customer_order.cannot_cancel_order'));
        }

        $reason = $request->input('reason') ?: __('ui.customer_order.customer_cancel_reason');
        if ($order->status !== OrderStatus::Pending->value) {
            $reason = __('ui.customer_order.customer_cancel_before_prep', ['reason' => $reason]);
        }

        $this->orders->cancel($order, null, $reason);

        return back()->with('success', __('ui.customer_order.order_cancelled_notified'));
    }
}
