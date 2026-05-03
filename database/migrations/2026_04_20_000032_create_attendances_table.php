<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff clock-in / clock-out per shift. `worked_minutes` is computed when
 * `clock_out_at` is set. `source` distinguishes self-service taps from
 * manager-added entries (for after-the-fact corrections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('clock_in_at')->nullable();
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->nullable()
                ->comment('computed when clock_out_at is set');
            $table->string('notes', 500)->nullable();
            $table->enum('source', ['self', 'manager_added', 'auto'])->default('self');
            $table->foreignId('edited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'clock_in_at'], 'att_branch_in_idx');
            $table->index(['user_id', 'clock_in_at'], 'att_user_in_idx');
            $table->index(['user_id', 'clock_out_at'], 'att_user_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
