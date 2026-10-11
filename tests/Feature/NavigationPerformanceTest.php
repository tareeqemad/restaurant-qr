<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Support\FirstRunSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NavigationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_permission_checks_reuse_three_queries_without_losing_denials(): void
    {
        $user = User::factory()->create(['username' => 'permission-perf', 'role' => 'cashier', 'status' => 'active']);
        $permission = Permission::where('name', 'payments.viewAny')->firstOrFail();
        $user->permissions()->attach($permission, ['granted' => false]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse($user->hasPermission('payments.viewAny'));
            $this->assertTrue($user->hasPermission('tables.viewAny'));
            $this->assertFalse($user->hasPermission('not.a.permission'));
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
    }

    public function test_permission_snapshot_does_not_survive_a_new_request_or_refresh(): void
    {
        $user = User::factory()->create(['username' => 'permission-refresh', 'role' => 'cashier', 'status' => 'active']);
        $permission = Permission::where('name', 'payments.viewAny')->firstOrFail();
        $this->assertTrue($user->hasPermission($permission->name));
        $user->permissions()->attach($permission, ['granted' => false]);

        $originalRequest = app('request');
        try {
            app()->instance('request', Request::create('/next-page'));
            $this->assertFalse($user->hasPermission($permission->name));
            $user->permissions()->detach($permission);
            $user->refresh();
            $this->assertTrue($user->hasPermission($permission->name));
        } finally {
            app()->instance('request', $originalRequest);
        }
    }

    public function test_installed_navigation_does_not_inspect_schema_or_count_all_records(): void
    {
        User::factory()->create(['username' => 'setup-perf']);
        Branch::create(['code' => 'perf', 'name' => 'Performance', 'is_active' => true]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse(FirstRunSetup::shouldRunWizard());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('exists', strtolower($query['query']));
            $this->assertStringNotContainsString('information_schema', strtolower($query['query']));
        }
    }
}
