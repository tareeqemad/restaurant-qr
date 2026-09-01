<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Setting;
use App\Support\MarketProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $baseCode = strtoupper((string) Setting::get('accounting_base_currency', MarketProfile::currency()));
            $baseSymbol = (string) Setting::get('accounting_currency_symbol', MarketProfile::currencySymbol());

            Currency::query()->update(['is_base' => false]);

            // A small restaurant in this market works with shekels and
            // dollars even when sales are recorded in one base currency.
            // Keep any accountant-entered exchange rate on re-seed.
            foreach ([
                'ILS' => ['name' => 'شيكل', 'symbol' => '₪', 'display_order' => 1],
                'USD' => ['name' => 'دولار أمريكي', 'symbol' => '$', 'display_order' => 2],
            ] as $code => $defaults) {
                Currency::firstOrCreate(
                    ['code' => $code],
                    [
                        ...$defaults,
                        'rate_to_base' => 1.000000,
                        'is_base' => false,
                        'is_active' => true,
                    ],
                );
            }

            $baseName = match ($baseCode) {
                'ILS' => 'شيكل',
                'USD' => 'دولار أمريكي',
                'JOD' => 'دينار أردني',
                default => $baseCode,
            };

            Currency::updateOrCreate(
                ['code' => $baseCode],
                [
                    'name' => $baseName,
                    'symbol' => $baseSymbol,
                    'rate_to_base' => 1.000000,
                    'is_base' => true,
                    'is_active' => true,
                    'display_order' => $baseCode === 'USD' ? 2 : 1,
                    'rate_updated_at' => now(),
                ]
            );

            if (! Setting::where('key', 'currency_symbol')->exists()) {
                Setting::put('currency_symbol', $baseSymbol, 'billing', 'string');
            }

            if (! Setting::where('key', 'sales_currency')->exists()) {
                Setting::put('sales_currency', $baseCode, 'billing', 'string');
            }

            if (! Setting::where('key', 'accounting_base_currency')->exists()) {
                Setting::put('accounting_base_currency', $baseCode, 'accounting', 'string');
            }

            if (! Setting::where('key', 'accounting_currency_symbol')->exists()) {
                Setting::put('accounting_currency_symbol', $baseSymbol, 'accounting', 'string');
            }

            if (! Setting::where('key', 'sales_to_accounting_rate')->exists()) {
                Setting::put('sales_to_accounting_rate', 1, 'accounting', 'float');
            }
        });
    }
}
