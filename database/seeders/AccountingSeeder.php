<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as $account) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $account['code']],
                [
                    ...$account,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function accounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'الصندوق', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'النقدية الموجودة في درج الكاشير أو الخزنة.'],
            ['code' => '1010', 'name' => 'البنك', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'أرصدة الحسابات البنكية والتحويلات البنكية.'],
            ['code' => '1020', 'name' => 'مقاصة بطاقات الدفع', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مبالغ محصلة بالبطاقات قبل تسويتها في البنك.'],
            ['code' => '1030', 'name' => 'مقاصة المحافظ الإلكترونية', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مبالغ محصلة عبر تطبيقات ومحافظ الدفع قبل التسوية.'],
            ['code' => '1040', 'name' => 'مقاصة البيع الآجل للعملاء', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مبالغ مبيعات آجلة أو إشعارات دائنة تحتاج تسوية.'],
            ['code' => '1100', 'name' => 'الذمم المدينة - العملاء', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مستحقات المطعم على العملاء بعد إصدار الفواتير.'],
            ['code' => '1150', 'name' => 'مخزون تحت التحويل بين الفروع', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مخزون خرج من فرع ولم يؤكد الفرع الآخر استلامه بعد.'],
            ['code' => '1200', 'name' => 'المخزون', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'قيمة المواد والمكونات المتاحة للبيع أو الإنتاج.'],
            ['code' => '1300', 'name' => 'ضريبة القيمة المضافة - مدخلات', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'ضريبة مشتريات قابلة للخصم من ضريبة المخرجات.'],
            ['code' => '2000', 'name' => 'الذمم الدائنة - الموردون', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'مستحقات الموردين على المطعم.'],
            ['code' => '2100', 'name' => 'ضريبة القيمة المضافة - مخرجات', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'ضريبة المبيعات المستحقة للجهات الضريبية.'],
            ['code' => '2200', 'name' => 'أمانات الإكراميات', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'إكراميات محصلة لصالح الموظفين ولم تصرف بعد.'],
            ['code' => '2300', 'name' => 'استلامات مخزون غير مفوترة', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'بضاعة تم استلامها في المخزن ولم تصل فاتورة المورد الخاصة بها بعد.'],
            ['code' => '3000', 'name' => 'رأس المال وحقوق الملكية', 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'حقوق مالك المنشأة وصافي الاستثمار.'],
            ['code' => '3010', 'name' => 'أرصدة افتتاحية', 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'رصيد افتتاحي للمخزون أو النقد عند بدء استخدام النظام.'],
            ['code' => '4000', 'name' => 'إيرادات المبيعات', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'إيرادات بيع الأصناف قبل الخصومات والمردودات.'],
            ['code' => '4010', 'name' => 'إيرادات رسوم الخدمة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'رسوم الخدمة المحملة على الفواتير.'],
            ['code' => '4020', 'name' => 'إيرادات التوصيل', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'رسوم التوصيل المحملة على الطلبات.'],
            ['code' => '4090', 'name' => 'خصومات ومسموحات المبيعات', 'type' => 'contra_revenue', 'normal_balance' => 'debit', 'description' => 'حساب مقابل للإيراد يخفض صافي المبيعات.'],
            ['code' => '4100', 'name' => 'مردودات ومسموحات المبيعات', 'type' => 'contra_revenue', 'normal_balance' => 'debit', 'description' => 'استردادات أو مردودات تخفض صافي المبيعات.'],
            ['code' => '4200', 'name' => 'فروقات جرد دائنة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'زيادات جردية أو تسويات مخزون لصالح المنشأة.'],
            ['code' => '4210', 'name' => 'فروقات صندوق دائنة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'فوائض نقدية عند إغلاق الشفت.'],
            ['code' => '5000', 'name' => 'تكلفة البضاعة المباعة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة المواد المرتبطة بالأصناف المباعة.'],
            ['code' => '5100', 'name' => 'مصروفات تشغيلية', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مصروفات تشغيل المطعم اليومية غير المرتبطة مباشرة بتكلفة الصنف.'],
            ['code' => '5200', 'name' => 'مصروف ديون معدومة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مبالغ ذمم مدينة تم شطبها لعدم التحصيل.'],
            ['code' => '5300', 'name' => 'عمولات بنكية وبطاقات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'رسوم بوابات الدفع والبنوك والبطاقات.'],
            ['code' => '5400', 'name' => 'هدر وتالف المخزون', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة المواد التالفة أو المهدورة.'],
            ['code' => '5410', 'name' => 'عجز وفروقات جرد مدينة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقص جردي أو صرف مخزون غير مرتبط ببيع.'],
            ['code' => '5420', 'name' => 'فروقات أسعار مشتريات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'فرق السعر بين قيمة الاستلام وقيمة فاتورة المورد.'],
            ['code' => '5510', 'name' => 'عجز صندوق', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقص نقدي عند إغلاق الشفت.'],
        ];
    }
}
