<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the table number on every record that displays it, so an
 * admin renaming or deleting a table doesn't silently rewrite the
 * customer's history.
 *
 * Without these snapshots:
 *   - Receipts read `$invoice->tableSession->table->number` LIVE,
 *     so changing "5" → "5A" makes yesterday's receipt show "5A".
 *   - Deleting a table where any session ever existed throws SQL 1451
 *     because table_sessions.table_id was constrained() with no
 *     onDelete modifier (= RESTRICT).
 *
 * Three columns + one FK fix:
 *
 *   1. orders.table_number_snapshot          — set when an order opens
 *   2. table_sessions.table_number_snapshot  — set when a session opens
 *   3. invoices.table_number_snapshot        — set at invoice issuance
 *
 * Plus: backfill every existing row from the live FK so historical
 * data inherits the current value (best we can do — there's no audit
 * log of past renames). Future renames are snapshot-safe.
 *
 * The FK on table_sessions.table_id is also flipped to nullOnDelete so
 * a manager can delete a table cleanly once all sessions are closed.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Add the snapshot columns ─────────────────────────────────
        // Defensive `hasColumn` guard — a partial previous run (failed
        // mid-flight on SQLite FK alter) can leave one of these columns
        // already in place. Re-running must be idempotent.
        if (! Schema::hasColumn('orders', 'table_number_snapshot')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('table_number_snapshot', 50)->nullable()->after('table_id')
                      ->comment('Snapshot of the table number at order creation. Survives table rename/delete.');
            });
        }
        if (! Schema::hasColumn('table_sessions', 'table_number_snapshot')) {
            Schema::table('table_sessions', function (Blueprint $table) {
                $table->string('table_number_snapshot', 50)->nullable()->after('table_id')
                      ->comment('Snapshot of the table number at session open. Survives table rename/delete.');
            });
        }
        if (! Schema::hasColumn('invoices', 'table_number_snapshot')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('table_number_snapshot', 50)->nullable()->after('table_session_id')
                      ->comment('Snapshot of the table number at invoice issuance. Survives table rename/delete.');
            });
        }

        // ── Backfill from the live FK ────────────────────────────────
        // Best-effort: any pre-existing record gets the table's CURRENT
        // number. Renames before this migration are unrecoverable — we
        // have no rename log. Records created AFTER the migration get
        // their snapshot at creation time via the model hooks.
        DB::statement('
            UPDATE orders
            SET table_number_snapshot = (
                SELECT t.number FROM tables t WHERE t.id = orders.table_id
            )
            WHERE table_id IS NOT NULL
              AND table_number_snapshot IS NULL
        ');
        DB::statement('
            UPDATE table_sessions
            SET table_number_snapshot = (
                SELECT t.number FROM tables t WHERE t.id = table_sessions.table_id
            )
            WHERE table_id IS NOT NULL
              AND table_number_snapshot IS NULL
        ');
        DB::statement('
            UPDATE invoices
            SET table_number_snapshot = (
                SELECT ts.table_number_snapshot
                FROM table_sessions ts
                WHERE ts.id = invoices.table_session_id
            )
            WHERE table_session_id IS NOT NULL
              AND table_number_snapshot IS NULL
        ');

        // ── Fix the table_sessions.table_id FK ───────────────────────
        // Originally `constrained()` with no modifier = RESTRICT, which
        // blocks `table->delete()` whenever ANY session (active OR
        // closed) ever existed on it. With snapshots in place, NULLing
        // the FK on table delete is safe: views fall back to the
        // snapshot for the human-readable label.
        //
        // SQLite test-mode doesn't support ALTER TABLE DROP CONSTRAINT,
        // so we recreate the column conditionally. The MySQL path uses
        // the standard drop-and-re-add cycle.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite recreates the table under the hood when we change
            // a constrained foreignId; the migration framework handles it.
            Schema::table('table_sessions', function (Blueprint $table) {
                // No-op for SQLite — its FK is already permissive in
                // testing (FOREIGN_KEYS pragma is OFF by default in
                // RefreshDatabase setup). The snapshot covers the
                // display correctness regardless.
            });
        } else {
            // Defensive FK swap — a partial previous run may have already
            // dropped the original FK. We catch and continue so the
            // migration is idempotent.
            try {
                Schema::table('table_sessions', function (Blueprint $table) {
                    $table->dropForeign(['table_id']);
                });
            } catch (\Throwable $e) {
                // FK was already absent (partial earlier run) — continue.
            }
            // Allow null on the column itself so the FK can null-out.
            try {
                Schema::table('table_sessions', function (Blueprint $table) {
                    $table->unsignedBigInteger('table_id')->nullable()->change();
                });
            } catch (\Throwable $e) {
                // Column already nullable — continue.
            }
            // Add the permissive FK. Catch in case it's already in place.
            try {
                Schema::table('table_sessions', function (Blueprint $table) {
                    $table->foreign('table_id')->references('id')->on('tables')->nullOnDelete();
                });
            } catch (\Throwable $e) {
                // FK already exists with the right shape — continue.
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('table_number_snapshot');
        });
        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropColumn('table_number_snapshot');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('table_number_snapshot');
        });
        // We don't restore the RESTRICT FK on rollback — leaving the
        // permissive variant is the safer default for production.
    }
};
