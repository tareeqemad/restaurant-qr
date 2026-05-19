<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\AttendanceLateNotification;
use App\Notifications\BaseNotification;
use App\Notifications\BillRequestedNotification;
use App\Notifications\BranchTransferReceivedNotification;
use App\Notifications\BranchTransferSentNotification;
use App\Notifications\CustomerDebtAlertNotification;
use App\Notifications\LowStockNotification;
use App\Notifications\NewOrderNotification;
use App\Notifications\NewReservationNotification;
use App\Notifications\NewReviewNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\PurchaseReceivedNotification;
use App\Notifications\RefundIssuedNotification;
use App\Notifications\ReservationUpcomingNotification;
use App\Notifications\ShiftClosedNotification;
use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Single dispatch point for all in-app notifications.
 *
 * Each notification type maps to a different audience — e.g., a new order
 * goes to managers + cashiers + waiters of the branch, but a low-stock
 * alert only goes to admins/managers (kitchen staff can't act on it).
 *
 * Routing is intentionally centralized here (not in each Notification
 * class) so:
 *   1. The recipient list is consistent across the codebase — no
 *      controller forgets to include a role.
 *   2. Adding a new role only touches this file, not every event point.
 *   3. Testing audiences is one focused service, not a scatter hunt.
 *
 * Owner-level users (SuperAdmin + Partner) are added on top of every
 * branch-scoped audience because they oversee everything.
 */
class NotifyService
{
    // ─── High-level fire-and-forget helpers ──────────────────────────────

    public function newOrder(\App\Models\Order $order): void
    {
        $audience = $this->branchUsersWithRoles($order->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Cashier->value,
            UserRole::Waiter->value,
        ]);
        $this->send($audience, new NewOrderNotification($order));
    }

    public function orderCancelled(\App\Models\Order $order, ?string $reason = null): void
    {
        $audience = $this->branchUsersWithRoles($order->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Cashier->value,
        ]);
        $this->send($audience, new OrderCancelledNotification($order, $reason));
    }

    public function newReservation(\App\Models\Reservation $reservation): void
    {
        $audience = $this->branchUsersWithRoles($reservation->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Waiter->value,
        ]);
        $this->send($audience, new NewReservationNotification($reservation));
    }

    public function newReview(\App\Models\Review $review): void
    {
        // Managers + admins moderate. Low ratings still go through here
        // (the notification class itself escalates severity to warning).
        $audience = $this->branchUsersWithRoles($review->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
        $this->send($audience, new NewReviewNotification($review));
    }

    public function lowStock(\App\Models\Ingredient $ingredient): void
    {
        $audience = $this->branchUsersWithRoles($ingredient->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Chef->value,
        ]);
        $this->send($audience, new LowStockNotification($ingredient));
    }

    public function refundIssued(\App\Models\Refund $refund): void
    {
        $branchId = $refund->branch_id ?? optional($refund->invoice)->branch_id;
        $audience = $this->branchUsersWithRoles($branchId, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Cashier->value,
        ]);
        $this->send($audience, new RefundIssuedNotification($refund));
    }

    /**
     * A customer's outstanding debt changed — either grew (settled a new
     * invoice on account) or shrunk (paid down). Managers + admins get
     * pinged when:
     *   - The debt newly crossed the customer's `credit_limit`, OR
     *   - The debt grew past a threshold the restaurant cares about
     *     (currently the same credit_limit value — null limit ⇒ no alert).
     *
     * Triggering inside the service (vs. always firing) keeps the bell
     * inbox quiet on normal small balances and loud only when human
     * judgment is actually needed.
     */
    public function customerDebtChanged(\App\Models\Customer $customer, ?\App\Models\Invoice $invoice = null): void
    {
        $customer->refresh();
        $outstanding = $customer->outstandingDebt();
        $limit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;

        // Skip the bell when there's no ceiling to compare against, or the
        // customer is comfortably under it. Restaurant owners that DO want
        // to monitor general balances can use the dashboard widget — this
        // alert is reserved for the "needs attention now" case.
        if ($limit === null || $outstanding < $limit - 0.01) {
            return;
        }

        $branchId = $invoice?->branch_id ?? $customer->default_branch_id;
        $audience = $this->branchUsersWithRoles($branchId, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);

        $this->send($audience, new CustomerDebtAlertNotification(
            customer:    $customer,
            outstanding: $outstanding,
            creditLimit: $limit,
            invoice:     $invoice,
        ));
    }

    /**
     * Heads-up that a staff member clocked in past the company's late
     * threshold. The late employee themselves is intentionally NOT in the
     * audience — telling them they're late after the fact is noise, not
     * action. Managers + admins get it so they can address absenteeism
     * patterns, not just one-off lateness.
     */
    public function attendanceLate(\App\Models\Attendance $attendance, int $minutesLate): void
    {
        $audience = $this->branchUsersWithRoles($attendance->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
        $this->send($audience, new AttendanceLateNotification($attendance, $minutesLate));
    }

    /**
     * A cashier closed their shift. Goes to admins/managers (NOT the
     * cashier themselves — they just did the action). Severity escalates
     * with the absolute cash variance inside the notification class.
     */
    public function shiftClosed(\App\Models\Shift $shift): void
    {
        $branchId = $shift->branch_id ?? optional($shift->user)->primaryBranch()?->id;
        $audience = $this->branchUsersWithRoles($branchId, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
        $this->send($audience, new ShiftClosedNotification($shift));
    }

    /**
     * A purchase order was (fully or partially) received. Goes to admins
     * + managers + the original creator (so the person who ordered the
     * goods knows they arrived even if they're not the one receiving).
     */
    public function purchaseReceived(\App\Models\PurchaseOrder $po, bool $partial = false): void
    {
        $audience = $this->branchUsersWithRoles($po->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);

        // Add the PO creator if not already in the audience — they may be a
        // chef or other role that isn't in the default management list.
        if ($po->created_by) {
            $creator = \App\Models\User::find($po->created_by);
            if ($creator && $creator->status === 'active') {
                $audience = $audience->concat([$creator])->unique('id')->values();
            }
        }

        $this->send($audience, new PurchaseReceivedNotification($po, $partial));
    }

    /**
     * Reminder fired by the scheduled command for reservations starting
     * within the next hour. Goes to managers/admins + the floor team
     * (waiters) so they can prep the table.
     */
    public function reservationUpcoming(\App\Models\Reservation $reservation): void
    {
        $audience = $this->branchUsersWithRoles($reservation->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Waiter->value,
        ]);
        $this->send($audience, new ReservationUpcomingNotification($reservation));
    }

    /**
     * Diner tapped "request bill". Cashier + waiter need to know NOW.
     */
    public function billRequested(\App\Models\TableSession $session): void
    {
        $audience = $this->branchUsersWithRoles($session->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Cashier->value,
            UserRole::Waiter->value,
        ]);
        $this->send($audience, new BillRequestedNotification($session));
    }

    /**
     * Inter-branch transfer was just sent — alert the RECEIVING branch's
     * staff so they're ready to confirm arrival. Audience is purposely
     * limited to the receiving side; the sending branch already knows.
     */
    public function branchTransferSent(\App\Models\BranchTransfer $transfer): void
    {
        $audience = $this->branchUsersWithRoles($transfer->to_branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            // Chef + Bartender often handle inbound stock at the kitchen/bar door
            UserRole::Chef->value,
            UserRole::Bartender->value,
        ]);
        $this->send($audience, new BranchTransferSentNotification($transfer));
    }

    /**
     * Receiving branch confirmed arrival — close the loop for the SENDING
     * branch's staff so they know the in-transit stock has landed safely.
     */
    public function branchTransferReceived(\App\Models\BranchTransfer $transfer): void
    {
        $audience = $this->branchUsersWithRoles($transfer->from_branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            // Whoever sent the transfer is usually the chef/bartender at the source
            UserRole::Chef->value,
            UserRole::Bartender->value,
        ]);
        $this->send($audience, new BranchTransferReceivedNotification($transfer));
    }

    // ─── Audience resolution ─────────────────────────────────────────────

    /**
     * Resolve a recipient set for a branch + roles. Always includes every
     * owner-level user (SuperAdmin + Partner), regardless of branch.
     *
     * Branch-scoped users are matched through the `branch_user` pivot —
     * the same way BranchScope decides who sees what data.
     */
    protected function branchUsersWithRoles(?int $branchId, array $roles): Collection
    {
        // Owner-level — every event, every branch, no pivot check.
        $owners = User::query()
            ->whereIn('role', UserRole::ownerRoles())
            ->where('status', 'active')
            ->get();

        if (! $branchId) {
            // Global event with no branch context → owners only.
            return $owners->unique('id')->values();
        }

        // Branch-scoped staff with one of the requested roles.
        $branchStaff = User::query()
            ->where('status', 'active')
            ->whereIn('role', $roles)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->get();

        return $owners->concat($branchStaff)->unique('id')->values();
    }

    // ─── Send (handles empty audience + branch-context safety) ───────────

    /**
     * Dispatch the notification to a collection of users. Wrapping
     * Notification::send in our own method gives us:
     *   - One place to bail on empty audiences (Laravel logs a noisy warning otherwise)
     *   - One place to add future cross-cutting behavior (rate-limit per
     *     type, dedupe, suppress for self-acting user, etc.)
     *
     * Note: we use sendNow (sync) instead of send. Sync delivery is fine
     * here — it's a DB insert per row and the audience is small. If the
     * row count grows or we add channels with HTTP I/O (mail/Slack), flip
     * to send() to push onto the queue.
     */
    public function send(Collection $users, BaseNotification $notification): void
    {
        if ($users->isEmpty()) return;

        // Suppress notifying the user who triggered the event — they
        // already know they did the thing.
        if ($actorId = optional(auth()->user())->id) {
            $users = $users->reject(fn ($u) => $u->id === $actorId)->values();
            if ($users->isEmpty()) return;
        }

        try {
            Notification::sendNow($users, $notification);
        } catch (\Throwable $e) {
            // Notifications are best-effort. A DB hiccup shouldn't roll
            // back the actual business action (an order placement, a
            // refund). Log and move on.
            report($e);
        }
    }
}
