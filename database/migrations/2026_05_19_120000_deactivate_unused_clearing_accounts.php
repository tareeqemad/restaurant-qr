<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deactivate the three clearing accounts that this restaurant never
 * needs:
 *
 *  1020  مقاصة بطاقات الدفع           — visa settles instantly to bank 1010
 *  1030  مقاصة المحافظ الإلكترونية   — wallets are not accepted
 *  1040  مقاصة البيع الآجل للعملاء    — superseded by the customer-debt ledger
 *                                       which keeps the balance on AR 1100
 *
 * We DO NOT delete the rows — historical journal lines (if any) still
 * reference them, and the trial-balance / journal pages need to be able
 * to render the labels. Setting `is_active = false` is enough: the chart
 * editor hides them from new entries while keeping historic reads intact.
 *
 * Reversible: `down()` flips the same rows back to active.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['1020', '1030', '1040'])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', ['1020', '1030', '1040'])
            ->update(['is_active' => true]);
    }
};
