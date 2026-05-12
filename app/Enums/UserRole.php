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

    /**
     * Privilege escalation guard — what roles is THIS actor allowed to
     * grant when creating/editing other users? Returns the role values
     * (strings) the dropdown should expose.
     *
     * Hierarchy (must NEVER let a lower rank grant a higher one):
     *   - SuperAdmin → everything (including SuperAdmin & Partner)
     *   - Partner    → everything EXCEPT SuperAdmin (technical role)
     *   - Admin      → manager + all staff (NOT another admin / partner / super)
     *   - Manager    → staff only (NOT admin / manager / partner / super)
     *   - everyone else → nothing (UI shouldn't even let them on this page,
     *                     but defense in depth)
     *
     * Why so strict: a fresh-install branch manager creating a "super_admin"
     * user is immediate full-system takeover. Without this filter the dropdown
     * lists every role with `super_admin` first, which is the literal default.
     */
    public static function grantableBy(?\App\Models\User $actor): array
    {
        if (! $actor) return [];

        // SuperAdmin can do anything.
        if ($actor->role === self::SuperAdmin->value) {
            return array_map(fn ($c) => $c->value, self::cases());
        }

        // Partner: every role except SuperAdmin (which is technical/system).
        if ($actor->role === self::Partner->value) {
            return collect(self::cases())
                ->reject(fn ($c) => $c === self::SuperAdmin)
                ->map(fn ($c) => $c->value)
                ->all();
        }

        // Admin: can spawn managers + staff, but NOT another admin/partner/super.
        if ($actor->role === self::Admin->value) {
            return [
                self::Manager->value,
                ...self::staffRoles(),
            ];
        }

        // Manager: staff only (no peer/upward grants).
        if ($actor->role === self::Manager->value) {
            return self::staffRoles();
        }

        return [];
    }

    /**
     * Same as grantableBy but returns label-keyed-by-value array suitable
     * for a `<select>` — matches the shape of `options()`.
     */
    public static function grantableOptions(?\App\Models\User $actor): array
    {
        $allowed = self::grantableBy($actor);
        return collect(self::cases())
            ->filter(fn ($c) => in_array($c->value, $allowed, true))
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->toArray();
    }
}
