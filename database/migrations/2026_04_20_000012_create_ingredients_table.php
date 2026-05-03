<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredients are GLOBAL — the same physical SKU is shared across branches
 * (think company-wide pantry catalog). Per-location stock + per-batch
 * tracking lives in `ingredient_stock` + `ingredient_batches` to capture
 * branch-specific quantities and FIFO expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->nullable()->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->foreignId('base_unit_id')->constrained('units');
            $table->foreignId('supplier_id')->nullable()
                ->constrained('suppliers')->nullOnDelete();
            $table->decimal('current_stock', 15, 4)->default(0);
            $table->decimal('reorder_threshold', 15, 4)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
