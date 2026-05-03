<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds against an invoice. `payment_id` may point at the original
 * payment for traceable card refunds; NULL means a generic invoice
 * refund (e.g. comp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique()
                ->comment('Human-readable, e.g. REF-20260421-0001');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            $table->decimal('amount', 12, 4);
            $table->enum('method', ['cash', 'card', 'transfer', 'app', 'credit', 'other'])->default('cash');
            $table->string('reference', 100)->nullable()
                ->comment('External txn id for card refunds');
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()
                ->constrained('shifts')->nullOnDelete();
            $table->timestamp('refunded_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'status']);
            $table->index('refunded_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
