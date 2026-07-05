<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingSeeder extends Seeder
{
    /**
     * Advanced-accounting mechanics a small cash/bank-transfer restaurant
     * never uses. They are seeded (so historical postings still resolve)
     * but shipped INACTIVE — hidden from the chart tree, mapping dropdowns
     * and manual-entry screens. The posting engine still writes to them if
     * an event ever fires, so disabling is safe and reversible.
     *
     * NOTE: this is the single source of truth for the disabled set. Do NOT
     * force is_active=true below, or the disable migrations get undone on
     * every re-seed (the bug this replaces).
     */
    private const DISABLED_CODES = [
        '1020', // مقاصة بطاقات — تسوية فورية إلى 1010
        '1030', // مقاصة المحافظ — غير مستخدمة
        '1040', // مقاصة البيع الآجل — الرصيد يبقى على 1100
        '2200', // أمانات الإكراميات — تُقبض مباشرة
        '4200', // فروقات جرد دائنة — لا جرد فعلي
        '4210', // فروقات صندوق دائنة — لا عدّ نقدية
        '5300', // عمولات بنكية — تسوية بلا رسوم
        '5410', // عجز جرد — لا جرد فعلي
        '5420', // فروقات أسعار مشتريات — لا مطابقة بندية
        '5510', // عجز صندوق — لا عدّ نقدية
        '5050', // مصاريف وجبات الموظفين — يتيم: استُبدل بنموذج 4030، لا يرحّل إليه كود
    ];

    public function run(): void
    {
        foreach ($this->accounts() as $account) {
            $exists = DB::table('accounts')->where('code', $account['code'])->exists();

            $payload = [
                ...$account,
                'is_system'  => true,
                'updated_at' => now(),
            ];

            // Only set is_active on first insert — never override an operator's
            // (or a disable migration's) later toggle on an existing account.
            // Fresh installs get the correct default: disabled set starts off.
            if (! $exists) {
                $payload['is_active']  = ! in_array($account['code'], self::DISABLED_CODES, true);
                $payload['created_at'] = now();
            }

            DB::table('accounts')->updateOrInsert(['code' => $account['code']], $payload);
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
            ['code' => '3020', 'name' => 'أرباح محتجزة', 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'صافي أرباح أو خسائر الفترات المقفلة بعد ترحيل حسابات الإيراد والمصاريف.'],
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

            // ── وجبات الموظفين (staff meals) — تنشئها هجرات 2026_05_25_* أيضاً؛
            //    مكرّرة هنا ليبقى الـ seeder مصدراً كاملاً للدليل (updateOrInsert idempotent).
            ['code' => '1110', 'name' => 'مستحقات على الموظفين - بدل الوجبات', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'قيمة وجبات الموظفين المستحقة عليهم قبل التسوية نقداً أو بخصم الراتب.'],
            ['code' => '2110', 'name' => 'خصومات الرواتب المستحقة', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'مبالغ خُصمت على الموظفين في إقفال شهري وستُحسم من راتب الشهر التالي.'],
            ['code' => '4030', 'name' => 'إيرادات بدل وجبات الموظفين', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'القيمة البيعية لوجبات الموظفين الداخلية.'],
            ['code' => '5050', 'name' => 'مصاريف وجبات الموظفين', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة الوجبات المقدّمة للموظفين كميزة عينية.'],
            ['code' => '5060', 'name' => 'هدايا ومكافآت الموظفين العينية', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'وجبات مُنحت للموظفين مجاناً (هدية أو تنازل إداري).'],

            // ── الأصول الثابتة والإهلاك (fixed assets) — تنشئها هجرة 2026_05_31_190000.
            ['code' => '1500', 'name' => 'الأصول الثابتة', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'تكلفة المعدات والأثاث والتجهيزات المرسملة.'],
            ['code' => '1590', 'name' => 'مجمع الإهلاك', 'type' => 'asset', 'normal_balance' => 'credit', 'description' => 'مجمع إهلاك الأصول الثابتة (حساب مقابل للأصل بطبيعة دائنة).'],
            ['code' => '4230', 'name' => 'ربح استبعاد أصل ثابت', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'الربح عندما يتجاوز متحصل بيع الأصل قيمته الدفترية.'],
            ['code' => '5500', 'name' => 'مصروف الإهلاك', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'قسط الإهلاك الدوري للأصول الثابتة.'],
            ['code' => '5530', 'name' => 'خسارة استبعاد أصل ثابت', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'الخسارة عندما يقل متحصل بيع الأصل عن قيمته الدفترية.'],

            // ── فروقات العملة (FX) — تنشئها هجرة 2026_05_31_180000. تبقى نشطة لأنها
            //    تُرحَّل تلقائياً عند تسوية ذمم بعملة أجنبية حتى لو عملة الدفاتر واحدة اليوم.
            ['code' => '4220', 'name' => 'أرباح فروقات العملة', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'ربح صرف عند تسوية ذمم أو التزامات بعملة أجنبية بسعر أفضل.'],
            ['code' => '5520', 'name' => 'خسائر فروقات العملة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'خسارة صرف عند تسوية ذمم أو التزامات بعملة أجنبية بسعر أسوأ.'],
        ];
    }
}
