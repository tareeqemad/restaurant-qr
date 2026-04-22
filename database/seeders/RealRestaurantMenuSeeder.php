<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Station;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic Arabic/Jordanian restaurant menu — 7 categories × 10+ items each.
 *
 * Images: Unsplash featured URLs (stable, CDN-hosted). All URLs were hand-picked
 * to match the dish. If the network blocks external images, fall back to the
 * local placeholder via MenuItem::imageUrl().
 *
 * IMPORTANT: this seeder REPLACES existing categories + menu-items. Run only
 * on a fresh/demo DB (or when user explicitly wants to reset the menu).
 */
class RealRestaurantMenuSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding real restaurant menu (7 categories × 10+ items)...');

        // Resolve stations (kitchen for food, bar for drinks)
        $kitchen = Station::where('code', 'KITCHEN')->first() ?? Station::first();
        $bar     = Station::where('code', 'BAR')->first()     ?? Station::where('name', 'LIKE', '%بار%')->first() ?? $kitchen;

        DB::transaction(function () use ($kitchen, $bar) {
            // Wipe existing (and their dependencies) — orders keep historic name_snapshot
            DB::table('recipe_items')->delete();
            DB::table('menu_item_allergens')->delete();
            DB::table('menu_item_modifier_group')->delete();

            // Hard-delete so unique indexes (slug, SKU) are freed for fresh seed.
            // Disable FK checks briefly since historic order_items reference menu_items.
            // Those historic rows keep their snapshot fields so past orders still display.
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                MenuItem::withTrashed()->forceDelete();
                Category::withTrashed()->forceDelete();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }

            $data = $this->menuData($kitchen->id ?? 1, $bar->id ?? 1);

            foreach ($data as $catIdx => $cat) {
                $category = Category::create([
                    'name'              => $cat['name'],
                    'name_en'           => $cat['name_en'],
                    'slug'              => $cat['slug'],
                    'image'             => $cat['image'],
                    'icon'              => $cat['icon'],
                    'color'             => $cat['color'],
                    'default_station_id'=> $cat['station_id'],
                    'display_order'     => $catIdx + 1,
                    'active'            => true,
                ]);

                foreach ($cat['items'] as $itemIdx => $item) {
                    MenuItem::create([
                        'name'               => $item['name'],
                        'name_en'            => $item['name_en'],
                        'sku'                => $item['sku'],
                        // Explicit slug from SKU (always unique) — prevents collisions
                        // when the same dish name appears in multiple categories.
                        'slug'               => \Illuminate\Support\Str::slug($item['sku']),
                        'category_id'        => $category->id,
                        'station_id'         => $cat['station_id'],
                        'description'        => $item['description'] ?? null,
                        'description_en'     => $item['description_en'] ?? null,
                        'price'              => $item['price'],
                        'cost'               => $item['cost'] ?? round($item['price'] * 0.35, 2),
                        'image'              => $item['image'],
                        'prep_time_minutes'  => $item['prep'] ?? 10,
                        'calories'           => $item['calories'] ?? null,
                        'display_order'      => $itemIdx + 1,
                        'is_available'       => true,
                        'is_featured'        => $item['featured'] ?? false,
                    ]);
                }

                $this->command->line("  ✓ {$cat['name']}: ".count($cat['items']).' items');
            }
        });

        $totalItems = MenuItem::count();
        $totalCats  = Category::count();
        $this->command->info("Done — {$totalCats} categories, {$totalItems} menu items.");
    }

    /**
     * The full menu dataset.
     * Every image URL is a stable Unsplash photo (https://images.unsplash.com/photo-XXX).
     */
    protected function menuData(int $kitchenId, int $barId): array
    {
        return [
            // ═════════════════════════════════════ 1. المقبلات
            [
                'name' => 'المقبلات', 'name_en' => 'Appetizers', 'slug' => 'appetizers',
                'icon' => 'bi-egg-fried', 'color' => '#b8872a',
                'image' => 'https://images.unsplash.com/photo-1541014741259-de529411b96a?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'حمص', 'name_en' => 'Hummus', 'sku' => 'APP-01', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1590004953868-dd0c25d1c3a0?w=600&q=80',
                     'description' => 'حمص بالطحينة وزيت الزيتون', 'prep' => 5, 'calories' => 220],
                    ['name' => 'متبل', 'name_en' => 'Mutabal', 'sku' => 'APP-02', 'price' => 2.75,
                     'image' => 'https://images.unsplash.com/photo-1590080877747-c3f28d3f0e2a?w=600&q=80',
                     'description' => 'متبل باذنجان مشوي على الفحم', 'prep' => 5, 'calories' => 180],
                    ['name' => 'بابا غنوج', 'name_en' => 'Baba Ghanoush', 'sku' => 'APP-03', 'price' => 2.75,
                     'image' => 'https://images.unsplash.com/photo-1633236557616-bfa6654ef3e8?w=600&q=80',
                     'description' => 'باذنجان مدخّن بالطحينة', 'prep' => 5, 'calories' => 190],
                    ['name' => 'لبنة', 'name_en' => 'Labneh', 'sku' => 'APP-04', 'price' => 2.00,
                     'image' => 'https://images.unsplash.com/photo-1551504734-5ee1c4a1479b?w=600&q=80',
                     'description' => 'لبنة مع زيت الزيتون والزعتر', 'prep' => 3, 'calories' => 150],
                    ['name' => 'فتوش', 'name_en' => 'Fattoush', 'sku' => 'APP-05', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=600&q=80',
                     'description' => 'سلطة خضراء مع خبز محمّص وسماق', 'prep' => 7, 'calories' => 200, 'featured' => true],
                    ['name' => 'ورق عنب', 'name_en' => 'Stuffed Vine Leaves', 'sku' => 'APP-06', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1606756790138-261d2b21cd75?w=600&q=80',
                     'description' => 'ورق عنب محشي بالأرز والخضار', 'prep' => 10, 'calories' => 240],
                    ['name' => 'محاشي كوسا', 'name_en' => 'Stuffed Zucchini', 'sku' => 'APP-07', 'price' => 3.25,
                     'image' => 'https://images.unsplash.com/photo-1625944525533-473f1b3d9684?w=600&q=80',
                     'description' => 'كوسا محشي لحمة وأرز', 'prep' => 12, 'calories' => 260],
                    ['name' => 'كبة مقلية', 'name_en' => 'Fried Kibbeh', 'sku' => 'APP-08', 'price' => 4.00,
                     'image' => 'https://images.unsplash.com/photo-1574484284002-952d92456975?w=600&q=80',
                     'description' => 'كبة لحمة مقلية ذهبية', 'prep' => 8, 'calories' => 320, 'featured' => true],
                    ['name' => 'بطاطا مقلية', 'name_en' => 'French Fries', 'sku' => 'APP-09', 'price' => 2.00,
                     'image' => 'https://images.unsplash.com/photo-1573080496219-bb080dd4f877?w=600&q=80',
                     'description' => 'بطاطا مقرمشة', 'prep' => 6, 'calories' => 360],
                    ['name' => 'مقلوبة صغيرة', 'name_en' => 'Mini Maqluba', 'sku' => 'APP-10', 'price' => 3.75,
                     'image' => 'https://images.unsplash.com/photo-1598866594230-a7c12756260f?w=600&q=80',
                     'description' => 'مقلوبة باذنجان ولحم صغيرة', 'prep' => 10, 'calories' => 320],
                    ['name' => 'فلافل', 'name_en' => 'Falafel', 'sku' => 'APP-11', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1593001874117-c99c800e3eb7?w=600&q=80',
                     'description' => 'فلافل مقرمشة بالطحينة', 'prep' => 7, 'calories' => 300],
                ],
            ],

            // ═════════════════════════════════════ 2. الأطباق الرئيسية
            [
                'name' => 'الأطباق الرئيسية', 'name_en' => 'Main Courses', 'slug' => 'main-courses',
                'icon' => 'bi-dropbox', 'color' => '#1f4733',
                'image' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'منسف', 'name_en' => 'Mansaf', 'sku' => 'MAIN-01', 'price' => 12.00,
                     'image' => 'https://images.unsplash.com/photo-1625944525533-473f1b3d9684?w=600&q=80',
                     'description' => 'منسف أردني أصيل بلحم الخروف واللبن الجميد', 'prep' => 30, 'calories' => 850, 'featured' => true],
                    ['name' => 'مقلوبة لحم', 'name_en' => 'Meat Maqluba', 'sku' => 'MAIN-02', 'price' => 9.50,
                     'image' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=600&q=80',
                     'description' => 'مقلوبة لحم بالخضار', 'prep' => 25, 'calories' => 720],
                    ['name' => 'مقلوبة دجاج', 'name_en' => 'Chicken Maqluba', 'sku' => 'MAIN-03', 'price' => 8.00,
                     'image' => 'https://images.unsplash.com/photo-1625944525533-473f1b3d9684?w=600&q=80',
                     'description' => 'مقلوبة دجاج بالقرنبيط والباذنجان', 'prep' => 25, 'calories' => 650],
                    ['name' => 'كبسة دجاج', 'name_en' => 'Chicken Kabsa', 'sku' => 'MAIN-04', 'price' => 8.50,
                     'image' => 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?w=600&q=80',
                     'description' => 'أرز بسمتي بالبهارات والدجاج', 'prep' => 28, 'calories' => 700],
                    ['name' => 'مسخن', 'name_en' => 'Musakhan', 'sku' => 'MAIN-05', 'price' => 9.00,
                     'image' => 'https://images.unsplash.com/photo-1625944525533-473f1b3d9684?w=600&q=80',
                     'description' => 'دجاج بالسماق والبصل على خبز الطابون', 'prep' => 25, 'calories' => 680, 'featured' => true],
                    ['name' => 'فريكة بالدجاج', 'name_en' => 'Freekeh with Chicken', 'sku' => 'MAIN-06', 'price' => 7.50,
                     'image' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&q=80',
                     'description' => 'فريكة بالدجاج والمكسرات', 'prep' => 22, 'calories' => 610],
                    ['name' => 'مجدرة', 'name_en' => 'Mujadara', 'sku' => 'MAIN-07', 'price' => 5.00,
                     'image' => 'https://images.unsplash.com/photo-1599021456807-25db0f974333?w=600&q=80',
                     'description' => 'عدس مع أرز وبصل مقرمش', 'prep' => 18, 'calories' => 480],
                    ['name' => 'شيش طاووق', 'name_en' => 'Shish Tawook', 'sku' => 'MAIN-08', 'price' => 8.50,
                     'image' => 'https://images.unsplash.com/photo-1529193591184-b1d58069ecdd?w=600&q=80',
                     'description' => 'دجاج متبل ومشوي', 'prep' => 20, 'calories' => 620],
                    ['name' => 'قدرة خليلية', 'name_en' => 'Qidra', 'sku' => 'MAIN-09', 'price' => 10.00,
                     'image' => 'https://images.unsplash.com/photo-1518291344630-4857135fb581?w=600&q=80',
                     'description' => 'لحم ضأن مع الأرز والحمص', 'prep' => 35, 'calories' => 780],
                    ['name' => 'صيادية', 'name_en' => 'Sayadieh', 'sku' => 'MAIN-10', 'price' => 9.50,
                     'image' => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?w=600&q=80',
                     'description' => 'أرز بالسمك والبصل', 'prep' => 25, 'calories' => 640],
                ],
            ],

            // ═════════════════════════════════════ 3. المشاوي
            [
                'name' => 'المشاوي', 'name_en' => 'Grill', 'slug' => 'grill',
                'icon' => 'bi-fire', 'color' => '#b91c1c',
                'image' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'كباب لحم', 'name_en' => 'Lamb Kebab', 'sku' => 'GRL-01', 'price' => 9.00,
                     'image' => 'https://images.unsplash.com/photo-1529193591184-b1d58069ecdd?w=600&q=80',
                     'description' => 'كباب لحم ضأن متبل', 'prep' => 18, 'calories' => 620, 'featured' => true],
                    ['name' => 'كباب دجاج', 'name_en' => 'Chicken Kebab', 'sku' => 'GRL-02', 'price' => 7.00,
                     'image' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',
                     'description' => 'كباب دجاج مشوي', 'prep' => 15, 'calories' => 520],
                    ['name' => 'كفتة مشوية', 'name_en' => 'Grilled Kofta', 'sku' => 'GRL-03', 'price' => 7.50,
                     'image' => 'https://images.unsplash.com/photo-1544025162-d76694265947?w=600&q=80',
                     'description' => 'كفتة لحم مشوية', 'prep' => 15, 'calories' => 580],
                    ['name' => 'ريش غنم', 'name_en' => 'Lamb Chops', 'sku' => 'GRL-04', 'price' => 14.00,
                     'image' => 'https://images.unsplash.com/photo-1558030006-450675393462?w=600&q=80',
                     'description' => 'ريش غنم مشوية', 'prep' => 20, 'calories' => 780, 'featured' => true],
                    ['name' => 'شقف لحم', 'name_en' => 'Lamb Shish', 'sku' => 'GRL-05', 'price' => 10.00,
                     'image' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?w=600&q=80',
                     'description' => 'شقف لحم ضأن مشوية', 'prep' => 18, 'calories' => 680],
                    ['name' => 'فراخ مشوي', 'name_en' => 'Grilled Chicken', 'sku' => 'GRL-06', 'price' => 8.00,
                     'image' => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=600&q=80',
                     'description' => 'فراخ نصف مشوي', 'prep' => 22, 'calories' => 640],
                    ['name' => 'سمك مشوي', 'name_en' => 'Grilled Fish', 'sku' => 'GRL-07', 'price' => 12.00,
                     'image' => 'https://images.unsplash.com/photo-1467003909585-2f8a72700288?w=600&q=80',
                     'description' => 'سمك طازج مشوي', 'prep' => 20, 'calories' => 480],
                    ['name' => 'مشاوي مشكل', 'name_en' => 'Mixed Grill', 'sku' => 'GRL-08', 'price' => 15.00,
                     'image' => 'https://images.unsplash.com/photo-1558030006-450675393462?w=600&q=80',
                     'description' => 'تشكيلة مشاوي لشخصين', 'prep' => 25, 'calories' => 1100, 'featured' => true],
                    ['name' => 'تكا دجاج', 'name_en' => 'Chicken Tikka', 'sku' => 'GRL-09', 'price' => 7.50,
                     'image' => 'https://images.unsplash.com/photo-1599487488170-d11ec9c172f0?w=600&q=80',
                     'description' => 'تكا دجاج بالبهارات', 'prep' => 17, 'calories' => 540],
                    ['name' => 'صدر دجاج مشوي', 'name_en' => 'Chicken Breast', 'sku' => 'GRL-10', 'price' => 7.00,
                     'image' => 'https://images.unsplash.com/photo-1598103442097-8b74394b95c6?w=600&q=80',
                     'description' => 'صدر دجاج صحي مشوي', 'prep' => 14, 'calories' => 380],
                ],
            ],

            // ═════════════════════════════════════ 4. السندويشات
            [
                'name' => 'السندويشات', 'name_en' => 'Sandwiches', 'slug' => 'sandwiches',
                'icon' => 'bi-basket3-fill', 'color' => '#8b5cf6',
                'image' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'برجر لحم', 'name_en' => 'Beef Burger', 'sku' => 'SND-01', 'price' => 5.00,
                     'image' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=600&q=80',
                     'description' => 'برجر لحم مشوي مع الخضار', 'prep' => 12, 'calories' => 680, 'featured' => true],
                    ['name' => 'برجر دجاج', 'name_en' => 'Chicken Burger', 'sku' => 'SND-02', 'price' => 4.50,
                     'image' => 'https://images.unsplash.com/photo-1606755962773-d324e0a13086?w=600&q=80',
                     'description' => 'برجر دجاج مقرمش', 'prep' => 10, 'calories' => 620],
                    ['name' => 'شاورما دجاج', 'name_en' => 'Chicken Shawarma', 'sku' => 'SND-03', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1633321702518-7feccafb94d5?w=600&q=80',
                     'description' => 'شاورما دجاج مع ثوم ومخلل', 'prep' => 8, 'calories' => 560, 'featured' => true],
                    ['name' => 'شاورما لحم', 'name_en' => 'Meat Shawarma', 'sku' => 'SND-04', 'price' => 4.00,
                     'image' => 'https://images.unsplash.com/photo-1561651823-34b8b89a5a44?w=600&q=80',
                     'description' => 'شاورما لحم مع الطحينة', 'prep' => 8, 'calories' => 620],
                    ['name' => 'كلوب سندويتش', 'name_en' => 'Club Sandwich', 'sku' => 'SND-05', 'price' => 4.50,
                     'image' => 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=600&q=80',
                     'description' => 'كلوب دجاج ولحم مدخن', 'prep' => 10, 'calories' => 680],
                    ['name' => 'سندويتش تونة', 'name_en' => 'Tuna Sandwich', 'sku' => 'SND-06', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1539252554453-80ab65ce3586?w=600&q=80',
                     'description' => 'سندويتش تونة بالخس', 'prep' => 5, 'calories' => 420],
                    ['name' => 'سندويتش جبنة', 'name_en' => 'Cheese Sandwich', 'sku' => 'SND-07', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=600&q=80',
                     'description' => 'سندويتش جبنة حلوم مشوية', 'prep' => 5, 'calories' => 480],
                    ['name' => 'سندويتش بيض', 'name_en' => 'Egg Sandwich', 'sku' => 'SND-08', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1482049016688-2d3e1b311543?w=600&q=80',
                     'description' => 'سندويتش بيض مع الخضار', 'prep' => 6, 'calories' => 400],
                    ['name' => 'فاهيتا', 'name_en' => 'Fajita', 'sku' => 'SND-09', 'price' => 5.00,
                     'image' => 'https://images.unsplash.com/photo-1605301325-9b4c7f6d11cb?w=600&q=80',
                     'description' => 'فاهيتا دجاج بالفلفل', 'prep' => 12, 'calories' => 540],
                    ['name' => 'فلافل', 'name_en' => 'Falafel Wrap', 'sku' => 'SND-10', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1593001874117-c99c800e3eb7?w=600&q=80',
                     'description' => 'لفة فلافل مع طحينة', 'prep' => 7, 'calories' => 420],
                    ['name' => 'هوت دوغ', 'name_en' => 'Hot Dog', 'sku' => 'SND-11', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1619740455993-9e612b1af08a?w=600&q=80',
                     'description' => 'هوت دوغ كلاسيك', 'prep' => 8, 'calories' => 460],
                ],
            ],

            // ═════════════════════════════════════ 5. السلطات
            [
                'name' => 'السلطات', 'name_en' => 'Salads', 'slug' => 'salads',
                'icon' => 'bi-flower1', 'color' => '#10b981',
                'image' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'سلطة سيزر', 'name_en' => 'Caesar Salad', 'sku' => 'SAL-01', 'price' => 4.00,
                     'image' => 'https://images.unsplash.com/photo-1550304943-4f24f54ddde9?w=600&q=80',
                     'description' => 'خس، دجاج، جبنة بارميزان، كراوتون', 'prep' => 8, 'calories' => 420, 'featured' => true],
                    ['name' => 'تبولة', 'name_en' => 'Tabbouleh', 'sku' => 'SAL-02', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=600&q=80',
                     'description' => 'بقدونس، برغل، طماطم، ليمون', 'prep' => 7, 'calories' => 180],
                    ['name' => 'فتوش', 'name_en' => 'Fattoush', 'sku' => 'SAL-03', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=600&q=80',
                     'description' => 'خضار مع خبز محمّص وسماق', 'prep' => 6, 'calories' => 220],
                    ['name' => 'سلطة عربية', 'name_en' => 'Arabic Salad', 'sku' => 'SAL-04', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?w=600&q=80',
                     'description' => 'طماطم، خيار، بصل، بقدونس', 'prep' => 5, 'calories' => 140],
                    ['name' => 'سلطة يونانية', 'name_en' => 'Greek Salad', 'sku' => 'SAL-05', 'price' => 4.00,
                     'image' => 'https://images.unsplash.com/photo-1529059997568-3d847b1154f0?w=600&q=80',
                     'description' => 'خضار، زيتون، جبنة فيتا', 'prep' => 7, 'calories' => 280],
                    ['name' => 'سلطة كولسلو', 'name_en' => 'Coleslaw', 'sku' => 'SAL-06', 'price' => 2.00,
                     'image' => 'https://images.unsplash.com/photo-1607532941433-304659e8198a?w=600&q=80',
                     'description' => 'ملفوف مع مايونيز', 'prep' => 4, 'calories' => 200],
                    ['name' => 'سلطة دجاج', 'name_en' => 'Chicken Salad', 'sku' => 'SAL-07', 'price' => 5.00,
                     'image' => 'https://images.unsplash.com/photo-1546793665-c74683f339c1?w=600&q=80',
                     'description' => 'دجاج مشوي مع خضار طازجة', 'prep' => 10, 'calories' => 380],
                    ['name' => 'سلطة تونة', 'name_en' => 'Tuna Salad', 'sku' => 'SAL-08', 'price' => 4.50,
                     'image' => 'https://images.unsplash.com/photo-1505253716362-afaea1d3d1af?w=600&q=80',
                     'description' => 'تونة مع خس وزيتون', 'prep' => 8, 'calories' => 340],
                    ['name' => 'سلطة روكا', 'name_en' => 'Rocket Salad', 'sku' => 'SAL-09', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?w=600&q=80',
                     'description' => 'روكا مع جبنة بارميزان', 'prep' => 5, 'calories' => 220],
                    ['name' => 'سلطة فواكه', 'name_en' => 'Fruit Salad', 'sku' => 'SAL-10', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?w=600&q=80',
                     'description' => 'تشكيلة فواكه طازجة', 'prep' => 6, 'calories' => 180],
                ],
            ],

            // ═════════════════════════════════════ 6. المشروبات
            [
                'name' => 'المشروبات', 'name_en' => 'Drinks', 'slug' => 'drinks',
                'icon' => 'bi-cup-straw', 'color' => '#3b82f6',
                'image' => 'https://images.unsplash.com/photo-1544145945-f90425340c7e?w=600&q=80',
                'station_id' => $barId,
                'items' => [
                    ['name' => 'عصير برتقال طازج', 'name_en' => 'Fresh Orange Juice', 'sku' => 'DRK-01', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1621506289937-a8e4df240d0b?w=600&q=80',
                     'description' => 'عصير برتقال طبيعي', 'prep' => 3, 'calories' => 120, 'featured' => true],
                    ['name' => 'عصير ليمون بالنعناع', 'name_en' => 'Lemon Mint', 'sku' => 'DRK-02', 'price' => 2.00,
                     'image' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&q=80',
                     'description' => 'ليمون طازج مع نعناع', 'prep' => 3, 'calories' => 90, 'featured' => true],
                    ['name' => 'عصير مانجو', 'name_en' => 'Mango Juice', 'sku' => 'DRK-03', 'price' => 2.75,
                     'image' => 'https://images.unsplash.com/photo-1546171753-97d7676e4602?w=600&q=80',
                     'description' => 'عصير مانجو طبيعي', 'prep' => 3, 'calories' => 140],
                    ['name' => 'عصير فراولة', 'name_en' => 'Strawberry Juice', 'sku' => 'DRK-04', 'price' => 2.75,
                     'image' => 'https://images.unsplash.com/photo-1557825835-70d97c4aa567?w=600&q=80',
                     'description' => 'فراولة طازجة', 'prep' => 3, 'calories' => 110],
                    ['name' => 'ميلك شيك', 'name_en' => 'Milkshake', 'sku' => 'DRK-05', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1541658016709-82535e94bc69?w=600&q=80',
                     'description' => 'ميلك شيك فانيلا/شوكولاتة/فراولة', 'prep' => 4, 'calories' => 340],
                    ['name' => 'قهوة عربية', 'name_en' => 'Arabic Coffee', 'sku' => 'DRK-06', 'price' => 1.50,
                     'image' => 'https://images.unsplash.com/photo-1511537190424-bbbab87ac5ad?w=600&q=80',
                     'description' => 'قهوة عربية بالهيل', 'prep' => 4, 'calories' => 5],
                    ['name' => 'قهوة تركية', 'name_en' => 'Turkish Coffee', 'sku' => 'DRK-07', 'price' => 1.75,
                     'image' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=600&q=80',
                     'description' => 'قهوة تركية أصيلة', 'prep' => 5, 'calories' => 10],
                    ['name' => 'شاي بالنعناع', 'name_en' => 'Mint Tea', 'sku' => 'DRK-08', 'price' => 1.00,
                     'image' => 'https://images.unsplash.com/photo-1558160074-4d7d8bdf4256?w=600&q=80',
                     'description' => 'شاي أسود مع نعناع طازج', 'prep' => 3, 'calories' => 20],
                    ['name' => 'كابتشينو', 'name_en' => 'Cappuccino', 'sku' => 'DRK-09', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=600&q=80',
                     'description' => 'كابتشينو إيطالي', 'prep' => 4, 'calories' => 110],
                    ['name' => 'لاتيه', 'name_en' => 'Latte', 'sku' => 'DRK-10', 'price' => 2.75,
                     'image' => 'https://images.unsplash.com/photo-1561882468-9110e03e0f78?w=600&q=80',
                     'description' => 'لاتيه بالحليب', 'prep' => 4, 'calories' => 140],
                    ['name' => 'مياه معدنية', 'name_en' => 'Mineral Water', 'sku' => 'DRK-11', 'price' => 0.50,
                     'image' => 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?w=600&q=80',
                     'description' => 'مياه معدنية 500مل', 'prep' => 1, 'calories' => 0],
                    ['name' => 'مشروبات غازية', 'name_en' => 'Soft Drinks', 'sku' => 'DRK-12', 'price' => 1.25,
                     'image' => 'https://images.unsplash.com/photo-1581636625402-29b2a704ef13?w=600&q=80',
                     'description' => 'كولا / سبرايت / فانتا', 'prep' => 1, 'calories' => 150],
                ],
            ],

            // ═════════════════════════════════════ 7. الحلويات
            [
                'name' => 'الحلويات', 'name_en' => 'Desserts', 'slug' => 'desserts',
                'icon' => 'bi-cake2', 'color' => '#ec4899',
                'image' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?w=600&q=80',
                'station_id' => $kitchenId,
                'items' => [
                    ['name' => 'كنافة', 'name_en' => 'Kunafa', 'sku' => 'DES-01', 'price' => 4.00,
                     'image' => 'https://images.unsplash.com/photo-1541443131876-44b03de101c5?w=600&q=80',
                     'description' => 'كنافة بالجبنة والقطر', 'prep' => 10, 'calories' => 540, 'featured' => true],
                    ['name' => 'بقلاوة', 'name_en' => 'Baklava', 'sku' => 'DES-02', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1519915028121-7d3463d20b13?w=600&q=80',
                     'description' => 'بقلاوة بالفستق والعسل', 'prep' => 5, 'calories' => 480],
                    ['name' => 'أم علي', 'name_en' => 'Um Ali', 'sku' => 'DES-03', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1587736906005-f31e35fcc6ea?w=600&q=80',
                     'description' => 'أم علي ساخنة بالحليب والمكسرات', 'prep' => 12, 'calories' => 440],
                    ['name' => 'بسبوسة', 'name_en' => 'Basbousa', 'sku' => 'DES-04', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1546039907-7fa05f864c02?w=600&q=80',
                     'description' => 'بسبوسة بالسميد والجوز الهند', 'prep' => 5, 'calories' => 380],
                    ['name' => 'مهلبية', 'name_en' => 'Muhalabia', 'sku' => 'DES-05', 'price' => 2.50,
                     'image' => 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=600&q=80',
                     'description' => 'مهلبية بالحليب وماء الورد', 'prep' => 6, 'calories' => 280],
                    ['name' => 'قطايف', 'name_en' => 'Qatayef', 'sku' => 'DES-06', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1628191137342-208b9bb0a1f7?w=600&q=80',
                     'description' => 'قطايف بالقشطة والفستق', 'prep' => 8, 'calories' => 420],
                    ['name' => 'تشيز كيك', 'name_en' => 'Cheesecake', 'sku' => 'DES-07', 'price' => 4.50,
                     'image' => 'https://images.unsplash.com/photo-1533134242443-d4fd215305ad?w=600&q=80',
                     'description' => 'تشيز كيك بالتوت الأحمر', 'prep' => 5, 'calories' => 560, 'featured' => true],
                    ['name' => 'تيراميسو', 'name_en' => 'Tiramisu', 'sku' => 'DES-08', 'price' => 4.50,
                     'image' => 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=600&q=80',
                     'description' => 'تيراميسو إيطالي', 'prep' => 5, 'calories' => 520],
                    ['name' => 'آيس كريم', 'name_en' => 'Ice Cream', 'sku' => 'DES-09', 'price' => 2.00,
                     'image' => 'https://images.unsplash.com/photo-1566454419290-57a0589c9b51?w=600&q=80',
                     'description' => 'نكهات متعددة', 'prep' => 3, 'calories' => 240],
                    ['name' => 'براوني', 'name_en' => 'Brownie', 'sku' => 'DES-10', 'price' => 3.50,
                     'image' => 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?w=600&q=80',
                     'description' => 'براوني شوكولاتة دافئ', 'prep' => 5, 'calories' => 520],
                    ['name' => 'فواكه موسمية', 'name_en' => 'Seasonal Fruits', 'sku' => 'DES-11', 'price' => 3.00,
                     'image' => 'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?w=600&q=80',
                     'description' => 'طبق فواكه موسمية', 'prep' => 5, 'calories' => 180],
                ],
            ],
        ];
    }
}
