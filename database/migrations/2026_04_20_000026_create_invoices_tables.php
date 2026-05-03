<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices + their split-bill helper:
 *   - invoices       : one per session at checkout time
 *   - invoice_splits : optional split-tendering rows (e.g. "person 1 pays
 *                      X by card, person 2 pays Y by cash")
 *
 * `loyalty_*` columns capture the loyalty arithmetic for this invoice
 * (earn + redeem + cash discount). Customer-name/phone are FLAT snapshots
 * for the printed receipt — survive even if the FK customer_id is later
 * nulled out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 32)->unique();
            $table->foreignId('table_session_id')->nullable()
                ->constrained('table_sessions')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()
                ->constrained('orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('service_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('tip', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('refunded_total', 12, 4)->default(0)
                ->comment('Denormalized sum of completed refunds — keeps reports fast');
            $table->decimal('balance', 12, 2)->default(0);

            $table->enum('status', ['draft', 'issued', 'paid', 'partially_paid', 'cancelled', 'unpaid_writeoff'])
                ->default('draft');

            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();

            $table->foreignId('loyalty_customer_id')->nullable()
                ->constrained('loyalty_customers')->nullOnDelete();
            $table->unsignedInteger('loyalty_points_earned')->default(0);
            $table->unsignedInteger('loyalty_points_redeemed')->default(0);
            $table->decimal('loyalty_discount', 12, 4)->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index(['branch_id', 'status', 'issued_at'], 'invoices_branch_status_issued_at_idx');
        });

        Schema::create('invoice_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('الشخص الأول');
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'card', 'transfer', 'app', 'credit'])->default('cash');
            $table->boolean('paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_splits');
        Schema::dropIfExists('invoices');
    }
};
