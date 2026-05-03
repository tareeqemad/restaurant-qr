<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only inventory ledger — every stock change leaves a row here.
 * Reports (waste %, COGS by item, reorder history) read from this table.
 *
 * `quantity` is always positive; the sign of the movement is encoded by
 * `type`. `quantity_in_base` is normalized to the ingredient's base unit
 * so cross-unit math (kg of receipt vs g of usage) works without joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('ingredient_id')->constrained('ingredients');
            $table->foreignId('batch_id')->nullable()
                ->constrained('ingredient_batches')->nullOnDelete();
            $table->foreignId('storage_location_id')->nullable()
                ->constrained('storage_locations')->nullOnDelete();
            $table->enum('type', ['in', 'out', 'waste', 'adjustment', 'return'])
                ->comment('in=purchase/return, out=order-usage, waste=spoilage, adjustment=manual');
            $table->decimal('quantity', 15, 4)
                ->comment('Positive always; direction determined by type');
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('quantity_in_base', 15, 4)
                ->comment('Normalized to ingredient base unit');
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('stock_before', 15, 4)->default(0);
            $table->decimal('stock_after', 15, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['ingredient_id', 'occurred_at']);
            $table->index('type');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
