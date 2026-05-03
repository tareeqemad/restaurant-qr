<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty point ledger. Comes AFTER invoices + orders because both are
 * referenced. `points` is signed (positive earn, negative redeem) so the
 * balance is `SUM(points)` per loyalty_customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_customer_id')
                ->constrained('loyalty_customers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
            $table->foreignId('order_id')->nullable()
                ->constrained('orders')->nullOnDelete();
            $table->enum('type', ['earn', 'redeem', 'adjust', 'expire', 'bonus']);
            $table->integer('points')->comment('Signed: positive = earn, negative = redeem');
            $table->decimal('cash_value', 14, 4)->default(0)
                ->comment('For redeem: discount value applied');
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loyalty_customer_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
