<?php

namespace Database\Seeders;

use App\Models\LookupGroup;
use Illuminate\Database\Seeder;

class LookupGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['code' => 'expense_categories', 'label' => 'تصنيفات المصروفات', 'icon' => 'bi-cash-coin', 'subtitle' => 'تظهر عند تسجيل المصروفات وتربط كل تصنيف بحسابه المحاسبي.', 'scope' => LookupGroup::GLOBAL, 'display_order' => 10],
            ['code' => 'zones', 'label' => 'مناطق الطاولات', 'icon' => 'bi-geo-alt-fill', 'subtitle' => 'مناطق الصالة المشتركة مثل داخلي وخارجي وVIP، وتستخدمها الطاولات وتوزيع الجرسون.', 'scope' => LookupGroup::GLOBAL, 'display_order' => 20],
            ['code' => 'discount_categories', 'label' => 'تصنيفات الخصومات', 'icon' => 'bi-percent', 'subtitle' => 'تظهر للكاشير عند توثيق سبب الخصم وتغذي تقارير الخصومات.', 'scope' => LookupGroup::GLOBAL, 'display_order' => 30],
            ['code' => 'waste_reasons', 'label' => 'أسباب الهدر', 'icon' => 'bi-trash3-fill', 'subtitle' => 'تظهر عند تسجيل هدر المخزون وتغذي تحليل الفقد وتقاريره.', 'scope' => LookupGroup::GLOBAL, 'display_order' => 40],
        ];

        foreach ($groups as $attributes) {
            $group = LookupGroup::withTrashed()->updateOrCreate(
                ['code' => $attributes['code']],
                [...$attributes, 'is_active' => true, 'is_system' => true],
            );

            if ($group->trashed()) {
                $group->restore();
            }
        }
    }
}
