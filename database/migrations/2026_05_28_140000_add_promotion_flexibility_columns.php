<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three flexibility rules added to `menu_promotions`:
 *
 *   1. `min_subtotal` — minimum order total required before this promo
 *      kicks in. "خصم 10% لو الفاتورة > 50 شيكل". OrderService checks
 *      after recalculate; if the cart drops below the threshold (item
 *      removed, etc.) the promo silently disengages.
 *
 *   2. `channels` (JSON array) — which order sources can earn this
 *      promo. Values match the `order_source` enum on `orders`:
 *      qr, cashier, waiter, phone, delivery, other. NULL = all
 *      channels eligible (the current behaviour).
 *
 *   3. `excluded_item_ids` (JSON array) — when a CATEGORY-targeted
 *      promo carries this, the listed menu items skip the discount.
 *      Lets you say "all desserts 20% off EXCEPT الكنافة". Ignored on
 *      menu_item-targeted rows (no concept of "category minus item").
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->decimal('min_subtotal', 12, 2)->nullable()->after('value')
                  ->comment('Minimum order subtotal before promo applies. Null = no minimum.');
            $table->json('channels')->nullable()->after('days_of_week')
                  ->comment('Array of allowed order_source values. Null = all channels.');
            $table->json('excluded_item_ids')->nullable()->after('channels')
                  ->comment('For category-targeted promos: menu items that opt out of the discount. Null = none.');
        });
    }

    public function down(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->dropColumn(['min_subtotal', 'channels', 'excluded_item_ids']);
        });
    }
};
