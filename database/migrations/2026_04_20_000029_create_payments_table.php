<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments tendered against an invoice. Multiple payments allowed
 * (split-tendering). `shift_id` is set when a cashier is on shift —
 * NULL is fine for back-office payments outside a shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'transfer', 'app', 'credit'])->default('cash');
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable()->comment('Card last 4 / transaction id');
            $table->foreignId('received_by_user_id')->constrained('users');
            $table->foreignId('shift_id')->nullable()
                ->constrained('shifts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamps();

            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
