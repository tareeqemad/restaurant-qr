<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Identity and safety boundary for the fictional, opt-in demonstration. */
final class DemoDataset
{
    public const ID = 'dar-al-sarw-v1';

    public const BRANCH = 'dar-al-sarw';

    public static function data(): array
    {
        return require __DIR__.'/data/dar-al-sarw.php';
    }

    public static function assertCanSeed(): void
    {
        self::assertEnvironment();
        if (mb_strlen((string) config('restaurant.demo_password')) < 12) {
            throw new RuntimeException('Set RESTAURANT_DEMO_PASSWORD to a private password of at least 12 characters before seeding the demo.');
        }

        $marker = Setting::query()->where('key', 'demo_dataset')->value('value');
        if ($marker === self::ID) {
            return;
        }
        if ($marker !== null) {
            throw new RuntimeException('This database belongs to another demo dataset. Use a separate empty database; no data was changed.');
        }
        foreach (['branches', 'users', 'categories', 'menu_items', 'ingredients', 'suppliers', 'tables', 'table_sessions', 'orders', 'invoices', 'payments', 'journal_entries'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("Demo seeding requires an empty database or the marked Dar Al Sarw demo. Existing [{$table}] data was left untouched.");
            }
        }
    }

    public static function assertDemo(): void
    {
        self::assertEnvironment();
        if (Setting::query()->where('key', 'demo_dataset')->value('value') !== self::ID) {
            throw new RuntimeException('Run DatabaseSeeder on a separate empty demo database first. This component seeder never replaces an existing restaurant.');
        }
    }

    private static function assertEnvironment(): void
    {
        if (! app()->environment('local', 'testing') && ! config('restaurant.demo', false)) {
            throw new RuntimeException('Demo seeding is disabled. A hosted demonstration requires explicit RESTAURANT_DEMO_MODE=true.');
        }
        if (filter_var(Setting::query()->where('key', 'setup_completed')->value('value'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Owner setup is complete. Demo seeders cannot alter this installation.');
        }
    }
}
