<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 11)->startOfDay());
        $this->branch = Branch::create(['code' => 'acct-regression', 'name' => 'Accounting', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->accountant = User::create([
            'name' => 'Accountant', 'username' => 'acct-regression',
            'role' => UserRole::Accountant->value, 'status' => 'active', 'password' => bcrypt('test'),
        ]);
        $this->accountant->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
        $this->actingAs($this->accountant)->withSession(['active_branch_id' => $this->branch->id]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function payload(string $token = 'accounting-retry'): array
    {
        return [
            '_idem' => $token, 'posted_on' => '2026-10-11', 'description' => 'Manual review entry',
            'lines' => [
                ['account_id' => Account::where('code', AccountingService::CASH)->value('id'), 'debit' => 50, 'credit' => 0],
                ['account_id' => Account::where('code', AccountingService::OPENING_BALANCE_EQUITY)->value('id'), 'debit' => 0, 'credit' => 50],
            ],
        ];
    }

    private function postEntry(string $date, string $description, float $amount = 1, array $metadata = []): JournalEntry
    {
        return app(AccountingService::class)->post(
            eventType: 'manual_journal', source: null, branchId: $this->branch->id,
            postedOn: $date, description: $description,
            lines: [
                ['account' => AccountingService::CASH, 'debit' => $amount, 'credit' => 0],
                ['account' => AccountingService::OPENING_BALANCE_EQUITY, 'debit' => 0, 'credit' => $amount],
            ],
            metadata: $metadata, createdBy: $this->accountant->id,
        );
    }

    public function test_failed_validation_releases_receipt_and_committed_replay_survives_cache_expiry(): void
    {
        $payload = $this->payload();
        $url = route('admin.accounting.manual-entry.store');
        $this->post($url, [...$payload, 'description' => ''])->assertSessionHasErrors('description');
        $this->assertDatabaseCount('accounting_command_receipts', 0);
        $this->assertDatabaseCount('journal_entries', 0);

        $this->post($url, $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('accounting_command_receipts', 1);
        Cache::flush();
        $this->travel(11)->minutes();
        $this->post($url, $payload)->assertSessionHas('info');
        $this->assertSame(1, JournalEntry::where('event_type', 'manual_journal')->count());

        $changed = $payload;
        $changed['lines'][0]['debit'] = 60;
        $changed['lines'][1]['credit'] = 60;
        $this->post($url, $changed)->assertSessionHas('error');
        $this->assertSame(1, JournalEntry::where('event_type', 'manual_journal')->count());
    }

    public function test_closed_period_rejection_can_be_corrected_with_the_same_token(): void
    {
        AccountingPeriod::create([
            'branch_id' => $this->branch->id, 'name' => 'September',
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'closed',
        ]);
        $payload = $this->payload('closed-period-retry');
        $this->post(route('admin.accounting.manual-entry.store'), [...$payload, 'posted_on' => '2026-09-10'])
            ->assertSessionHas('error');
        $this->assertDatabaseCount('accounting_command_receipts', 0);
        $this->assertDatabaseCount('journal_entries', 0);

        $this->post(route('admin.accounting.manual-entry.store'), $payload)->assertSessionHas('success');
        $this->assertDatabaseCount('accounting_command_receipts', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_receipt_tokens_are_scoped_to_branch_user_and_operation(): void
    {
        $payload = $this->payload('shared-token');
        $url = route('admin.accounting.manual-entry.store');
        $this->post($url, $payload)->assertSessionHas('success');

        $other = Branch::create(['code' => 'other-accounting', 'name' => 'Other', 'is_active' => true]);
        $this->accountant->branches()->attach($other->id, ['is_primary' => false, 'joined_at' => now()]);
        $this->withSession(['active_branch_id' => $other->id])->post($url, $payload)->assertSessionHas('success');

        $second = User::create([
            'name' => 'Second accountant', 'username' => 'second-accountant',
            'role' => UserRole::Accountant->value, 'status' => 'active', 'password' => bcrypt('test'),
        ]);
        $second->branches()->attach($other->id, ['is_primary' => true, 'joined_at' => now()]);
        $this->actingAs($second)->post($url, $payload)->assertSessionHas('success');
        $this->post(route('admin.accounting.opening-balances.store'), [
            ...$payload, 'lines' => [$payload['lines'][0]], 'auto_balance' => true,
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('accounting_command_receipts', 4);
        $this->assertDatabaseCount('journal_entries', 4);
    }

    public function test_journal_export_matches_search_including_entries_before_this_month(): void
    {
        $old = $this->postEntry('2026-08-03', 'Review match old');
        $current = $this->postEntry('2026-10-03', 'Unrelated current entry');
        $filters = ['search' => 'Review match', 'to' => '2026-10-11'];
        $this->get(route('admin.accounting.journal', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.id', $old->id));
        $csv = $this->get(route('admin.accounting.journal.export.csv', $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString($old->entry_no, $csv);
        $this->assertStringNotContainsString($current->entry_no, $csv);

        $all = $this->get(route('admin.accounting.journal.export.csv', ['to' => '2026-10-11']))->assertOk()->streamedContent();
        $this->assertStringContainsString($old->entry_no, $all);
        $this->assertStringContainsString($current->entry_no, $all);
    }

    public function test_later_ledger_pages_keep_running_balances_without_hydrating_previous_rows(): void
    {
        $this->postEntry('2026-09-30', 'Opening', 25);
        for ($i = 1; $i <= 85; $i++) {
            $this->postEntry('2026-10-01', 'Movement '.$i);
        }
        $hydrated = 0;
        JournalLine::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $this->get(route('admin.accounting.ledger', [
            'account_id' => Account::where('code', AccountingService::CASH)->value('id'),
            'from' => '2026-10-01', 'to' => '2026-10-11', 'page' => 3,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('lines.data', 5)->where('lines.data.0.runningBalance', 106)
            ->where('lines.data.4.runningBalance', 110)->where('summary.closing', 110));
        $this->assertLessThanOrEqual(10, $hydrated, 'Previous pages must be aggregated in the database, not hydrated into PHP.');
    }

    public function test_journal_only_hydrates_reversal_metadata_for_visible_entries(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->postEntry('2026-10-01', 'History '.$i, metadata: ['posted_by_user_id' => $this->accountant->id]);
        }
        $original = $this->postEntry('2026-10-02', 'Visible original');
        $this->postEntry('2026-10-03', 'Reversal reference', metadata: ['reverses_entry_id' => $original->id]);
        $hydrated = 0;
        JournalEntry::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $this->get(route('admin.accounting.journal'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 20)->where('entries.data.1.id', $original->id)
            ->where('entries.data.1.reversed', true)->where('entries.data.1.correctUrl', null));
        $this->assertLessThanOrEqual(25, $hydrated, 'Unrelated historical metadata must not be hydrated for a journal page.');
    }

    public function test_month_and_year_lists_only_run_checks_for_the_requested_row(): void
    {
        $year = FiscalYear::create([
            'branch_id' => $this->branch->id, 'name' => '2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        $period = null;
        foreach (range(1, 12) as $month) {
            $start = now()->setMonth($month)->startOfMonth();
            $period = AccountingPeriod::create([
                'branch_id' => $this->branch->id, 'fiscal_year_id' => $year->id,
                'name' => $start->format('Y-m'), 'starts_on' => $start->toDateString(),
                'ends_on' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'open',
            ]);
        }
        $ledgerScans = 0;
        DB::listen(function ($query) use (&$ledgerScans) {
            if (str_contains(strtolower($query->sql), 'sum(journal_lines.debit)')) {
                $ledgerScans++;
            }
        });
        $this->get(route('admin.accounting.periods'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('periods.data', 12)->where('periods.data.0.checksLoaded', false)->where('periods.data.0.issueCount', null));
        $this->get(route('admin.accounting.fiscal-years'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('years.data.0.checksLoaded', false));
        $this->assertSame(0, $ledgerScans);
        $this->get(route('admin.accounting.periods', ['check_period_id' => $period->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('periods.data.0.checksLoaded', true)->has('periods.data.0.checks', 5));
        $this->assertSame(1, $ledgerScans);
        $this->get(route('admin.accounting.fiscal-years', ['check_year_id' => $year->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('years.data.0.checksLoaded', true)->has('years.data.0.checks', 5));
        $this->assertSame(2, $ledgerScans);
    }

    public function test_accounting_navigation_keeps_the_applied_review_dates(): void
    {
        $period = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $this->get(route('admin.accounting.journal', $period))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('urls.profitLoss', route('admin.reports.profit-loss', $period))
            ->where('urls.trialBalance', route('admin.accounting.trial-balance', $period))
            ->where('urls.balanceSheet', route('admin.accounting.balance-sheet', $period + ['as_of' => '2026-09-30'])));
        $this->get(route('admin.reports.profit-loss', $period))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('urls.journal', route('admin.accounting.journal', $period)));
        $this->get(route('admin.accounting.balance-sheet', $period + ['as_of' => '2026-09-30']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('asOf', '2026-09-30')
            ->where('urls.profitLoss', route('admin.reports.profit-loss', $period)));
    }
}
