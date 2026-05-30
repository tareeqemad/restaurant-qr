<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four advanced flexibility rules added to `menu_promotions` — one
 * migration covers all of them because they're cohesive (the admin
 * form shows them together and they share enforcement plumbing in
 * PromotionService / OrderService).
 *
 *   #11 Usage limit
 *     - `usage_limit`   int nullable   max distinct orders that can claim
 *     - `usage_count`   int default 0  atomic counter incremented per order
 *
 *   #15 Birthday / audience filter
 *     - `audience`      string default 'everyone'
 *       Allowed: 'everyone' | 'birthday_month'
 *       Uses Customer.birthday (already exists) to gate the promo.
 *
 *   #16 Free modifier
 *     - `free_modifier_ids` JSON nullable
 *       When the parent item-targeted promo fires, the listed
 *       modifier ids on that item have their price_delta zeroed
 *       (the cheese-with-burger pattern).
 *
 *   #14 BXGY (buy X get Y free of same item)
 *     - `bxgy_buy_qty`  int nullable
 *     - `bxgy_get_qty`  int nullable
 *       When BOTH are set, the promo acts as: every Y items become
 *       free for every X items at full price (qty=3 with bxgy=2/1
 *       → 2 paid + 1 free). Applied in OrderService::recalculate
 *       after item snapshots are in place.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->integer('usage_limit')->nullable()->after('min_subtotal')
                  ->comment('Null = unlimited; otherwise the max distinct orders that can claim this promo.');
            $table->integer('usage_count')->default(0)->after('usage_limit')
                  ->comment('Atomic counter; incremented exactly once per order that claims this promo.');

            $table->string('audience', 30)->default('everyone')->after('excluded_item_ids')
                  ->comment('everyone | birthday_month — picks the customer-side filter.');

            $table->json('free_modifier_ids')->nullable()->after('audience')
                  ->comment('Modifier ids that go free on the parent item this promo targets (cheese-with-burger).');

            $table->integer('bxgy_buy_qty')->nullable()->after('free_modifier_ids')
                  ->comment('"Buy X" portion of a Buy-N-Get-M promo. Null = not a BXGY promo.');
            $table->integer('bxgy_get_qty')->nullable()->after('bxgy_buy_qty')
                  ->comment('"Get Y free" portion of a Buy-N-Get-M promo.');
        });
    }

    public function down(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->dropColumn([
                'usage_limit', 'usage_count',
                'audience', 'free_modifier_ids',
                'bxgy_buy_qty', 'bxgy_get_qty',
            ]);
        });
    }
};
