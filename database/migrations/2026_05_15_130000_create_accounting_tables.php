<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 191);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'contra_revenue', 'expense']);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('entry_no', 40)->unique();
            $table->date('posted_on');
            $table->string('description');
            $table->string('base_currency_code', 5)->nullable();
            $table->string('currency_code', 5)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('event_type', 80)->nullable();
            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'event_type'], 'journal_source_event_unique');
            $table->index(['branch_id', 'posted_on']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description')->nullable();
            $table->decimal('debit', 14, 4)->default(0);
            $table->decimal('credit', 14, 4)->default(0);
            $table->string('currency_code', 5)->nullable();
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('foreign_debit', 18, 4)->default(0);
            $table->decimal('foreign_credit', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['account_id', 'branch_id']);
            $table->index('currency_code');
            $table->index(['journal_entry_id', 'line_no']);
        });

        DB::table('accounts')->insert($this->defaultAccounts());
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }

    private function defaultAccounts(): array
    {
        $now = now();

        return collect([
            ['1000', 'الصندوق', 'asset', 'debit', 'النقدية الموجودة في درج الكاشير أو الخزنة.'],
            ['1010', 'البنك', 'asset', 'debit', 'أرصدة الحسابات البنكية والتحويلات البنكية.'],
            ['1020', 'مقاصة بطاقات الدفع', 'asset', 'debit', 'مبالغ محصلة بالبطاقات قبل تسويتها في البنك.'],
            ['1030', 'مقاصة المحافظ الإلكترونية', 'asset', 'debit', 'مبالغ محصلة عبر تطبيقات ومحافظ الدفع قبل التسوية.'],
            ['1040', 'مقاصة البيع الآجل للعملاء', 'asset', 'debit', 'مبالغ مبيعات آجلة أو إشعارات دائنة تحتاج تسوية.'],
            ['1100', 'الذمم المدينة - العملاء', 'asset', 'debit', 'مستحقات المطعم على العملاء بعد إصدار الفواتير.'],
            ['1150', 'مخزون تحت التحويل بين الفروع', 'asset', 'debit', 'مخزون خرج من فرع ولم يؤكد الفرع الآخر استلامه بعد.'],
            ['1200', 'المخزون', 'asset', 'debit', 'قيمة المواد والمكونات المتاحة للبيع أو الإنتاج.'],
            ['1300', 'ضريبة القيمة المضافة - مدخلات', 'asset', 'debit', 'ضريبة مشتريات قابلة للخصم من ضريبة المخرجات.'],
            ['2000', 'الذمم الدائنة - الموردون', 'liability', 'credit', 'مستحقات الموردين على المطعم.'],
            ['2100', 'ضريبة القيمة المضافة - مخرجات', 'liability', 'credit', 'ضريبة المبيعات المستحقة للجهات الضريبية.'],
            ['2200', 'أمانات الإكراميات', 'liability', 'credit', 'إكراميات محصلة لصالح الموظفين ولم تصرف بعد.'],
            ['2300', 'استلامات مخزون غير مفوترة', 'liability', 'credit', 'بضاعة تم استلامها في المخزن ولم تصل فاتورة المورد الخاصة بها بعد.'],
            ['3000', 'رأس المال وحقوق الملكية', 'equity', 'credit', 'حقوق مالك المنشأة وصافي الاستثمار.'],
            ['3010', 'أرصدة افتتاحية', 'equity', 'credit', 'رصيد افتتاحي للمخزون أو النقد عند بدء استخدام النظام.'],
            ['3020', 'أرباح محتجزة', 'equity', 'credit', 'صافي أرباح أو خسائر الفترات المقفلة بعد ترحيل حسابات الإيراد والمصاريف.'],
            ['4000', 'إيرادات المبيعات', 'revenue', 'credit', 'إيرادات بيع الأصناف قبل الخصومات والمردودات.'],
            ['4010', 'إيرادات رسوم الخدمة', 'revenue', 'credit', 'رسوم الخدمة المحملة على الفواتير.'],
            ['4020', 'إيرادات التوصيل', 'revenue', 'credit', 'رسوم التوصيل المحملة على الطلبات.'],
            ['4090', 'خصومات ومسموحات المبيعات', 'contra_revenue', 'debit', 'حساب مقابل للإيراد يخفض صافي المبيعات.'],
            ['4100', 'مردودات ومسموحات المبيعات', 'contra_revenue', 'debit', 'استردادات أو مردودات تخفض صافي المبيعات.'],
            ['4200', 'فروقات جرد دائنة', 'revenue', 'credit', 'زيادات جردية أو تسويات مخزون لصالح المنشأة.'],
            ['4210', 'فروقات صندوق دائنة', 'revenue', 'credit', 'فوائض نقدية عند إغلاق الشفت.'],
            ['5000', 'تكلفة البضاعة المباعة', 'expense', 'debit', 'تكلفة المواد المرتبطة بالأصناف المباعة.'],
            ['5100', 'مصروفات تشغيلية', 'expense', 'debit', 'مصروفات تشغيل المطعم اليومية غير المرتبطة مباشرة بتكلفة الصنف.'],
            ['5200', 'مصروف ديون معدومة', 'expense', 'debit', 'مبالغ ذمم مدينة تم شطبها لعدم التحصيل.'],
            ['5300', 'عمولات بنكية وبطاقات', 'expense', 'debit', 'رسوم بوابات الدفع والبنوك والبطاقات.'],
            ['5400', 'هدر وتالف المخزون', 'expense', 'debit', 'تكلفة المواد التالفة أو المهدورة.'],
            ['5410', 'عجز وفروقات جرد مدينة', 'expense', 'debit', 'نقص جردي أو صرف مخزون غير مرتبط ببيع.'],
            ['5420', 'فروقات أسعار مشتريات', 'expense', 'debit', 'فرق السعر بين قيمة الاستلام وقيمة فاتورة المورد.'],
            ['5510', 'عجز صندوق', 'expense', 'debit', 'نقص نقدي عند إغلاق الشفت.'],
        ])->map(fn (array $row) => [
            'code' => $row[0],
            'name' => $row[1],
            'type' => $row[2],
            'normal_balance' => $row[3],
            'description' => $row[4],
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
    }
};
