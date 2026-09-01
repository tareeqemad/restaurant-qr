<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Defaults required by a clean Arabic-market restaurant installation. */
class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->settings() as $row) {
            if (! Setting::query()->where('key', $row['key'])->exists()) {
                Setting::put($row['key'], $row['value'], $row['group'], $row['type']);
            }

            if ($row['label'] !== null || $row['description'] !== null) {
                DB::table('settings')->where('key', $row['key'])->update([
                    'label' => $row['label'],
                    'description' => $row['description'],
                ]);
            }
        }
    }

    /** @return list<array{key:string,value:mixed,group:string,type:string,label:?string,description:?string}> */
    private function settings(): array
    {
        return [
            ['key' => 'accounting_base_currency', 'value' => 'ILS', 'group' => 'accounting', 'type' => 'string', 'label' => null, 'description' => null],
            ['key' => 'accounting_currency_symbol', 'value' => '₪', 'group' => 'accounting', 'type' => 'string', 'label' => null, 'description' => null],
            ['key' => 'currency_symbol', 'value' => '₪', 'group' => 'billing', 'type' => 'string', 'label' => 'رمز العملة', 'description' => 'الرمز الافتراضي لعرض الأسعار والفواتير.'],
            ['key' => 'payment_method_cash_enabled', 'value' => true, 'group' => 'payments', 'type' => 'bool', 'label' => null, 'description' => null],
            ['key' => 'payment_method_transfer_enabled', 'value' => true, 'group' => 'payments', 'type' => 'bool', 'label' => null, 'description' => null],
            ['key' => 'payment_method_card_enabled', 'value' => false, 'group' => 'payments', 'type' => 'bool', 'label' => null, 'description' => null],
            ['key' => 'payment_method_palpay_enabled', 'value' => false, 'group' => 'payments', 'type' => 'bool', 'label' => null, 'description' => null],
            ['key' => 'payment_method_jawwal_pay_enabled', 'value' => false, 'group' => 'payments', 'type' => 'bool', 'label' => null, 'description' => null],
            ['key' => 'sales_currency', 'value' => 'ILS', 'group' => 'billing', 'type' => 'string', 'label' => null, 'description' => null],
            ['key' => 'sales_to_accounting_rate', 'value' => 1, 'group' => 'accounting', 'type' => 'float', 'label' => null, 'description' => null],
            ['key' => 'staff_meal_over_limit_policy', 'value' => 'warn', 'group' => 'staff_meals', 'type' => 'string', 'label' => null, 'description' => null],
        ];
    }
}
