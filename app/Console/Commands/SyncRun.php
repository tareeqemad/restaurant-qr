<?php

namespace App\Console\Commands;

use App\Sync\SyncManager;
use Illuminate\Console\Command;

/**
 * Run one sync pass. Wired to the scheduler every minute (routes/console.php)
 * so the moment the internet returns the backlog drains automatically, and
 * also invoked by the admin "Sync now" button.
 *
 *     php artisan sync:run
 *
 * Always exits SUCCESS — being offline is normal, not a failure; per-stream
 * outcomes live in the sync_states table for the status view.
 */
class SyncRun extends Command
{
    protected $signature = 'sync:run';

    protected $description = 'Sync with the cloud (config down, transactions up) when reachable.';

    public function handle(SyncManager $manager): int
    {
        $report = $manager->run();

        if (! empty($report['skipped'])) {
            $this->line('Sync skipped: '.$report['reason']);

            return self::SUCCESS;
        }

        if (! empty($report['offline'])) {
            $this->warn('Cloud unreachable — will retry on the next pass.');

            return self::SUCCESS;
        }

        foreach (($report['streams'] ?? []) as $name => $result) {
            $result['ok']
                ? $this->info("✓ {$name}: {$result['count']} row(s)")
                : $this->error("✗ {$name}: {$result['error']}");
        }

        return self::SUCCESS;
    }
}
