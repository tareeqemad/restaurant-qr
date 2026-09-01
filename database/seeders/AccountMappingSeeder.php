<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Portable defaults for automatic accounting postings. */
class AccountMappingSeeder extends Seeder
{
    public function run(): void
    {
        $accountIds = DB::table('accounts')->pluck('id', 'code');

        foreach ($this->mappings() as [$context, $key, $accountCode]) {
            $accountId = $accountIds[$accountCode] ?? null;
            if (! $accountId) {
                throw new RuntimeException("Missing account [{$accountCode}] for mapping [{$context}:{$key}].");
            }

            if (! DB::table('account_mappings')->where('context', $context)->where('key', $key)->exists()) {
                DB::table('account_mappings')->insert([
                    'context' => $context,
                    'key' => $key,
                    'account_id' => $accountId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return list<array{string,string,string}> */
    private function mappings(): array
    {
        return [
            ['expense_category', 'code:rent', '5110'],
            ['expense_category', 'code:utilities', '5120'],
            ['expense_category', 'code:payroll', '5130'],
            ['expense_category', 'code:maintenance', '5140'],
            ['expense_category', 'code:cleaning_packaging', '5150'],
            ['expense_category', 'code:telecom', '5160'],
            ['expense_category', 'code:transport', '5170'],
            ['expense_category', 'code:digital_subscriptions', '5180'],
            ['expense_category', 'code:other_operating', '5190'],
            ['payment_method', 'cash', '1000'],
            ['payment_method', 'transfer', '1010'],
            ['payment_method', 'bank_transfer', '1010'],
            ['payment_method', 'card', '1010'],
            ['payment_method', 'palpay', '1020'],
            ['payment_method', 'jawwal_pay', '1030'],
        ];
    }
}
