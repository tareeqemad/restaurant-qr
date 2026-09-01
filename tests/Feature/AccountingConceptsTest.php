<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\CashReconciliation;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingConceptsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'acct-concepts', 'name' => 'Accounting Concepts', 'is_active' => true]);
        BranchContext::set($this->branch->id);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Accounting Admin',
            'username' => 'accounting-admin',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_opening_balances_auto_balance_to_opening_equity(): void
    {
        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.opening-balances.store'), [
                'posted_on' => now()->toDateString(),
                'description' => 'Opening balances',
                'auto_balance' => 1,
                'lines' => [
                    ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                ],
            ])
            ->assertRedirect(route('admin.accounting.opening-balances'));

        $entry = JournalEntry::where('event_type', 'opening_balance')->with('lines.account')->firstOrFail();

        $this->assertEqualsWithDelta(100, (float) $entry->lines->first(fn ($line) => $line->account?->code === AccountingService::CASH)?->debit, 0.01);
        $this->assertEqualsWithDelta(100, (float) $entry->lines->first(fn ($line) => $line->account?->code === AccountingService::OPENING_BALANCE_EQUITY)?->credit, 0.01);
    }

    public function test_opening_balance_keeps_foreign_amount_and_posts_base_currency_value(): void
    {
        Setting::put('accounting_base_currency', 'ILS', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '₪', 'accounting', 'string');

        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'Shekel',
            'symbol' => '₪',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 3.7,
            'is_base' => false,
            'is_active' => true,
        ]);

        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.opening-balances.store'), [
                'posted_on' => now()->toDateString(),
                'description' => 'USD capital opening',
                'auto_balance' => 1,
                'lines' => [
                    [
                        'account_id' => $cash->id,
                        'foreign_debit' => 100000,
                        'foreign_credit' => 0,
                        'currency_code' => 'USD',
                        'exchange_rate' => 3.7,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.accounting.opening-balances'));

        $line = JournalEntry::where('event_type', 'opening_balance')
            ->with('lines.account')
            ->firstOrFail()
            ->lines
            ->first(fn ($line) => $line->account?->code === AccountingService::CASH);

        $this->assertSame('USD', $line->currency_code);
        $this->assertEqualsWithDelta(100000, (float) $line->foreign_debit, 0.01);
        $this->assertEqualsWithDelta(370000, (float) $line->debit, 0.01);
    }

    public function test_operational_posting_converts_sales_currency_to_accounting_base_currency(): void
    {
        Setting::put('accounting_base_currency', 'USD', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '$', 'accounting', 'string');
        Setting::put('sales_currency', 'ILS', 'billing', 'string');
        Setting::put('sales_to_accounting_rate', 0.27, 'accounting', 'float');

        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'Shekel',
            'symbol' => '₪',
            'rate_to_base' => 0.27,
            'is_base' => false,
            'is_active' => true,
        ]);

        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.27,
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->toDateString(),
            'is_active' => true,
            'source' => 'test',
        ]);

        $entry = app(AccountingService::class)->post(
            eventType: 'currency_conversion_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now(),
            description: 'Currency conversion probe',
            lines: [
                ['account' => AccountingService::CASH, 'currency_code' => 'ILS', 'foreign_debit' => 100, 'foreign_credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'currency_code' => 'ILS', 'foreign_debit' => 0, 'foreign_credit' => 100],
            ],
            createdBy: $this->admin->id,
        );
        $cashLine = $entry->lines->first(fn ($line) => $line->account?->code === AccountingService::CASH);

        $this->assertSame('USD', $entry->base_currency_code);
        $this->assertSame('ILS', $cashLine->currency_code);
        $this->assertEqualsWithDelta(100, (float) $cashLine->foreign_debit, 0.01);
        $this->assertEqualsWithDelta(27, (float) $cashLine->debit, 0.01);
    }

    public function test_exchange_rate_history_uses_daily_override_inside_period(): void
    {
        Setting::put('accounting_base_currency', 'USD', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '$', 'accounting', 'string');
        Setting::put('sales_currency', 'ILS', 'billing', 'string');

        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'Shekel',
            'symbol' => '₪',
            'rate_to_base' => 0.27,
            'is_base' => false,
            'is_active' => true,
        ]);
        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.27,
            'valid_from' => '2026-05-01',
            'valid_to' => '2026-05-31',
            'is_active' => true,
            'source' => 'monthly',
        ]);
        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.28,
            'valid_from' => '2026-05-15',
            'valid_to' => '2026-05-15',
            'is_active' => true,
            'source' => 'daily_update',
        ]);

        $entry = app(AccountingService::class)->post(
            eventType: 'daily_rate_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-05-15 18:00:00',
            description: 'Daily rate probe',
            lines: [
                ['account' => AccountingService::CASH, 'currency_code' => 'ILS', 'foreign_debit' => 100, 'foreign_credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'currency_code' => 'ILS', 'foreign_debit' => 0, 'foreign_credit' => 100],
            ],
            createdBy: $this->admin->id,
        );
        $cashLine = $entry->lines->first(fn ($line) => $line->account?->code === AccountingService::CASH);

        $this->assertEqualsWithDelta(0.28, (float) $cashLine->exchange_rate, 0.000001);
        $this->assertEqualsWithDelta(28, (float) $cashLine->debit, 0.01);
    }

    public function test_operational_posting_requires_exchange_rate_for_posting_date(): void
    {
        Setting::put('accounting_base_currency', 'USD', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '$', 'accounting', 'string');
        Setting::put('sales_currency', 'ILS', 'billing', 'string');

        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'Shekel',
            'symbol' => '₪',
            'rate_to_base' => 0.27,
            'is_base' => false,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يوجد سعر صرف صالح');

        app(AccountingService::class)->post(
            eventType: 'missing_rate_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-06-01 18:00:00',
            description: 'Missing rate probe',
            lines: [
                ['account' => AccountingService::CASH, 'currency_code' => 'ILS', 'foreign_debit' => 100, 'foreign_credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'currency_code' => 'ILS', 'foreign_debit' => 0, 'foreign_credit' => 100],
            ],
            createdBy: $this->admin->id,
        );
    }

    public function test_closed_period_blocks_new_manual_journal_posting(): void
    {
        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id,
            'name' => 'Closed May',
            'starts_on' => '2026-05-01',
            'ends_on' => '2026-05-31',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $period))
            ->assertRedirect();

        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();
        $bank = Account::where('code', AccountingService::BANK)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.manual-entry.store'), [
                'posted_on' => '2026-05-15',
                'description' => 'Should be blocked',
                'lines' => [
                    ['account_id' => $bank->id, 'debit' => 10, 'credit' => 0],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => 10],
                ],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, JournalEntry::where('event_type', 'manual_journal')->count());
    }

    public function test_period_name_is_generated_from_start_date_when_omitted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.store'), [
                'starts_on' => '2026-09-01',
                'ends_on' => '2026-09-30',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('accounting_periods', [
            'branch_id' => $this->branch->id,
            'name' => 'شهر 09/2026',
            'starts_on' => '2026-09-01 00:00:00',
            'ends_on' => '2026-09-30 00:00:00',
        ]);
    }

    public function test_closing_period_locks_posting_without_zeroing_nominal_accounts(): void
    {
        app(AccountingService::class)->post(
            eventType: 'closing_revenue_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-05-15',
            description: 'Closing revenue probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 100, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 100],
            ],
            createdBy: $this->admin->id,
        );

        app(AccountingService::class)->post(
            eventType: 'closing_expense_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-05-20',
            description: 'Closing expense probe',
            lines: [
                ['account' => AccountingService::OPERATING_EXPENSES, 'debit' => 35, 'credit' => 0],
                ['account' => AccountingService::CASH, 'debit' => 0, 'credit' => 35],
            ],
            createdBy: $this->admin->id,
        );

        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id,
            'name' => 'May 2026',
            'starts_on' => '2026-05-01',
            'ends_on' => '2026-05-31',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $period))
            ->assertRedirect()
            ->assertSessionHas('success');

        $period->refresh();
        $this->assertSame('closed', $period->status);
        $this->assertNull($period->closing_journal_entry_id);
        $this->assertSame(0, JournalEntry::where('event_type', 'period_closing')->count());

        $this->assertEqualsWithDelta(-100, $this->ledgerDebitMinusCredit(AccountingService::SALES_REVENUE, '2026-05-01', '2026-05-31'), 0.01);
        $this->assertEqualsWithDelta(35, $this->ledgerDebitMinusCredit(AccountingService::OPERATING_EXPENSES, '2026-05-01', '2026-05-31'), 0.01);
        $this->assertEqualsWithDelta(0, $this->ledgerDebitMinusCredit(AccountingService::RETAINED_EARNINGS, '2026-05-01', '2026-05-31'), 0.01);
    }

    public function test_reopening_period_unlocks_posting_without_a_reversal_entry(): void
    {
        app(AccountingService::class)->post(
            eventType: 'reopen_closing_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-06-10',
            description: 'Reopen closing probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 40, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 40],
            ],
            createdBy: $this->admin->id,
        );

        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id,
            'name' => 'June 2026',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-30',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $period))
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.reopen', $period))
            ->assertRedirect()
            ->assertSessionHas('success');

        $period->refresh();
        $this->assertSame('open', $period->status);
        $this->assertNull($period->closing_journal_entry_id);

        $this->assertSame(0, JournalEntry::where('event_type', 'period_closing_reversal')->count());
        $this->assertEqualsWithDelta(-40, $this->ledgerDebitMinusCredit(AccountingService::SALES_REVENUE, '2026-06-01', '2026-06-30'), 0.01);
    }

    public function test_fiscal_year_closing_posts_year_entry_and_blocks_future_posting_inside_year(): void
    {
        app(AccountingService::class)->post(
            eventType: 'fiscal_year_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-07-15',
            description: 'Fiscal year probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 120, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 120],
            ],
            createdBy: $this->admin->id,
        );

        $year = FiscalYear::create([
            'branch_id' => $this->branch->id,
            'name' => 'FY 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.fiscal-years.close', $year))
            ->assertRedirect()
            ->assertSessionHas('success');

        $year->refresh();
        $this->assertSame('closed', $year->status);
        $this->assertNotNull($year->closing_journal_entry_id);

        $closing = JournalEntry::findOrFail($year->closing_journal_entry_id);
        $this->assertSame('fiscal_year_closing', $closing->event_type);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('السنة المالية FY 2026 مقفلة');

        app(AccountingService::class)->post(
            eventType: 'blocked_by_fiscal_year',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-08-01',
            description: 'Blocked by fiscal year',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 5, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 5],
            ],
            createdBy: $this->admin->id,
        );
    }

    public function test_closing_checklist_blocks_active_table_session_before_period_close(): void
    {
        $table = Table::create([
            'number' => 'CLOSE-1',
            'capacity' => 4,
            'status' => 'occupied',
            'active' => true,
        ]);
        TableSession::create([
            'table_id' => $table->id,
            'status' => 'active',
            'opened_at' => '2026-09-10 09:00:00',
        ]);

        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id,
            'name' => 'September 2026',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.periods.close', $period))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('open', $period->refresh()->status);
    }

    public function test_balance_sheet_includes_current_earnings_from_ledger(): void
    {
        app(AccountingService::class)->post(
            eventType: 'balance_sheet_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->toDateString(),
            description: 'Balance sheet probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 75, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 75],
            ],
            createdBy: $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.balance-sheet', ['as_of' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/BalanceSheet')
                ->where('metrics.currentEarnings', 75)
                ->where('metrics.totalAssets', 75)
                ->where('metrics.balanced', true)
            );
    }

    public function test_reconciliation_records_book_statement_difference(): void
    {
        app(AccountingService::class)->post(
            eventType: 'reconciliation_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now()->toDateString(),
            description: 'Reconciliation probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 100, 'credit' => 0],
                ['account' => AccountingService::OPENING_BALANCE_EQUITY, 'debit' => 0, 'credit' => 100],
            ],
            createdBy: $this->admin->id,
        );

        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.reconciliations.store'), [
                'account_id' => $cash->id,
                'statement_date' => now()->toDateString(),
                'statement_balance' => 96,
            ])
            ->assertRedirect();

        $reconciliation = CashReconciliation::firstOrFail();
        $this->assertEqualsWithDelta(100, (float) $reconciliation->book_balance, 0.01);
        $this->assertEqualsWithDelta(-4, (float) $reconciliation->difference, 0.01);
        $this->assertSame('variance', $reconciliation->status);

        $expense = Account::where('code', AccountingService::OPERATING_EXPENSES)->firstOrFail();
        $this->actingAs($this->admin)
            ->post(route('admin.accounting.reconciliations.resolve', $reconciliation), [
                'adjustment_account_id' => $expense->id,
                'posted_on' => now()->toDateString(),
                'notes' => 'عجز مثبت بعد مراجعة عدّ الصندوق وإيصالات الوردية',
            ])->assertRedirect();

        $reconciliation->refresh();
        $this->assertSame('resolved', $reconciliation->status);
        $this->assertNotNull($reconciliation->resolution_journal_entry_id);
        $entry = JournalEntry::findOrFail($reconciliation->resolution_journal_entry_id);
        $this->assertSame('reconciliation_adjustment', $entry->event_type);
        $this->assertEntryAccountTotals($entry, AccountingService::OPERATING_EXPENSES, 4, 0);
        $this->assertEntryAccountTotals($entry, AccountingService::CASH, 0, 4);
    }

    public function test_accounting_settlement_forms_post_tax_and_tips_without_payment_clearing(): void
    {
        $accounting = app(AccountingService::class);

        $accounting->post(
            eventType: 'tax_settlement_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-05-20',
            description: 'Tax settlement probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 70, 'credit' => 0],
                ['account' => AccountingService::INPUT_VAT, 'debit' => 30, 'credit' => 0],
                ['account' => AccountingService::OUTPUT_VAT, 'debit' => 0, 'credit' => 100],
            ],
            createdBy: $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.settlements', [
                'from' => '2026-05-01',
                'to' => '2026-05-31',
                'as_of' => '2026-06-02',
            ]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.settlements.tax-payment'), [
                'from' => '2026-05-01',
                'to' => '2026-05-31',
                'posted_on' => '2026-06-01',
                'payment_method' => 'transfer',
            ])
            ->assertRedirect(route('admin.accounting.journal', ['event_type' => 'tax_payment']));

        $taxEntry = JournalEntry::where('event_type', 'tax_payment')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($taxEntry, AccountingService::OUTPUT_VAT, 100, 0);
        $this->assertEntryAccountTotals($taxEntry, AccountingService::INPUT_VAT, 0, 30);
        $this->assertEntryAccountTotals($taxEntry, AccountingService::BANK, 0, 70);

        $accounting->post(
            eventType: 'tips_settlement_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-06-02',
            description: 'Tips settlement probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 12, 'credit' => 0],
                ['account' => AccountingService::TIPS_PAYABLE, 'debit' => 0, 'credit' => 12],
            ],
            createdBy: $this->admin->id,
        );

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.settlements.tips-payout'), [
                'posted_on' => '2026-06-02',
                'amount' => 12,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('admin.accounting.journal', ['event_type' => 'tips_payout']));

        $tipsEntry = JournalEntry::where('event_type', 'tips_payout')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($tipsEntry, AccountingService::TIPS_PAYABLE, 12, 0);
        $this->assertEntryAccountTotals($tipsEntry, AccountingService::CASH, 0, 12);

        $this->assertFalse(Route::has('admin.accounting.settlements.payment-clearing'));
        $this->assertDatabaseHas('accounts', ['code' => AccountingService::PALPAY_WALLET, 'is_active' => true]);
        $this->assertDatabaseHas('accounts', ['code' => AccountingService::JAWWAL_PAY_WALLET, 'is_active' => true]);
        $this->assertDatabaseMissing('accounts', ['code' => '1040']);
    }

    public function test_accountant_can_transfer_wallet_balance_to_bank_without_overdrawing_it(): void
    {
        $accounting = app(AccountingService::class);

        $accounting->post(
            eventType: 'wallet_balance_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-06-03',
            description: 'PalPay balance probe',
            lines: [
                ['account' => AccountingService::PALPAY_WALLET, 'debit' => 50, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 50],
            ],
            createdBy: $this->admin->id,
        );

        $settlementsUrl = route('admin.accounting.settlements', ['as_of' => '2026-06-03']);

        $this->actingAs($this->admin)
            ->get($settlementsUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Settlements')
                ->has('wallets', 2)
                ->where('wallets.0.method', 'palpay')
                ->where('wallets.0.balance', 50)
                ->where('wallets.0.transferable', true)
                ->where('urls.walletTransfer', route('admin.accounting.settlements.wallet-transfer'))
            );

        $this->actingAs($this->admin)
            ->post(route('admin.accounting.settlements.wallet-transfer'), [
                'wallet_method' => 'palpay',
                'posted_on' => '2026-06-03',
                'amount' => 30,
                'notes' => 'PalPay transfer reference 17',
            ])
            ->assertRedirect($settlementsUrl);

        $entry = JournalEntry::where('event_type', 'wallet_to_bank')->with('lines.account')->firstOrFail();
        $this->assertEntryAccountTotals($entry, AccountingService::BANK, 30, 0);
        $this->assertEntryAccountTotals($entry, AccountingService::PALPAY_WALLET, 0, 30);
        $this->assertEqualsWithDelta(20, $accounting->availableWalletBalance('palpay', $this->branch->id, '2026-06-03'), 0.01);

        $this->actingAs($this->admin)
            ->from($settlementsUrl)
            ->post(route('admin.accounting.settlements.wallet-transfer'), [
                'wallet_method' => 'palpay',
                'posted_on' => '2026-06-03',
                'amount' => 21,
            ])
            ->assertRedirect($settlementsUrl)
            ->assertSessionHas('error');

        $this->assertSame(1, JournalEntry::where('event_type', 'wallet_to_bank')->count());
        $this->assertEqualsWithDelta(20, $accounting->availableWalletBalance('palpay', $this->branch->id, '2026-06-03'), 0.01);
    }

    public function test_settlements_screen_stays_available_when_an_old_database_has_no_wallet_account(): void
    {
        $wallet = Account::query()->where('code', AccountingService::PALPAY_WALLET)->firstOrFail();
        DB::table('account_mappings')->where('account_id', $wallet->id)->delete();
        $wallet->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.settlements', ['as_of' => '2026-06-03']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Settlements')
                ->where('wallets.0.method', 'palpay')
                ->where('wallets.0.balance', 0)
                ->where('wallets.0.configured', false)
                ->where('wallets.0.transferable', false)
            );
    }

    public function test_aging_report_lists_open_customer_and_supplier_balances(): void
    {
        Setting::put('accounting_base_currency', 'USD', 'accounting', 'string');
        Setting::put('accounting_currency_symbol', '$', 'accounting', 'string');
        Setting::put('sales_currency', 'ILS', 'billing', 'string');

        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar',
            'symbol' => '$',
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        Currency::updateOrCreate(['code' => 'ILS'], [
            'name' => 'Shekel',
            'symbol' => 'ILS',
            'rate_to_base' => 0.25,
            'is_base' => false,
            'is_active' => true,
        ]);
        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.25,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => null,
            'is_active' => true,
            'source' => 'test',
        ]);

        $table = Table::create(['number' => 'A1', 'capacity' => 2, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create(['table_id' => $table->id, 'cover_count' => 2, 'status' => 'active']);

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $session->id,
            'customer_name' => 'Open Customer',
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
            'status' => 'issued',
            'issued_at' => now()->subDays(40),
        ]);
        app(AccountingService::class)->recordInvoiceIssued($invoice);

        $supplier = Supplier::create(['name' => 'Open Supplier']);
        $supplierInvoice = SupplierInvoice::create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'SUP-OPEN',
            'subtotal' => 80,
            'total' => 80,
            'balance' => 80,
            'status' => 'unpaid',
            'invoice_date' => now()->subDays(10)->toDateString(),
        ]);
        app(AccountingService::class)->recordSupplierInvoiceCreated($supplierInvoice);

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.aging', ['as_of' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Aging')
                ->has('arRows', 1)
                ->where('arRows.0.party', 'Open Customer')
                ->where('arRows.0.amount', 25)
                ->has('apRows', 1)
                ->where('apRows.0.party', 'Open Supplier')
                ->where('apRows.0.amount', 20)
            );
    }

    private function assertEntryAccountTotals(JournalEntry $entry, string $accountCode, float $expectedDebit, float $expectedCredit): void
    {
        $lines = $entry->lines->filter(fn ($line) => $line->account?->code === $accountCode);

        $this->assertEqualsWithDelta($expectedDebit, (float) $lines->sum('debit'), 0.01);
        $this->assertEqualsWithDelta($expectedCredit, (float) $lines->sum('credit'), 0.01);
    }

    private function ledgerDebitMinusCredit(string $accountCode, string $from, string $to): float
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.branch_id', $this->branch->id)
            ->where('journal_entries.status', 'posted')
            ->where('accounts.code', $accountCode)
            ->whereDate('journal_entries.posted_on', '>=', $from)
            ->whereDate('journal_entries.posted_on', '<=', $to)
            ->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as net')
            ->first();

        return (float) ($row->net ?? 0);
    }
}
