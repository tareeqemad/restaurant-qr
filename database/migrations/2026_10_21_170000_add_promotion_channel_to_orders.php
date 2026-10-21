<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Keep legacy orders null: their already-saved pricing context
            // must not acquire a new channel when totals are recalculated.
            $table->string('promotion_channel', 20)->nullable()->after('order_source');
        });
        Schema::create('order_promotion_redemptions', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('menu_promotions')->cascadeOnDelete();
            $table->dateTime('redeemed_at');
            $table->primary(['order_id', 'promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_promotion_redemptions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('promotion_channel'));
    }
};
