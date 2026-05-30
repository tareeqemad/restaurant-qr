<?php

namespace App\Services\Licensing;

class LicenseSigner
{
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalJson($payload), $this->secret());
    }

    public function verify(array $payload, string $signature): bool
    {
        return hash_equals($this->sign($payload), $signature);
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
}
