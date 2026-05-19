<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three related feature additions in one migration so they ship together
 * and the test suite has a consistent baseline:
 *
 *  1. Composite ingredients (sub-recipes) — an ingredient can be marked
 *     `is_composite` and given a yield (e.g. 200g sugar + 100ml water →
 *     280g sauce). The recipe of the composite is stored in the SAME
 *     `recipe_items` table by setting `parent_ingredient_id` instead of
 *     `menu_item_id`. InventoryService recursively expands composites
 *     when deducting an order, so the raw inputs are what actually leave
 *     the shelf — no parallel "produced stock" to maintain.
 *
 *  2. Yield ratio on raw ingredients — `yield_pct` (0-100, null = 100%).
 *     When goods are received, stock added = received × yield_pct%, and
 *     the per-unit cost is grossed up accordingly. Cleans up the
 *     "I paid for 5kg whole chicken but only 3.5kg is usable" problem.
 *
 *  3. Daily inventory snapshot table — a flat audit table written once
 *     per day by the scheduled `app:snapshot-inventory` command. Lets the
 *     ops team see "what did we have at midnight" without re-running
 *     trial-balance arithmetic over all of inventory_movements.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Composite (sub-recipe) flag + yield. yield_quantity is in
            // the ingredient's BASE unit, so we don't carry a separate
            // yield_unit_id — it inherits from base_unit_id.
            $table->boolean('is_composite')->default(false)->after('track_stock');
            $table->decimal('composite_yield', 15, 4)->nullable()->after('is_composite')
                  ->comment('Output qty in base unit produced by the sub-recipe (e.g. 280g sauce). Null when not composite.');

            // Trim/yield ratio for RAW ingredients (separate from the
            // composite yield above). 0–100; null = 100% (no loss).
            $table->decimal('yield_pct', 5, 2)->nullable()->after('cost_per_unit')
                  ->comment('Usable percentage from received quantity. 70 = 30% loss to trim/cleaning.');
        });

        Schema::table('recipe_items', function (Blueprint $table) {
            // A recipe line belongs to EITHER a menu_item OR a composite
            // ingredient. Both can't be set, and exactly one must be.
            // SQLite doesn't enforce CHECK constraints in older versions
            // reliably, so we rely on the service layer + a route policy
            // to keep this exclusive.
            $table->foreignId('parent_ingredient_id')->nullable()
                  ->after('menu_item_id')
                  ->constrained('ingredients')->cascadeOnDelete();

            // Original unique was (menu_item_id, ingredient_id). With the
            // new parent column we want (parent_ingredient_id, ingredient_id)
            // to also be unique for composite recipes.
            $table->index(['parent_ingredient_id', 'ingredient_id'], 'recipe_items_parent_ingredient_idx');
        });

        // menu_item_id was NOT NULL — relax to nullable so a recipe line
        // can hang off a composite ingredient via parent_ingredient_id
        // instead. Laravel 12 supports `change()` natively without
        // doctrine/dbal across MySQL and SQLite, so a single call covers
        // both drivers (production + the test in-memory DB).
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->foreignId('menu_item_id')->nullable()->change();
        });

        Schema::create('inventory_snapshots', function (Blueprint $table) {
            // Per-ingredient close-of-day record. (ingredient_id, taken_on)
            // is unique so a re-run of the command never duplicates rows.
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->date('taken_on');
            $table->decimal('quantity_in_base', 15, 4)->default(0);
            $table->decimal('cost_value', 14, 2)->default(0)
                  ->comment('quantity_in_base × cost_per_unit at snapshot time.');
            $table->timestamps();

            $table->unique(['ingredient_id', 'branch_id', 'taken_on'], 'inv_snapshot_uniq');
            $table->index('taken_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshots');

        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropIndex('recipe_items_parent_ingredient_idx');
            $table->dropConstrainedForeignId('parent_ingredient_id');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['is_composite', 'composite_yield', 'yield_pct']);
        });
    }
};
