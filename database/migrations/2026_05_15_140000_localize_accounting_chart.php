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

        foreach ($this->arabicAccounts() as $account) {
            DB::table('accounts')
                ->where('code', $account['code'])
                ->update([
                    'name' => $account['name'],
                    'description' => $account['description'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Arabic accounting names are now the canonical chart labels.
    }

    private function arabicAccounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'الصندوق', 'description' => 'النقدية الموجودة في درج الكاشير أو الخزنة.'],
            ['code' => '1010', 'name' => 'البنك', 'description' => 'أرصدة الحسابات البنكية والتحويلات البنكية.'],
            ['code' => '1020', 'name' => 'مقاصة بطاقات الدفع', 'description' => 'مبالغ محصلة بالبطاقات قبل تسويتها في البنك.'],
            ['code' => '1030', 'name' => 'مقاصة المحافظ الإلكترونية', 'description' => 'مبالغ محصلة عبر تطبيقات ومحافظ الدفع قبل التسوية.'],
            ['code' => '1040', 'name' => 'مقاصة البيع الآجل للعملاء', 'description' => 'مبالغ مبيعات آجلة أو إشعارات دائنة تحتاج تسوية.'],
            ['code' => '1100', 'name' => 'الذمم المدينة - العملاء', 'description' => 'مستحقات المطعم على العملاء بعد إصدار الفواتير.'],
            ['code' => '1200', 'name' => 'المخزون', 'description' => 'قيمة المواد والمكونات المتاحة للبيع أو الإنتاج.'],
            ['code' => '1300', 'name' => 'ضريبة القيمة المضافة - مدخلات', 'description' => 'ضريبة مشتريات قابلة للخصم من ضريبة المخرجات.'],
            ['code' => '2000', 'name' => 'الذمم الدائنة - الموردون', 'description' => 'مستحقات الموردين على المطعم.'],
            ['code' => '2100', 'name' => 'ضريبة القيمة المضافة - مخرجات', 'description' => 'ضريبة المبيعات المستحقة للجهات الضريبية.'],
            ['code' => '2200', 'name' => 'أمانات الإكراميات', 'description' => 'إكراميات محصلة لصالح الموظفين ولم تصرف بعد.'],
            ['code' => '3000', 'name' => 'رأس المال وحقوق الملكية', 'description' => 'حقوق مالك المنشأة وصافي الاستثمار.'],
            ['code' => '4000', 'name' => 'إيرادات المبيعات', 'description' => 'إيرادات بيع الأصناف قبل الخصومات والمردودات.'],
            ['code' => '4010', 'name' => 'إيرادات رسوم الخدمة', 'description' => 'رسوم الخدمة المحملة على الفواتير.'],
            ['code' => '4020', 'name' => 'إيرادات التوصيل', 'description' => 'رسوم التوصيل المحملة على الطلبات.'],
            ['code' => '4090', 'name' => 'خصومات ومسموحات المبيعات', 'description' => 'حساب مقابل للإيراد يخفض صافي المبيعات.'],
            ['code' => '4100', 'name' => 'مردودات ومسموحات المبيعات', 'description' => 'استردادات أو مردودات تخفض صافي المبيعات.'],
            ['code' => '5000', 'name' => 'تكلفة البضاعة المباعة', 'description' => 'تكلفة المواد المرتبطة بالأصناف المباعة.'],
            ['code' => '5100', 'name' => 'مصروفات تشغيلية', 'description' => 'مصروفات تشغيل المطعم اليومية غير المرتبطة مباشرة بتكلفة الصنف.'],
            ['code' => '5200', 'name' => 'مصروف ديون معدومة', 'description' => 'مبالغ ذمم مدينة تم شطبها لعدم التحصيل.'],
            ['code' => '5300', 'name' => 'عمولات بنكية وبطاقات', 'description' => 'رسوم بوابات الدفع والبنوك والبطاقات.'],
        ];
    }

};
