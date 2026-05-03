<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer reservations. Lifecycle: pending → confirmed → seated →
 * completed / cancelled / no_show. State machine enforced in the model
 * (see Reservation::transitionTo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique()
                ->comment('human-readable code shown to the customer (e.g. "RV-9F3K")');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('reserved_for')->comment('start time of the booking');
            $table->unsignedSmallInteger('duration_minutes')->default(90);
            $table->enum('status', ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'])
                ->default('pending');
            $table->string('customer_notes', 500)->nullable();
            $table->string('internal_notes', 500)->nullable()
                ->comment('seen by branch staff only — never returned to the portal');
            $table->foreignId('confirmed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'reserved_for', 'status'], 'res_branch_when_status_idx');
            $table->index(['customer_id', 'reserved_for'], 'res_customer_when_idx');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
