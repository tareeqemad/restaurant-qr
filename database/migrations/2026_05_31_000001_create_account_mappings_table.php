<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('context', 60);
            $table->string('key', 120);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['context', 'key'], 'account_mappings_context_key_unique');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
