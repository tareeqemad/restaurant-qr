<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('asset_number', 40)->unique();
            $table->string('name');
            $table->string('category', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('vendor_name', 150)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->date('acquisition_date');
            $table->date('in_service_date');
            $table->decimal('cost', 14, 4);
            $table->decimal('salvage_value', 14, 4)->default(0);
            $table->decimal('foreign_cost', 18, 4);
            $table->decimal('foreign_salvage_value', 18, 4)->default(0);
            $table->string('currency_code', 5);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->unsignedInteger('useful_life_months');
            $table->string('depreciation_method', 40)->default('straight_line');
            $table->string('payment_method', 40)->default('bank_transfer');
            $table->string('status', 30)->default('active');
            $table->decimal('accumulated_depreciation', 14, 4)->default(0);
            $table->date('depreciated_through')->nullable();
            $table->foreignId('purchase_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('disposal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 14, 4)->default(0);
            $table->string('disposal_payment_method', 40)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['acquisition_date', 'in_service_date']);
            $table->index('depreciated_through');
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('posted_on');
            $table->decimal('amount', 14, 4);
            $table->decimal('accumulated_after', 14, 4);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period_start', 'period_end'], 'fixed_asset_period_unique');
            $table->index(['branch_id', 'posted_on']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
    }
};
