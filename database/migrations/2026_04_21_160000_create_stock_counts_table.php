<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Counts — periodic physical inventory reconciliation.
 *
 * Lifecycle:
 *   draft     → header created, admin is entering counts
 *   finalized → locked + adjustment movements generated for each variance
 *
 * On finalize:
 *   for each line where counted_qty != system_qty:
 *     create inventory_movement(type='adjustment', qty = counted - system, reason = 'stock count VAR-...')
 *     update ingredient.current_stock = counted_qty
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique()->comment('CNT-YYYYMMDD-NNNN');
            $table->date('count_date')->index();
            $table->enum('status', ['draft', 'finalized', 'cancelled'])->default('draft')->index();

            $table->text('notes')->nullable();

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();

            $table->decimal('system_qty',  15, 4)->comment('Snapshot of ingredient.current_stock at time count was started');
            $table->decimal('counted_qty', 15, 4)->nullable()->comment('Actual qty counted — null means not yet entered');
            $table->decimal('variance',    15, 4)->default(0)->comment('counted − system');
            $table->decimal('variance_cost', 12, 4)->default(0)->comment('Monetary value of the variance');

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
