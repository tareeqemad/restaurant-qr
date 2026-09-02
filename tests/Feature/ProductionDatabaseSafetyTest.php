<?php

namespace Tests\Feature;

use App\Listeners\PreventDestructiveDatabaseCommands;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionDatabaseSafetyTest extends TestCase
{
    public function test_refresh_is_blocked_in_production_even_after_laravel_confirmation(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.allow_destructive_database_commands', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocked [migrate:refresh] in production');

        Event::dispatch($this->event('migrate:refresh'));
    }

    public function test_explicit_emergency_flag_can_unlock_a_disposable_production_database(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.allow_destructive_database_commands', true);

        $this->listener()->handle($this->event('migrate:fresh'));

        $this->addToAssertionCount(1);
    }

    public function test_normal_production_migrations_remain_available(): void
    {
        $this->app['env'] = 'production';
        config()->set('app.allow_destructive_database_commands', false);

        $this->listener()->handle($this->event('migrate'));

        $this->addToAssertionCount(1);
    }

    public function test_credit_note_rollback_detaches_refunds_before_dropping_the_parent(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_12_000093_create_credit_notes_table.php'));
        $detach = strpos($migration, '$this->dropRefundForeignKeys();');
        $drop = strpos($migration, "Schema::dropIfExists('credit_notes');");

        $this->assertNotFalse($detach);
        $this->assertNotFalse($drop);
        $this->assertLessThan($drop, $detach);
        $this->assertStringContainsString('$this->ensureRefundForeignKey();', $migration);
        $this->assertStringContainsString("TABLE_NAME` = 'refunds'", $migration);
    }

    public function test_refresh_handles_legacy_batches_where_refunds_predate_credit_notes(): void
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('restaurant_qr_vue_test', DB::connection()->getDatabaseName());

        $legacyFeatureBatch = ((int) DB::table('migrations')->max('batch')) + 1;
        DB::table('migrations')->whereIn('migration', [
            '2026_08_12_000093_create_credit_notes_table',
            '2026_08_12_000094_create_credit_note_lines_table',
            '2026_08_12_000094_create_debt_writeoffs_table',
            '2026_08_12_000095_create_refund_allocations_table',
            '2026_09_02_000001_upgrade_legacy_accounting_schema',
        ])->update(['batch' => $legacyFeatureBatch]);

        $exit = Artisan::call('migrate:refresh', ['--force' => true]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('refunds'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('credit_notes'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('credit_note_lines'));
    }

    private function listener(): PreventDestructiveDatabaseCommands
    {
        return new PreventDestructiveDatabaseCommands($this->app);
    }

    private function event(string $command): CommandStarting
    {
        return new CommandStarting($command, new ArrayInput([]), new BufferedOutput);
    }
}
