<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Review;
use App\Models\SectionAssignment;
use App\Models\TableSession;
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
use App\Notifications\OrderChangeRequestedNotification;
use App\Notifications\OrderReadyNotification;
use App\Notifications\PurchaseReceivedNotification;
use App\Notifications\RefundIssuedNotification;
use App\Notifications\ReservationUpcomingNotification;
use App\Notifications\WaiterHelpNotification;
use App\Support\BranchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Single dispatch point for all in-app notifications.
 *
 * Each notification type maps to the smallest audience that can act on it.
 * Floor events go to the responsible waiter; management events stay with
 * managers and owners.
 *
 * Routing is intentionally centralized here (not in each Notification
 * class) so:
 *   1. The recipient list is consistent across the codebase — no
 *      controller forgets to include a role.
 *   2. Adding a new role only touches this file, not every event point.
 *   3. Testing audiences is one focused service, not a scatter hunt.
 *
 * Owner-level users are included only for management events. Floor events
 * stay with the employee who can act on them now; oversight belongs in the
 * live dashboards, not in a permanently noisy bell inbox.
 */
class NotifyService
{
    // ─── High-level fire-and-forget helpers ──────────────────────────────

    public function newOrder(Order $order): void
    {
        // The bell is the first thing a waiter sees.  Ship enough context to
        // describe the round, never a blind «approve» action.
        $order->loadMissing('table', 'items.station', 'tableSession.orders');
        $audience = $this->floorWaiters(
            $order->branch_id,
            $order->table?->zone_lookup_id,
            $order->tableSession?->assigned_waiter_id,
        );
        $audience = $this->floorFallback($audience, $order->branch_id);
        $this->send($audience, new NewOrderNotification($order));
    }

    /** A customer changed their mind; only the waiter who can decide gets it. */
    public function orderChangeRequested(OrderChangeRequest $changeRequest): void
    {
        $changeRequest->loadMissing('order.table', 'order.tableSession', 'orderItem');
        $order = $changeRequest->order;
        if (! $order) {
            return;
        }

        $audience = $this->floorWaiters(
            $order->branch_id,
            $order->table?->zone_lookup_id,
            $order->tableSession?->assigned_waiter_id,
        );
        $audience = $this->floorFallback($audience, $order->branch_id);
        $this->send($audience, new OrderChangeRequestedNotification($changeRequest));
    }

