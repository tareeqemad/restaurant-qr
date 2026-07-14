<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Support\BranchContext;
use Illuminate\Database\Seeder;

/**
 * Adds the "بطاقات نت" (Internet Cards) category + three duration-based
 * cards (1h / 2h / 3h) to EVERY branch.
 *
 * Why per-branch: each branch sells its own cards (its own till, its own
 * stock count), so the category lives under each branch's menu rather
 * than as a shared global resource. The price ladder is the same across
 * branches by default — owner can edit per-branch from the admin UI.
 *
 * No `station_id` and `prep = 0`: cards need no preparation. OrderService
 * auto-readies zero-prep stationless items at approval, so they never
 * appear on any KDS and never block an order from reaching «جاهز».
 *
 * No recipe: zero ingredients consumed (the card itself is just a coupon
 * code). `cost = 0` so profit reports treat the full price as margin.
 *
 * Idempotent — re-running this seeder does not duplicate. Lookups go
 * through the BranchScope-aware `slug` so the same slug can coexist
 * across branches.
 */
class InternetCardsSeeder extends Seeder
{
    public function run(): void
    {
        // Default ladder. Slight discount for longer durations so customers
        // see an incentive to upgrade. Owner can override per-branch later.
        $cards = [
            ['hours' => 1, 'sku' => 'NET-1H', 'name' => 'بطاقة نت — ساعة',     'name_en' => 'Internet Card — 1 Hour',  'price' => 5.00],
            ['hours' => 2, 'sku' => 'NET-2H', 'name' => 'بطاقة نت — ساعتين',  'name_en' => 'Internet Card — 2 Hours', 'price' => 9.00],
            ['hours' => 3, 'sku' => 'NET-3H', 'name' => 'بطاقة نت — 3 ساعات',  'name_en' => 'Internet Card — 3 Hours', 'price' => 12.00],
        ];

        foreach (Branch::all() as $branch) {
            BranchContext::forBranch($branch->id, function () use ($branch, $cards) {
                $category = Category::updateOrCreate(
                    ['slug' => 'internet-cards'],
                    [
                        'name'           => 'بطاقات نت',
                        'name_en'        => 'Internet Cards',
                        'description'    => 'بطاقات استخدام واي فاي بأوقات مختلفة',
                        'icon'           => 'bi-wifi',
                        'color'          => '#0ea5e9',
                        // Pushed to the end so food + drinks stay on top of
                        // the customer menu — Wi-Fi is an add-on, not a draw.
                        'display_order'  => 100,
                        'active'         => true,
                    ]
                );

                foreach ($cards as $idx => $c) {
                    MenuItem::updateOrCreate(
                        ['slug' => 'net-card-'.$c['hours'].'h'],
                        [
                            'category_id'        => $category->id,
                            'station_id'         => null,    // sold at the till, not cooked
                            'sku'                => $c['sku'],
                            'name'               => $c['name'],
                            'name_en'            => $c['name_en'],
                            'description'        => $c['hours'].' ساعة استخدام واي فاي بسرعة عالية',
                            'price'              => $c['price'],
                            'cost'               => 0,
                            'prep_time_minutes'  => 0,
                            'is_available'       => true,
                            'is_featured'        => false,
                            'display_order'      => $idx + 1,
                        ]
                    );
                }
            });
        }
    }
}
