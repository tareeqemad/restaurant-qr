<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let recipe lines reference an ingredient-specific unit (tbsp,
 * scoop, ladle, pinch) instead of forcing every quantity into the
 * global g/ml/pcs grid.
 *
 * Why this matters: a chef writes "1 ملعقة كبيرة سكر" — they don't
 * weigh it. The operator defines "ملعقة كبيرة" once on the sugar
 * ingredient (factor_to_base = 12g, say), and from then on every
 * recipe that uses sugar by the tablespoon gets the deduction math
 * for free.
 *
 * Mirrors the FK pattern already in place on purchase_order_items.
 * Backward compatible: existing recipes have `ingredient_unit_id = null`
 * and keep using the global `units` table via `unit_id`.
 *
 * Applies to both:
 *  - recipe_items          (menu-item recipes + sub-recipes of composite ingredients)
 *  - modifier_recipe_items (extras that consume their own stock)
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['recipe_items', 'modifier_recipe_items'] as $table) {
            if (! Schema::hasTable($table)) continue;
            if (Schema::hasColumn($table, 'ingredient_unit_id')) continue;

            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('ingredient_unit_id')->nullable()
                    ->after('unit_id')
                    ->constrained('ingredient_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['recipe_items', 'modifier_recipe_items'] as $table) {
            if (! Schema::hasTable($table)) continue;
            if (! Schema::hasColumn($table, 'ingredient_unit_id')) continue;

            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('ingredient_unit_id');
            });
        }
    }
};
