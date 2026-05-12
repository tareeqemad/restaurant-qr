<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

/**
 * Default discount categories — referenced by `order_discounts.category_lookup_id`.
 * Global (no branch_id) so the same dictionary applies to every branch.
 *
 * `is_system = true` so the lookups admin UI doesn't allow hard-deletion;
 * an admin can still rename or deactivate any row, and add new ones.
 */
class DiscountCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'regular_customer', 'label' => 'عميل دائم / مكافأة', 'color' => '#14532d', 'icon' => 'bi-heart-fill',          'order' => 1],
            ['code' => 'complaint',        'label' => 'تعويض شكوى',         'color' => '#b91c1c', 'icon' => 'bi-emoji-frown',         'order' => 2],
            ['code' => 'manager_approval', 'label' => 'بموافقة المدير',     'color' => '#1d4ed8', 'icon' => 'bi-person-check-fill',   'order' => 3],
            ['code' => 'promo',            'label' => 'حملة ترويجية',        'color' => '#b45309', 'icon' => 'bi-megaphone-fill',      'order' => 4],
            ['code' => 'staff_meal',       'label' => 'وجبة موظف',           'color' => '#0f766e', 'icon' => 'bi-people-fill',         'order' => 5],
            ['code' => 'other',            'label' => 'أخرى',                'color' => '#475569', 'icon' => 'bi-three-dots',          'order' => 9],
        ];

        foreach ($rows as $r) {
            Lookup::updateOrCreate(
                ['group' => 'discount_categories', 'branch_id' => null, 'code' => $r['code']],
                [
                    'label'         => $r['label'],
                    'color'         => $r['color'],
                    'icon'          => $r['icon'],
                    'display_order' => $r['order'],
                    'is_active'     => true,
                    'is_system'     => true,
                ]
            );
        }
    }
}
