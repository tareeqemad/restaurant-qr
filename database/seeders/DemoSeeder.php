<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Opt-in fictional demonstration, installed atomically without clearing data. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertCanSeed();
        $firstInstall = ! Setting::query()->where('key', 'demo_dataset')->exists();
        DB::transaction(function () use ($firstInstall): void {
            Setting::put('demo_dataset', DemoDataset::ID, 'demo');
            $this->call(SystemSeeder::class);
            if ($firstInstall) {
                $this->seedIdentity();
            }
            $this->call([
                BranchSeeder::class, StationSeeder::class, UserSeeder::class,
                TableSeeder::class, MenuSeeder::class, RecipesSeeder::class,
                DemoAccountingSeeder::class,
            ]);
        });
        $this->command?->info('Dar Al Sarw demonstration ready. Existing stock, passwords, prices and table state were preserved.');
    }

    private function seedIdentity(): void
    {
        foreach ([
            'site_name' => 'مطعم دار السرو',
            'demo_tagline' => 'لمة فلسطينية بطعم البيت — تجربة عرض',
            'demo_menu_notice' => 'قائمة تجريبية وأسعار توضيحية — لا تمثل مطعماً حقيقياً أو أسعار السوق الحالية',
            'receipt_footer' => 'دار السرو — فاتورة تجريبية لعرض النظام',
            'legal_name' => '', 'tax_number' => '', 'brand_logo' => '', 'brand_favicon' => '',
        ] as $key => $value) {
            Setting::put($key, $value, str_starts_with($key, 'demo_') ? 'demo' : 'general');
        }
        foreach ([
            'theme_primary' => '#A3422B', 'theme_dark' => '#25343A', 'theme_header' => '#FFFDFA',
            'theme_accent' => '#B78338', 'theme_menu' => '#F7F3ED',
            'theme_header_style' => 'light', 'theme_menu_style' => 'brand',
        ] as $key => $value) {
            Setting::put($key, $value, 'theme');
        }
        foreach ([
            'setup_completed' => false, 'sms_enabled' => false, 'service_enabled' => false,
            'waiter_direct_fire' => true, 'strict_stock' => true,
        ] as $key => $value) {
            Setting::put($key, $value, 'general', 'bool');
        }
    }
}
