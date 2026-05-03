<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIFO batches per ingredient per branch. Each receipt of stock creates a
 * batch with its own remaining_qty + unit_cost; deductions consume the
 * oldest non-empty batch first (handled by InventoryService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('ingredient_id')->constrained('ingredients');
            $table->string('batch_number', 80)->nullable()
                ->comment('Lot/batch number from supplier, if any');
            $table->date('received_date');
            $table->date('expiry_date')->nullable()
                ->comment('null = no expiry (e.g., salt, sugar)');
            $table->decimal('initial_qty', 15, 4)->comment('Qty received into this batch (base unit)');
            $table->decimal('remaining_qty', 15, 4)->comment('Qty left (depleted by FIFO deductions)');
            $table->decimal('unit_cost', 12, 4)->default(0)
                ->comment('Cost/base-unit for this specific batch');
            $table->string('source_type', 60)->nullable()
                ->comment('e.g., App\\Models\\PurchaseOrderItem');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ingredient_id', 'expiry_date']);
            $table->index(['ingredient_id', 'received_date']);
            $table->index(['source_type', 'source_id']);
            $table->index('expiry_date');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_batches');
    }
};
