<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

/** Default operating-expense categories used by the accounting mappings. */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'rent',                  'label' => 'إيجار',                'icon' => 'bi-house-door',          'order' => 10],
            ['code' => 'utilities',             'label' => 'كهرباء ومياه وغاز',    'icon' => 'bi-lightning-charge',    'order' => 20],
            ['code' => 'payroll',               'label' => 'رواتب وأجور',          'icon' => 'bi-people',               'order' => 30],
            ['code' => 'maintenance',           'label' => 'صيانة وإصلاحات',       'icon' => 'bi-tools',                'order' => 40],
            ['code' => 'cleaning_packaging',    'label' => 'نظافة وتغليف',         'icon' => 'bi-box-seam',             'order' => 50],
            ['code' => 'telecom',               'label' => 'اتصالات وإنترنت',      'icon' => 'bi-wifi',                 'order' => 60],
            ['code' => 'transport',             'label' => 'نقل وتوصيل',           'icon' => 'bi-truck',                'order' => 70],
            ['code' => 'digital_subscriptions', 'label' => 'اشتراكات وخدمات رقمية', 'icon' => 'bi-cloud-check', 'order' => 80],
            ['code' => 'other_operating',       'label' => 'تشغيلية أخرى',         'icon' => 'bi-three-dots',           'order' => 90],
        ];

        foreach ($rows as $row) {
            Lookup::withTrashed()->updateOrCreate(
                ['group' => 'expense_categories', 'branch_id' => null, 'code' => $row['code']],
                [
                    'label' => $row['label'],
                    'icon' => $row['icon'],
                    'display_order' => $row['order'],
                    'is_active' => true,
                    'is_system' => true,
                    'deleted_at' => null,
                ],
            );
        }
    }
}
