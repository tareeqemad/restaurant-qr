<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('settings')) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'currency_symbol'],
                [
                    'value' => '₪',
                    'group' => 'billing',
                    'type' => 'string',
                    'label' => 'رمز العملة',
                    'description' => 'الرمز الافتراضي لعرض الأسعار والفواتير.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('currencies')) {
            DB::table('currencies')->update(['is_base' => false]);

            DB::table('currencies')->updateOrInsert(
                ['code' => 'ILS'],
                [
                    'name' => 'شيكل',
                    'symbol' => '₪',
                    'rate_to_base' => 1.000000,
                    'is_base' => true,
                    'is_active' => true,
                    'display_order' => 1,
                    'rate_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Keep the selected business currency intact on rollback.
    }
};
