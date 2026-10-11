<?php

namespace Tests\Feature;

use App\Console\Commands\BuildCustomerRelease;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use ZipArchive;

/** Exercises the real zip collector without booting the app or a database. */
class CustomerReleasePackageTest extends TestCase
{
    private string $workDir;

    private Application $app;

    private ?Container $originalContainer;

    private mixed $originalFacadeApp;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('The PHP zip extension is required.');
        }

        $this->workDir = dirname(__DIR__, 2).'/tmp/customer-release-'.bin2hex(random_bytes(6));
        (new Filesystem)->makeDirectory($this->workDir.'/project', 0755, true);
        $this->originalContainer = Container::getInstance();
        $this->originalFacadeApp = Facade::getFacadeApplication();
        $this->app = new Application($this->workDir.'/project');
        $this->app->instance('date', new DateFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->put('.env.example', "APP_NAME=Template\nAPP_ENV=local\nAPP_DEBUG=true\nAPP_URL=http://localhost\nAPP_KEY=\nDB_PASSWORD=\n");
        $this->put('artisan', '<?php // shipped application entry point');
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->flush();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->originalFacadeApp);
            Container::setInstance($this->originalContainer);
        }
        if (isset($this->workDir)) {
            $resolved = realpath($this->workDir);
            $temporaryRoot = realpath(dirname(__DIR__, 2).'/tmp');
            if ($resolved !== false && $temporaryRoot !== false
                && str_starts_with($resolved, $temporaryRoot.DIRECTORY_SEPARATOR.'customer-release-')) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
        parent::tearDown();
    }

    private function put(string $relativePath, string $contents): void
    {
        $path = $this->workDir.'/project/'.$relativePath;
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);
    }

    private function package(): ZipArchive
    {
        $command = new BuildCustomerRelease;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--app-url' => 'https://customer.example.com',
            '--app-name' => 'Paid Customer',
            '--output' => $this->workDir.'/customer.zip',
        ]);
        $this->assertSame(0, $status, $tester->getDisplay());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->workDir.'/customer.zip'));

        return $zip;
    }

    public function test_customer_release_package_contains_a_sanitized_production_environment(): void
    {
        $this->put('.env', 'PRIVATE_ENV_SENTINEL');
        $zip = $this->package();
        try {
            $env = $zip->getFromName('.env');
            $this->assertIsString($env);
            $this->assertStringContainsString('APP_ENV=production', $env);
            $this->assertStringContainsString('APP_DEBUG=false', $env);
            $this->assertStringNotContainsString('PRIVATE_ENV_SENTINEL', $env);
            $this->assertStringContainsString('APP_URL=https://customer.example.com', $env);
            $this->assertMatchesRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/=]+/', $env);
            $this->assertNotFalse($zip->locateName('.env.example'));
            $this->assertNotFalse($zip->locateName('CUSTOMER-DEPLOYMENT.md'));
            $this->assertNotFalse($zip->locateName('release-manifest.json'));
            $guide = $zip->getFromName('CUSTOMER-DEPLOYMENT.md');
            $this->assertStringContainsString('new, empty database', $guide);
            $this->assertStringContainsString('RESTAURANT_DEMO_MODE=true', $guide);
            $this->assertStringContainsString('at least 12 characters', $guide);
            $this->assertStringContainsString('php artisan db:seed --class=DemoSeeder --force', $guide);
            $this->assertStringContainsString('`accountant1`', $guide);
            $this->assertStringNotContainsString('app:install', $guide);

            $manifest = json_decode($zip->getFromName('release-manifest.json'), true);
            $this->assertSame('ar', $manifest['locale']);
            $this->assertSame('https://customer.example.com', $manifest['app_url']);
            $this->assertSame(['generated_at', 'locale', 'app_url', 'includes_vendor'], array_keys($manifest));
        } finally {
            $zip->close();
        }
    }

    public function test_clean_release_excludes_local_secrets_and_uploads_but_keeps_bundled_demo_brand_and_fonts(): void
    {
        $excluded = [
            'tmp/.env.production.backup', 'tmp/mysql-audit/customer.ibd', 'tmp/test-results.txt', '.tmp/private-preview.json',
            'docs/private-local-notes.md',
            '.codex/settings.json', '.codex-staging/build/manifest.json', '.agents/context.md',
            '.claude/settings.local.json', '.aws/credentials', '.ssh/id_rsa', 'auth.json',
            '.env.testing', '.env.production.local', '.env.preview.backup', '.env-old', '.env~',
            'config/.ENV.backup', 'config/.env.snapshot',
            'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php',
            'storage/app/public/menu-items/old-customer.jpg', 'storage/app/private/customer.csv',
            'storage/oauth-private.key', 'storage/logs/laravel.log',
            'public/storage/menu-items/old-customer.jpg',
            'public/default_logo.png', 'public/assets/brand/relax-monogram.svg', 'public/hot',
        ];
        $included = [
            'public/demo/menu/mains.svg', 'public/brand/dar-al-sarw-logo.svg', 'public/brand/fonts.css',
            'public/brand/interface.css', 'public/assets/fonts/cairo/arabic.woff2',
            'public/assets/fonts/inter/latin.woff2', 'public/build/manifest.json',
            'public/build/assets/app.js', 'database/seeders/data/dar-al-sarw.php',
            'docs/REPAIR-PLAN.md', 'docs/demo-dar-al-sarw.md', 'docs/ui-redesign-audit.json',
        ];
        foreach ($excluded as $path) {
            $this->put($path, 'PRIVATE_RELEASE_SENTINEL');
        }
        foreach ($included as $path) {
            $this->put($path, 'bundled asset '.$path);
        }

        $zip = $this->package();
        try {
            foreach ($excluded as $path) {
                $this->assertFalse($zip->locateName($path), 'Excluded file leaked: '.$path);
            }
            foreach ($included as $path) {
                $this->assertSame('bundled asset '.$path, $zip->getFromName($path), 'Bundled file missing: '.$path);
            }
            foreach (['storage/app/public/', 'storage/app/private/', 'storage/framework/cache/data/', 'bootstrap/cache/'] as $directory) {
                $this->assertNotFalse($zip->locateName($directory), 'Runtime directory missing: '.$directory);
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $this->assertStringNotContainsString('PRIVATE_RELEASE_SENTINEL', (string) $zip->getFromIndex($index));
            }
        } finally {
            $zip->close();
        }
    }
}
