<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch expenses. Categories live in the global `lookups` table
 * (group='expense_categories'); cash-paid expenses optionally link to
 * a `cash_movement` so the till stays reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('expense_category_id')->constrained('lookups');
            $table->string('expense_number', 32)->unique();
            $table->string('description', 255);
            $table->text('notes')->nullable();
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'cheque', 'other'])->default('cash');
            $table->string('payment_reference', 100)->nullable()
                ->comment('cheque #, transfer id, etc.');
            $table->string('vendor_name', 150)->nullable();
            $table->foreignId('supplier_id')->nullable()
                ->constrained('suppliers')->nullOnDelete();
            $table->string('attachment_path')->nullable();
            $table->date('expense_date');
            $table->foreignId('shift_id')->nullable()
                ->constrained('shifts')->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()
                ->constrained('cash_movements')->nullOnDelete();
            $table->enum('status', ['pending_approval', 'approved', 'rejected'])->default('pending_approval');
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'expense_date'], 'exp_branch_date_idx');
            $table->index(['branch_id', 'status'], 'exp_branch_status_idx');
            $table->index(['branch_id', 'expense_category_id'], 'exp_branch_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
