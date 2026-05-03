<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. `causer` is who did it (usually User), `subject`
 * is what they did it to (polymorphic). `properties` JSON captures whatever
 * the call site decided was worth logging — dollar amounts, items count,
 * before/after status, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 64)->default('default');
            $table->foreignId('causer_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('causer_type')->default('AppModelsUser')->nullable();
            $table->string('event', 64)
                ->comment('created|updated|deleted|approved|cancelled|paid|etc');
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['log_name', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
