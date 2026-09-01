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
        '2200', // أمانات الإكراميات — تُقبض مباشرة
        '4200', // فروقات جرد دائنة — لا جرد فعلي
        '4210', // فروقات صندوق دائنة — لا عدّ نقدية
        '5410', // عجز جرد — لا جرد فعلي
        '5420', // فروقات أسعار مشتريات — لا مطابقة بندية
        '5510', // عجز صندوق — لا عدّ نقدية
    ];

    public function run(): void
    {
        foreach ($this->accounts() as $account) {
            $exists = DB::table('accounts')->where('code', $account['code'])->exists();

            $payload = [
                ...$account,
                'is_system' => true,
                'updated_at' => now(),
            ];

            // Only set is_active on first insert — never override an operator's
            // (or a disable migration's) later toggle on an existing account.
            // Fresh installs get the correct default: disabled set starts off.
            if (! $exists) {
                $payload['is_active'] = ! in_array($account['code'], self::DISABLED_CODES, true);
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
            ['code' => '1020', 'name' => 'محفظة PalPay', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مبالغ محصلة في محفظة PalPay ولم يحولها المحاسب إلى البنك بعد.'],
            ['code' => '1030', 'name' => 'محفظة Jawwal Pay', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مبالغ محصلة في محفظة Jawwal Pay ولم يحولها المحاسب إلى البنك بعد.'],
            ['code' => '1100', 'name' => 'الذمم المدينة - العملاء', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مستحقات المطعم على العملاء بعد إصدار الفواتير.'],
            ['code' => '1150', 'name' => 'مخزون تحت التحويل بين الفروع', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'مخزون خرج من فرع ولم يؤكد الفرع الآخر استلامه بعد.'],
            ['code' => '1160', 'name' => 'الحساب الجاري بين الفروع', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'حساب نظامي متقابل لقيمة المخزون المنقول بين الفروع ويتعادل في القوائم المجمعة.'],
            ['code' => '1200', 'name' => 'المخزون', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'قيمة المواد والمكونات المتاحة للبيع أو الإنتاج.'],
            ['code' => '1300', 'name' => 'ضريبة مشتريات الموردين - مدخلات', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'ضريبة تُدخل من فاتورة المورد نفسها إن وُجدت، ولا تتبع إعداد ضريبة فاتورة الزبون.'],
            ['code' => '2000', 'name' => 'الذمم الدائنة - الموردون', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'مستحقات الموردين على المطعم.'],
            ['code' => '2050', 'name' => 'رواتب وأجور مستحقة', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'رواتب تم إثباتها ولم تُدفع بعد.'],
            ['code' => '2100', 'name' => 'ضريبة فواتير الزبائن - مخرجات', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'ضريبة اختيارية على الفاتورة الصادرة من المطعم للزبون حسب جدول السريان.'],
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
            ['code' => '5000', 'name' => 'تكلفة البضاعة المباعة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة المواد المرتبطة بالأصناف المباعة.'],
            ['code' => '5100', 'name' => 'مصروفات تشغيلية', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مصروفات تشغيل المطعم اليومية غير المرتبطة مباشرة بتكلفة الصنف.'],
            ['code' => '5110', 'name' => 'إيجار المحل والفروع', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'إيجار موقع المطعم أو أحد فروعه.'],
            ['code' => '5120', 'name' => 'كهرباء ومياه وغاز', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'فواتير الكهرباء والمياه والغاز ومرافق التشغيل.'],
            ['code' => '5130', 'name' => 'رواتب وأجور الموظفين', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'رواتب وأجور وبدلات الموظفين.'],
            ['code' => '5140', 'name' => 'صيانة وإصلاحات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'صيانة المعدات والمحل والإصلاحات الدورية.'],
            ['code' => '5150', 'name' => 'نظافة وتغليف واستهلاكات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مواد التنظيف والتغليف والمستهلكات اليومية.'],
            ['code' => '5160', 'name' => 'اتصالات وإنترنت', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'الهاتف والإنترنت وخدمات الاتصال.'],
            ['code' => '5170', 'name' => 'نقل وتوصيل', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقل مشتريات أو توصيلات مدفوعة من المطعم.'],
            ['code' => '5180', 'name' => 'اشتراكات وخدمات رقمية', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'البرامج والخدمات السحابية والاشتراكات الدورية.'],
            ['code' => '5190', 'name' => 'مصاريف تشغيلية أخرى', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مصروف تشغيلي لا ينتمي إلى تصنيف أدق.'],
            ['code' => '5200', 'name' => 'مصروف ديون معدومة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'مبالغ ذمم مدينة تم شطبها لعدم التحصيل.'],
            ['code' => '5400', 'name' => 'هدر وتالف المخزون', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'تكلفة المواد التالفة أو المهدورة.'],
            ['code' => '5410', 'name' => 'عجز وفروقات جرد مدينة', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'نقص جردي أو صرف مخزون غير مرتبط ببيع.'],
            ['code' => '5420', 'name' => 'فروقات أسعار مشتريات', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'فرق السعر بين قيمة الاستلام وقيمة فاتورة المورد.'],

            // ── وجبات الموظفين (staff meals) — تنشئها هجرات 2026_05_25_* أيضاً؛
            //    مكرّرة هنا ليبقى الـ seeder مصدراً كاملاً للدليل (updateOrInsert idempotent).
            ['code' => '1110', 'name' => 'مستحقات وجبات الموظفين', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'الجزء المتجاوز فقط من البدل الشهري والمستحق فعلياً على الموظف.'],
            ['code' => '2110', 'name' => 'خصومات الرواتب المستحقة', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'مبالغ خُصمت على الموظفين في إقفال شهري وستُحسم من راتب الشهر التالي.'],
            ['code' => '2150', 'name' => 'أرصدة مقدمة للزبائن', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'مبالغ استلمها المطعم من زبائن ولم تُستخدم في فواتير بعد.'],
            ['code' => '4030', 'name' => 'استرداد تكلفة وجبات الموظفين', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'المبالغ المستحقة على الموظفين عن الجزء الذي تجاوز البدل الشهري فقط.'],
            ['code' => '5060', 'name' => 'تكلفة وجبات ومنافع الموظفين', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'التكلفة الفعلية للمكونات المصروفة في وجبات الموظفين المجانية أو المدعومة.'],

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
