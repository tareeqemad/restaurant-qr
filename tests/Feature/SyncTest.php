<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SyncState;
use App\Support\BranchContext;
use App\Sync\SyncManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

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

    public function test_synced_models_get_ulids_and_table_pull_exports_foreign_uuid_refs(): void
    {
        config(['sync.role' => 'cloud', 'sync.accept_token' => 'secret']);

        $branch = Branch::create(['code' => 'main', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($branch->id);
        $category = Category::create(['name' => 'Food', 'slug' => 'food', 'active' => true]);

        $this->assertNotEmpty($branch->uuid);
        $this->assertNotEmpty($category->uuid);

        $response = $this->withToken('secret')
            ->getJson('/api/sync/pull?stream=categories')
            ->assertOk();

        $change = collect($response->json('changes'))->firstWhere('slug', 'food');

        $this->assertSame($category->uuid, $change['uuid']);
        $this->assertSame($branch->uuid, $change['_sync_refs']['branch_id']);
    }

    public function test_cloud_push_receives_branch_owned_rows_by_uuid_not_local_id(): void
    {
        config(['sync.role' => 'cloud', 'sync.accept_token' => 'secret']);

        $branch = Branch::create(['code' => 'main', 'name' => 'Main', 'is_active' => true]);
        BranchContext::set($branch->id);

        $remoteOrderUuid = '01JZ0000000000000000000001';

        $this->withToken('secret')->postJson('/api/sync/push', [
            'stream' => 'orders',
            'changes' => [[
                'uuid' => $remoteOrderUuid,
                'branch_id' => 999999,
                'number' => 'ORD-REMOTE-0001',
                'order_source' => 'dine_in',
                'order_type' => 'dine_in',
                'status' => 'pending',
                'subtotal' => 0,
                'discount_total' => 0,
                'tax_total' => 0,
                'service_total' => 0,
                'delivery_fee' => 0,
                'tip' => 0,
                'total' => 0,
                'tax_rate' => 0,
                'service_rate' => 0,
                'submitted_at' => '2026-05-30 10:00:00',
                'created_at' => '2026-05-30 10:00:00',
                'updated_at' => '2026-05-30 10:00:00',
                '_sync_refs' => ['branch_id' => $branch->uuid],
            ]],
        ])->assertOk()->assertJson(['received' => 1]);

        $order = Order::where('uuid', $remoteOrderUuid)->firstOrFail();

        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame('ORD-REMOTE-0001', $order->number);
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
