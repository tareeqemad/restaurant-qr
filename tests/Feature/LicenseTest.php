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
        $keys = $this->configureLicenseKeysForCloud();
        config(['license.role' => 'cloud']);

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

        $this->configureLicenseKeysForBranch($keys);

        $this->assertSame($license->license_key, $payload['license_key']);
        $this->assertSame('active', $payload['status']);
        $this->assertNotEmpty($payload['activation_uuid']);
        $this->assertTrue(app(LicenseSigner::class)->verify($payload, $response->json('signature')));
        $this->assertDatabaseHas('license_activations', [
            'license_id' => $license->id,
            'branch_uuid' => '01JZBRANCH00000000000001',
            'status' => 'active',
        ]);
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
        $keys = $this->makeLicenseKeyPair();
        $this->registerWriteRoute('/_license-renewed-write');
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'sync.branch_uuid' => '01JZBRANCH00000000000001',
        ]);
        $this->configureLicenseKeysForBranch($keys);

        $this->storeLocalPayload('expired', now()->subMonths(7), now()->subMonth(), now()->subDay(), $keys);

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
            'branch_uuid' => '01JZBRANCH00000000000001',
            'activation_uuid' => '01JZACTIVATION0000000001',
            'server_time' => now()->toIso8601String(),
        ];
        $signature = $this->signPayloadWithPrivateKey($payload, $keys);

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
        $keys = $this->makeLicenseKeyPair();
        $this->registerWriteRoute('/_license-expired-write');
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'sync.branch_uuid' => '01JZBRANCH00000000000001',
        ]);
        $this->configureLicenseKeysForBranch($keys);

        $this->storeLocalPayload('expired', now()->subMonths(8), now()->subMonth(), now()->subDay(), $keys);

        Http::fake([
            'https://licenses.test/api/license/check' => Http::response('', 500),
        ]);

        $this->postJson('/_license-expired-write')
            ->assertStatus(423)
            ->assertJson(['license_status' => 'expired']);
    }

    public function test_license_manager_accepts_signed_cloud_payload(): void
    {
        $keys = $this->makeLicenseKeyPair();
        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'sync.branch_uuid' => '01JZBRANCH00000000000001',
        ]);
        $this->configureLicenseKeysForBranch($keys);

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
            'branch_uuid' => '01JZBRANCH00000000000001',
            'activation_uuid' => '01JZACTIVATION0000000001',
            'server_time' => now()->toIso8601String(),
        ];
        $signature = $this->signPayloadWithPrivateKey($payload, $keys);

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

    public function test_license_activation_limit_blocks_extra_branch(): void
    {
        $this->configureLicenseKeysForCloud();
        config(['license.role' => 'cloud']);

        $license = License::create([
            'customer_name' => 'Ahmad',
            'period_months' => 12,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'grace_days' => 14,
            'max_branches' => 1,
        ]);

        $this->postJson('/api/license/check', [
            'license_key' => $license->license_key,
            'branch_uuid' => '01JZBRANCH00000000000001',
        ])->assertOk();

        $this->postJson('/api/license/check', [
            'license_key' => $license->license_key,
            'branch_uuid' => '01JZBRANCH00000000000002',
        ])
            ->assertStatus(423)
            ->assertJson(['status' => 'activation_limit']);

        $this->assertSame(1, $license->activations()->count());
    }

    public function test_branch_rejects_hmac_payload_when_public_key_is_configured(): void
    {
        $keys = $this->makeLicenseKeyPair();

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
            'branch_uuid' => '01JZBRANCH00000000000001',
            'activation_uuid' => '01JZACTIVATION0000000001',
            'server_time' => now()->toIso8601String(),
        ];

        config([
            'license.private_key' => null,
            'license.public_key' => null,
            'license.signing_secret' => 'leaked-legacy-secret',
        ]);
        $forgedSignature = app(LicenseSigner::class)->sign($payload);

        config([
            'license.enabled' => true,
            'license.role' => 'branch',
            'license.key' => 'RQ-TEST',
            'license.cloud_url' => 'https://licenses.test',
            'sync.branch_uuid' => '01JZBRANCH00000000000001',
        ]);
        $this->configureLicenseKeysForBranch($keys);

        Http::fake([
            'https://licenses.test/api/license/check' => Http::response([
                'payload' => $payload,
                'signature' => $forgedSignature,
            ], 200),
        ]);

        $state = app(LicenseManager::class)->refresh();

        $this->assertSame('invalid', $state->status);
        $this->assertFalse(app(LicenseManager::class)->allowsOperation($state));
    }

    private function registerWriteRoute(string $path): void
    {
        Route::post($path, fn () => response()->json(['ok' => true]))->middleware('license');
    }

    private function storeLocalPayload(string $status, $startsAt, $expiresAt, $graceEndsAt, array $keys): void
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
            'branch_uuid' => '01JZBRANCH00000000000001',
            'activation_uuid' => '01JZACTIVATION0000000001',
            'server_time' => now()->toIso8601String(),
        ];

        LocalLicenseState::current()->storeSignedPayload(
            'RQ-TEST',
            $payload,
            $this->signPayloadWithPrivateKey($payload, $keys),
        );
    }

    private function makeLicenseKeyPair(): array
    {
        $opensslConfig = $this->opensslConfigPath();
        $resource = openssl_pkey_new([
            'config' => $opensslConfig,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $privateKey = '';
        openssl_pkey_export($resource, $privateKey, null, ['config' => $opensslConfig]);
        $details = openssl_pkey_get_details($resource);

        return [
            'private' => $privateKey,
            'public' => $details['key'],
        ];
    }

    private function opensslConfigPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restaurant-qr-openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        }

        return $path;
    }

    private function configureLicenseKeysForCloud(): array
    {
        $keys = $this->makeLicenseKeyPair();

        config([
            'license.private_key' => $keys['private'],
            'license.private_key_path' => null,
            'license.public_key' => $keys['public'],
            'license.public_key_path' => null,
            'license.signing_secret' => null,
        ]);

        return $keys;
    }

    private function configureLicenseKeysForBranch(array $keys): void
    {
        config([
            'license.private_key' => null,
            'license.private_key_path' => null,
            'license.public_key' => $keys['public'],
            'license.public_key_path' => null,
            'license.signing_secret' => null,
        ]);
    }

    private function signPayloadWithPrivateKey(array $payload, array $keys): string
    {
        config([
            'license.private_key' => $keys['private'],
            'license.private_key_path' => null,
            'license.public_key' => $keys['public'],
            'license.public_key_path' => null,
            'license.signing_secret' => null,
        ]);

        $signature = app(LicenseSigner::class)->sign($payload);
        $this->configureLicenseKeysForBranch($keys);

        return $signature;
    }
}
