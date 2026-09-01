<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\CustomerSalesTaxRate;
use App\Models\JournalLine;
use App\Models\Setting;
use App\Services\Accounting\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * One source of truth for tax on invoices issued to restaurant customers.
 *
 * This does not control tax entered on supplier invoices. Customer tax is
 * effective-dated so a restaurant can change it monthly or yearly without
 * rewriting orders that already captured their own rate snapshot.
 */
class TaxConfiguration
{
    public static function switchIsOn(): bool
    {
        return self::isEnabled();
    }

    public static function effectiveFrom(): ?string
    {
        if ($schedule = self::scheduleOn()) {
            return $schedule->effective_from->toDateString();
        }

        $value = trim((string) Setting::get('tax_effective_from', ''));

        return $value !== '' ? Carbon::parse($value)->toDateString() : null;
    }

    public static function isEnabled(mixed $date = null): bool
    {
        if ($schedule = self::scheduleOn($date)) {
            return (bool) $schedule->enabled;
        }

        $enabled = (bool) Setting::get('tax_enabled', config('restaurant.tax.enabled', false));
        if (! $enabled) {
            return false;
        }

        $effectiveFrom = self::effectiveFrom();
        if (! $effectiveFrom) {
            return true;
        }

        return Carbon::parse($date ?: now())->startOfDay()
            ->greaterThanOrEqualTo(Carbon::parse($effectiveFrom)->startOfDay());
    }

    public static function rate(mixed $date = null): float
    {
        if ($schedule = self::scheduleOn($date)) {
            return $schedule->enabled ? (float) $schedule->rate : 0.0;
        }

        return self::isEnabled($date)
            ? (float) Setting::get('tax_rate', config('restaurant.tax.rate', 0))
            : 0.0;
    }

    public static function scheduleOn(mixed $date = null): ?CustomerSalesTaxRate
    {
        if (! Schema::hasTable('customer_sales_tax_rates')) {
            return null;
        }

        $on = Carbon::parse($date ?: now())->toDateString();

        return CustomerSalesTaxRate::query()
            ->whereDate('effective_from', '<=', $on)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public static function nextSchedule(mixed $date = null): ?CustomerSalesTaxRate
    {
        if (! Schema::hasTable('customer_sales_tax_rates')) {
            return null;
        }

        $after = Carbon::parse($date ?: now())->toDateString();

        return CustomerSalesTaxRate::query()
            ->whereDate('effective_from', '>', $after)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int, CustomerSalesTaxRate> */
    public static function schedules(): Collection
    {
        if (! Schema::hasTable('customer_sales_tax_rates')) {
            return collect();
        }

        return CustomerSalesTaxRate::query()
            ->with('createdBy:id,name')
            ->orderByDesc('effective_from')
            ->get();
    }

    /** @return Collection<int, int> */
    public static function accountIds(): Collection
    {
        $defaultCodes = collect([
            AccountingService::INPUT_VAT,
            AccountingService::OUTPUT_VAT,
        ]);

        $mappedIds = AccountMapping::query()
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->whereIn('key', ['input_vat', 'output_vat'])
            ->pluck('account_id');

        return Account::query()
            ->whereIn('code', $defaultCodes)
            ->pluck('id')
            ->concat($mappedIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /** @return Collection<int, int> */
    public static function salesTaxAccountIds(): Collection
    {
        return self::idsFor(AccountingService::OUTPUT_VAT, 'output_vat');
    }

    /** @return Collection<int, int> */
    public static function supplierTaxAccountIds(): Collection
    {
        return self::idsFor(AccountingService::INPUT_VAT, 'input_vat');
    }

    /** @return Collection<int, int> */
    private static function idsFor(string $defaultCode, string $mappingKey): Collection
    {
        $mappedIds = AccountMapping::query()
            ->where('context', AccountMapping::CONTEXT_POSTING_ROLE)
            ->where('key', $mappingKey)
            ->pluck('account_id');

        return Account::query()
            ->where('code', $defaultCode)
            ->pluck('id')
            ->concat($mappedIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * The day-to-day chart should feel as if tax does not exist while the
     * switch is off. This hides the accounts without deleting them or their
     * journal lines; formal historical reports still use hideableAccountIds().
     *
     * @return Collection<int, int>
     */
    public static function operationallyHiddenAccountIds(): Collection
    {
        return self::isEnabled() ? collect() : self::salesTaxAccountIds();
    }

    /**
     * Tax accounts with history must stay visible in formal reports. Only
     * zero-history accounts disappear from the day-to-day chart while tax is
     * disabled.
     *
     * @return Collection<int, int>
     */
    public static function hideableAccountIds(): Collection
    {
        if (self::switchIsOn()) {
            return collect();
        }

        $ids = self::salesTaxAccountIds();
        $withHistory = JournalLine::query()
            ->whereIn('account_id', $ids)
            ->distinct()
            ->pluck('account_id');

        return $ids->diff($withHistory)->values();
    }

    public static function hasHistory(): bool
    {
        return JournalLine::query()->whereIn('account_id', self::accountIds())->exists();
    }

    public static function hasSalesHistory(): bool
    {
        return JournalLine::query()->whereIn('account_id', self::salesTaxAccountIds())->exists();
    }
}
