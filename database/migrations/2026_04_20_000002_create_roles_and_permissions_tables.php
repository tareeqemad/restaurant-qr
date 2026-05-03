<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC primitives.
 *
 *   - `roles` carries an optional `branch_id`: NULL = global template
 *     managed by Super Admin, set = branch-specific role.
 *   - `permissions` are global identifiers; the role_permission and
 *     user_permission pivots wire grants/denies.
 *   - `user_permission.granted=false` is an explicit revoke that overrides
 *     the role grant (rare, used for one-off restrictions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()
                ->constrained('branches')->cascadeOnDelete();
            $table->string('name', 64)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('branch_id');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('label');
            $table->string('group', 64)->nullable();
            $table->string('group_label')->nullable();
            $table->string('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted')->default(true)->comment('false=revoked explicitly');
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
