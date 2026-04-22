<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency support — display prices to tourists in their local currency.
 *
 * Base currency stays the one configured in config/restaurant.php (JOD by default).
 * All invoices/receipts are STILL recorded in base — the currency switcher only
 * converts what's DISPLAYED on the customer menu and bill preview.
 *
 * Each row's rate_to_base = "1 unit of this currency = X units of base currency".
 * So if base=JOD and row is USD with rate_to_base=0.71, then 1 USD = 0.71 JOD.
 * Conversely, 10 JOD = 10 / 0.71 ≈ 14.08 USD.
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
            $table->decimal('rate_to_base', 14, 6)->default(1)
                  ->comment('Multiply foreign amount by this to get base-currency amount');
            $table->boolean('is_base')->default(false)->comment('Exactly one row should be base');
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamp('rate_updated_at')->nullable()->comment('When rate was last edited');
            $table->timestamps();
        });

        // Seed with common currencies for the region
        $baseCode = config('restaurant.currency', 'JOD');
        $seed = [
            ['code' => 'JOD', 'name' => 'دينار أردني',   'symbol' => 'د.أ', 'rate' => 1,      'order' => 1],
            ['code' => 'USD', 'name' => 'دولار أمريكي',  'symbol' => '$',   'rate' => 0.71,   'order' => 2],
            ['code' => 'EUR', 'name' => 'يورو',          'symbol' => '€',   'rate' => 0.77,   'order' => 3],
            ['code' => 'SAR', 'name' => 'ريال سعودي',    'symbol' => 'ر.س', 'rate' => 0.19,   'order' => 4],
            ['code' => 'AED', 'name' => 'درهم إماراتي',  'symbol' => 'د.إ', 'rate' => 0.19,   'order' => 5],
            ['code' => 'ILS', 'name' => 'شيكل',          'symbol' => '₪',   'rate' => 0.19,   'order' => 6],
            ['code' => 'EGP', 'name' => 'جنيه مصري',     'symbol' => 'ج.م', 'rate' => 0.014,  'order' => 7],
        ];

        foreach ($seed as $s) {
            DB::table('currencies')->insert([
                'code'            => $s['code'],
                'name'            => $s['name'],
                'symbol'          => $s['symbol'],
                'rate_to_base'    => $s['code'] === $baseCode ? 1 : $s['rate'],
                'is_base'         => $s['code'] === $baseCode,
                'is_active'       => true,
                'display_order'   => $s['order'],
                'rate_updated_at' => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
