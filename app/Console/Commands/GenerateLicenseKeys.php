<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class GenerateLicenseKeys extends Command
{
    protected $signature = 'license:generate-keys
        {--dir= : Output directory, relative to the project root unless absolute}
        {--force : Overwrite existing key files}';

    protected $description = 'Generate an RSA private/public key pair for license signing.';

    public function handle(): int
    {
        $directory = $this->resolveDirectory($this->option('dir') ?: storage_path('app/license'));
        $privatePath = $directory.DIRECTORY_SEPARATOR.'license-private.pem';
        $publicPath = $directory.DIRECTORY_SEPARATOR.'license-public.pem';

        File::ensureDirectoryExists($directory);

        if (! $this->option('force') && (File::exists($privatePath) || File::exists($publicPath))) {
            $this->error('License key files already exist. Re-run with --force only if you intentionally want to replace them.');

            return self::FAILURE;
        }

        [$privateKey, $publicKey] = $this->generateKeyPair($directory);

        File::put($privatePath, $privateKey);
        File::put($publicPath, $publicKey);
        @chmod($privatePath, 0600);
        @chmod($publicPath, 0644);

        $this->info('License signing keys generated.');
        $this->line('');
        $this->line('Cloud node .env:');
        $this->line('LICENSE_PRIVATE_KEY_PATH='.$this->relativePath($privatePath));
        $this->line('LICENSE_PUBLIC_KEY_PATH='.$this->relativePath($publicPath));
        $this->line('');
        $this->line('Customer/branch node .env:');
        $this->line('LICENSE_PUBLIC_KEY_PATH='.$this->relativePath($publicPath));

        return self::SUCCESS;
    }

    private function generateKeyPair(string $directory): array
    {
        $opensslConfig = $this->opensslConfigPath($directory);
        $resource = openssl_pkey_new([
            'config' => $opensslConfig,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Unable to generate license key pair.');
        }

        $privateKey = '';
        if (! openssl_pkey_export($resource, $privateKey, null, ['config' => $opensslConfig])) {
            throw new RuntimeException('Unable to export license private key.');
        }

        $details = openssl_pkey_get_details($resource);
        $publicKey = $details['key'] ?? null;
        if (! is_string($publicKey) || $publicKey === '') {
            throw new RuntimeException('Unable to export license public key.');
        }

        return [$privateKey, $publicKey];
    }

    private function resolveDirectory(string $directory): string
    {
        if ($this->isAbsolutePath($directory)) {
            return $directory;
        }

        return base_path($directory);
    }

    private function opensslConfigPath(string $directory): string
    {
        $configured = getenv('OPENSSL_CONF');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $path = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        if (! File::exists($path)) {
            File::put($path, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        }

        return $path;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)))
            : $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
