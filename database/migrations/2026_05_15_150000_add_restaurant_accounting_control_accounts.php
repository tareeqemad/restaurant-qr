<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        foreach ($this->accounts() as $account) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $account['code']],
                [
                    ...$account,
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        // Keep accounting rows for audit safety.
    }

    private function accounts(): array
    {
        return [
            ['code' => '1150', 'name' => 'مخزون تحت التحويل بين الفروع', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مخزون خرج من فرع ولم يؤكد الفرع الآخر استلامه بعد.'],
            ['code' => '2300', 'name' => 'استلامات مخزون غير مفوترة', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'بضاعة تم استلامها في المخزن ولم تصل فاتورة المورد الخاصة بها بعد.'],
            ['code' => '3010', 'name' => 'أرصدة افتتاحية', 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'رصيد افتتاحي للمخزون أو النقد عند بدء استخدام النظام.'],
            ['code' => '4200', 'name' => 'فروقات جرد دائنة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'زيادات جردية أو تسويات مخزون لصالح المنشأة.'],
            ['code' => '4210', 'name' => 'فروقات صندوق دائنة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'فوائض نقدية عند إغلاق الشفت.'],
            ['code' => '5400', 'name' => 'هدر وتالف المخزون', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة المواد التالفة أو المهدورة.'],
            ['code' => '5410', 'name' => 'عجز وفروقات جرد مدينة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقص جردي أو صرف مخزون غير مرتبط ببيع.'],
            ['code' => '5420', 'name' => 'فروقات أسعار مشتريات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'فرق السعر بين قيمة الاستلام وقيمة فاتورة المورد.'],
            ['code' => '5510', 'name' => 'عجز صندوق', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقص نقدي عند إغلاق الشفت.'],
        ];
    }
};
