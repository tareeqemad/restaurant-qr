<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Restores the intended "lean chart" for a small cash / bank-transfer
 * restaurant. The original disable migrations (2026_05_19_120000 and
 * 2026_05_19_140000) turned these accounts off, but AccountingSeeder used
 * to force is_active=true on every run and silently switched them back on
 * — so production ended up with all 10 advanced-accounting accounts active
 * and cluttering the chart tree.
 *
 * The seeder is now fixed (never overrides is_active on an existing row),
 * and this migration re-applies the disabled state one final time so
 * existing databases (local + production) match the seeder's fresh-install
 * default.
 *
 * Disabling only hides these from the chart tree, account-mapping dropdowns
 * and manual-entry screens. The posting engine still writes to them if an
 * event fires (it deliberately ignores is_active), and they still appear in
 * the trial balance when they carry a balance — so nothing breaks and the
 * change is fully reversible via down().
 */
return new class extends Migration
{
    private array $codes = [
        '1020', // مقاصة بطاقات الدفع
        '1030', // مقاصة المحافظ الإلكترونية
        '1040', // مقاصة البيع الآجل للعملاء
        '2200', // أمانات الإكراميات
        '4200', // فروقات جرد دائنة
        '4210', // فروقات صندوق دائنة
        '5300', // عمولات بنكية وبطاقات
        '5410', // عجز وفروقات جرد مدينة
        '5420', // فروقات أسعار مشتريات
        '5510', // عجز صندوق
    ];

    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('code', $this->codes)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('accounts')
            ->whereIn('code', $this->codes)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
