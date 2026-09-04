<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\Deployment\MigrationReconciler;
use App\Services\Deployment\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemOperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_the_system_health_center(): void
    {
        [$branch, $admin] = $this->staff(UserRole::SuperAdmin, 'health-admin');

        $this->actingAs($admin)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('admin.system-health'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SystemHealth/Index')
                ->has('report.summary')
                ->has('report.checks', 13)
                ->where('deployment.deployCommand', 'php artisan app:deploy')
                ->where('report.checks', fn ($checks) => collect($checks)->contains('key', 'migrations')
                    && collect($checks)->contains('key', 'public_storage')
                    && collect($checks)->contains('key', 'backup'))
                ->where('shell.nav', fn ($nav) => collect($nav)->contains(function ($item) {
                    if (($item['label'] ?? null) === 'حالة النظام') {
                        return true;
                    }

                    return collect($item['children'] ?? [])->contains('label', 'حالة النظام');
                }))
            );
    }

    public function test_non_technical_manager_cannot_open_system_health(): void
    {
        [$branch, $manager] = $this->staff(UserRole::Manager, 'health-manager');

        $this->actingAs($manager)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('admin.system-health'))
            ->assertForbidden();
    }

    public function test_deployment_sources_cover_shared_hosting_and_safe_schema_upgrade(): void
    {
        $filesystem = file_get_contents(config_path('filesystems.php'));
        $deploy = file_get_contents(app_path('Console/Commands/DeployApplication.php'));
        $backup = file_get_contents(app_path('Console/Commands/BackupDatabase.php'));
        $migration = file_get_contents(database_path('migrations/2026_09_02_000001_upgrade_legacy_accounting_schema.php'));

        $this->assertStringContainsString('PUBLIC_STORAGE_MODE', $filesystem);
        $this->assertStringContainsString("['linked', 'direct']", $filesystem);
        $this->assertStringContainsString("protected \$signature = 'app:deploy", $deploy);
        $this->assertStringContainsString("'Applying database migrations', 'migrate'", $deploy);
        $this->assertStringContainsString('dumpWithPhp', $backup);
        $this->assertStringContainsString("function_exists('proc_open')", $backup);
        $this->assertStringContainsString('AccountingSchemaUpgrade', $migration);
        $this->assertStringNotContainsString('dropColumn(', $migration);
    }

    public function test_frontend_health_detects_a_build_older_than_its_sources(): void
    {
        $directory = storage_path('framework/testing/frontend-build-'.uniqid());
        $manifest = $directory.'/build/manifest.json';
        $source = $directory.'/resources/js/App.vue';

        File::ensureDirectoryExists(dirname($manifest));
        File::ensureDirectoryExists(dirname($source));
        File::put($manifest, '{}');
        File::put($source, '<template><main /></template>');

        try {
            $now = time();
            touch($manifest, $now - 60);
            touch($source, $now);
            clearstatcache(true, $manifest);
            clearstatcache(true, $source);

            $service = app(SystemHealthService::class);
            $stale = $service->frontendBuildState($manifest, [$source]);

            $this->assertTrue($stale['exists']);
            $this->assertTrue($stale['stale']);

            touch($manifest, $now + 10);
            clearstatcache(true, $manifest);
            $fresh = $service->frontendBuildState($manifest, [$source]);

            $this->assertFalse($fresh['stale']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_existing_tables_can_be_reconciled_without_running_schema_sql(): void
    {
        $migration = '2026_08_12_000042_create_users_table';
        $before = DB::table('users')->count();
        DB::table('migrations')->where('migration', $migration)->delete();

        $reconciler = app(MigrationReconciler::class);
        $this->assertContains($migration, $reconciler->candidates());

        $applied = $reconciler->apply();

        $this->assertContains($migration, $applied);
        $this->assertDatabaseHas('migrations', ['migration' => $migration]);
        $this->assertSame($before, DB::table('users')->count());
    }

    private function staff(UserRole $role, string $username): array
    {
        $branch = Branch::create([
            'code' => $username,
            'name' => 'فرع فحص النظام',
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => $role->label(),
            'username' => $username,
            'role' => $role->value,
            'status' => 'active',
            'password' => bcrypt('password'),
            'primary_branch_id' => $branch->id,
        ]);
        $user->branches()->attach($branch->id, ['is_primary' => true, 'joined_at' => now()]);

        return [$branch, $user];
    }
}
