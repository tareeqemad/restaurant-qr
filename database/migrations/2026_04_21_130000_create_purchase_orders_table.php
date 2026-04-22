<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Orders — the bridge between suppliers and inventory.
 *
 * Lifecycle:
 *   draft              → Created but not sent to supplier (admin can still edit)
 *   sent               → Issued to supplier; awaiting delivery
 *   partially_received → Some lines received; others still outstanding
 *   received           → Fully received; stock was added + ingredient costs updated
 *   cancelled          → No goods delivered; not reversible
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique()->comment('Human-readable PO number, e.g. PO-20260421-0001');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->enum('status', ['draft', 'sent', 'partially_received', 'received', 'cancelled'])
                  ->default('draft')->index();

            // Totals — kept denormalized for fast reports. Recomputed from lines.
            $table->decimal('subtotal',  12, 4)->default(0);
            $table->decimal('tax_total', 12, 4)->default(0);
            $table->decimal('total',     12, 4)->default(0);

            // Expected vs. actual
            $table->date('expected_at')->nullable()->comment('Expected delivery date');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable()->comment('Set when fully received');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->text('notes')->nullable()->comment('Delivery instructions or internal memo');

            // Audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
            $table->index('expected_at');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete()
                  ->comment('Unit ordered in (may differ from ingredient.base_unit)');

            // Ordered quantities + pricing
            $table->decimal('quantity_ordered', 15, 4);
            $table->decimal('unit_price', 12, 4)->comment('Price per ordered unit (not base unit)');
            $table->decimal('subtotal', 12, 4)->default(0)->comment('quantity_ordered × unit_price');

            // Receipt tracking — may be updated over multiple receipts
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
