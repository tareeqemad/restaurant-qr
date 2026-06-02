<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `ton` weight unit on existing installs. Wholesale
 * suppliers price + ship in tons (sugar, flour, onions, rice) and the
 * operator must be able to receive a PO line "5 tons" without writing
 * 5,000,000 in the grams column. UnitConverter already handles the
 * factor chain — we just need the row to exist.
 *
 * Safe to re-run: updateOrCreate by `code`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('units')->updateOrInsert(
            ['code' => 'ton'],
            [
                'code' => 'ton',
                'name' => 'طن',
                'name_en' => 'tonne',
                'unit_type' => 'weight',
                'factor_to_base' => 1000000,
                'is_base' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Only remove the row when nothing references it. The `ton` unit can be
        // adopted by recipe / purchase lines the moment it exists, and those
        // carry a FK to units.id — an unconditional delete then aborts the whole
        // rollback with a 1451 constraint error, leaving the migration batch
        // half-applied. Skip the delete when the unit is still in use.
        $unit = DB::table('units')->where('code', 'ton')->first();

        if (! $unit) {
            return;
        }

        $inUse = collect(['recipe_items', 'modifier_recipe_items', 'purchase_order_items', 'purchase_receipt_items'])
            ->filter(fn ($table) => Schema::hasTable($table) && Schema::hasColumn($table, 'unit_id'))
            ->contains(fn ($table) => DB::table($table)->where('unit_id', $unit->id)->exists());

        if (! $inUse) {
            DB::table('units')->where('id', $unit->id)->delete();
        }
    }
};
