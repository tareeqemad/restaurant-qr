<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier invoices (bills received from suppliers) + payments we make to them.
 *
 * Flow:
 *   PO → receive goods → supplier issues invoice → we record it here →
 *   we pay partial/full → when balance reaches 0, invoice.status='paid'
 *
 * An invoice MAY be linked to a PO, but doesn't have to be — useful for ad-hoc
 * purchases (e.g., cash purchase at the market) that bypass the PO workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60)->comment('Supplier-provided invoice number (as printed on their bill)');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();

            // Amounts
            $table->decimal('subtotal',  12, 4)->default(0);
            $table->decimal('tax_total', 12, 4)->default(0);
            $table->decimal('total',     12, 4);
            $table->decimal('paid_total',12, 4)->default(0);
            $table->decimal('balance',   12, 4)->default(0)->index();

            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])
                  ->default('unpaid')->index();

            // Dates
            $table->date('invoice_date');
            $table->date('due_date')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable()->comment('Scan of physical invoice');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
            $table->unique(['supplier_id', 'number']);  // Same supplier can't submit the same invoice twice
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 4);
            $table->enum('method', ['cash', 'bank_transfer', 'cheque', 'card', 'credit_note', 'other'])->default('cash');
            $table->string('reference', 100)->nullable()->comment('Cheque number, txn id, ...');
            $table->date('paid_on');
            $table->text('notes')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->timestamps();

            $table->index(['supplier_invoice_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_invoices');
    }
};
