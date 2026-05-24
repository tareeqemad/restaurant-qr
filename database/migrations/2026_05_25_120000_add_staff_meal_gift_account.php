<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds account 5060 (هدايا وجبات الموظفين) for the new "gift" settlement
 * method. A gift charge posts:
 *
 *   At charge time: DR 5050 / CR 1110  (same as a normal charge)
 *   At gift settle: DR 5060 / CR 1110  (recognizes the giveaway as a
 *                                      distinct expense AND clears the
 *                                      receivable in one step)
 *
 * Net effect: the food cost lands in 5050 (regular meals) + 5060
 * (gifts), and 1110 (receivable) is zero. The split lets the GM see
 * exactly how much was given away vs. how much is on the running tab.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) return;

        DB::table('accounts')->updateOrInsert(
            ['code' => '5060'],
            [
                'code'           => '5060',
                'name'           => 'هدايا ومكافآت الموظفين العينية',
                'type'           => 'expense',
                'normal_balance' => 'debit',
                'description'    => 'وجبات مجانية أو إعفاءات ممنوحة للموظفين خارج بدل الوجبات الأساسي.',
                'is_system'      => true,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        );
    }

    public function down(): void
    {
        // Leave the account — historical journal lines reference it.
    }
};
