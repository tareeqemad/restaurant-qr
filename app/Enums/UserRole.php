<?php

namespace App\Enums;

/**
 * System role catalogue. Two "owner-level" roles see every branch:
 *   - SuperAdmin: technical/system role (developer, billing, system logs).
 *   - Partner:    business owner. Same data access as SuperAdmin but no
 *                 access to system-level UIs (subscription, dev tools).
 *
 * Use `UserRole::ownerRoles()` (and `User::isOwnerLevel()`) anywhere that
 * needs "skip the branch filter" — never a hard-coded `isSuperAdmin()`,
 * which would silently exclude partners.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Partner = 'partner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Waiter = 'waiter';
    case Chef = 'chef';
    case Bartender = 'bartender';
    case Cashier = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'مدير النظام',
            self::Partner => 'شريك',
            self::Admin => 'مدير عام',
            self::Manager => 'مدير فرع',
            self::Waiter => 'جرسون',
            self::Chef => 'المطبخ',
            self::Bartender => 'البار',
            self::Cashier => 'كاشير',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->toArray();
    }

    /**
     * Roles that get business-level full access to every branch's data.
     * Includes SuperAdmin and Partner.
     */
    public static function ownerRoles(): array
    {
        return [
            self::SuperAdmin->value,
            self::Partner->value,
        ];
    }

    public static function adminRoles(): array
    {
        return [
            self::SuperAdmin->value,
            self::Partner->value,
            self::Admin->value,
            self::Manager->value,
        ];
    }

    public static function staffRoles(): array
    {
        return [
            self::Waiter->value,
            self::Chef->value,
            self::Bartender->value,
            self::Cashier->value,
        ];
    }
}
