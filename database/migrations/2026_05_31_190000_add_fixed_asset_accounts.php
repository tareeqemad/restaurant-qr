<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->accounts() as $account) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $account['code']],
                [
                    ...$account,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        // Keep system accounts because posted journal history may reference them.
    }

    private function accounts(): array
    {
        return [
            [
                'code' => '1500',
                'name' => 'Fixed assets',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'description' => 'Restaurant equipment, furniture, and long-lived operating assets.',
            ],
            [
                'code' => '1590',
                'name' => 'Accumulated depreciation',
                'type' => 'asset',
                'normal_balance' => 'credit',
                'description' => 'Contra-asset account that accumulates depreciation posted against fixed assets.',
            ],
            [
                'code' => '4230',
                'name' => 'Gain on fixed asset disposal',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'description' => 'Gain recognized when fixed asset disposal proceeds exceed book value.',
            ],
            [
                'code' => '5500',
                'name' => 'Depreciation expense',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'description' => 'Periodic depreciation expense for fixed assets.',
            ],
            [
                'code' => '5530',
                'name' => 'Loss on fixed asset disposal',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'description' => 'Loss recognized when fixed asset disposal proceeds are below book value.',
            ],
        ];
    }
};
