<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the modifier's original `price_delta` whenever a promotion's
 * `free_modifier_ids` list zeroes it out. Lets the accounting layer
 * recognise the SAVINGS as a real discount instead of silently
 * crediting less revenue.
 *
 *   - NULL → no promotion applied, price_delta IS the full price
 *   - set  → price_delta is the EFFECTIVE (discounted/zeroed) value;
 *            original carries the menu menu-card price for reporting
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->decimal('price_delta_original', 12, 2)->nullable()->after('price_delta')
                  ->comment('Original modifier price before any promotion zeroed it. Null = no promo.');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropColumn('price_delta_original');
        });
    }
};
