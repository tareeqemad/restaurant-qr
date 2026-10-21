<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_submission_receipts', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->char('payload_hash', 64);
            $table->longText('response_json')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_submission_receipts');
    }
};
