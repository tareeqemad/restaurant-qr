<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order header. Carries the full state needed for:
 *
 *   - Dine-in (table + table_session)
 *   - Portal pickup / delivery (no table; customer + delivery_address)
 *   - External-platform orders (Talabat/Careem; order_source +
 *     external_reference + platform_commission_pct)
 *
 * ETA snapshots (estimated_prep_minutes, estimated_ready_at,
 * estimated_delivered_at) are stamped at creation time so the customer
 * sees a stable "ready in 25 min" instead of a number that drifts
 * whenever someone tweaks an item recipe. delivery_fee is also frozen
 * here — invoices are append-only audit records and must not change
 * if the branch's delivery fee setting is updated tomorrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 32)->unique();
            $table->foreignId('table_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->string('order_source', 20)->default('dine_in');
            $table->string('external_reference', 80)->nullable();
            $table->decimal('platform_commission_pct', 5, 2)->default(0)
                ->comment('Commission % charged by platform (e.g. 25 for 25% Talabat cut)');
            $table->foreignId('table_session_id')->nullable()
                ->constrained('table_sessions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->foreignId('customer_address_id')->nullable()
                ->constrained('customer_addresses')->nullOnDelete();
            $table->enum('order_type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
            $table->enum('status', [
                'pending', 'approved', 'preparing', 'ready',
                'delivered', 'completed', 'cancelled',
            ])->default('pending');
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('null when placed by customer');
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('customer_notes')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancelled_reason')->nullable();

            // Money (snapshot at time of order)
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('service_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('tip', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('service_rate', 5, 2)->default(0);

            // Timing snapshots
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedSmallInteger('estimated_prep_minutes')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->timestamp('estimated_delivered_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['table_id', 'status']);
            $table->index('submitted_at');
            $table->index('order_source');
            $table->index('branch_id');
            $table->index('customer_id');
            $table->index('scheduled_for');
            $table->index(['branch_id', 'status', 'order_type'], 'orders_branch_status_type_idx');
            $table->index(['branch_id', 'order_source'], 'orders_branch_source_idx');
            $table->index(['branch_id', 'customer_phone'], 'orders_branch_customer_phone_idx');
            $table->index(['branch_id', 'external_reference'], 'orders_branch_external_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
