<?php

namespace App\Console\Commands;

use App\Services\Deployment\AccountingSchemaUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class UpgradeAccountingSchema extends Command
{
    protected $signature = 'accounting:schema-upgrade
        {--apply : Apply the additive upgrade; without it the command only inspects}
        {--skip-backup : Apply without taking a local backup first}';

    protected $description = 'Inspect or safely upgrade an existing database for accounting, credit, refund, and reconciliation controls.';

    public function handle(AccountingSchemaUpgrade $upgrade): int
    {
        if (! $upgrade->supportsCurrentConnection()) {
            $this->error('This upgrade bridge supports MySQL only.');

            return self::FAILURE;
        }

        $pending = $upgrade->pendingChanges();
        if ($pending === []) {
            $this->info('Accounting schema is current; no upgrade is required.');

            return self::SUCCESS;
        }

        $this->warn('Accounting schema upgrade required:');
        foreach ($pending as $change) {
            $this->line("  - {$change}");
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('Inspection only. Normal deploys apply this through migrations; run with --apply for an immediate upgrade.');

            return self::SUCCESS;
        }

        if (! $this->option('skip-backup')) {
            $this->line('Creating a local database backup first...');
            $exit = Artisan::call('backup:run', ['--no-upload' => true]);
            $this->output->write(Artisan::output());
            if ($exit !== self::SUCCESS) {
                $this->error('Upgrade aborted because the safety backup failed. No schema change was attempted.');

                return self::FAILURE;
            }
        }

        try {
            $changes = $upgrade->apply();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('It is safe to fix the reported issue and run the command again.');

            return self::FAILURE;
        }

        $this->newLine();
        foreach ($changes as $change) {
            $this->line("  ✓ {$change}");
        }
        $this->info('Accounting schema upgrade completed without deleting business data.');

        return self::SUCCESS;
    }
}
