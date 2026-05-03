<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order line items + their pivots:
 *   - order_items          : per-item lifecycle (pending → ready → served)
 *   - order_item_modifiers : selected modifier snapshot per line item
 *   - order_discounts      : applied discount snapshot per order
 *
 * Snapshots (`name_snapshot`, `unit_price`, `name_en_snapshot`, …) freeze
 * the menu state at order time so historical orders don't get rewritten
 * when the menu changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained();
            $table->foreignId('station_id')->nullable()
                ->constrained('stations')->nullOnDelete();
            $table->string('name_snapshot')->comment('Menu item name at time of order');
            $table->string('name_en_snapshot')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->comment('Menu item price snapshot');
            $table->decimal('modifiers_total', 10, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->comment('(unit_price + modifiers) * quantity');
            $table->text('notes')->nullable()->comment('Customer notes');
            $table->enum('course', ['appetizer', 'main', 'dessert', 'drink', 'other'])->default('main');
            $table->integer('fire_order')->default(0)->comment('Lower fires first; 0=fire-now');
            $table->enum('status', ['pending', 'approved', 'preparing', 'ready', 'served', 'cancelled'])
                ->default('pending');
            $table->foreignId('prepared_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('served_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('cancelled_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('prep_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()
                ->constrained('modifiers')->nullOnDelete();
            $table->string('name_snapshot');
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()
                ->constrained('discounts')->nullOnDelete();
            $table->string('name_snapshot');
            $table->enum('type', ['percent', 'fixed'])->default('percent');
            $table->decimal('value', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->foreignId('applied_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
    }
};
