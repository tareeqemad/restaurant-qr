<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 26)->nullable()->unique();
            $table->string('currency_code', 5);
            $table->string('base_currency_code', 5);
            $table->decimal('rate', 18, 8)->comment('Multiply currency amount by this rate to get base-currency amount');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 30)->default('manual');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['currency_code', 'base_currency_code', 'valid_from', 'valid_to'], 'currency_rates_lookup_idx');
            $table->index(['currency_code', 'base_currency_code', 'is_active'], 'currency_rates_active_idx');
        });

        $this->backfillCurrentCurrencyRates();
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchange_rates');
    }

    private function backfillCurrentCurrencyRates(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $baseCode = DB::table('currencies')->where('is_base', true)->value('code') ?: 'USD';
        $now = now();

        DB::table('currencies')
            ->where('code', '!=', $baseCode)
            ->where('rate_to_base', '>', 0)
            ->orderBy('id')
            ->get()
            ->each(function ($currency) use ($baseCode, $now) {
                DB::table('currency_exchange_rates')->insert([
                    'uuid' => (string) Str::ulid(),
                    'currency_code' => strtoupper((string) $currency->code),
                    'base_currency_code' => strtoupper((string) $baseCode),
                    'rate' => (float) $currency->rate_to_base,
                    'valid_from' => $now->toDateString(),
                    'valid_to' => null,
                    'is_active' => (bool) $currency->is_active,
                    'source' => 'migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }
};
