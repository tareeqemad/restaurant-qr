<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu-item promotions — one table that covers ALL three patterns the
 * restaurant ever needs:
 *
 *   1. PERMANENT sale price:
 *        starts_at = null, ends_at = null, time_from = null, days = null
 *        ("الشاورما خصم دائم 25 بدل 30")
 *
 *   2. SCHEDULED campaign:
 *        starts_at = 2026-06-01, ends_at = 2026-06-30
 *        ("عروض رمضان")
 *
 *   3. HAPPY HOUR / WEEKLY:
 *        time_from = "15:00", time_to = "17:00", days_of_week = [0,1,2,3,4]
 *        ("خصم 20% على كل الحلويات أيام الأسبوع 3-5 عصراً")
 *
 * Targeting works at two levels:
 *   - target_type = 'menu_item'  + target_id = item id  → just that item
 *   - target_type = 'category'   + target_id = cat id   → entire category
 *
 * Discount expression:
 *   - type = 'sale_price'  + value = X     → item now costs X (absolute)
 *   - type = 'percent'     + value = 20    → 20% off the menu price
 *   - type = 'fixed_off'   + value = 5     → 5 ש"ח off (rare but useful)
 *
 * Conflict resolution (multiple promos match same item at same time):
 *   Higher `priority` wins. Tie → most-specific target wins (item > category).
 *   The PromotionService::resolveForItem encapsulates this so callers never
 *   reimplement the tie-break.
 *
 * Branch scoping is optional. branch_id = null → applies in every branch.
 * Useful for chain-wide marketing campaigns vs branch-specific clearance.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('menu_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()
                  ->constrained()->cascadeOnDelete()
                  ->comment('Null = applies in every branch (chain-wide promo).');

            $table->string('name', 200)
                  ->comment('Human-readable name shown on the dashboard + audit log.');
            $table->string('description', 500)->nullable()
                  ->comment('Optional context for the manager (used on the receipt too).');

            // Discount expression
            $table->enum('type', ['sale_price', 'percent', 'fixed_off'])
                  ->comment('How `value` should be interpreted.');
            $table->decimal('value', 12, 2)
                  ->comment('sale_price → absolute new price | percent → 0..100 | fixed_off → currency amount');

            // Targeting — one of two shapes (item OR category)
            $table->enum('target_type', ['menu_item', 'category']);
            $table->unsignedBigInteger('target_id')
                  ->comment('References menu_items.id OR categories.id depending on target_type.');

            // Scheduling — every nullable. All-null = always-on permanent promo.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->time('time_from')->nullable()
                  ->comment('Time-of-day window start (e.g. 15:00) — together with time_to gives happy-hour.');
            $table->time('time_to')->nullable();
            $table->json('days_of_week')->nullable()
                  ->comment('Array of weekday ints 0=Sunday .. 6=Saturday. Null = every day.');

            // Behaviour flags
            $table->boolean('active')->default(true)
                  ->comment('Master kill-switch; admin can pause without editing the schedule.');
            $table->unsignedSmallInteger('priority')->default(0)
                  ->comment('Higher beats lower when multiple promos match. Default 0.');

            $table->foreignId('created_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Lookups are dominated by "find active promo for this item right now"
            // — the composite + an active filter cover that hot path.
            $table->index(['target_type', 'target_id', 'active'], 'menu_promos_target_active_idx');
            $table->index(['branch_id', 'active'], 'menu_promos_branch_active_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot the menu price BEFORE discount so reports + invoices
            // can show "كان 30 → الآن 25" even if the promo expires later.
            // Null = no promotion applied (item ordered at full price).
            $table->decimal('unit_price_original', 12, 2)->nullable()->after('unit_price')
                  ->comment('Menu price before any active promotion. Null = unit_price is the full price.');
            $table->foreignId('promotion_id')->nullable()->after('unit_price_original')
                  ->constrained('menu_promotions')->nullOnDelete()
                  ->comment('Which promotion drove the discounted unit_price (audit).');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('unit_price_original');
        });
        Schema::dropIfExists('menu_promotions');
    }
};
