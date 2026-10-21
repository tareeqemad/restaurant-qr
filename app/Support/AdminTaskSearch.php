<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\SupplierInvoice;

/** Search-only destinations, authorized with the same gates as their controllers. */
class AdminTaskSearch
{
    public static function build(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }
        $items = [];
        $add = static function (string $label, string $route, string $keywords, string $icon = 'bi-arrow-return-left') use (&$items): void {
            $items[] = ['label' => $label, 'href' => route($route), 'keywords' => $keywords,
                'icon' => 'bi '.$icon, 'active' => request()->routeIs($route), 'children' => []];
        };
        if ($user->can('viewAny', Ingredient::class)) {
            foreach ([
                ['ملخص المخزون', 'admin.inventory.dashboard', 'مستودع مخزن رصيد'],
                ['حركة المخزون', 'admin.inventory.index', 'مستودع حركات صرف ادخال'],
                ['المكونات والأرصدة', 'admin.ingredients.index', 'مواد خام مكونات مخزون رصيد'],
                ['مواقع التخزين', 'admin.storage-locations.index', 'مستودعات مخازن رفوف'],
                ['الهدر والتالف', 'admin.waste.index', 'اتلاف تالف خسارة مخزون'],
                ['تحويلات الفروع', 'admin.branch-transfers.index', 'نقل مخزون تحويل مستودع'],
            ] as $item) {
                $add(...$item);
            }
            if (config('restaurant.nav.batch_expiry')) {
                $add('الدفعات والصلاحية', 'admin.batches.index', 'انتهاء صلاحية دفعات مخزون');
            }
            if (config('restaurant.nav.units_management')) {
                $add('وحدات القياس', 'admin.units.index', 'كيلو غرام لتر وحدة مخزون');
            }
        }
        foreach ([
            [PurchaseOrder::class, 'أوامر الشراء', 'admin.purchase-orders.index', 'شراء طلب توريد مشتريات'],
            [SupplierInvoice::class, 'فواتير الموردين', 'admin.supplier-invoices.index', 'فاتورة مورد مشتريات دين'],
            [Supplier::class, 'الموردون', 'admin.suppliers.index', 'مورد موردين شركة توريد'],
            [StockCount::class, 'الجرد الفعلي', 'admin.stock-counts.index', 'جرد عد مخزون فرق'],
        ] as [$model, $label, $route, $keywords]) {
            if ($user->can('viewAny', $model)) {
                $add($label, $route, $keywords);
            }
        }
        if ($user->can('viewAny', Supplier::class) && config('restaurant.nav.vendor_price_compare')) {
            $add('مقارنة أسعار الموردين', 'admin.vendor-prices.compare', 'اسعار مورد ارخص تكلفة');
        }
        if ($user->hasPermission('reports.viewAny')) {
            $add('اقتراحات التوريد', 'admin.reports.reorder-suggestions', 'نواقص اعادة طلب شراء مخزون');
            $add('الأرباح والخسائر', 'admin.reports.profit-loss', 'ربح خسارة دخل محاسبة');
            if ($user->hasPermission('inventory.viewAny')) {
                $add('تقييم المخزون', 'admin.reports.stock-valuation', 'قيمة تكلفة مخزون');
            }
        }
        if ($user->can('viewAny', MenuItem::class)) {
            foreach ([
                ['أصناف المنيو', 'admin.menu-items.index', 'منيو قائمة طعام صنف وجبة اسعار'],
                ['تصنيفات المنيو', 'admin.categories.index', 'اقسام قائمة فئات تصنيف'],
                ['خيارات الأصناف', 'admin.modifiers.index', 'اضافات تعديل اختيار حجم منيو'],
                ['مسببات الحساسية', 'admin.allergens.index', 'حساسية تحسس منيو'],
            ] as $item) {
                $add(...$item);
            }
        }
        if ($user->hasPermission('promotions.viewAny')) {
            $add('العروض والتخفيضات', 'admin.promotions.index', 'خصم عرض كوبون منيو');
        }
        if ($user->hasPermission('chart_of_accounts.viewAny')) {
            $labels = [
                'journal' => ['دفتر اليومية', 'قيد قيود يومية محاسبة'],
                'ledger' => ['دفتر الأستاذ', 'استاذ كشف حساب حركة محاسبة'],
                'trialBalance' => ['ميزان المراجعة', 'ميزان تدقيق محاسبة'],
                'balanceSheet' => ['الميزانية العمومية', 'ميزانية مركز مالي اصول التزامات'],
                'aging' => ['أعمار الديون', 'دين ديون ذمم متأخرات'],
                'taxReport' => ['تقرير الضريبة', 'ضريبة ضرائب محاسبة'],
                'accounts' => ['دليل الحسابات', 'شجرة حساب محاسبة'],
                'openingBalances' => ['الأرصدة الافتتاحية', 'رصيد افتتاحي بداية محاسبة'],
                'manualEntry' => ['قيد محاسبي يدوي', 'اضافة قيد تسجيل محاسبة'],
                'fiscalYears' => ['السنوات المالية', 'سنة مالية اقفال'],
                'periods' => ['الفترات المحاسبية', 'فترة شهر اقفال محاسبة'],
                'mappings' => ['قواعد الترحيل', 'ربط حسابات ربط الحسابات تعيين خرائط محاسبة'],
                'reconciliations' => ['المطابقة البنكية', 'تسوية بنك كشف حساب مطابقة'],
                'settlements' => ['التسويات المالية', 'تسوية محفظة ضريبة اكراميات'],
                'fixedAssets' => ['الأصول الثابتة', 'اصل اهلاك استهلاك معدات'],
            ];
            foreach (AccountingWorkspace::urls() as $key => $href) {
                if ($href && isset($labels[$key])) {
                    $items[] = ['label' => $labels[$key][0], 'keywords' => $labels[$key][1], 'href' => $href,
                        'icon' => 'bi-journal-text', 'active' => false, 'children' => []];
                }
            }
        }

        return $items;
    }
}
