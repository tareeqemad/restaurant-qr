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

        $urls = [
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
            'cashShifts' => $user?->hasPermission('chart_of_accounts.viewAny')
                ? route('admin.accounting.cash-shifts') : null,
            'settlements' => $user?->hasPermission('chart_of_accounts.update')
                ? route('admin.accounting.settlements') : null,
            'fixedAssets' => route('admin.accounting.fixed-assets.index'),
        ];

        // Carry the chosen review period through the workspace, including a
        // visit via setup/home, instead of silently comparing different months.
        $period = array_filter(request()->only(['from', 'to']), static fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value));
        $asOf = request()->input('as_of', request()->input('statement_date'));
        if (is_string($asOf) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $period['to'] = $asOf;
            $period['from'] ??= substr($asOf, 0, 7).'-01';
        }
        if ($period !== []) {
            foreach ($urls as $key => &$url) {
                if ($url) {
                    $parameters = $period;
                    if (isset($period['to'])) {
                        if (in_array($key, ['balanceSheet', 'aging', 'settlements'], true)) {
                            $parameters['as_of'] = $period['to'];
                        } elseif ($key === 'reconciliations') {
                            $parameters['statement_date'] = $period['to'];
                        }
                    }
                    $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($parameters);
                }
            }
        }

        return $urls;
    }
}
