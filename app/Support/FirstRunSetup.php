<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class FirstRunSetup
{
    public static function needsSetup(): bool
    {
        if (! static::tablesReady()) {
            return false;
        }

        if (User::query()->count() === 0 || Branch::query()->count() === 0) {
            return true;
        }

        return false;
    }

    public static function shouldRunWizard(): bool
    {
        return static::tablesReady() && (! static::completed() || static::needsSetup());
    }

    public static function completed(): bool
    {
        if (! static::settingsReady()) {
            return false;
        }

        return (bool) Setting::get('setup_completed', false);
    }

    public static function hasUsers(): bool
    {
        return static::usersReady() && User::query()->exists();
    }

    public static function hasBranches(): bool
    {
        return static::branchesReady() && Branch::query()->exists();
    }

    public static function hasExistingRecords(): bool
    {
        return static::hasUsers() || static::hasBranches();
    }

    public static function willResetDemoData(): bool
    {
        return static::tablesReady()
            && ! static::completed()
            && static::hasExistingRecords();
    }

    public static function canAccess(?User $user): bool
    {
        if (! static::hasUsers()) {
            return true;
        }

        return $user?->isOwnerLevel() || $user?->hasAnyRole(['super_admin', 'partner', 'admin']);
    }

    public static function tablesReady(): bool
    {
        return static::usersReady() && static::branchesReady() && static::settingsReady();
    }

    private static function usersReady(): bool
    {
        return static::hasTable('users');
    }

    private static function branchesReady(): bool
    {
        return static::hasTable('branches');
    }

    private static function settingsReady(): bool
    {
        return static::hasTable('settings');
    }

    private static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