    public function orderCancelled(Order $order, ?string $reason = null): void
    {
        $audience = $this->branchUsersWithRoles($order->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Cashier->value,
        ]);
        $this->send($audience, new OrderCancelledNotification($order, $reason));
    }

    public function newReservation(Reservation $reservation): void
    {
        $audience = $this->branchUsersWithRoles($reservation->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Waiter->value,
        ]);
        $this->send($audience, new NewReservationNotification($reservation));
    }

    public function newReview(Review $review): void
    {
        // Managers + admins moderate. Low ratings still go through here
        // (the notification class itself escalates severity to warning).
        $audience = $this->branchUsersWithRoles($review->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
        $this->send($audience, new NewReviewNotification($review));
    }

    public function lowStock(Ingredient $ingredient): void
    {
        $audience = $this->branchUsersWithRoles($ingredient->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Chef->value,
        ]);
        $this->send($audience, new LowStockNotification($ingredient));
    }

    public function refundIssued(Refund $refund): void
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
    public function customerDebtChanged(Customer $customer, ?Invoice $invoice = null): void
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
            customer: $customer,
            outstanding: $outstanding,
            creditLimit: $limit,
            invoice: $invoice,
        ));
    }

    /**
     * Heads-up that a staff member clocked in past the company's late
     * threshold. The late employee themselves is intentionally NOT in the
     * audience — telling them they're late after the fact is noise, not
     * action. Managers + admins get it so they can address absenteeism
     * patterns, not just one-off lateness.
     */
    public function attendanceLate(Attendance $attendance, int $minutesLate): void
    {
        $audience = $this->branchUsersWithRoles($attendance->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);
        $this->send($audience, new AttendanceLateNotification($attendance, $minutesLate));
    }

    /**
     * A purchase order was (fully or partially) received. Goes to admins
     * + managers + the original creator (so the person who ordered the
     * goods knows they arrived even if they're not the one receiving).
     */
    public function purchaseReceived(PurchaseOrder $po, bool $partial = false): void
    {
        $audience = $this->branchUsersWithRoles($po->branch_id, [
            UserRole::Admin->value,
            UserRole::Manager->value,
        ]);

        // Add the PO creator if not already in the audience — they may be a
        // chef or other role that isn't in the default management list.
        if ($po->created_by) {
            $creator = User::find($po->created_by);
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
    public function reservationUpcoming(Reservation $reservation): void
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
    public function billRequested(TableSession $session): void
    {
        $session->loadMissing('table');
        $audience = $this->branchUsersWithRoles($session->branch_id, [
            UserRole::Cashier->value,
        ], includeOwners: false);
        $audience = $audience
            ->concat($this->floorWaiters(
                $session->branch_id,
                $session->table?->zone_lookup_id,
                $session->assigned_waiter_id,
            ))
            ->unique('id')
            ->values();
        $audience = $this->floorFallback($audience, $session->branch_id);
        $this->send($audience, new BillRequestedNotification($session));
    }

    /** A table raised its hand; this is a floor action, not a passive log. */
    public function waiterHelp(TableSession $session): void
    {
        $session->loadMissing('table');
        $audience = $this->floorWaiters(
            $session->branch_id,
            $session->table?->zone_lookup_id,
            $session->assigned_waiter_id,
        );
        $audience = $this->floorFallback($audience, $session->branch_id);
        $this->send($audience, new WaiterHelpNotification($session));
    }

    /** One notification per kitchen/bar ready action, even for bulk taps. */
    public function orderReady(Order $order): void
    {
        $order->loadMissing('table', 'tableSession');
        $audience = $this->floorWaiters(
            $order->branch_id,
            $order->table?->zone_lookup_id,
            $order->tableSession?->assigned_waiter_id,
        );
        $audience = $this->floorFallback($audience, $order->branch_id);
        $this->send($audience, new OrderReadyNotification($order));
    }

    /**
     * Inter-branch transfer was just sent — alert the RECEIVING branch's
     * staff so they're ready to confirm arrival. Audience is purposely
     * limited to the receiving side; the sending branch already knows.
     */
    public function branchTransferSent(BranchTransfer $transfer): void
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
    public function branchTransferReceived(BranchTransfer $transfer): void
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
     * Resolve a recipient set for a branch + roles. Management callers may
     * include owner-level users (SuperAdmin + Partner), regardless of branch.
     *
     * Branch-scoped users are matched through the `branch_user` pivot —
     * the same way BranchScope decides who sees what data.
     */
    protected function branchUsersWithRoles(?int $branchId, array $roles, bool $includeOwners = true): Collection
    {
        $owners = $includeOwners
            ? User::query()
                ->whereIn('role', UserRole::ownerRoles())
                ->where('status', 'active')
                ->get()
            : collect();

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

    /**
     * Route floor noise through today's section roster. If no roster exists,
     * every branch waiter remains the fallback so setup is never mandatory.
     */
    protected function floorWaiters(
        ?int $branchId,
        ?int $zoneId,
        ?int $assignedWaiterId = null,
    ): Collection {
        if (! $branchId) {
            return collect();
        }

        if ($assignedWaiterId) {
            $assigned = User::query()
                ->whereKey($assignedWaiterId)
                ->where('status', 'active')
                ->where('role', UserRole::Waiter->value)
                ->whereHas('branches', fn ($query) => $query->where('branches.id', $branchId))
                ->get();

            if ($assigned->isNotEmpty()) {
                return $assigned;
            }
        }

        $waiters = $this->branchUsersWithRoles(
            $branchId,
            [UserRole::Waiter->value],
            includeOwners: false,
        );
        $rosterDate = BranchContext::forBranch(
            $branchId,
            fn () => SectionAssignment::effectiveDate(),
        );

        if ($rosterDate !== null && $zoneId !== null) {
            $rosteredIds = BranchContext::forBranch($branchId, fn () => SectionAssignment::query()
                ->forRosterableWaiters()
                ->forDate($rosterDate)
                ->where('zone_lookup_id', $zoneId)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all());

            $rostered = $waiters->whereIn('id', $rosteredIds)->values();
            if ($rostered->isNotEmpty()) {
                return $rostered;
            }
        }

        return $waiters->unique('id')->values();
    }

    /** Never lose a floor event just because the shift roster is empty. */
    protected function floorFallback(Collection $audience, ?int $branchId): Collection
    {
        if ($audience->isNotEmpty()) {
            return $audience;
        }

        return $this->branchUsersWithRoles(
            $branchId,
            [UserRole::Manager->value],
            includeOwners: false,
        );
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
        if ($users->isEmpty()) {
            return;
        }

        // Suppress notifying the user who triggered the event — they
        // already know they did the thing.
        if ($actorId = optional(auth()->user())->id) {
            $users = $users->reject(fn ($u) => $u->id === $actorId)->values();
            if ($users->isEmpty()) {
                return;
            }
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
