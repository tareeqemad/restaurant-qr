<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill suppliers that have no rows in `branch_supplier`.
 *
 * Context: the original branch_supplier migration (2026_05_03_100000)
 * attached every then-existing supplier to every active branch. New
 * suppliers since then are expected to be attached via the controller,
 * which defaults to the active branch on create. But anything created
 * out-of-band — tinker, seeders run after that migration, an older
 * import — can end up orphaned (no pivot rows) and is therefore
 * invisible under the new branch-strict supplier filter.
 *
 * This migration finds those orphans and attaches each to every active
 * branch. That's the same fallback the original backfill used, and it
 * preserves the "supplier is visible everywhere" behavior that orphans
 * accidentally enjoyed before the strict filter went in.
 *
 * Reversal is intentionally a no-op: once branch admins start
 * curating per-branch supplier lists, we can't tell rows we added
 * here apart from rows users intentionally added afterwards.
 */
return new class extends Migration {
    public function up(): void
    {
        $branchIds = DB::table('branches')->where('is_active', true)->pluck('id');
        if ($branchIds->isEmpty()) {
            return;
        }

        $orphanIds = DB::table('suppliers')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('branch_supplier')
                  ->whereColumn('branch_supplier.supplier_id', 'suppliers.id');
            })
            ->pluck('id');

        if ($orphanIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($orphanIds as $supplierId) {
            foreach ($branchIds as $branchId) {
                $rows[] = [
                    'supplier_id' => $supplierId,
                    'branch_id'   => $branchId,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('branch_supplier')->insert($chunk);
        }
    }

    public function down(): void
    {
        // Intentional no-op. See class docblock.
    }
};
