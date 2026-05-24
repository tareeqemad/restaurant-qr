<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the `ton` weight unit on existing installs. Wholesale
 * suppliers price + ship in tons (sugar, flour, onions, rice) and the
 * operator must be able to receive a PO line "5 tons" without writing
 * 5,000,000 in the grams column. UnitConverter already handles the
 * factor chain — we just need the row to exist.
 *
 * Safe to re-run: updateOrCreate by `code`.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('units')->updateOrInsert(
            ['code' => 'ton'],
            [
                'code'           => 'ton',
                'name'           => 'طن',
                'name_en'        => 'tonne',
                'unit_type'      => 'weight',
                'factor_to_base' => 1000000,
                'is_base'        => false,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('units')->where('code', 'ton')->delete();
    }
};
