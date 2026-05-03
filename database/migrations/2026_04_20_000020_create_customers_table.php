<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authenticated diners (separate from staff Users + separate auth guard).
 * Phone is canonical login id; email optional.
 *
 * Customers are GLOBAL (not branch-scoped) — one diner can dine at any
 * branch. Their reservations / reviews / orders carry branch_id so
 * branch staff still see only what's relevant to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->unique()->comment('canonical login id');
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->date('birthday')->nullable();
            $table->enum('gender', ['male', 'female', 'unspecified'])->default('unspecified');
            $table->json('preferences')->nullable()
                ->comment('dietary tags, favourite items, seating preference, etc.');
            $table->foreignId('default_branch_id')->nullable()
                ->constrained('branches')->nullOnDelete();
            $table->foreignId('loyalty_customer_id')->nullable()
                ->constrained('loyalty_customers')->nullOnDelete();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->string('blocked_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('default_branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
