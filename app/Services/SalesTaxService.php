<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\TaxJurisdiction;
use App\Support\BranchContext;
use App\Support\MarketProfile;

class SalesTaxService
{
    public function rateForBranch(?int $branchId = null, ?string $date = null): float
    {
        $enabled = (bool) Setting::get('tax_enabled', config('restaurant.tax.enabled', true));
        if (! $enabled) {
            return 0.0;
        }

        if (! MarketProfile::isUs()) {
            return (float) Setting::get('tax_rate', config('restaurant.tax.rate', 16));
        }

        $date ??= now()->toDateString();
        $branchId ??= BranchContext::current();
        $branch = $branchId ? Branch::find($branchId) : null;
        $rule = $this->ruleForBranch($branch, $date);

        return $rule ? (float) $rule->rate : (float) Setting::get('tax_rate', config('restaurant.tax.rate', 0));
    }

    public function ruleForBranch(?Branch $branch = null, ?string $date = null): ?TaxJurisdiction
    {
        $date ??= now()->toDateString();
        $country = strtoupper((string) ($branch?->settingValue('tax_country', 'US') ?: 'US'));
        $state = $this->norm($branch?->settingValue('tax_state'));
        $city = $this->norm($branch?->settingValue('tax_city', $branch?->city));
        $postalCode = $this->norm($branch?->settingValue('tax_postal_code'));

        return TaxJurisdiction::query()
            ->activeOn($date)
            ->where(function ($query) use ($branch) {
                $query->whereNull('branch_id');
                if ($branch) {
                    $query->orWhere('branch_id', $branch->id);
                }
            })
            ->where(function ($query) use ($country) {
                $query->whereNull('country')->orWhere('country', $country);
            })
            ->where(function ($query) use ($state) {
                $query->whereNull('state');
                if ($state !== '') {
                    $query->orWhereRaw('LOWER(state) = ?', [$state]);
                }
            })
            ->where(function ($query) use ($city) {
                $query->whereNull('city');
                if ($city !== '') {
                    $query->orWhereRaw('LOWER(city) = ?', [$city]);
                }
            })
            ->where(function ($query) use ($postalCode) {
                $query->whereNull('postal_code');
                if ($postalCode !== '') {
                    $query->orWhere('postal_code', $postalCode);
                }
            })
            ->orderByDesc('branch_id')
            ->orderBy('priority')
            ->orderByRaw('(CASE WHEN postal_code IS NULL THEN 0 ELSE 1 END) DESC')
            ->orderByRaw('(CASE WHEN city IS NULL THEN 0 ELSE 1 END) DESC')
            ->orderByRaw('(CASE WHEN state IS NULL THEN 0 ELSE 1 END) DESC')
            ->orderByDesc('is_default')
            ->first();
    }

    private function norm(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
