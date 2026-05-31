<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Models\Setting;
use App\Support\MarketProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExchangeRateService
{
    public function rateFor(string $currencyCode, ?string $baseCurrencyCode = null, mixed $date = null): float
    {
        $currencyCode = $this->normalizeCode($currencyCode);
        $baseCurrencyCode = $this->normalizeCode($baseCurrencyCode ?: $this->baseCurrencyCode());

        if ($currencyCode === $baseCurrencyCode) {
            return 1.0;
        }

        return (float) $this->findForDateOrFail($currencyCode, $baseCurrencyCode, $date)->rate;
    }

    public function findForDateOrFail(string $currencyCode, ?string $baseCurrencyCode = null, mixed $date = null): CurrencyExchangeRate
    {
        $currencyCode = $this->normalizeCode($currencyCode);
        $baseCurrencyCode = $this->normalizeCode($baseCurrencyCode ?: $this->baseCurrencyCode());
        $date = $this->dateString($date);

        $rate = $this->findForDate($currencyCode, $baseCurrencyCode, $date);

        if (! $rate) {
            throw new \RuntimeException($this->missingRateMessage($currencyCode, $baseCurrencyCode, $date));
        }

        return $rate;
    }

    public function findForDate(string $currencyCode, ?string $baseCurrencyCode = null, mixed $date = null): ?CurrencyExchangeRate
    {
        $currencyCode = $this->normalizeCode($currencyCode);
        $baseCurrencyCode = $this->normalizeCode($baseCurrencyCode ?: $this->baseCurrencyCode());
        $date = $this->dateString($date);

        if ($currencyCode === $baseCurrencyCode) {
            return null;
        }

        return CurrencyExchangeRate::query()
            ->where('currency_code', $currencyCode)
            ->where('base_currency_code', $baseCurrencyCode)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $date);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    public function record(
        string $currencyCode,
        ?string $baseCurrencyCode,
        float $rate,
        mixed $validFrom,
        mixed $validTo = null,
        ?int $createdBy = null,
        string $source = 'manual',
        ?string $note = null,
    ): CurrencyExchangeRate {
        $currencyCode = $this->normalizeCode($currencyCode);
        $baseCurrencyCode = $this->normalizeCode($baseCurrencyCode ?: $this->baseCurrencyCode());
        $validFrom = $this->dateString($validFrom);
        $validTo = $validTo ? $this->dateString($validTo) : null;

        if ($currencyCode === $baseCurrencyCode) {
            throw new \InvalidArgumentException('لا يحتاج سعر صرف عندما تكون عملة العملية نفس عملة الدفاتر.');
        }

        if ($rate <= 0) {
            throw new \InvalidArgumentException('سعر الصرف يجب أن يكون أكبر من صفر.');
        }

        if ($validTo && $validTo < $validFrom) {
            throw new \InvalidArgumentException('تاريخ نهاية سعر الصرف يجب أن يكون بعد تاريخ البداية أو مساويا له.');
        }

        return DB::transaction(function () use ($currencyCode, $baseCurrencyCode, $rate, $validFrom, $validTo, $createdBy, $source, $note) {
            $exchangeRate = CurrencyExchangeRate::create([
                'currency_code' => $currencyCode,
                'base_currency_code' => $baseCurrencyCode,
                'rate' => $rate,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'is_active' => true,
                'source' => $source,
                'note' => $note,
                'created_by' => $createdBy,
            ]);

            if ($this->coversDate($validFrom, $validTo, now())) {
                Currency::where('code', $currencyCode)->update([
                    'rate_to_base' => $rate,
                    'rate_updated_at' => now(),
                ]);
            }

            return $exchangeRate;
        });
    }

    public function missingRateMessage(string $currencyCode, string $baseCurrencyCode, mixed $date = null): string
    {
        $date = $this->dateString($date);

        return "لا يوجد سعر صرف صالح للعملة {$currencyCode} مقابل {$baseCurrencyCode} بتاريخ {$date}. حدّث سعر الصرف من الإعدادات > العملات قبل ترحيل العملية.";
    }

    public function baseCurrencyCode(): string
    {
        return $this->normalizeCode(
            Setting::get('accounting_base_currency', Currency::base()?->code ?? MarketProfile::currency())
        );
    }

    public function normalizeCode(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : 'USD';
    }

    private function dateString(mixed $date): string
    {
        return Carbon::parse($date ?: now())->toDateString();
    }

    private function coversDate(string $validFrom, ?string $validTo, mixed $date): bool
    {
        $date = $this->dateString($date);

        return $validFrom <= $date && ($validTo === null || $validTo >= $date);
    }
}
