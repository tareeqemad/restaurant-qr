<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'lead_time_days')) {
                $table->unsignedSmallInteger('lead_time_days')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'payment_terms_days')) {
                $table->unsignedSmallInteger('payment_terms_days')->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'minimum_order_amount')) {
                $table->decimal('minimum_order_amount', 12, 4)->nullable();
            }
            if (! Schema::hasColumn('suppliers', 'delivery_days')) {
                $table->json('delivery_days')->nullable();
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
        });

        if (! Schema::hasTable('purchase_receipts')) {
            Schema::create('purchase_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches');
                $table->string('number', 40)->unique();
                $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained();
                $table->foreignId('received_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('received_at');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['purchase_order_id', 'received_at']);
                $table->index(['supplier_id', 'received_at']);
                $table->index('branch_id');
            });
        }

        if (! Schema::hasTable('purchase_receipt_items')) {
            Schema::create('purchase_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->nullable()
                    ->constrained()->nullOnDelete();
                $table->foreignId('ingredient_id')->constrained();
                $table->foreignId('unit_id')->constrained('units');
                $table->foreignId('storage_location_id')->nullable()
                    ->constrained('storage_locations')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()
                    ->constrained('ingredient_batches')->nullOnDelete();
                $table->decimal('quantity_received', 15, 4);
                $table->decimal('quantity_in_base', 15, 4);
                $table->decimal('unit_price', 12, 4);
                $table->decimal('unit_price_in_base', 12, 4);
                $table->decimal('subtotal', 12, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['ingredient_id', 'created_at']);
                $table->index('purchase_order_item_id');
            });
        }

        if (! Schema::hasTable('supplier_invoice_items')) {
            Schema::create('supplier_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->nullable()
                    ->constrained()->nullOnDelete();
                $table->foreignId('ingredient_id')->nullable()
                    ->constrained()->nullOnDelete();
                $table->foreignId('unit_id')->nullable()
                    ->constrained('units')->nullOnDelete();
                $table->string('description', 255);
                $table->decimal('quantity', 15, 4)->default(1);
                $table->decimal('unit_price', 12, 4)->default(0);
                $table->decimal('subtotal', 12, 4)->default(0);
                $table->decimal('tax_total', 12, 4)->default(0);
                $table->decimal('total', 12, 4)->default(0);
                $table->decimal('received_qty', 15, 4)->nullable();
                $table->decimal('received_total', 12, 4)->nullable();
                $table->decimal('variance_qty', 15, 4)->nullable();
                $table->decimal('variance_total', 12, 4)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('supplier_invoice_id');
                $table->index('purchase_order_item_id');
                $table->index('ingredient_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }
            if (Schema::hasColumn('purchase_orders', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['lead_time_days', 'payment_terms_days', 'minimum_order_amount', 'delivery_days'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
