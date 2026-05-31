<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Customer-side inbox for marketing announcements + future system pings.
 *
 * Notifications live in the same `notifications` table as the staff side,
 * routed via the polymorphic `notifiable_type/_id`. Filtering by the
 * authenticated `customer` guard means only that customer's rows surface.
 *
 * The list paginates and exposes mark-read/mark-all-read actions; the
 * navbar's bell counter polls a tiny endpoint for the unread total.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $customer = auth('customer')->user();

        $notifications = $customer->notifications()->paginate(20);

        return view('portal.notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, string $id)
    {
        $customer = auth('customer')->user();
        $notification = $customer->notifications()->findOrFail($id);
        $notification->markAsRead();

        // If the notification has an action_url stored in its data blob,
        // hop the customer there directly — cleaner UX than landing back
        // on the inbox after clicking a CTA-bearing notification.
        $url = $notification->data['action_url'] ?? null;
        if ($url) {
            return redirect()->to($url);
        }

        return back()->with('success', __('portal.notifications.marked_read'));
    }

    public function markAllRead(Request $request)
    {
        $customer = auth('customer')->user();
        $customer->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', __('portal.notifications.marked_all_read'));
    }

    /**
     * JSON endpoint feeding the navbar bell counter. Lightweight COUNT
     * query — runs on every page load, so we keep it as cheap as possible.
     */
    public function unreadCount()
    {
        $customer = auth('customer')->user();

        return response()->json([
            'count' => $customer ? $customer->unreadNotifications()->count() : 0,
        ]);
    }
}
