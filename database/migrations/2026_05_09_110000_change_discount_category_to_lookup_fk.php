<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promote `order_discounts.category` from a free-text enum string to a
 * proper FK into `lookups` (group = `discount_categories`). The numeric ID
 * matches the pattern used by expenses (`expense_category_id`) and lets
 * admins manage the catalogue from the lookups UI without code changes.
 *
 * Rolling forward right after the previous migration that introduced the
 * `category` column — no production data exists yet, so we drop and add
 * cleanly. If discounts had been applied in production, this would need
 * a backfill step (find or create lookup row per distinct string, then
 * write the new FK before dropping the old column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::table('order_discounts', function (Blueprint $table) {
            $table->foreignId('category_lookup_id')->nullable()
                ->after('amount')
                ->constrained('lookups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Defensive: the later migration 2026_05_09_140000 is a more robust
        // rewrite of this one and operates on the same `category_lookup_id`
        // column. On a full rollback its down() runs first and already drops
        // the FK + restores `category`, so by the time we get here the
        // column may be gone and `category` may be back. Guard both steps so
        // this migration is a clean no-op in that case instead of crashing
        // with "Can't DROP FOREIGN KEY ... check that it exists".
        Schema::table('order_discounts', function (Blueprint $table) {
            if (Schema::hasColumn('order_discounts', 'category_lookup_id')) {
                $table->dropConstrainedForeignId('category_lookup_id');
            }
        });

        Schema::table('order_discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('order_discounts', 'category')) {
                $table->string('category', 32)->nullable()->after('amount');
            }
        });
    }
};
