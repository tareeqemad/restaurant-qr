<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier-side bills + payments. A supplier invoice may originate from a
 * purchase_order (received goods) OR be a standalone bill (services,
 * one-off purchases). `(supplier_id, number)` is the natural key — same
 * supplier can't send the same invoice number twice, but two suppliers
 * may both have an "INV-001".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('number', 60)
                ->comment('Supplier-provided invoice number (as printed on their bill)');
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('purchase_orders')->nullOnDelete();
            $table->decimal('subtotal', 12, 4)->default(0);
            $table->decimal('tax_total', 12, 4)->default(0);
            $table->decimal('total', 12, 4);
            $table->decimal('paid_total', 12, 4)->default(0);
            $table->decimal('balance', 12, 4)->default(0);
            $table->enum('status', ['unpaid', 'partially_paid', 'paid', 'cancelled'])->default('unpaid');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable()
                ->comment('Scan of physical invoice');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_id', 'number']);
            $table->index(['supplier_id', 'status']);
            $table->index('balance');
            $table->index('status');
            $table->index('due_date');
            $table->index('branch_id');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')
                ->constrained('supplier_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 4);
            $table->enum('method', ['cash', 'bank_transfer', 'cheque', 'card', 'credit_note', 'other'])
                ->default('cash');
            $table->string('reference', 100)->nullable()
                ->comment('Cheque number, txn id, ...');
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->foreignId('paid_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()
                ->constrained('shifts')->nullOnDelete();
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
