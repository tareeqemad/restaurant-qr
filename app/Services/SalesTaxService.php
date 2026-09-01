<?php

namespace App\Services;

use App\Support\TaxConfiguration;

/**
 * Resolves the effective customer-invoice tax rate.
 *
 * The rate is restaurant-owned and effective-dated. It is never inferred from
 * a US state, city or postal code, and it remains zero while tax is disabled.
 */
class SalesTaxService
{
    public function rateForBranch(?int $branchId = null, ?string $date = null): float
    {
        $date ??= now()->toDateString();

        return TaxConfiguration::isEnabled($date)
            ? TaxConfiguration::rate($date)
            : 0.0;
    }
}
