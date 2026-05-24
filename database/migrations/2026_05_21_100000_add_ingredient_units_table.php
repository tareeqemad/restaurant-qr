<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-ingredient alternate units (pack sizes). One ingredient (cola)
 * can be bought as a single can OR as a 24-can carton OR as a 60-can
 * pallet — each row here is one of those alternate units, with the
 * factor that converts back to the ingredient's BASE unit.
 *
 * Why on its own table instead of reusing `units`:
 *   - Global `units` are generic (g, ml, pcs) and shared across all
 *     ingredients. A "24-can carton" makes sense ONLY for cola.
 *   - Each pack carries its own barcode + price, which don't fit on
 *     a global unit table.
 *
 * The base unit (`ingredients.base_unit_id`) stays as the canonical
 * inventory unit — every stock count, recipe deduction, and history
 * report continues to talk in the base unit. The alternate units only
 * affect the purchase + (optionally) the sale side.
 *
 * Adds two FK columns to existing tables:
 *   - `purchase_order_items.ingredient_unit_id` — when set, the PO
 *     line is in this alternate unit; `quantity_ordered` is in this
 *     unit, the receipt service applies factor_to_base before adding
 *     to stock.
 *   - `purchase_receipt_items.ingredient_unit_id` — same story for
 *     the actual receipt (may differ from the order if the supplier
 *     delivered in a different pack size).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingredient_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100)
                ->comment('Display name in Arabic, e.g. "كرتون 24 علبة"');
            $table->decimal('factor_to_base', 15, 4)
                ->comment('How many base units in 1 of this unit. Example: 24 (cans per carton).');
            $table->string('barcode', 64)->nullable()
                ->comment('EAN/UPC/whatever the supplier prints on the pack.');
            $table->decimal('purchase_price', 12, 4)->nullable()
                ->comment('Last known purchase price PER THIS UNIT (not per base).');
            $table->decimal('sale_price', 12, 4)->nullable()
                ->comment('Counter sale price PER THIS UNIT — for selling packs as-is to walk-ins.');
            $table->boolean('is_default_purchase')->default(false)
                ->comment('Pre-selected when adding the ingredient to a PO line.');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // (ingredient, name) must be unique — no two "كرتون 24" on
            // the same item. Barcode unique GLOBALLY across all
            // ingredient_units so a scan resolves unambiguously.
            $table->unique(['ingredient_id', 'name'], 'ing_units_name_uniq');
            $table->unique('barcode', 'ing_units_barcode_uniq');
            $table->index(['ingredient_id', 'is_default_purchase']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('ingredient_unit_id')->nullable()
                ->after('unit_id')
                ->constrained('ingredient_units')->nullOnDelete();
        });

        // purchase_receipt_items may not exist on every install (some
        // older migrations skipped it) — guard the alter so the
        // migration runs cleanly either way.
        if (Schema::hasTable('purchase_receipt_items')) {
            Schema::table('purchase_receipt_items', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_receipt_items', 'ingredient_unit_id')) {
                    $table->foreignId('ingredient_unit_id')->nullable()
                        ->after('unit_id')
                        ->constrained('ingredient_units')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_receipt_items')
            && Schema::hasColumn('purchase_receipt_items', 'ingredient_unit_id')) {
            Schema::table('purchase_receipt_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ingredient_unit_id');
            });
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_unit_id');
        });

        Schema::dropIfExists('ingredient_units');
    }
};
