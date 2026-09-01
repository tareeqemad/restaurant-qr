<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class RuntimeConfig
{
    public static function apply(): void
    {
        if (! static::settingsReady()) {
            return;
        }

        static::applyString('restaurant.menu_base_url', 'menu_base_url');
        static::applyString('restaurant.currency', 'sales_currency');
        static::applyString('restaurant.currency_symbol', 'currency_symbol');

        static::applyBool('sync.enabled', 'sync_enabled');
        static::applyString('sync.role', 'sync_role');
        static::applyString('sync.cloud_url', 'sync_cloud_url');
        static::applyString('sync.token', 'sync_token');
        static::applyString('sync.branch_id', 'sync_branch_id');
        static::applyString('sync.branch_uuid', 'sync_branch_uuid');
        config(['sync.accept_token' => config('sync.token')]);

    }

    private static function applyString(string $configKey, string $settingKey): void
    {
        $value = Setting::get($settingKey);

        if (is_string($value) && trim($value) !== '') {
            config([$configKey => trim($value)]);
        }
    }

    private static function applyBool(string $configKey, string $settingKey): void
    {
        $setting = Setting::query()->where('key', $settingKey)->first();

        if ($setting) {
            config([$configKey => Setting::get($settingKey, config($configKey))]);
        }
    }

    private static function settingsReady(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
