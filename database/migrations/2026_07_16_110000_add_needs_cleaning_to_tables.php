<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bussing: a table whose party left but which nobody has wiped down yet.
 *
 * Deliberately a TIMESTAMP rather than a new `status` enum value:
 *
 *  - Safe by default. If a restaurant doesn't track bussing they simply ignore
 *    the hint; the table stays `available` and seatable. Making it a status
 *    would strand every table in "needs cleaning" the moment nobody taps
 *    "cleaned" — and would force a settings toggle to avoid that.
 *  - It carries a clock. "Dirty for 12 minutes" is the actual operational
 *    signal, exactly like the existing table_sessions.bill_requested_at.
 *  - It's additive: no enum rewrite, and nothing that switches on `status`
 *    anywhere in the app has to learn a new case.
 *
 * Cleared automatically when a new session opens on the table (TableSession's
 * booted hook), so the flag can never get stuck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->timestamp('needs_cleaning_since')->nullable()->after('status');
            $table->index(['branch_id', 'needs_cleaning_since'], 'tables_branch_cleaning_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndex('tables_branch_cleaning_idx');
            $table->dropColumn('needs_cleaning_since');
        });
    }
};
