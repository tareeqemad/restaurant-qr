<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_command_receipts', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->char('payload_hash', 64);
            $table->text('redirect_url')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_command_receipts');
    }
};
