<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            Currency::query()->update(['is_base' => false]);

            Currency::updateOrCreate(
                ['code' => 'ILS'],
                [
                    'name' => 'شيكل',
                    'symbol' => '₪',
                    'rate_to_base' => 1.000000,
                    'is_base' => true,
                    'is_active' => true,
                    'display_order' => 1,
                    'rate_updated_at' => now(),
                ]
            );

            if (! Setting::where('key', 'currency_symbol')->exists()) {
                Setting::put('currency_symbol', '₪', 'billing', 'string');
            }
        });
    }
}
