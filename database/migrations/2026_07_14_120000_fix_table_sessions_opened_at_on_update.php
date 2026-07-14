<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MariaDB gives the FIRST timestamp column of a table an implicit
 * `ON UPDATE current_timestamp()` unless told otherwise. table_sessions
 * hit that trap on opened_at: every later update to the row (bill request,
 * activity touch, status change) silently RESET the session's opening time,
 * corrupting every "session age" computation (idle sweeper, attention
 * banner, cashier header). Redefine the column with an explicit default
 * and NO on-update clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) has no ON UPDATE semantics to fix.
        }

        DB::statement(
            'ALTER TABLE table_sessions
             MODIFY opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE table_sessions
             MODIFY opened_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }
};
