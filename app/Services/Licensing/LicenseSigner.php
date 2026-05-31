<?php

namespace App\Services\Licensing;

use RuntimeException;

class LicenseSigner
{
    private const ASYMMETRIC_PREFIX = 'v2.';

    private const HMAC_PREFIX = 'v1.';

    public function sign(array $payload): string
    {
        $message = $this->canonicalJson($payload);

        if ($privateKey = $this->privateKey()) {
            $key = openssl_pkey_get_private($privateKey);
            if ($key === false) {
                throw new RuntimeException('License private key is not valid.');
            }

            $signature = '';
            if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign the license payload.');
            }

            return self::ASYMMETRIC_PREFIX.base64_encode($signature);
        }

        return self::HMAC_PREFIX.hash_hmac('sha256', $message, $this->secret());
    }

    public function verify(array $payload, string $signature): bool
    {
        $message = $this->canonicalJson($payload);

        if (str_starts_with($signature, self::ASYMMETRIC_PREFIX)) {
            return $this->verifyAsymmetric($message, substr($signature, strlen(self::ASYMMETRIC_PREFIX)));
        }

        // When a public key is configured, this node must only trust cloud
        // signatures from the matching private key. HMAC is a legacy fallback.
        if ($this->publicKey()) {
            return false;
        }

        $expected = self::HMAC_PREFIX.hash_hmac('sha256', $message, $this->secret());
        if (str_starts_with($signature, self::HMAC_PREFIX)) {
            return hash_equals($expected, $signature);
        }

        // Backward compatibility for payloads cached before signatures had a
        // version prefix.
        return hash_equals(substr($expected, strlen(self::HMAC_PREFIX)), $signature);
    }

    private function canonicalJson(array $payload): string
    {
        $this->sortKeys($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortKeys(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortKeys($value);
            }
        }
    }

    private function secret(): string
    {
        return (string) config('license.signing_secret', config('app.key'));
    }

    private function verifyAsymmetric(string $message, string $signature): bool
    {
        $publicKey = $this->publicKey();
        if (! $publicKey) {
            return false;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return false;
        }

        return openssl_verify($message, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function privateKey(): ?string
    {
        return $this->keyMaterial(config('license.private_key'), config('license.private_key_path'));
    }

    private function publicKey(): ?string
    {
        return $this->keyMaterial(config('license.public_key'), config('license.public_key_path'));
    }

    private function keyMaterial(mixed $inline, mixed $path): ?string
    {
        $path = $this->stringOrNull($path);
        if ($path) {
            return $this->readKeyPath($path);
        }

        $inline = $this->stringOrNull($inline);
        if (! $inline) {
            return null;
        }

        if (! str_contains($inline, '-----BEGIN') && is_file($inline)) {
            return $this->readKeyPath($inline);
        }

        return str_replace('\n', "\n", $inline);
    }

    private function readKeyPath(string $path): ?string
    {
        $path = str_starts_with($path, 'file://') ? substr($path, 7) : $path;
        $resolved = $this->isAbsolutePath($path) ? $path : base_path($path);

        if (! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }

        $contents = file_get_contents($resolved);

        return $contents === false ? null : trim($contents);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }
}
