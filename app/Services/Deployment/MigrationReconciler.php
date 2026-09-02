<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles squashed one-table migrations with imported/legacy databases.
 * It never runs SQL from a migration and records a migration only when its
 * table already exists (or the known additive accounting upgrade is already
 * satisfied). This prevents `migrate` from trying to recreate live tables.
 */
class MigrationReconciler
{
    public function __construct(private readonly AccountingSchemaUpgrade $accounting) {}

    /** @return list<string> */
    public function candidates(): array
    {
        $repository = app('migration.repository');
        $ran = $repository->repositoryExists() ? $repository->getRan() : [];

        return collect(File::files(database_path('migrations')))
            ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
            ->reject(fn (string $migration) => in_array($migration, $ran, true))
            ->filter(function (string $migration): bool {
                if (preg_match('/_create_(.+)_table$/', $migration, $match)) {
                    return Schema::hasTable($match[1]);
                }

                return $migration === '2026_09_02_000001_upgrade_legacy_accounting_schema'
                    && $this->accounting->supportsCurrentConnection()
                    && $this->accounting->pendingChanges() === [];
            })
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function apply(): array
    {
        $repository = app('migration.repository');
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }

        $candidates = $this->candidates();
        if ($candidates === []) {
            return [];
        }

        $batch = $repository->getNextBatchNumber();
        foreach ($candidates as $migration) {
            $repository->log($migration, $batch);
        }

        return $candidates;
    }
}
