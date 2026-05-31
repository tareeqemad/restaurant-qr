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
                'code' => '4220',
                'name' => 'Foreign exchange gain',
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'description' => 'Gain from settling foreign-currency receivables or payables at a favorable exchange rate.',
            ],
            [
                'code' => '5520',
                'name' => 'Foreign exchange loss',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'description' => 'Loss from settling foreign-currency receivables or payables at an unfavorable exchange rate.',
            ],
        ];
    }
};
