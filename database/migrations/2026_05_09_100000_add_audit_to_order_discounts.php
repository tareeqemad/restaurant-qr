<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-applied discounts need an audit trail beyond just `applied_by_user_id`.
 * `category` lets reports break down WHY money was discounted (loyal customer
 * vs complaint compensation vs manager override), and `reason` captures the
 * cashier's free-text justification — non-null at app level, nullable here so
 * legacy rows survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('amount');
            $table->string('reason', 500)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('order_discounts', function (Blueprint $table) {
            $table->dropColumn(['category', 'reason']);
        });
    }
};
