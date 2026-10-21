<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->char('payload_hash', 64);
            $table->longText('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_payment_receipts');
    }
};
