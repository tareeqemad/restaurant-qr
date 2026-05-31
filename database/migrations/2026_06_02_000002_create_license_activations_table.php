<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 26)->unique();
            $table->foreignId('license_id')->constrained('licenses')->cascadeOnDelete();
            $table->string('branch_uuid', 80);
            $table->string('branch_id', 80)->nullable();
            $table->string('app_url')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['license_id', 'branch_uuid']);
            $table->index(['license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
    }
};
