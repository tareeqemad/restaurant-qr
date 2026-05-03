<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic stock-take. A draft count snapshots every ingredient's
 * current_stock; finalising adjusts each ingredient and writes
 * inventory_movements of type='adjustment'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 40)->unique()->comment('CNT-YYYYMMDD-NNNN');
            $table->date('count_date');
            $table->enum('status', ['draft', 'finalized', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('count_date');
            $table->index('status');
            $table->index('branch_id');
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained();
            $table->decimal('system_qty', 15, 4)
                ->comment('Snapshot of ingredient.current_stock at time count was started');
            $table->decimal('counted_qty', 15, 4)->nullable()
                ->comment('Actual qty counted — null means not yet entered');
            $table->decimal('variance', 15, 4)->default(0)
                ->comment('counted − system');
            $table->decimal('variance_cost', 12, 4)->default(0)
                ->comment('Monetary value of the variance');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
