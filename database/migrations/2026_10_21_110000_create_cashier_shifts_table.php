<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            // Nullable after closing: one custody per employee, across branches.
            $table->unsignedBigInteger('active_user_id')->nullable()->unique();
            $table->decimal('opening_cash', 14, 2);
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();
        });
        Schema::create('cashier_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_branch_id')->index();
            $table->string('event_key')->unique();
            $table->string('kind', 50);
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->string('reason', 500)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->dateTime('occurred_at');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('cashier_shift_id'));
        Schema::dropIfExists('cashier_shift_movements');
        Schema::dropIfExists('cashier_shifts');
    }
};
