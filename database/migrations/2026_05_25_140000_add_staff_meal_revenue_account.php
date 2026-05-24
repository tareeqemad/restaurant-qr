<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds account 4030 (إيرادات بدل وجبات الموظفين) to support proper
 * double-entry on staff meal charges.
 *
 * The earlier flow had reversed directionality (DR expense / CR
 * receivable on a CHARGE — which made 1110 carry a credit balance,
 * the opposite of what a receivable should be). This account is the
 * credit-side counterpart so the right entry becomes:
 *
 *   DR 1110 Staff Receivable (retail amount)
 *   CR 4030 Staff Meal Revenue (retail amount)
 *
 * Settlements then clear the receivable into the appropriate side:
 *   cash    → DR 1000 / CR 1110
 *   payroll → DR 2110 / CR 1110
 *   gift    → DR 5060 / CR 1110  (recognizes giveaway as perk expense)
 *   writeoff→ DR 5200 / CR 1110  (bad debt)
 *
 * Reporting framing: 4030 is internal revenue. Owners who don't want
 * staff meals inflating their top-line "sales" line can group it as
 * "internal income" in the income statement template.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) return;

        DB::table('accounts')->updateOrInsert(
            ['code' => '4030'],
            [
                'code'           => '4030',
                'name'           => 'إيرادات بدل وجبات الموظفين',
                'type'           => 'revenue',
                'normal_balance' => 'credit',
                'description'    => 'القيمة الاسمية للوجبات المُحمَّلة على بدل الموظفين (قابلة للتحصيل أو التحول إلى مكافأة).',
                'is_system'      => true,
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        );
    }

    public function down(): void
    {
        // Historical journal lines reference 4030 — keep the account.
    }
};
