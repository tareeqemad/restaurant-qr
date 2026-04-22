<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredient batches — track stock by lot/expiry for FIFO picking.
 *
 * A batch is created on every stock-IN movement (purchase receipt or positive
 * adjustment). It holds the initial received qty + remaining qty. On stock-OUT,
 * the service walks batches in order (earliest expiry first, then oldest
 * received first) and decrements remaining_qty.
 *
 * The existing `ingredients.current_stock` column stays in sync (it's the sum
 * of remaining_qty across batches) so reports that don't care about batches
 * still work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 80)->nullable()->comment('Lot/batch number from supplier, if any');
            $table->date('received_date');
            $table->date('expiry_date')->nullable()->index()->comment('null = no expiry (e.g., salt, sugar)');

            $table->decimal('initial_qty',   15, 4)->comment('Qty received into this batch (base unit)');
            $table->decimal('remaining_qty', 15, 4)->comment('Qty left (depleted by FIFO deductions)');
            $table->decimal('unit_cost',     12, 4)->default(0)->comment('Cost/base-unit for this specific batch');

            // Provenance — how did this batch enter?
            $table->string('source_type', 60)->nullable()->comment('e.g., App\\Models\\PurchaseOrderItem');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['ingredient_id', 'expiry_date']);
            $table->index(['ingredient_id', 'received_date']);
            $table->index(['source_type', 'source_id']);
        });

        // Link inventory movements to the batch(es) they came from/went to.
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('ingredient_id')
                  ->constrained('ingredient_batches')->nullOnDelete()
                  ->comment('Which batch this movement affected (nullable for legacy/untracked movements)');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
        Schema::dropIfExists('ingredient_batches');
    }
};
