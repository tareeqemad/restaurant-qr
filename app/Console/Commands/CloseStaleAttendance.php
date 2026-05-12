<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-closes attendance records that have been open longer than the
 * configured stale window (default: 24 hours). A staffer who forgets to
 * clock out leaves the system counting indefinitely — meaningless for
 * payroll, distorts the live "currently working" widget, and inflates
 * worked-minutes reports.
 *
 * Strategy:
 *   • Stale shifts are clamped to clock_in + 12h (a generous full shift)
 *     rather than `now()`, so payroll isn't credited for the unattended
 *     time between actual departure and the cron sweep.
 *   • source = 'auto_closed' marks the row so reports can tell apart
 *     manager corrections from cron sweeps.
 *   • notes get a stamped Arabic explanation for audit visibility.
 *
 * Idempotent: each shift only ever crosses the stale boundary once.
 */
class CloseStaleAttendance extends Command
{
    protected $signature   = 'attendance:close-stale
                              {--hours=24 : Open shifts older than this are considered stale}
                              {--shift-cap=12 : Cap worked hours credited to the auto-closed shift}
                              {--dry : Report what would change without writing}';

    protected $description = 'Close attendance shifts left open beyond the stale window (default 24h)';

    public function handle(): int
    {
        $hours    = max(1, (int) $this->option('hours'));
        $shiftCap = max(1, (int) $this->option('shift-cap'));
        $dry      = (bool) $this->option('dry');

        $threshold = now()->subHours($hours);

        $stale = Attendance::open()
            ->where('clock_in_at', '<=', $threshold)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('لا توجد جلسات حضور معلّقة تجاوزت ' . $hours . ' ساعة.');
            return self::SUCCESS;
        }

        $this->info("معالجة {$stale->count()} جلسة حضور معلّقة (أقدم من {$hours} ساعة)...");

        $closed = 0;

        DB::transaction(function () use ($stale, $shiftCap, $dry, &$closed) {
            foreach ($stale as $att) {
                $clockOut = $att->clock_in_at->copy()->addHours($shiftCap);

                // Never stamp clock_out in the future — clamp to now if
                // the shift cap would land beyond the current moment
                // (only relevant if hours < shift-cap — defensive).
                if ($clockOut->greaterThan(now())) {
                    $clockOut = now();
                }

                $minutes = max(0, (int) round(
                    $att->clock_in_at->diffInSeconds($clockOut) / 60
                ) - (int) $att->break_minutes);

                $stamp = Carbon::now()->format('Y-m-d H:i');
                $note  = "[إغلاق تلقائي {$stamp}] تجاوز {$hours} ساعة بدون انصراف. تم احتساب الورديّة بحد أقصى {$shiftCap} ساعة.";

                $existingNote = trim((string) $att->notes);
                $merged = $existingNote === ''
                    ? $note
                    : ($existingNote . "\n" . $note);

                if ($dry) {
                    $this->line("  • #{$att->id} user={$att->user_id} → ينغلق على {$clockOut->format('Y-m-d H:i')} ({$minutes} د)");
                    continue;
                }

                $att->update([
                    'clock_out_at'   => $clockOut,
                    'worked_minutes' => $minutes,
                    'source'         => 'auto_closed',
                    'notes'          => $merged,
                ]);

                $closed++;
            }
        });

        if ($dry) {
            $this->warn('وضع التجربة (--dry): لم يتم حفظ أي تغييرات.');
        } else {
            $this->info("تم إغلاق {$closed} جلسة بنجاح.");
        }

        return self::SUCCESS;
    }
}
