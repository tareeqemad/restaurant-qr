<?php

namespace App\Console\Commands;

use App\Services\Deployment\AccountingSchemaUpgrade;
use App\Services\Deployment\MigrationReconciler;
use App\Services\Deployment\PublicStorageService;
use App\Services\Deployment\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DeployApplication extends Command
{
    protected $signature = 'app:deploy
        {--skip-backup : Deploy database changes without taking a pre-migration backup.}
        {--no-optimize : Leave caches cleared instead of rebuilding production caches.}';

    protected $description = 'Run a safe production deployment: clear stale caches, back up, migrate, prepare storage, optimize, and verify.';

    public function handle(
        SystemHealthService $health,
        PublicStorageService $storage,
        MigrationReconciler $reconciler,
        AccountingSchemaUpgrade $accounting,
    ): int {
        $this->components->info('Restaurant QR production deployment');

        if (! $this->runArtisan('Clearing stale framework caches', 'optimize:clear')
            || ! $this->runArtisan('Loading fresh .env configuration', 'config:cache')) {
            return self::FAILURE;
        }
        $this->reloadCachedConfiguration();

        $url = (string) config('app.url');
        $remoteUrl = ! str_contains($url, 'localhost') && ! str_contains($url, '127.0.0.1');
        if ($remoteUrl && (app()->environment() !== 'production' || config('app.debug'))) {
            $this->error('Refusing deployment: remote APP_URL requires APP_ENV=production and APP_DEBUG=false.');

            return self::FAILURE;
        }

        try {
            $pending = $health->pendingMigrations();
            $accountingPending = $accounting->supportsCurrentConnection() ? $accounting->pendingChanges() : [];
        } catch (\Throwable $e) {
            $this->error('Database preflight failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (($pending !== [] || $accountingPending !== []) && $health->hasBusinessData() && ! $this->option('skip-backup')) {
            if (! $this->runArtisan('Creating pre-migration backup', 'backup:run', ['--no-upload' => true])) {
                $this->error('Deployment stopped before schema changes. Take a manual backup, then rerun with --skip-backup.');

                return self::FAILURE;
            }
        }

        $reconciled = $reconciler->apply();
        if ($reconciled !== []) {
            $this->line('  ✓ Reconciled '.count($reconciled).' verified existing migration(s) without schema SQL.');
        }

        if (! $this->runArtisan('Applying database migrations', 'migrate', ['--force' => true])) {
            return self::FAILURE;
        }

        if ($accounting->supportsCurrentConnection() && $accounting->pendingChanges() !== []) {
            try {
                $changes = $accounting->apply();
                $this->line('  ✓ Applied '.count($changes).' additive accounting schema change(s).');
            } catch (\Throwable $e) {
                $this->error('Accounting schema upgrade failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $storageResult = $storage->prepare();
        match ($storageResult['status']) {
            'good' => $this->components->task('Preparing public storage', fn () => true),
            'warning' => $this->warn($storageResult['message'].($storageResult['command'] ? "\n{$storageResult['command']}" : '')),
            default => $this->error($storageResult['message']),
        };
        if ($storageResult['status'] === 'danger') {
            return self::FAILURE;
        }

        if (! $this->option('no-optimize') && ! $this->runArtisan('Building Laravel production caches', 'optimize')) {
            return self::FAILURE;
        }

        $report = $health->report();
        foreach ($report['checks'] as $check) {
            $mark = match ($check['status']) {
                'good' => '✓', 'warning' => '!', default => '✗'
            };
            $this->line("  {$mark} {$check['label']}: {$check['summary']}");
        }

        if ($report['summary']['danger'] > 0) {
            $this->error('Deployment finished its steps, but production readiness still has failures. Run php artisan app:health.');

            return self::FAILURE;
        }

        $this->info('Deployment completed and verified.');

        return self::SUCCESS;
    }

    private function runArtisan(string $label, string $command, array $arguments = []): bool
    {
        $exit = self::FAILURE;
        try {
            $this->components->task($label, function () use ($command, $arguments, &$exit) {
                $exit = Artisan::call($command, $arguments);

                return $exit === self::SUCCESS;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        if ($exit !== self::SUCCESS) {
            $this->output->write(Artisan::output());
        }

        return $exit === self::SUCCESS;
    }

    private function reloadCachedConfiguration(): void
    {
        $path = app()->getCachedConfigPath();
        if (! is_file($path)) {
            return;
        }

        $fresh = require $path;
        foreach ($fresh as $key => $value) {
            config()->set($key, $value);
        }
    }
}
