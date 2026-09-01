<?php

namespace App\Console\Commands;

use App\Models\TableSession;
use App\Services\BillingService;
use App\Support\Duration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto-closes abandoned QR drafts quickly while keeping a separate, longer
 * safety window for real table visits.
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
                              {--minutes= : Override both idle thresholds in minutes}
                              {--dry-run : Report what would close without writing}';

    protected $description = 'Close idle QR drafts and zero-exposure table visits using separate thresholds';

    public function handle(BillingService $billing): int
    {
        $override = $this->option('minutes');
        $browseMinutes = max(1, (int) ($override
            ?: config('restaurant.order.browsing_session_idle_minutes', 20)));
        $engagedMinutes = max(1, (int) ($override
            ?: config('restaurant.order.session_idle_close_minutes', 240)));
        $dryRun  = (bool) $this->option('dry-run');

        $earliestThreshold = now()->subMinutes(min($browseMinutes, $engagedMinutes));

        // Last activity falls back to opened_at for legacy rows created
        // before the last_activity_at column existed.
        $query = TableSession::query()
            ->where('status', 'active')
            ->where(function ($q) use ($earliestThreshold) {
                $q->where('last_activity_at', '<=', $earliestThreshold)
                  ->orWhere(function ($q2) use ($earliestThreshold) {
                      $q2->whereNull('last_activity_at')
                         ->where('opened_at', '<=', $earliestThreshold);
                  });
            });

        $closed  = 0;
        $skipped = [];   // table labels with money still on them
        $query->orderBy('id')->chunkById(100, function ($sessions) use ($billing, $dryRun, $browseMinutes, $engagedMinutes, &$closed, &$skipped) {
            foreach ($sessions as $session) {
                $lastActivity = $session->last_activity_at ?? $session->opened_at;
                $minutes = $session->engaged_at ? $engagedMinutes : $browseMinutes;
                if (! $lastActivity || $lastActivity->gt(now()->subMinutes($minutes))) {
                    continue;
                }

                $idle = Duration::since($lastActivity);
                $reason = $session->engaged_at
                    ? "إغلاق تلقائي — تجاوزت الزيارة حد الخمول ({$minutes} دقيقة) بدون مستحقات"
                    : "إغلاق تلقائي — انتهت مهلة تصفح QR ({$minutes} دقيقة) بدون طلب";

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
            $this->info("لا توجد مسودات تجاوزت {$browseMinutes} دقيقة أو زيارات تجاوزت {$engagedMinutes} دقيقة.");
        } else {
            $this->info("تم إغلاق {$closed} جلسة خاملة.");
        }

        return self::SUCCESS;
    }
}
