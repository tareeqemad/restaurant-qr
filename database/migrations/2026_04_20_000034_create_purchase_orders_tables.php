<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders + line items. Drives stock receipt: when a PO is
 * received, each item creates an `ingredient_batch` + an inventory_movement
 * (handled by PurchaseOrderService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 40)->unique()
                ->comment('Human-readable PO number, e.g. PO-20260421-0001');
            $table->foreignId('supplier_id')->constrained();
            $table->enum('status', ['draft', 'sent', 'partially_received', 'received', 'cancelled'])
                ->default('draft');
            $table->decimal('subtotal', 12, 4)->default(0);
            $table->decimal('tax_total', 12, 4)->default(0);
            $table->decimal('total', 12, 4)->default(0);
            $table->date('expected_at')->nullable()->comment('Expected delivery date');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable()->comment('Set when fully received');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable()->comment('Delivery instructions or internal memo');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
            $table->index('expected_at');
            $table->index('status');
            $table->index('branch_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained();
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity_ordered', 15, 4);
            $table->decimal('unit_price', 12, 4)
                ->comment('Price per ordered unit (not base unit)');
            $table->decimal('subtotal', 12, 4)->default(0)
                ->comment('quantity_ordered × unit_price');
            $table->decimal('quantity_received', 15, 4)->default(0);
            $table->timestamp('fully_received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
