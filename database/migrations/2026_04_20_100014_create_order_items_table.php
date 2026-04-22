<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->string('name_snapshot')->comment('Menu item name at time of order');
            $table->string('name_en_snapshot')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->comment('Menu item price snapshot');
            $table->decimal('modifiers_total', 10, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->comment('(unit_price + modifiers) * quantity');
            $table->text('notes')->nullable()->comment('Customer notes');
            $table->enum('course', ['appetizer', 'main', 'dessert', 'drink', 'other'])->default('main');
            $table->integer('fire_order')->default(0)->comment('Lower fires first; 0=fire-now');
            $table->enum('status', [
                'pending',
                'approved',
                'preparing',
                'ready',
                'served',
                'cancelled',
            ])->default('pending');
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('served_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
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
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_snapshot');
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
    }
};
