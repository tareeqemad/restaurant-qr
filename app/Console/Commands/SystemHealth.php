<?php

namespace App\Console\Commands;

use App\Services\Deployment\SystemHealthService;
use Illuminate\Console\Command;

class SystemHealth extends Command
{
    protected $signature = 'app:health {--json : Print the report as JSON.}';

    protected $description = 'Inspect production readiness: database, migrations, storage, cache, scheduler, backup, queue, and build.';

    public function handle(SystemHealthService $health): int
    {
        $report = $health->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['الحالة', 'الفحص', 'النتيجة', 'الإجراء'],
                collect($report['checks'])->map(fn (array $check) => [
                    match ($check['status']) {
                        'good' => 'OK', 'warning' => 'WARN', default => 'FAIL'
                    },
                    $check['label'],
                    $check['summary'].($check['detail'] ? ' — '.$check['detail'] : ''),
                    $check['command'] ?? '',
                ])->all(),
            );
            $this->line(sprintf(
                'Summary: %d ready, %d warnings, %d failures.',
                $report['summary']['good'],
                $report['summary']['warning'],
                $report['summary']['danger'],
            ));
        }

        return $report['summary']['danger'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
