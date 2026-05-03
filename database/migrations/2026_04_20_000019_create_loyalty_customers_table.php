<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty profile keyed by phone — separate from the portal Customer auth
 * record. The two tables coexist; bridged by `customers.loyalty_customer_id`
 * when an authenticated diner is also a loyalty member.
 *
 * `loyalty_transactions` lives in a later migration because it FKs invoices
 * + orders, which don't exist yet at this point.
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
            $table->string('tier', 20)->default('bronze');
            $table->timestamp('last_visit_at')->nullable();
            $table->integer('total_visits')->default(0);
            $table->decimal('total_spent', 14, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_customers');
    }
};
