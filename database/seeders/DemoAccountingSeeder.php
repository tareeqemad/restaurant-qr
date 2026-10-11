<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Services\Accounting\AccountingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Empty accounting references for the marked demo; never creates a posting. */
class DemoAccountingSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataset::assertDemo();
        $branch = Branch::where('code', DemoDataset::BRANCH)->firstOrFail();
        DB::transaction(function () use ($branch): void {
            foreach (AccountingService::postingRoleDefinitions() as $role => $definition) {
                // Existing custom mappings and later account deactivations are
                // operator choices. Only newly installed defaults are enabled.
                if (AccountMapping::where('context', AccountMapping::CONTEXT_POSTING_ROLE)->where('key', $role)->exists()) {
                    continue;
                }
                $account = Account::where('code', $definition['default'])->firstOrFail();
                if (! $account->is_system || ! in_array($account->type, $definition['types'], true)) {
                    throw new RuntimeException("The demo system account for [{$role}] is not a valid default.");
                }
                if (! $account->is_active) {
                    $account->update(['is_active' => true]);
                }
                AccountMapping::create([
                    'context' => AccountMapping::CONTEXT_POSTING_ROLE,
                    'key' => $role, 'account_id' => $account->id,
                ]);
            }

            $start = now()->startOfYear();
            $end = $start->copy()->endOfYear();
            $scope = fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            $year = FiscalYear::where($scope)
                ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)->first();
            if (! $year) {
                $year = FiscalYear::create([
                    'branch_id' => $branch->id, 'name' => 'السنة المالية '.$start->year,
                    'starts_on' => $start, 'ends_on' => $end, 'status' => 'open',
                    'notes' => 'فترات فارغة للتجربة؛ لا تتضمن أرصدة أو قيوداً افتتاحية.',
                ]);
            }
            // Do not reshape a manually configured year or reopen closed work.
            if ($year->isClosed() || ! $year->starts_on->isSameDay($start) || ! $year->ends_on->isSameDay($end)) {
                return;
            }
            for ($month = 1; $month <= 12; $month++) {
                $monthStart = $start->copy()->month($month)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                $overlap = AccountingPeriod::where($scope)
                    ->whereDate('starts_on', '<=', $monthEnd)->whereDate('ends_on', '>=', $monthStart)->exists();
                if (! $overlap) {
                    AccountingPeriod::create([
                        'branch_id' => $year->branch_id, 'fiscal_year_id' => $year->id,
                        'name' => 'شهر '.$monthStart->format('m/Y'),
                        'starts_on' => $monthStart, 'ends_on' => $monthEnd, 'status' => 'open',
                    ]);
                }
            }
        });
    }
}
