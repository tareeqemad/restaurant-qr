<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay-in / pay-out drawer movements within a shift (e.g. petty cash for
 * a supplier, change brought in mid-shift). Always FKs back to a shift
 * so close-out can reconcile against expected cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['pay_in', 'pay_out']);
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
