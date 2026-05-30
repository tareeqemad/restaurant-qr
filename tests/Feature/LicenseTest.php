<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LocalLicenseState;
use App\Services\Licensing\LicenseManager;
use App\Services\Licensing\LicenseSigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_license_check_returns_a_signed_payload(): void
    {
        config(['license.role' => 'cloud', 'license.signing_secret' => 'test-secret']);

        $license = License::create([
            'customer_name' => 'Ahmad',
            'restaurant_name' => 'Demo Restaurant',
            'period_months' => 12,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'grace_days' => 14,
            'max_branches' => 1,
        ]);

        $response = $this->postJson('/api/license/check', [
            'license_key' => $license->license_key,
            'branch_uuid' => '01JZBRANCH00000000000001',
        ])->assertOk();

        $payload = $response->json('payload');

        $this->assertSame($license->license_key, $payload['license_key']);
        $this->assertSame('active', $payload['status']);
        $this->assertTrue(app(LicenseSigner::class)->verify($payload, $response->json('signature')));
    }

    public function test_cash_renewal_extends_the_license_and_records_payment(): void
    {
        $license = License::create([
            'customer_name' => 'Ahmad',
            'period_months' => 6,
            'starts_at' => '2026-01-01',
            'expires_at' => '2026-06-30',
            'grace_days' => 14,
            'max_branches' => 1,
        ]);

        $payment = $license->renew(
            periodMonths: 6,
            amount: 500,
            paidAt: Carbon::parse('2026-06-01 10:00:00'),
            receivedByUserId: null,
            notes: 'cash at office',
        );

        $license->refresh();

        $this->assertSame('2026-12-31', $license->expires_at->toDateString());
        $this->assertSame(6, $license->period_months);
        $this->assertSame('2026-07-01', $payment->starts_at->toDateString());
        $this->assertSame('2026-12-31', $payment->expires_at->toDateString());
        $this->assertDatabaseHas('license_payments', [
            'license_id' => $license->id,
            'period_months' => 6,
            'amount' => 500,
        ]);
    }

    public function test_branch_refreshes_from_cloud_and_allows_write_after_renewal(): void
    {
        $this->registerWriteRoute('/_license-renewed-write');
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'license.signing_secret' => 'test-secret',
        ]);

        $this->storeLocalPayload('expired', now()->subMonths(7), now()->subMonth(), now()->subDay());

        $payload = [
            'license_key' => 'RQ-TEST',
            'license_uuid' => '01JZLICENSE0000000000001',
            'customer_name' => 'Ahmad',
            'restaurant_name' => 'Demo Restaurant',
            'status' => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addMonths(6)->toDateString(),
            'grace_ends_at' => now()->addMonths(6)->addDays(14)->toDateString(),
            'max_branches' => 1,
            'branch_uuid' => null,
            'server_time' => now()->toIso8601String(),
        ];
        $signature = app(LicenseSigner::class)->sign($payload);

        Http::fake([
            'https://licenses.test/api/license/check' => Http::response([
                'payload' => $payload,
                'signature' => $signature,
            ], 200),
        ]);

        $this->postJson('/_license-renewed-write')->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('local_license_states', [
            'license_key' => 'RQ-TEST',
            'status' => 'active',
        ]);
    }

    public function test_branch_blocks_write_when_license_is_expired_and_cloud_is_unavailable(): void
    {
        $this->registerWriteRoute('/_license-expired-write');
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'license.signing_secret' => 'test-secret',
        ]);

        $this->storeLocalPayload('expired', now()->subMonths(8), now()->subMonth(), now()->subDay());

        Http::fake([
            'https://licenses.test/api/license/check' => Http::response('', 500),
        ]);

        $this->postJson('/_license-expired-write')
            ->assertStatus(423)
            ->assertJson(['license_status' => 'expired']);
    }

    public function test_license_manager_accepts_signed_cloud_payload(): void
    {
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'license.signing_secret' => 'test-secret',
        ]);

        $payload = [
            'license_key' => 'RQ-TEST',
            'license_uuid' => '01JZLICENSE0000000000001',
            'customer_name' => 'Ahmad',
            'restaurant_name' => 'Demo Restaurant',
            'status' => 'active',
            'starts_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'grace_ends_at' => now()->addYear()->addDays(14)->toDateString(),
            'max_branches' => 1,
            'branch_uuid' => null,
            'server_time' => now()->toIso8601String(),
        ];
        $signature = app(LicenseSigner::class)->sign($payload);

        Http::fake([
            'https://licenses.test/api/license/check' => Http::response([
                'payload' => $payload,
                'signature' => $signature,
            ], 200),
        ]);

        $state = app(LicenseManager::class)->refresh();

        $this->assertSame('active', $state->status);
        $this->assertTrue(app(LicenseManager::class)->allowsOperation($state));
    }

    private function registerWriteRoute(string $path): void
    {
        Route::post($path, fn () => response()->json(['ok' => true]))->middleware('license');
    }

    private function storeLocalPayload(string $status, $startsAt, $expiresAt, $graceEndsAt): void
    {
        $payload = [
            'license_key' => 'RQ-TEST',
            'license_uuid' => '01JZLICENSE0000000000001',
            'customer_name' => 'Ahmad',
            'restaurant_name' => 'Demo Restaurant',
            'status' => $status,
            'starts_at' => $startsAt->toDateString(),
            'expires_at' => $expiresAt->toDateString(),
            'grace_ends_at' => $graceEndsAt->toDateString(),
            'max_branches' => 1,
            'branch_uuid' => null,
            'server_time' => now()->toIso8601String(),
        ];

        LocalLicenseState::current()->storeSignedPayload(
            'RQ-TEST',
            $payload,
            app(LicenseSigner::class)->sign($payload),
        );
    }
}
