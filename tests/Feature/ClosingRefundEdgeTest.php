<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\RefundService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #28 A fiscal-year close must succeed even when a monthly period inside the
 *     year is already closed (the closing entry is exempt from the period lock).
 * #27 A pending refund whose creation month gets closed can still be completed
 *     (it posts at the completion date, not the frozen creation date).
 * #5  Manual journal / opening balances reject a duplicate submit via a token.
 */
class ClosingRefundEdgeTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'cre', 'name' => 'CRE', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'cre-admin', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    private function accrue(string $date, float $amount): void
    {
        app(AccountingService::class)->post(
            eventType: 'probe', source: null, branchId: $this->branch->id,
            postedOn: $date, description: 'p',
            lines: [
                ['account' => AccountingService::ACCOUNTS_RECEIVABLE, 'debit' => $amount, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => $amount],
            ],
            createdBy: $this->admin->id,
        );
    }

    /** #28 — fiscal-year close works with a closed December period still in place. */
    public function test_fiscal_year_close_succeeds_over_a_closed_month(): void
    {
        $this->accrue('2026-03-15', 100);   // unclosed month → still needs closing at year end
        $this->accrue('2026-12-15', 50);

        // Close December as a monthly period first (posts period_closing on 12-31).
        $dec = AccountingPeriod::create([
            'branch_id' => $this->branch->id, 'name' => 'Dec 2026',
            'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $dec))
            ->assertSessionHas('success');

        // Now close the fiscal year. Its closing entry is dated 2026-12-31 — inside
        // the already-closed December period. BEFORE the fix the period lock
        // rejected it and the year could never close.
        $year = FiscalYear::create([
            'branch_id' => $this->branch->id, 'name' => 'FY 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
        ]);
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fiscal-years.close', $year))
            ->assertSessionHas('success');

        $this->assertSame('closed', $year->fresh()->status);
        $this->assertNotNull($year->fresh()->closing_journal_entry_id);
        $this->assertSame('fiscal_year_closing',
            JournalEntry::find($year->fresh()->closing_journal_entry_id)->event_type);
    }

    /** #27 — a pending refund completes even after its creation month is closed. */
    public function test_pending_refund_completes_after_month_close(): void
    {
        $table = \App\Models\Table::create(['number'=>'R1','capacity'=>2,'status'=>'available','active'=>true]);
        $session = \App\Models\TableSession::create(['table_id'=>$table->id,'cover_count'=>1,'status'=>'closed']);
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id, 'table_session_id' => $session->id,
            'issued_by_user_id' => $this->admin->id,
            'subtotal' => 100, 'discount_total' => 0, 'tax_total' => 0, 'service_total' => 0,
            'delivery_fee' => 0, 'tip' => 0, 'total' => 100, 'paid_total' => 100, 'balance' => 0,
            'status' => 'paid', 'issued_at' => now()->subMonths(2), 'paid_at' => now()->subMonths(2),
        ]);

        // Pending card refund initiated two months ago.
        $refund = app(RefundService::class)->issue(
            $invoice, 50.0, 'card', 'بانتظار البوابة', $this->admin->id, ['status' => 'pending']
        );
        $refund->update(['refunded_at' => now()->subMonths(2)]);

        // Close the month the refund was created in.
        $monthStart = now()->subMonths(2)->startOfMonth()->toDateString();
        $monthEnd   = now()->subMonths(2)->endOfMonth()->toDateString();
        AccountingPeriod::create([
            'branch_id' => $this->branch->id, 'name' => 'Refund month',
            'starts_on' => $monthStart, 'ends_on' => $monthEnd, 'status' => 'closed',
            'closed_at' => now(), 'closed_by' => $this->admin->id,
        ]);

        // Completing it must post at TODAY (open), not the frozen creation date.
        app(RefundService::class)->complete($refund->fresh(), $this->admin->id, 'REF-123');

        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame(50.0, (float) $invoice->fresh()->refunded_total);
        $this->assertSame(1, JournalEntry::where('source_type', Refund::class)
            ->where('source_id', $refund->id)->where('event_type', 'refund_completed')->count());
    }

    /** #5 — a duplicate manual-entry submit (same idempotency token) is rejected. */
    public function test_manual_entry_rejects_duplicate_submit(): void
    {
        $cash = \App\Models\Account::where('code', AccountingService::CASH)->firstOrFail();
        $rev  = \App\Models\Account::where('code', AccountingService::SALES_REVENUE)->firstOrFail();
        $token = 'idem-test-0001';

        $payload = [
            '_idem' => $token,
            'posted_on' => now()->toDateString(),
            'description' => 'قيد اختبار',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $rev->id,  'debit' => 0,  'credit' => 50],
            ],
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), $payload)
            ->assertSessionHas('success');

        // Same token again → bounced, no second entry.
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, JournalEntry::where('event_type', 'manual_journal')->count(),
            'A duplicate submit must not post a second manual entry.');
    }
}
