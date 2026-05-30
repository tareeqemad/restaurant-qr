<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the `promotions` permission group on existing installs so the
 * role-edit screen surfaces them and managers can grant/revoke per-user.
 * Default attachments mirror the operational reality:
 *   - admin/manager get full CRUD
 *   - cashier gets viewAny so the POS can light up the discount badge
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $perms = [
            'viewAny' => 0,
            'create'  => 1,
            'update'  => 2,
            'delete'  => 3,
        ];

        $createdIds = [];
        foreach ($perms as $action => $order) {
            $id = DB::table('permissions')->where('name', "promotions.{$action}")->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name'          => "promotions.{$action}",
                    'label'         => "promotions.{$action}",
                    'group'         => 'promotions',
                    'group_label'   => 'promotions',
                    'display_order' => $order,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
            $createdIds[$action] = $id;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) return;
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager', 'cashier'])
            ->pluck('id', 'name');

        $matrix = [
            'admin'   => ['viewAny', 'create', 'update', 'delete'],
            'manager' => ['viewAny', 'create', 'update', 'delete'],
            'cashier' => ['viewAny'],
        ];

        foreach ($matrix as $roleName => $actions) {
            $roleId = $roleIds->get($roleName);
            if (! $roleId) continue;
            foreach ($actions as $action) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $createdIds[$action]],
                    ['role_id' => $roleId, 'permission_id' => $createdIds[$action]],
                );
            }
        }
    }

    public function down(): void
    {
        // Leave the rows — operational managers may rely on them.
    }
};
