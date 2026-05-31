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

        $market = Setting::get('market_profile');
        if (Setting::get('setup_completed', false) && is_string($market) && array_key_exists($market, config('market.profiles', []))) {
            config(['market.profile' => $market]);
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

        static::applyBool('license.enabled', 'license_enabled');
        static::applyString('license.role', 'license_role');
        static::applyString('license.cloud_url', 'license_cloud_url');
        static::applyString('license.key', 'license_key');
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
