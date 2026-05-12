<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `branch_id` to the five financial child tables that were leaking
 * cross-branch when aggregated.
 *
 * Why this matters (proven leak):
 *   `CashierController.php`:
 *     'cash_today' => Payment::whereDate('created_at', today())
 *                            ->where('method', 'cash')
 *                            ->sum('amount');
 *   The query has NO branch filter — it sums every branch's cash. Manager
 *   in Gaza saw "5,000 ₪ كاش اليوم"; manager in Khanyounis saw the SAME
 *   5,000 (= total of both branches). Same problem on every dashboard
 *   KPI that goes through Payment / Refund / OrderDiscount / InvoiceSplit /
 *   CashMovement without joining back through their parent.
 *
 * Tables affected & how branch_id is derived:
 *   - payments         → invoices.branch_id (via invoice_id)
 *   - refunds          → invoices.branch_id (via invoice_id)
 *   - order_discounts  → orders.branch_id   (via order_id)
 *   - invoice_splits   → invoices.branch_id (via invoice_id)
 *   - cash_movements   → shifts.branch_id   (via shift_id)
 *
 * Migration plan (data-preserving):
 *   1. Add `branch_id` as nullable FK
 *   2. Backfill from the parent table
 *   3. Once every row has a value, change to NOT NULL
 *   4. Add a (branch_id, *) compound index for the main aggregate columns
 *
 * Counts at migration time: payments=5 rows, others=0 rows.
 * Backfill is therefore tiny; safe to run inline (no chunking needed).
 */
return new class extends Migration
{
    /**
     * Each entry: [table, parent_table, parent_fk_on_child, date_column_for_index].
     * The date column is the one most-aggregated together with branch_id
     * (cash today, refunds today, discounts in P&L period, etc.).
     */
    protected array $tables = [
        ['payments',        'invoices', 'invoice_id', 'paid_at'],
        ['refunds',         'invoices', 'invoice_id', 'refunded_at'],
        ['order_discounts', 'orders',   'order_id',   'created_at'],
        ['invoice_splits',  'invoices', 'invoice_id', 'paid_at'],
        ['cash_movements',  'shifts',   'shift_id',   'created_at'],
    ];

    public function up(): void
    {
        foreach ($this->tables as [$table, $parent, $fk, $dateCol]) {

            // ─── Step 1: add nullable branch_id column ───────────────
            if (! Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, function (Blueprint $t) use ($fk) {
                    $t->foreignId('branch_id')
                        ->nullable()
                        ->after($fk)
                        ->constrained('branches')
                        // Branches can't be hard-deleted while operational
                        // tables reference them; restrict mirrors how the
                        // parent FK on the child already behaves.
                        ->restrictOnDelete();
                });
            }

            // ─── Step 2: backfill from parent ────────────────────────
            // Single UPDATE-with-JOIN — works on MySQL/MariaDB. Covers
            // every existing row in one statement (current counts are
            // tiny, but the same pattern scales to millions).
            $this->backfillBranchId($table, $parent, $fk);

            // ─── Step 3: any leftover NULLs would block NOT NULL.
            // If the parent itself is NULL on branch_id (shouldn't happen —
            // every parent table is BelongsToBranch with NOT NULL constraint
            // — but defense in depth) we abort with a clear message rather
            // than silently force a value.
            $orphans = DB::table($table)->whereNull('branch_id')->count();
            if ($orphans > 0) {
                throw new \RuntimeException(
                    "Cannot make `{$table}.branch_id` NOT NULL: {$orphans} row(s) "
                    . "have no parent branch_id. Investigate manually before re-running."
                );
            }

            // ─── Step 4: lock to NOT NULL ────────────────────────────
            // change() requires doctrine/dbal in older Laravel; in 11.x
            // the schema builder handles this natively.
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('branch_id')->nullable(false)->change();
            });

            // ─── Step 5: composite index for the typical aggregate ───
            //   "branch + date" is the shape of every cash/refund/discount
            //   KPI query; without this MySQL would scan via the date
            //   index and filter branch in memory.
            $idxName = "{$table}_branch_{$dateCol}_idx";
            if ($dateCol && ! $this->hasIndex($table, $idxName)) {
                Schema::table($table, function (Blueprint $t) use ($dateCol, $idxName) {
                    $t->index(['branch_id', $dateCol], $idxName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as [$table, , , $dateCol]) {
            $idxName = "{$table}_branch_{$dateCol}_idx";
            if ($this->hasIndex($table, $idxName)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($idxName));
            }
            if (Schema::hasColumn($table, 'branch_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
            }
        }
    }

    protected function backfillBranchId(string $table, string $parent, string $fk): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("
                UPDATE `{$table}` AS c
                INNER JOIN `{$parent}` AS p ON p.id = c.`{$fk}`
                SET c.branch_id = p.branch_id
                WHERE c.branch_id IS NULL
                  AND p.branch_id IS NOT NULL
            ");

            return;
        }

        DB::table($table)
            ->whereNull('branch_id')
            ->whereExists(function ($query) use ($table, $parent, $fk) {
                $query->selectRaw('1')
                    ->from($parent)
                    ->whereColumn("{$parent}.id", "{$table}.{$fk}")
                    ->whereNotNull("{$parent}.branch_id");
            })
            ->update([
                'branch_id' => DB::raw(
                    "(select `branch_id` from `{$parent}` where `{$parent}`.`id` = `{$table}`.`{$fk}` limit 1)"
                ),
            ]);
    }

    /**
     * Cross-database "does this index already exist?".
     */
    protected function hasIndex(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }
};
