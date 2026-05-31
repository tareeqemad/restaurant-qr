<?php

namespace App\Services\Licensing;

use App\Models\LocalLicenseState;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class LicenseManager
{
    public function __construct(private readonly LicenseSigner $signer) {}

    public function state(): LocalLicenseState
    {
        return LocalLicenseState::current();
    }

    public function configuredKey(): ?string
    {
        return config('license.key') ?: $this->state()->license_key;
    }

    public function isEnabled(): bool
    {
        return (bool) config('license.enabled');
    }

    public function refreshIfStale(): LocalLicenseState
    {
        if (! $this->isEnabled()) {
            return $this->state();
        }

        $state = $this->state();
        $hours = max(1, (int) config('license.refresh_hours', 12));

        if (! $state->last_checked_at || $state->last_checked_at->lt(now()->subHours($hours))) {
            return $this->refresh();
        }

        return $state;
    }

    public function refresh(): LocalLicenseState
    {
        $state = $this->state();
        $licenseKey = $this->configuredKey();

        if (! $this->isEnabled()) {
            return $state;
        }

        if (! $licenseKey) {
            $state->markProblem('missing', 'License key is not configured.');

            return $state;
        }

        $cloudUrl = rtrim((string) config('license.cloud_url'), '/');
        if ($cloudUrl === '') {
            $state->markProblem('offline', 'License cloud URL is not configured.');

            return $state;
        }

        $branchUuid = trim((string) config('sync.branch_uuid'));
        if ($branchUuid === '') {
            $state->markProblem('missing_branch', 'Branch UUID is not configured for license activation.');

            return $state;
        }

        try {
            $response = Http::timeout((int) config('license.timeout', 8))
                ->acceptJson()
                ->post($cloudUrl.'/api/license/check', [
                    'license_key' => $licenseKey,
                    'branch_uuid' => $branchUuid,
                    'branch_id' => config('sync.branch_id'),
                    'app_url' => config('app.url'),
                ]);
        } catch (Throwable $e) {
            $state->markProblem($state->payload ? $state->status : 'offline', $e->getMessage());

            return $state;
        }

        if ($response->status() === 404) {
            $state->markProblem('invalid', 'License key was not found on the cloud.');

            return $state;
        }

        if (! $response->successful()) {
            $state->markProblem(
                $response->json('status') ?: ($state->payload ? $state->status : 'offline'),
                $response->json('message') ?: 'License cloud returned HTTP '.$response->status().'.'
            );

            return $state;
        }

        $payload = $response->json('payload');
        $signature = $response->json('signature');

        if (! is_array($payload) || ! is_string($signature) || ! $this->signer->verify($payload, $signature)) {
            $state->markProblem('invalid', 'License cloud response signature is invalid.');

            return $state;
        }

        $state->storeSignedPayload($licenseKey, $payload, $signature);

        return $state->fresh();
    }

    public function allowsOperation(?LocalLicenseState $state = null, ?CarbonInterface $now = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        return $this->summary($state, $now)['allows_operations'];
    }

    public function summary(?LocalLicenseState $state = null, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $state ??= $this->state();

        if (! $this->isEnabled()) {
            return [
                'status' => 'disabled',
                'label' => 'غير مفعّل',
                'color' => 'secondary',
                'allows_operations' => true,
                'message' => 'نظام الترخيص غير مفعّل على هذا الجهاز.',
            ];
        }

        if (! $this->configuredKey()) {
            return $this->blocked('missing', 'لا يوجد مفتاح ترخيص على هذا الجهاز.');
        }

        if ($this->hasClockRollback($state, $now)) {
            return $this->blocked('clock_mismatch', 'ساعة الجهاز أقدم من آخر وقت مؤكد من السحابة.');
        }

        if (! $state->payload || ! $state->signature || ! $this->signer->verify($state->payload, $state->signature)) {
            return $this->blocked('invalid', 'الترخيص المحلي غير صالح أو غير موقّع.');
        }

        return match ($state->status) {
            'active' => $this->activeSummary($state, $now),
            'grace' => $this->graceSummary($state, $now),
            'pending' => $this->blocked('pending', 'الترخيص لم يبدأ بعد.'),
            'suspended' => $this->blocked('suspended', 'الترخيص موقوف من السحابة.'),
            'cancelled' => $this->blocked('cancelled', 'الترخيص ملغى.'),
            'expired' => $this->expiredSummary($state, $now),
            'activation_limit', 'activation_invalid', 'missing_branch' => $this->blocked(
                $state->status,
                $state->last_error ?: 'تعذر تفعيل هذا الفرع على الترخيص.'
            ),
            default => $this->blocked($state->status ?: 'unknown', 'حالة الترخيص غير معروفة.'),
        };
    }

    private function activeSummary(LocalLicenseState $state, CarbonInterface $now): array
    {
        if ($state->starts_at && $now->lt($state->starts_at->copy()->startOfDay())) {
            return $this->blocked('pending', 'الترخيص لم يبدأ بعد.');
        }

        if ($state->expires_at && $now->gt($state->expires_at->copy()->endOfDay())) {
            return $this->graceSummary($state, $now);
        }

        return [
            'status' => 'active',
            'label' => 'فعّال',
            'color' => 'success',
            'allows_operations' => true,
            'message' => 'الترخيص فعّال.',
        ];
    }

    private function graceSummary(LocalLicenseState $state, CarbonInterface $now): array
    {
        if ($state->grace_ends_at && $now->lte($state->grace_ends_at->copy()->endOfDay())) {
            return [
                'status' => 'grace',
                'label' => 'فترة سماح',
                'color' => 'warning',
                'allows_operations' => true,
                'message' => 'الترخيص منتهي لكن ما زال ضمن فترة السماح.',
            ];
        }

        return $this->blocked('expired', 'انتهى الترخيص وانتهت فترة السماح.');
    }

    private function expiredSummary(LocalLicenseState $state, CarbonInterface $now): array
    {
        if ($state->grace_ends_at && $now->lte($state->grace_ends_at->copy()->endOfDay())) {
            return $this->graceSummary($state, $now);
        }

        return $this->blocked('expired', 'انتهى الترخيص وانتهت فترة السماح.');
    }

    private function blocked(string $status, string $message): array
    {
        return [
            'status' => $status,
            'label' => match ($status) {
                'missing' => 'غير مضبوط',
                'invalid' => 'غير صالح',
                'pending' => 'لم يبدأ',
                'suspended' => 'موقوف',
                'cancelled' => 'ملغى',
                'expired' => 'منتهي',
                'clock_mismatch' => 'مشكلة بالوقت',
                'missing_branch' => 'فرع غير مضبوط',
                'activation_limit', 'activation_invalid' => 'تفعيل مرفوض',
                default => 'غير متاح',
            },
            'color' => 'danger',
            'allows_operations' => false,
            'message' => $message,
        ];
    }

    private function hasClockRollback(LocalLicenseState $state, CarbonInterface $now): bool
    {
        if (! $state->last_server_time_at) {
            return false;
        }

        $allowedSkewDays = max(0, (int) config('license.clock_skew_days', 1));

        return $now->lt($state->last_server_time_at->copy()->subDays($allowedSkewDays));
    }
}
