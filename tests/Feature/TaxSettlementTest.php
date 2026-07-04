<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #30 — tax settlement is balance-based: the payable is the outstanding VAT
 *       liability as of today. Paying it clears the balance, so re-opening the
 *       same range cannot pay it again (no double-pay) and the payment never
 *       erases the next period's payable.
 */
class TaxSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'tx', 'name' => 'TX', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Admin', 'username' => 'tx-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    /** Accrue output VAT (a sale) on a given date: Dr 1100 / Cr 2100. */
    private function accrueVat(string $date, float $amount): void
    {
        app(AccountingService::class)->post(
            eventType: 'vat_probe', source: null, branchId: $this->branch->id,
            postedOn: $date, description: 'ضريبة مبيعات',
            lines: [
                ['account' => AccountingService::ACCOUNTS_RECEIVABLE, 'debit' => $amount, 'credit' => 0],
                ['account' => AccountingService::OUTPUT_VAT, 'debit' => 0, 'credit' => $amount],
            ],
            createdBy: $this->admin->id,
        );
    }

    private function payTax(string $from, string $to): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.accounting.settlements.tax-payment'), [
            'from' => $from, 'to' => $to,
            'posted_on' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);
    }

    /** The Dr amount on account 2100 in the most recent tax_payment entry. */
    private function lastTaxPaymentOutputDebit(): float
    {
        $entry = JournalEntry::where('event_type', 'tax_payment')->latest('id')->with('lines.account')->first();
        if (! $entry) return 0.0;
        return (float) $entry->lines->firstWhere('account.code', AccountingService::OUTPUT_VAT)?->debit;
    }

    public function test_tax_settlement_is_balance_based_no_double_pay_and_no_next_period_bleed(): void
    {
        // June sale accrues 100 of output VAT.
        $this->accrueVat(now()->subDays(20)->toDateString(), 100);

        // Settle "June" (posted today, after the period). Clears the 100.
        $this->payTax(now()->subDays(30)->toDateString(), now()->subDays(10)->toDateString())
            ->assertSessionHas('success');
        $this->assertSame(1, JournalEntry::where('event_type', 'tax_payment')->count());
        $this->assertSame(100.0, $this->lastTaxPaymentOutputDebit());

        // Re-open the SAME range → nothing owed now, so paying again is refused.
        $this->payTax(now()->subDays(30)->toDateString(), now()->subDays(10)->toDateString())
            ->assertSessionHas('error');
        $this->assertSame(1, JournalEntry::where('event_type', 'tax_payment')->count(),
            'Re-settling an already-paid range must NOT create a second payment.');

        // July sale accrues another 50.
        $this->accrueVat(now()->subDays(3)->toDateString(), 50);

        // Settle "July" → exactly 50 (June already cleared, not erased, not double).
        $this->payTax(now()->subDays(9)->toDateString(), now()->toDateString())
            ->assertSessionHas('success');
        $this->assertSame(2, JournalEntry::where('event_type', 'tax_payment')->count());
        $this->assertSame(50.0, $this->lastTaxPaymentOutputDebit(),
            "Next period's payable must be its own 50 — neither erased by nor doubled with the prior settlement.");
    }
}
