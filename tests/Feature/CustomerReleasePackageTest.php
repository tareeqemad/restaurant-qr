<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class CustomerReleasePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_release_package_contains_a_sanitized_production_environment(): void
    {
        $workDir = storage_path('framework/testing/customer-release');
        File::deleteDirectory($workDir);
        File::ensureDirectoryExists($workDir);

        $output = $workDir.'/customer.zip';

        $this->artisan('release:customer-package', [
            '--app-url' => 'https://customer.example.com',
            '--app-name' => 'Paid Customer',
            '--output' => $output,
            '--force' => true,
        ])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($output));

        $env = $zip->getFromName('.env');
        $this->assertIsString($env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringNotContainsString('MARKET_PROFILE=', $env);
        $this->assertStringContainsString('APP_URL=https://customer.example.com', $env);
        $this->assertNotFalse($zip->locateName('CUSTOMER-DEPLOYMENT.md'));
        $this->assertNotFalse($zip->locateName('release-manifest.json'));

        $manifest = json_decode($zip->getFromName('release-manifest.json'), true);
        $this->assertSame('ar', $manifest['locale']);
        $this->assertSame('https://customer.example.com', $manifest['app_url']);
        $this->assertSame(
            ['generated_at', 'locale', 'app_url', 'includes_vendor'],
            array_keys($manifest),
        );

        $zip->close();
    }
}
