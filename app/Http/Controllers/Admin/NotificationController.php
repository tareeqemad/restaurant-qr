<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin notifications inbox + AJAX endpoints used by the header bell.
 *
 * The bell polls `recent` every ~30s for fresh count + preview rows;
 * `read` / `readAll` are POSTed when the user interacts with the dropdown.
 *
 * Branch filtering: owner-level users (SuperAdmin + Partner) see every
 * branch's notifications because they oversee everything; everyone else
 * is filtered to the active branch only. That mirrors how BranchScope
 * works on every other model in the app.
 */
class NotificationController extends Controller
{
    /**
     * Full inbox page — server-rendered, supports type/severity/read filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->notifications()->latest();
        $this->scopeToActiveBranch($query, $user);

        // Filters: ?type=order.new ?severity=warning ?status=unread|read|all
        if ($type = $request->get('type')) {
            $query->where('type_key', $type);
        }
        if ($severity = $request->get('severity')) {
            $query->where('severity', $severity);
        }
        $status = $request->get('status', 'all');
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->paginate(20)->withQueryString();

        // Sidebar counters — built off the same scope so they reflect what
        // the filter dropdown will show.
        $base = $user->notifications();
        $this->scopeToActiveBranch($base, $user);
        $stats = [
            'total'    => (clone $base)->count(),
            'unread'   => (clone $base)->whereNull('read_at')->count(),
            'today'    => (clone $base)->whereDate('created_at', today())->count(),
            'by_type'  => (clone $base)
                ->selectRaw('type_key, COUNT(*) as c')
                ->groupBy('type_key')
                ->pluck('c', 'type_key')
                ->toArray(),
        ];

        return view('admin.notifications.index', compact('notifications', 'stats', 'status'));
    }

    /**
     * JSON snapshot for the header bell — unread count + most recent
     * notifications (read AND unread, ordered by date). 30-second poll.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        $base = $user->notifications()->latest();
        $this->scopeToActiveBranch($base, $user);

        $rows = $base->limit(8)->get();
        $unread = (clone $base)->whereNull('read_at')->count();

        return response()->json([
            'unread' => $unread,
            'items'  => $rows->map(fn ($n) => $this->present($n))->values(),
        ]);
    }

    /**
     * Mark one notification read. Returns the new unread count so the
     * bell can update its badge in the same round-trip.
     */
    public function read(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->whereKey($id)->first();
        if ($notification && is_null($notification->read_at)) {
            $notification->markAsRead();
        }

        return response()->json([
            'ok'     => true,
            'unread' => $this->unreadCountFor($user),
        ]);
    }

    /**
     * Mark every unread notification as read for the current user (within
     * the active branch's scope, so a manager doesn't accidentally clear
     * another branch's queue).
     */
    public function readAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->unreadNotifications();
        $this->scopeToActiveBranch($query, $user);
        $query->update(['read_at' => now()]);

        return response()->json([
            'ok'     => true,
            'unread' => $this->unreadCountFor($user),
        ]);
    }

    /**
     * Delete one notification (soft "dismiss" — Laravel's table is hard delete).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $user->notifications()->whereKey($id)->delete();

        return response()->json([
            'ok'     => true,
            'unread' => $this->unreadCountFor($user),
        ]);
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * Restrict the query to the active branch unless the viewer is owner-level.
     * Notifications without a branch_id (system-wide events) are always shown.
     */
    protected function scopeToActiveBranch($query, $user): void
    {
        if ($user->isOwnerLevel()) return;

        $branchId = BranchContext::current() ?? optional($user->primaryBranch())->id;
        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereNull('branch_id');
            });
        }
    }

    protected function unreadCountFor($user): int
    {
        $q = $user->unreadNotifications();
        $this->scopeToActiveBranch($q, $user);
        return $q->count();
    }

    /**
     * Reshape a notification row into the JSON the header bell consumes.
     * Pulls the heavy bits out of the `data` JSON — the bell shouldn't
     * need to know the column shape.
     */
    protected function present($notification): array
    {
        $data = $notification->data ?? [];
        return [
            'id'           => $notification->id,
            'title'        => $data['title']        ?? 'إشعار',
            'body'         => $data['body']         ?? '',
            'icon'         => $data['icon']         ?? 'bi-bell',
            'severity'     => $data['severity']     ?? 'info',
            'action_url'   => $data['action_url']   ?? null,
            'action_label' => $data['action_label'] ?? null,
            'read'         => ! is_null($notification->read_at),
            'created_at'   => optional($notification->created_at)->diffForHumans(),
        ];
    }
}
