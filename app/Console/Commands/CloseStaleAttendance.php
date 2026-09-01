<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Forgotten checkouts must stop the live counter without creating fictional
 * paid hours. A quarantined row contributes zero until a manager corrects it.
 */
class CloseStaleAttendance extends Command
{
    protected $signature = 'attendance:close-stale
                            {--hours=24 : Open records older than this need review}
                            {--dry : Report what would change without writing}';

    protected $description = 'Move forgotten attendance records to manager review without crediting guessed hours';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dry = (bool) $this->option('dry');
        $threshold = now()->subHours($hours);
        $matched = 0;
        $closed = 0;

        Attendance::query()
            ->open()
            ->where('clock_in_at', '<=', $threshold)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($hours, $dry, &$matched, &$closed) {
                foreach ($rows as $row) {
                    $matched++;

                    if ($dry) {
                        $this->line("#{$row->id} — الموظف {$row->user_id} — سيُنقل للمراجعة");

                        continue;
                    }

                    $changed = DB::transaction(function () use ($row, $hours) {
                        $locked = Attendance::query()->whereKey($row->id)->lockForUpdate()->first();
                        if (! $locked?->isOpen()) {
                            return false;
                        }

                        $stamp = now()->format('Y/m/d H:i');
                        $locked->markNeedsReview(
                            "[مراجعة مطلوبة {$stamp}] بقي السجل مفتوحاً أكثر من {$hours} ساعة؛ لم تُحتسب ساعات تقديرية."
                        );

                        return true;
                    }, 3);

                    if ($changed) {
                        $closed++;
                    }
                }
            });

        if ($matched === 0) {
            $this->info("لا توجد سجلات مفتوحة تجاوزت {$hours} ساعة.");
        } elseif ($dry) {
            $this->warn("وضع المعاينة: {$matched} سجل يحتاج مراجعة، دون حفظ تغييرات.");
        } else {
            $this->info("نُقل {$closed} سجل إلى المراجعة دون احتساب ساعات غير مؤكدة.");
        }

        return self::SUCCESS;
    }
}
