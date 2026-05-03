<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches — top of the dependency tree. Every per-branch table FKs back
 * here, so this migration must run before any of them.
 *
 * `settings` is a free-form JSON bag for per-branch overrides (currently
 * delivery_estimated_minutes, delivery_fee, prep_buffer_minutes — adding
 * a new key is zero-schema-change). `code` is the URL-safe slug used in
 * routes and seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->json('settings')->nullable()
                ->comment('per-branch overrides: working hours, currency, tax, delivery, etc.');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
