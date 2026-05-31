<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class BuildCustomerRelease extends Command
{
    protected $signature = 'release:customer-package
        {--license-key= : Customer LICENSE_KEY from your license cloud}
        {--cloud-url= : Your license cloud URL, e.g. https://licenses.example.com}
        {--market=palestine : Market profile: palestine or us}
        {--app-url=http://localhost : Customer app URL}
        {--app-name=Restaurant QR : Customer app name}
        {--public-key= : Public key PEM path to ship with the customer package}
        {--output= : Output zip path}
        {--include-vendor : Include vendor/ in the package}
        {--include-sqlite-demo : Include database/database.sqlite if present}
        {--compress : Compress zip entries for a smaller package; default stores entries for faster packaging}
        {--sync-cloud-url= : Optional sync cloud URL for branch nodes}
        {--sync-token= : Optional sync token for branch nodes}
        {--force : Overwrite output zip if it already exists}';

    protected $description = 'Build a sanitized customer production package with license settings and public key only.';

    private array $excludedDirectories = [
        '.git',
        '.github',
        '.idea',
        '.vscode',
        'docs',
        'node_modules',
        'storage/app/backups',
        'storage/app/releases',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/testing',
        'storage/framework/views',
        'storage/logs',
        'tests',
    ];

    private array $excludedFiles = [
        '.env',
        '.env.backup',
        '.env.local',
        '.env.production',
        '.phpunit.result.cache',
        'phpunit.xml',
    ];

    private array $excludedExtensions = [
        'map',
    ];

    public function handle(): int
    {
        try {
            return $this->build();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function build(): int
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('The PHP zip extension is required to build customer packages.');
        }

        $licenseKey = $this->requiredOption('license-key');
        $cloudUrl = rtrim($this->requiredOption('cloud-url'), '/');
        $market = $this->market();
        $publicKeyPath = $this->publicKeyPath();
        $outputPath = $this->outputPath($market);

        if (is_file($outputPath) && ! $this->option('force')) {
            $this->error('Output package already exists. Re-run with --force or choose a different --output path.');

            return self::FAILURE;
        }

        $this->ensureDirectory(dirname($outputPath));

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create release zip: '.$outputPath);
        }

        $root = base_path();
        $this->addProjectFiles($zip, $root, $outputPath);
        $this->addRuntimeDirectories($zip);
        $this->addGeneratedEnv($zip, $licenseKey, $cloudUrl, $market);
        $this->addPublicKey($zip, $publicKeyPath);
        $this->addDeploymentGuide($zip, $licenseKey, $cloudUrl, $market);
        $this->addManifest($zip, $licenseKey, $cloudUrl, $market);

        $zip->close();

        $this->info('Customer package created: '.$outputPath);
        $this->warn('This is a sanitized production package, not source-code encryption. Use ionCube/SourceGuardian if the customer gets filesystem access and you need stronger source protection.');

        return self::SUCCESS;
    }

    private function addProjectFiles(ZipArchive $zip, string $root, string $outputPath): void
    {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $file) use ($root, $outputPath): bool {
                $relativePath = $this->relativePath($file->getPathname(), $root);

                return ! $this->shouldExclude($relativePath, $file->isDir(), $file->getPathname(), $outputPath);
            }
        );
        $iterator = new \RecursiveIteratorIterator(
            $filter,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $absolutePath = $file->getPathname();
            $relativePath = $this->relativePath($absolutePath, $root);

            if ($this->shouldExclude($relativePath, $file->isDir(), $absolutePath, $outputPath)) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
                continue;
            }

            $this->addFile($zip, $absolutePath, $relativePath);
        }
    }

    private function shouldExclude(string $relativePath, bool $isDir, string $absolutePath, string $outputPath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($absolutePath === $outputPath) {
            return true;
        }

        if ($relativePath === '') {
            return false;
        }

        if (str_starts_with($relativePath, 'storage/app/license/')) {
            return true;
        }

        if ($relativePath === 'database/database.sqlite' && ! $this->option('include-sqlite-demo')) {
            return true;
        }

        if ($relativePath === 'vendor' && ! $this->option('include-vendor')) {
            return true;
        }

        foreach ($this->excludedDirectories as $directory) {
            if ($relativePath === $directory || str_starts_with($relativePath, $directory.'/')) {
                return true;
            }
        }

        if ($isDir) {
            return false;
        }

        $fileName = basename($relativePath);
        if (in_array($relativePath, $this->excludedFiles, true) || in_array($fileName, $this->excludedFiles, true)) {
            return true;
        }

        if (in_array(strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION)), $this->excludedExtensions, true)) {
            return true;
        }

        if (str_ends_with($fileName, '.pem') && str_contains($relativePath, 'private')) {
            return true;
        }

        if (str_contains($relativePath, '/license-private.pem') || str_contains($relativePath, 'LICENSE_PRIVATE_KEY')) {
            return true;
        }

        return false;
    }

    private function addRuntimeDirectories(ZipArchive $zip): void
    {
        foreach ([
            'storage/app',
            'storage/app/license',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ] as $directory) {
            $zip->addEmptyDir($directory);
        }
    }

    private function addGeneratedEnv(ZipArchive $zip, string $licenseKey, string $cloudUrl, string $market): void
    {
        $env = is_file(base_path('.env.example'))
            ? (string) file_get_contents(base_path('.env.example'))
            : '';

        $values = [
            'APP_NAME' => $this->option('app-name'),
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false',
            'APP_URL' => $this->option('app-url'),
            'APP_FORCE_HTTPS' => str_starts_with((string) $this->option('app-url'), 'https://') ? 'true' : 'false',
            'MARKET_PROFILE' => $market,
            'LICENSE_ENABLED' => 'true',
            'LICENSE_ROLE' => 'branch',
            'LICENSE_CLOUD_URL' => $cloudUrl,
            'LICENSE_KEY' => $licenseKey,
            'LICENSE_PRIVATE_KEY_PATH' => '',
            'LICENSE_PUBLIC_KEY_PATH' => 'storage/app/license/license-public.pem',
            'LICENSE_PRIVATE_KEY' => '',
            'LICENSE_PUBLIC_KEY' => '',
            'LICENSE_SIGNING_SECRET' => '',
        ];

        if ($this->option('sync-cloud-url') || $this->option('sync-token')) {
            $values['SYNC_ENABLED'] = 'true';
            $values['SYNC_ROLE'] = 'branch';
            $values['SYNC_CLOUD_URL'] = rtrim((string) $this->option('sync-cloud-url'), '/');
            $values['SYNC_TOKEN'] = (string) $this->option('sync-token');
        }

        foreach ($values as $key => $value) {
            $env = $this->upsertEnv($env, $key, (string) $value);
        }

        $this->addString($zip, '.env', $env);
    }

    private function addPublicKey(ZipArchive $zip, string $publicKeyPath): void
    {
        $contents = file_get_contents($publicKeyPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read public key: '.$publicKeyPath);
        }

        $this->addString($zip, 'storage/app/license/license-public.pem', $contents);
    }

    private function addDeploymentGuide(ZipArchive $zip, string $licenseKey, string $cloudUrl, string $market): void
    {
        $guide = <<<MD
# Customer Deployment

This package is prepared for a customer node.

- Market: {$market}
- License cloud: {$cloudUrl}
- License key: {$licenseKey}

## Install

1. Upload the package to the customer server and extract it.
2. If `vendor/` was not included, run `composer install --no-dev --optimize-autoloader`.
3. Configure the database in `.env` if the customer is not using the included defaults.
4. Run:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

5. Open `/login` and let the customer try the demo data first.
6. When the customer decides to go live, open `/setup` to wipe demo data and create the real restaurant setup.

## License Rules

- This customer node contains only `storage/app/license/license-public.pem`.
- Never copy `license-private.pem` to a customer server.
- Renewals and suspensions are controlled from your license cloud.
- If the customer moves servers, revoke or reactivate the branch activation from the license details page.

MD;

        $this->addString($zip, 'CUSTOMER-DEPLOYMENT.md', $guide);
    }

    private function addManifest(ZipArchive $zip, string $licenseKey, string $cloudUrl, string $market): void
    {
        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'market' => $market,
            'app_url' => $this->option('app-url'),
            'license_cloud_url' => $cloudUrl,
            'license_key' => $licenseKey,
            'includes_vendor' => (bool) $this->option('include-vendor'),
            'includes_sqlite_demo' => (bool) $this->option('include-sqlite-demo'),
            'contains_private_license_key' => false,
        ];

        $this->addString($zip, 'release-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function addFile(ZipArchive $zip, string $absolutePath, string $relativePath): void
    {
        $zip->addFile($absolutePath, $relativePath);
        $this->setCompression($zip, $relativePath);
    }

    private function addString(ZipArchive $zip, string $relativePath, string $contents): void
    {
        $zip->addFromString($relativePath, $contents);
        $this->setCompression($zip, $relativePath);
    }

    private function setCompression(ZipArchive $zip, string $relativePath): void
    {
        if (! $this->option('compress') && method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName($relativePath, ZipArchive::CM_STORE);
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('Missing required option: --'.$name);
        }

        return $value;
    }

    private function market(): string
    {
        $market = trim((string) $this->option('market'));
        if (! in_array($market, ['palestine', 'us'], true)) {
            throw new RuntimeException('Invalid market. Use --market=palestine or --market=us.');
        }

        return $market;
    }

    private function publicKeyPath(): string
    {
        $path = trim((string) ($this->option('public-key') ?: config('license.public_key_path') ?: 'storage/app/license/license-public.pem'));
        $path = $this->absolutePath($path);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Public key file does not exist or is not readable: '.$path);
        }

        $contents = (string) file_get_contents($path);
        if (! str_contains($contents, '-----BEGIN PUBLIC KEY-----')) {
            throw new RuntimeException('Public key file is not a valid PEM public key: '.$path);
        }

        return $path;
    }

    private function outputPath(string $market): string
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            $slug = Str::slug((string) $this->option('app-name')) ?: 'restaurant-qr';
            $output = storage_path('app/releases/'.$slug.'-'.$market.'-'.now()->format('Ymd-His').'.zip');
        }

        return $this->absolutePath($output);
    }

    private function upsertEnv(string $env, string $key, string $value): string
    {
        $line = $key.'='.$this->envValue($value);

        if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $env)) {
            return preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $env) ?? $env;
        }

        return rtrim($env).PHP_EOL.$line.PHP_EOL;
    }

    private function envValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (in_array(strtolower($value), ['true', 'false', 'null'], true) || is_numeric($value)) {
            return $value;
        }

        if (preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }

    private function absolutePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }

    private function relativePath(string $absolutePath, string $root): string
    {
        return str_replace('\\', '/', ltrim(substr($absolutePath, strlen($root)), DIRECTORY_SEPARATOR));
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: '.$directory);
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
