<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\Reports\ProfitLossReport;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #25 — the ledger P&L must exclude BOTH the monthly period close and the
 *       annual fiscal-year close, or an income statement that spans the close
 *       date shows zero revenue/expense.
 * #26 — closing entries must not be reversible from the journal screen; undoing
 *       a close is only correct via the Reopen flow.
 */
class ClosingAndPnlTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'cp', 'name' => 'CP', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'cp-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function postSale(string $date, float $amount, string $event = 'sale_probe'): void
    {
        app(AccountingService::class)->post(
            eventType: $event, source: null, branchId: $this->branch->id,
            postedOn: $date, description: 'بيع',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => $amount, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => $amount],
            ],
            createdBy: $this->admin->id,
        );
    }

    /** #25 — annual P&L still reports operating revenue after the fiscal-year close. */
    public function test_pnl_ledger_excludes_fiscal_year_closing_entry(): void
    {
        $this->postSale('2026-06-15', 100);

        $year = FiscalYear::create([
            'branch_id' => $this->branch->id, 'name' => 'FY 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fiscal-years.close', $year))
            ->assertSessionHas('success');

        $closing = JournalEntry::findOrFail($year->refresh()->closing_journal_entry_id);
        $this->assertSame('fiscal_year_closing', $closing->event_type);

        // The closing entry (dated 2026-12-31) zeros 4000 into retained earnings.
        // The annual income statement must still show the 100 — BEFORE the fix it
        // netted to 0 because fiscal_year_closing was not excluded.
        $report = (new ProfitLossReport('2026-01-01', '2026-12-31', $this->branch->id, false, 'ledger'))->compute();
        $this->assertSame(100.0, (float) $report['sales']['gross_sales'],
            'Annual P&L must exclude the fiscal-year closing entry.');
    }

    /** #26 — a closing entry cannot be reversed from the journal adjustment screen. */
    public function test_closing_entry_cannot_be_reversed_from_journal(): void
    {
        $this->postSale('2026-09-15', 50);

        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id, 'name' => 'Sep 2026',
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open',
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $period))
            ->assertSessionHas('success');

        $closing = JournalEntry::findOrFail($period->refresh()->closing_journal_entry_id);
        $this->assertSame('period_closing', $closing->event_type);

        // Try to reverse the closing entry from the journal screen → rejected.
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.journal.adjust.store', $closing), [
                'mode' => 'reverse',
                'posted_on' => '2026-10-05',
                'reason' => 'محاولة عكس قيد الإقفال',
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, JournalEntry::where('event_type', 'manual_entry_reversal_'.$closing->id)->count(),
            'No reversal entry may be created for a closing entry from the journal screen.');
    }
}
