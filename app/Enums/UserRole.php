<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
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
            self::Admin => 'مدير عام',
            self::Manager => 'مدير فرع',
            self::Waiter => 'جرسون',
            self::Chef => 'شيف',
            self::Bartender => 'بارمان',
            self::Cashier => 'كاشير',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->toArray();
    }

    public static function adminRoles(): array
    {
        return [
            self::SuperAdmin->value,
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
