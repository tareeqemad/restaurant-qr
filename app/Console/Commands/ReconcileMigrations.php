<?php

namespace App\Console\Commands;

use App\Services\Deployment\MigrationReconciler;
use App\Services\Deployment\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ReconcileMigrations extends Command
{
    protected $signature = 'migrations:reconcile
        {--apply : Record verified existing structures in the migrations table.}
        {--skip-backup : Apply without taking a database backup first.}';

    protected $description = 'Safely reconcile imported/squashed databases without recreating existing tables.';

    public function handle(MigrationReconciler $reconciler, SystemHealthService $health): int
    {
        $candidates = $reconciler->candidates();
        if ($candidates === []) {
            $this->info('Migration history is already reconciled.');

            return self::SUCCESS;
        }

        $this->warn(count($candidates).' migration record(s) match structures that already exist.');
        foreach (array_slice($candidates, 0, 12) as $migration) {
            $this->line('  - '.$migration);
        }
        if (count($candidates) > 12) {
            $this->line('  … and '.(count($candidates) - 12).' more');
        }

        if (! $this->option('apply')) {
            $this->line('Inspection only. Run with --apply to reconcile them.');

            return self::SUCCESS;
        }

        if ($health->hasBusinessData() && ! $this->option('skip-backup')) {
            $this->line('Creating a safety backup first...');
            $exit = Artisan::call('backup:run', ['--no-upload' => true]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) {
                $this->error('Reconciliation aborted because the backup failed.');

                return self::FAILURE;
            }
        }

        $applied = $reconciler->apply();
        $this->info('Recorded '.count($applied).' verified migration(s); no schema SQL was executed.');

        return self::SUCCESS;
    }
}
