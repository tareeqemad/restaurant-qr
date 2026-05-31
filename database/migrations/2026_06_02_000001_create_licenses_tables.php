<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 26)->unique();
            $table->string('license_key', 80)->unique();
            $table->string('customer_name');
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('restaurant_name')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->unsignedSmallInteger('period_months')->default(12);
            $table->date('starts_at');
            $table->date('expires_at')->index();
            $table->unsignedSmallInteger('grace_days')->default(14);
            $table->unsignedSmallInteger('max_branches')->default(1);
            $table->timestamp('last_payment_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('license_payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 26)->unique();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_months');
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('paid_at');
            $table->string('method', 30)->default('cash');
            $table->string('reference')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_at');
            $table->date('expires_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['license_id', 'paid_at']);
        });

        Schema::create('local_license_states', function (Blueprint $table) {
            $table->id();
            $table->string('license_key', 80)->nullable();
            $table->json('payload')->nullable();
            $table->text('signature')->nullable();
            $table->string('status', 30)->default('missing')->index();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('grace_ends_at')->nullable();
            $table->unsignedSmallInteger('max_branches')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_server_time_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_license_states');
        Schema::dropIfExists('license_payments');
        Schema::dropIfExists('licenses');
    }
};
