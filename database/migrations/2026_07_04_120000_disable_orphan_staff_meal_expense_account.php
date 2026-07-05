<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Account 5050 «مصاريف وجبات الموظفين» is an orphan: the staff-meal cycle was
 * redesigned to post DR 1110 / CR 4030 (revenue model), so no code path posts
 * to 5050 anymore. It stayed active and cluttered the chart. Deactivate it —
 * the posting engine still ignores is_active, so a stray manual entry to it
 * would still work; this only hides it from the input/tree screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')->where('code', '5050')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '5050')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
