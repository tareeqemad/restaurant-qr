<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SyncState;
use App\Sync\SyncManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    // ── Cloud side: the pull endpoint ───────────────────────────────────────

    public function test_cloud_pull_returns_settings_changes_with_a_valid_token(): void
    {
        config(['sync.role' => 'cloud', 'sync.accept_token' => 'secret']);
        Setting::create(['key' => 'tax_rate', 'value' => '16', 'group' => 'tax', 'type' => 'int']);

        $response = $this->withToken('secret')
            ->getJson('/api/sync/pull?stream=settings')
            ->assertOk();

        // The DB may already hold seeded settings, so locate ours by key
        // rather than assuming a position in the ordered batch.
        $change = collect($response->json('changes'))->firstWhere('key', 'tax_rate');
        $this->assertNotNull($change, 'tax_rate setting not present in pulled changes');
        $this->assertSame('16', $change['value']);
    }

    public function test_cloud_pull_rejects_a_missing_or_wrong_token(): void
    {
        config(['sync.accept_token' => 'secret']);

        $this->getJson('/api/sync/pull?stream=settings')->assertStatus(401);
        $this->withToken('nope')->getJson('/api/sync/pull?stream=settings')->assertStatus(401);
    }

    public function test_cloud_pull_404s_for_an_unknown_stream(): void
    {
        config(['sync.accept_token' => 'secret']);

        $this->withToken('secret')->getJson('/api/sync/pull?stream=ghost')->assertStatus(404);
    }

    // ── Branch side: the manager ────────────────────────────────────────────

    public function test_branch_applies_pulled_settings_and_advances_the_cursor(): void
    {
        $this->asBranch();
        Http::fake([
            'cloud.test/up' => Http::response('ok', 200),
            'cloud.test/api/sync/pull*' => Http::response([
                'changes' => [[
                    'key' => 'foo', 'value' => 'bar', 'group' => 'general', 'type' => 'string',
                    'label' => null, 'description' => null,
                    'created_at' => '2026-05-30 10:00:00', 'updated_at' => '2026-05-30 10:00:00',
                ]],
                'cursor' => '2026-05-30 10:00:00',
            ], 200),
        ]);

        app(SyncManager::class)->run();

        $this->assertDatabaseHas('settings', ['key' => 'foo', 'value' => 'bar']);
        $this->assertSame('bar', Setting::get('foo'));   // cache was busted

        $state = SyncState::where('stream', 'settings')->first();
        $this->assertSame('ok', $state->last_status);
        $this->assertSame('2026-05-30 10:00:00', $state->cursor);
        $this->assertSame(1, $state->last_count);
    }

    public function test_branch_records_offline_when_the_cloud_is_unreachable(): void
    {
        $this->asBranch();
        Http::fake(['cloud.test/up' => Http::response('', 500)]);

        app(SyncManager::class)->run();

        $this->assertSame('offline', SyncState::where('stream', 'settings')->first()->last_status);
        $this->assertDatabaseMissing('settings', ['key' => 'foo']);
    }

    public function test_sync_is_a_no_op_on_a_standalone_node(): void
    {
        config(['sync.enabled' => false, 'sync.role' => 'standalone']);

        $report = app(SyncManager::class)->run();

        $this->assertTrue($report['skipped']);
        $this->assertSame(0, SyncState::count());
    }

    private function asBranch(): void
    {
        config([
            'sync.enabled' => true,
            'sync.role' => 'branch',
            'sync.cloud_url' => 'https://cloud.test',
            'sync.token' => 'secret',
            'sync.batch' => 200,
        ]);
    }
}
