<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Notifications\ReservationUpcomingNotification;
use App\Services\NotifyService;
use App\Support\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic reminder for confirmed reservations starting within the next
 * `--window-minutes` (default: 60). Designed to be safe to run frequently
 * (every ~5 minutes) — de-duplication guarantees one reminder per
 * reservation, ever.
 *
 * De-dup strategy: query the `notifications` table directly for any row
 * whose payload references this reservation_id and whose type is
 * ReservationUpcomingNotification. If any exists, skip.
 *
 * Why query rather than mark-on-the-row: a `reminded_at` column on
 * Reservation would require a migration and only buy us this single
 * use case. The notifications table is already there and the lookup is
 * cheap (notif_recipient_unread_idx narrows it efficiently).
 */
class SendReservationReminders extends Command
{
    protected $signature   = 'notifications:reservation-reminders
                              {--window-minutes=60 : Minutes ahead to look}';
    protected $description = 'Send heads-up notifications for confirmed reservations starting soon';

    public function handle(NotifyService $notify): int
    {
        $minutes = (int) $this->option('window-minutes');
        $now     = now();
        $until   = $now->copy()->addMinutes($minutes);

        // Bypass BranchScope — we iterate every branch's reservations from
        // a single CLI run. The notification class itself stamps branch_id
        // from the reservation row.
        $reservations = BranchContext::unscoped(fn () =>
            Reservation::with(['customer', 'table', 'branch'])
                ->whereIn('status', [
                    ReservationStatus::Confirmed->value,
                    ReservationStatus::Pending->value,   // include pending so the floor knows even if no one confirmed yet
                ])
                ->whereBetween('reserved_for', [$now, $until])
                ->get()
        );

        $sent    = 0;
        $skipped = 0;

        foreach ($reservations as $reservation) {
            // De-dup: any prior reminder of this exact type for this exact
            // reservation? skip.
            $alreadySent = DB::table('notifications')
                ->where('type', ReservationUpcomingNotification::class)
                ->whereJsonContains('data->extra->reservation_id', $reservation->id)
                ->exists();

            if ($alreadySent) {
                $skipped++;
                continue;
            }

            BranchContext::forBranch($reservation->branch_id, fn () =>
                $notify->reservationUpcoming($reservation)
            );
            $sent++;
        }

        $this->info("Reservation reminders — sent: {$sent}, skipped (already reminded): {$skipped}, scanned: " . $reservations->count());
        return self::SUCCESS;
    }
}
