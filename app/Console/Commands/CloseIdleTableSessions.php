<?php

namespace App\Console\Commands;

use App\Models\TableSession;
use App\Services\BillingService;
use App\Support\Duration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto-closes table sessions that have been idle beyond the configured
 * window (default: restaurant.order.session_idle_close_minutes = 240).
 *
 * Without this sweep the only expiry the QR session ever had was the
 * CUSTOMER's cookie lifetime — the server-side row stayed `active`
 * forever. Production accumulated zombie sessions open for days
 * ("منذ 9950 د"), tables looked permanently occupied, and a new guest
 * scanning the same table silently JOINED the previous party's session
 * (their orders would merge onto a stranger's bill).
 *
 * Strategy:
 *   • Only ZERO-EXPOSURE sessions are closed (BillingService::isZeroExposure):
 *     no orders, everything cancelled/zero-total, or invoice fully paid.
 *   • Sessions with unpaid money are NEVER auto-closed — the cashier must
 *     settle them. They're counted and logged as a warning so the manager
 *     sees which tables need attention.
 *   • Closing goes through BillingService::closeZeroExposureSession — the
 *     same path as a manual close — so the table is freed and the
 *     TableStatusChanged broadcast refreshes the boards.
 *
 * Idempotent: closed sessions leave the `active` filter, and the service
 * method re-checks status under lock, so overlapping runs are harmless.
 */
class CloseIdleTableSessions extends Command
{
    protected $signature   = 'table-sessions:close-idle
                              {--minutes= : Idle threshold in minutes (defaults to restaurant.order.session_idle_close_minutes)}
                              {--dry-run : Report what would close without writing}';

    protected $description = 'Close zero-exposure table sessions idle beyond the configured window (default 240 min)';

    public function handle(BillingService $billing): int
    {
        $minutes = (int) ($this->option('minutes')
            ?: config('restaurant.order.session_idle_close_minutes', 240));
        $minutes = max(1, $minutes);
        $dryRun  = (bool) $this->option('dry-run');

        $threshold = now()->subMinutes($minutes);

        // Last activity falls back to opened_at for legacy rows created
        // before the last_activity_at column existed.
        $query = TableSession::query()
            ->where('status', 'active')
            ->where(function ($q) use ($threshold) {
                $q->where('last_activity_at', '<=', $threshold)
                  ->orWhere(function ($q2) use ($threshold) {
                      $q2->whereNull('last_activity_at')
                         ->where('opened_at', '<=', $threshold);
                  });
            });

        $closed  = 0;
        $skipped = [];   // table labels with money still on them
        $reason  = "إغلاق تلقائي — تجاوزت الجلسة حد الخمول ({$minutes} دقيقة) بدون نشاط";

        $query->orderBy('id')->chunkById(100, function ($sessions) use ($billing, $dryRun, $reason, &$closed, &$skipped) {
            foreach ($sessions as $session) {
                $idle = Duration::since($session->last_activity_at ?? $session->opened_at);

                if (! $billing->isZeroExposure($session)) {
                    $skipped[] = $session->tableLabel();
                    continue;
                }

                if ($dryRun) {
                    $this->line("  • جلسة #{$session->id} طاولة {$session->tableLabel()} (خاملة {$idle}) → ستُغلق");
                    $closed++;
                    continue;
                }

                try {
                    $billing->closeZeroExposureSession($session, null, $reason);
                    $this->line("  • جلسة #{$session->id} طاولة {$session->tableLabel()} (خاملة {$idle}) → أُغلقت");
                    $closed++;
                } catch (\Throwable $e) {
                    // One bad session must not kill the sweep — log and move on.
                    Log::error("table-sessions:close-idle — فشل إغلاق الجلسة #{$session->id}: {$e->getMessage()}");
                    $this->error("  • جلسة #{$session->id} طاولة {$session->tableLabel()} → فشل: {$e->getMessage()}");
                }
            }
        });

        if ($skipped !== []) {
            $labels = implode('، ', $skipped);
            $msg = 'جلسات خاملة عليها مبالغ غير مسدّدة — تحتاج الكاشير ولن تُغلق تلقائياً: طاولات '.$labels;
            $this->warn('  ! '.count($skipped).' '.$msg);
            Log::warning("table-sessions:close-idle — {$msg}");
        }

        if ($dryRun) {
            $this->warn("وضع التجربة (--dry-run): {$closed} جلسة كانت ستُغلق — لم يتم حفظ أي تغييرات.");
        } elseif ($closed === 0 && $skipped === []) {
            $this->info("لا توجد جلسات خاملة تجاوزت {$minutes} دقيقة.");
        } else {
            $this->info("تم إغلاق {$closed} جلسة خاملة.");
        }

        return self::SUCCESS;
    }
}
