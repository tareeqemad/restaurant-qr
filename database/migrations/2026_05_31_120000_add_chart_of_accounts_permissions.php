<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the `chart_of_accounts` permission group so the accountant
 * gets a dedicated set of toggles separate from the generic admin
 * role. By default we attach it to admin + manager (the roles that
 * already see accounting reports). Cashier intentionally does NOT
 * get viewAny — they have no business browsing the chart from the
 * till.
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
            $name = "chart_of_accounts.{$action}";
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId([
                    'name'          => $name,
                    'label'         => $name,
                    'group'         => 'chart_of_accounts',
                    'group_label'   => 'chart_of_accounts',
                    'display_order' => $order,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
            $createdIds[$action] = $id;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) return;
        $roleIds = DB::table('roles')->whereIn('name', ['admin', 'manager'])->pluck('id', 'name');
        foreach (['admin', 'manager'] as $roleName) {
            $roleId = $roleIds->get($roleName);
            if (! $roleId) continue;
            foreach ($createdIds as $permId) {
                DB::table('role_permission')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permId],
                    ['role_id' => $roleId, 'permission_id' => $permId],
                );
            }
        }
    }

    public function down(): void
    {
        // Keep — historical role assignments depend on these rows.
    }
};
