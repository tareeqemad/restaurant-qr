<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking users ↔ branches with the role they hold IN that branch.
 * A user can belong to multiple branches (regional manager); is_primary
 * marks the default branch picked at login by SetActiveBranch middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()
                ->constrained('roles')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
