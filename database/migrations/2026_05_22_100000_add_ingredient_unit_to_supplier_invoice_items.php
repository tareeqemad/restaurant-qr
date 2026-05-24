<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the pack-size FK onto supplier_invoice_items too, so the
 * invoice line knows whether the supplier itemised in cartons or in
 * single cans. Without this, the variance computation in
 * SupplierInvoiceService can't tell the units apart when the PO line
 * uses an alternate unit but the invoice doesn't (or vice versa).
 *
 * Done as a separate migration because supplier_invoice_items wasn't
 * touched in the original ingredient_units migration (only
 * purchase_order_items + purchase_receipt_items).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('supplier_invoice_items')) return;
        if (Schema::hasColumn('supplier_invoice_items', 'ingredient_unit_id')) return;

        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->foreignId('ingredient_unit_id')->nullable()
                ->after('unit_id')
                ->constrained('ingredient_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_invoice_items')) return;
        if (! Schema::hasColumn('supplier_invoice_items', 'ingredient_unit_id')) return;

        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_unit_id');
        });
    }
};
