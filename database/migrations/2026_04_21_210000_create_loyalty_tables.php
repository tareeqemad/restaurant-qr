<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty: customers earn points on paid invoices, redeem points for discounts.
 *
 * Lookup key = phone number. No passwords or login for customers —
 * the cashier types the phone at checkout to find the customer record.
 *
 * Tier is derived from lifetime_points (never decreases on redemption):
 *   Bronze  : 0 - 999        (earns 10 pts / JOD)
 *   Silver  : 1000 - 4999    (earns 15 pts / JOD)
 *   Gold    : 5000+          (earns 20 pts / JOD)
 *
 * Redemption rate: 100 points = 1 base-currency unit discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_customers', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 30)->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();

            $table->unsignedInteger('points_balance')->default(0)->comment('Currently redeemable');
            $table->unsignedInteger('lifetime_points')->default(0)->comment('Total earned — drives tier');
            $table->string('tier', 20)->default('bronze')->index();

            $table->timestamp('last_visit_at')->nullable();
            $table->integer('total_visits')->default(0);
            $table->decimal('total_spent', 14, 4)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->enum('type', ['earn', 'redeem', 'adjust', 'expire', 'bonus'])->index();
            $table->integer('points')->comment('Signed: positive = earn, negative = redeem');
            $table->decimal('cash_value', 14, 4)->default(0)->comment('For redeem: discount value applied');

            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->index(['loyalty_customer_id', 'created_at']);
        });

        // Link invoices to customer for easy lookup
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('loyalty_customer_id')->nullable()->after('customer_phone')
                  ->constrained()->nullOnDelete();
            $table->unsignedInteger('loyalty_points_earned')->default(0)->after('loyalty_customer_id');
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('loyalty_points_earned');
            $table->decimal('loyalty_discount', 12, 4)->default(0)->after('loyalty_points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['loyalty_customer_id']);
            $table->dropColumn(['loyalty_customer_id', 'loyalty_points_earned', 'loyalty_points_redeemed', 'loyalty_discount']);
        });
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_customers');
    }
};
