<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill staff-meal permissions on production installs.
 *
 * The staff-meal feature was gated by raw `hasAnyRole(['manager', …])`
 * checks. That meant:
 *   1. Brand-new permissions weren't seeded (the PermissionSeeder runs
 *      only on fresh installs / on demand) — so the role-edit screen
 *      couldn't surface them.
 *   2. There was no way to grant a single cashier access to the
 *      monthly closure without promoting them to manager.
 *
 * This migration adds the missing permissions and attaches them to the
 * default roles per the original role-check matrix:
 *
 *   - viewAny       → manager, admin (and super_admin via owner bypass)
 *   - quick_consume → cashier, manager, admin
 *   - settle        → manager, admin
 *   - waive         → manager, admin
 *   - close_month   → manager, admin
 *
 * Idempotent — `updateOrCreate` + `syncWithoutDetaching` mean rerunning
 * is safe and won't strip any extra permissions an admin granted by
 * hand after the fact.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $perms = [
            'viewAny'       => ['label' => 'staff_meals.viewAny',       'order' => 0],
            'quick_consume' => ['label' => 'staff_meals.quick_consume', 'order' => 1],
            'settle'        => ['label' => 'staff_meals.settle',        'order' => 2],
            'waive'         => ['label' => 'staff_meals.waive',         'order' => 3],
            'close_month'   => ['label' => 'staff_meals.close_month',   'order' => 4],
        ];

        $createdIds = [];
        foreach ($perms as $action => $meta) {
            $id = DB::table('permissions')
                ->where('name', "staff_meals.{$action}")
                ->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name'          => "staff_meals.{$action}",
                    'label'         => $meta['label'],
                    'group'         => 'staff_meals',
                    'group_label'   => 'staff_meals',
                    'display_order' => $meta['order'],
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
            $createdIds[$action] = $id;
        }

        // Attach to default roles per the original hasAnyRole matrix.
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) return;

        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager', 'cashier'])
            ->pluck('id', 'name');

        $matrix = [
            'admin'   => ['viewAny', 'quick_consume', 'settle', 'waive', 'close_month'],
            'manager' => ['viewAny', 'quick_consume', 'settle', 'waive', 'close_month'],
            'cashier' => ['quick_consume'],
        ];

        foreach ($matrix as $roleName => $actions) {
            $roleId = $roleIds->get($roleName);
            if (! $roleId) continue;
            foreach ($actions as $action) {
                $permId = $createdIds[$action] ?? null;
                if (! $permId) continue;
                // upsert ignoring existing — never duplicate
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['role_id' => $roleId, 'permission_id' => $permId],
                );
            }
        }
    }

    public function down(): void
    {
        // Keep the rows — removing them mid-flight could lock managers
        // out of a screen they're actively using. Manual cleanup if needed.
    }
};
