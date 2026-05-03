<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency support. Exactly one row should be `is_base = true` —
 * all reports + invoices store amounts in that base currency, and other
 * currencies are converted at display time using `rate_to_base`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique()->comment('ISO 4217 code (JOD, USD, EUR, SAR)');
            $table->string('name', 60);
            $table->string('symbol', 10);
            $table->decimal('rate_to_base', 14, 6)->default(1.000000)
                ->comment('Multiply foreign amount by this to get base-currency amount');
            $table->boolean('is_base')->default(false)->comment('Exactly one row should be base');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamp('rate_updated_at')->nullable()->comment('When rate was last edited');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
