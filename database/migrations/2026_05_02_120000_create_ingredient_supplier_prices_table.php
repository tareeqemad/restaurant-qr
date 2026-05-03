<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Price History — append-only ledger of every price observed for
 * an ingredient × supplier pair. Each PO receipt writes a row; manual
 * adjustments can also write rows. The current `ingredients.cost_per_unit`
 * remains the weighted-average; this table is the *audit trail* that lets
 * the buyer answer:
 *
 *   - "How has the price of chicken from supplier X moved over 6 months?"
 *   - "Which supplier consistently offers the lowest price?"
 *   - "Did this supplier raise prices without notice?"
 *
 * Branch-scoped because the same ingredient bought by two branches at the
 * same supplier can hit different per-branch prices (regional pricing).
 *
 * `unit_price` is stored in the ORDERED unit (matches PO line). We also
 * keep `unit_price_in_base` so cross-supplier comparisons can be made
 * directly without per-row unit conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units');

            // Price as paid (ordered unit) + normalized to ingredient base unit
            $table->decimal('unit_price', 12, 4);
            $table->decimal('unit_price_in_base', 12, 4);

            // What changed vs the prior observation for this trio
            // (ingredient_id, supplier_id, branch_id). Computed on insert
            // so reports don't have to re-derive it. Null on first observation.
            $table->decimal('previous_price_in_base', 12, 4)->nullable();
            $table->decimal('change_pct', 8, 2)->nullable()
                ->comment('+ = price up, − = price down. Null on first obs.');

            // Provenance
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->enum('source', ['receipt', 'manual', 'import'])->default('receipt');
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            // When this price was observed (== PO receipt date for source=receipt)
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();

            // Hot path: "history of this ingredient at this supplier, newest first"
            $table->index(['ingredient_id', 'supplier_id', 'observed_at'],
                'isp_ing_sup_obs_idx');
            $table->index(['supplier_id', 'observed_at']);
            $table->index(['branch_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_supplier_prices');
    }
};
