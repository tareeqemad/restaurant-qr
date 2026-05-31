<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class CustomerReleasePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_release_package_contains_license_env_and_public_key_only(): void
    {
        $workDir = storage_path('framework/testing/customer-release');
        File::deleteDirectory($workDir);
        File::ensureDirectoryExists($workDir);

        $this->artisan('license:generate-keys', [
            '--dir' => $workDir.'/keys',
            '--force' => true,
        ])->assertSuccessful();

        $output = $workDir.'/customer.zip';

        $this->artisan('release:customer-package', [
            '--license-key' => 'RQ-PAID-CUSTOMER-001',
            '--cloud-url' => 'https://licenses.example.com',
            '--market' => 'us',
            '--app-url' => 'https://customer.example.com',
            '--app-name' => 'Paid Customer',
            '--public-key' => $workDir.'/keys/license-public.pem',
            '--output' => $output,
            '--force' => true,
        ])->assertSuccessful();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($output));

        $env = $zip->getFromName('.env');
        $this->assertIsString($env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('MARKET_PROFILE=us', $env);
        $this->assertStringContainsString('LICENSE_ENABLED=true', $env);
        $this->assertStringContainsString('LICENSE_ROLE=branch', $env);
        $this->assertStringContainsString('LICENSE_CLOUD_URL=https://licenses.example.com', $env);
        $this->assertStringContainsString('LICENSE_KEY=RQ-PAID-CUSTOMER-001', $env);
        $this->assertStringContainsString('LICENSE_PRIVATE_KEY_PATH=', $env);
        $this->assertStringContainsString('LICENSE_PUBLIC_KEY_PATH=storage/app/license/license-public.pem', $env);

        $this->assertNotFalse($zip->locateName('storage/app/license/license-public.pem'));
        $this->assertFalse($zip->locateName('storage/app/license/license-private.pem'));
        $this->assertFalse($zip->locateName('tests/Feature/LicenseTest.php'));
        $this->assertNotFalse($zip->locateName('CUSTOMER-DEPLOYMENT.md'));
        $this->assertNotFalse($zip->locateName('release-manifest.json'));

        $manifest = json_decode($zip->getFromName('release-manifest.json'), true);
        $this->assertSame('us', $manifest['market']);
        $this->assertFalse($manifest['contains_private_license_key']);

        $zip->close();
    }
}
