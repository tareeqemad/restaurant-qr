<?php

namespace App\Support;

/**
 * The accounting workspace navigation map, server-owned.
 *
 * `AccountingNav.vue` renders whatever this returns and drops every null
 * entry, so a user never sees a link their permission set would 403 on.
 * It lives in Support (not on a controller) because more than one
 * controller feeds the same nav — AccountingController owns the accounting
 * pages, AccountController owns the chart of accounts, and both must agree
 * on the map or the strip changes shape as you move between them.
 *
 * This is the only map. Controllers delegate here so permissions and page
 * groups cannot drift as the workspace evolves.
 */
class AccountingWorkspace
{
    public static function urls(): array
    {
        $user = auth()->user();

        return [
            'home' => route('admin.accounting.index'),
            'guide' => route('admin.accounting.guide'),
            'journal' => route('admin.accounting.journal'),
            'ledger' => route('admin.accounting.ledger'),
            'trialBalance' => route('admin.accounting.trial-balance'),
            'profitLoss' => route('admin.reports.profit-loss'),
            'balanceSheet' => route('admin.accounting.balance-sheet'),
            'aging' => route('admin.accounting.aging'),
            'taxReport' => route('admin.accounting.tax-report'),
            'accounts' => route('admin.accounts.index'),
            'openingBalances' => $user?->hasPermission('chart_of_accounts.create')
                ? route('admin.accounting.opening-balances') : null,
            'manualEntry' => $user?->hasPermission('chart_of_accounts.create')
                ? route('admin.accounting.manual-entry.create') : null,
            'fiscalYears' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.fiscal-years') : null,
            'periods' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.periods') : null,
            'mappings' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.mappings') : null,
            'reconciliations' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.reconciliations') : null,
            'settlements' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.settlements') : null,
            'fixedAssets' => route('admin.accounting.fixed-assets.index'),
        ];
    }
}
