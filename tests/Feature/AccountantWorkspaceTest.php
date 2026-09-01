<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountantWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'acct-workspace',
            'name' => 'فرع المحاسبة',
            'is_active' => true,
        ]);
        BranchContext::set($this->branch->id);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->accountant = User::create([
            'name' => 'محاسب الفرع',
            'username' => 'branch-accountant',
            'role' => UserRole::Accountant->value,
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->accountant->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_accountant_role_has_a_complete_accounting_workspace(): void
    {
        $this->assertTrue($this->accountant->canAccessAdmin());
        $this->assertTrue($this->accountant->hasPermission('chart_of_accounts.viewAny'));
        $this->assertTrue($this->accountant->hasPermission('chart_of_accounts.create'));
        $this->assertTrue($this->accountant->hasPermission('chart_of_accounts.update'));

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.guide'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Guide')
                ->has('postingRoles')
                ->has('postingGroups', 4)
                ->where('postingGroups.0.key', 'sales')
                ->where('postingGroups.1.key', 'purchasing_inventory')
                ->has('paymentPaths', 5)
                ->has('workflow', 6)
                ->where('workflow.0.key', 'periods')
                ->where('workflow.1.key', 'chart')
                ->where('workflow.2.key', 'opening')
                ->has('accounting.baseCurrency')
                ->where('accounting.taxEnabled', false)
                ->where('urls.mappings', route('admin.accounting.mappings'))
            );
    }

    public function test_accounting_guide_uses_live_mappings_and_payment_destinations(): void
    {
        $response = $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.guide'))
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('postingGroups', function ($groups) {
                $entries = collect($groups)->flatMap(fn ($group) => $group['entries']);
                $invoice = $entries->firstWhere('eventType', 'invoice_issued');
                $credits = collect($invoice['credits']);

                return collect($invoice['debits'])->contains(fn ($line) => ($line['account']['code'] ?? null) === AccountingService::ACCOUNTS_RECEIVABLE)
                    && $credits->contains(fn ($line) => ($line['account']['code'] ?? null) === AccountingService::SALES_REVENUE)
                    && ! $credits->contains(fn ($line) => ($line['account']['code'] ?? null) === AccountingService::OUTPUT_VAT);
            })
            ->where('paymentPaths', function ($paths) {
                $paths = collect($paths)->keyBy('code');

                return $paths->get('cash')['account']['code'] === AccountingService::CASH
                    && $paths->get('transfer')['account']['code'] === AccountingService::BANK
                    && $paths->get('card')['account']['code'] === AccountingService::BANK
                    && $paths->get('palpay')['account']['code'] === AccountingService::PALPAY_WALLET
                    && $paths->get('jawwal_pay')['account']['code'] === AccountingService::JAWWAL_PAY_WALLET;
            })
        );
    }

    public function test_accounting_navigation_has_one_parent_for_daily_books_and_setup(): void
    {
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Index')
                ->where('urls.openingBalances', route('admin.accounting.opening-balances'))
                ->where('urls.accounts', route('admin.accounts.index'))
                ->where('shell.nav', fn ($nav) => collect($nav)->where('label', 'المحاسبة')->count() === 1)
            );
    }

    public function test_accountant_can_manage_financial_destination_identifiers_without_global_settings_access(): void
    {
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.mappings.store'), [
                'payment_identifiers' => [
                    'bank_name' => 'بنك المحاسب',
                    'bank_account_holder' => 'المطعم',
                    'bank_account_number' => '778899',
                    'bank_iban' => '',
                    'palpay_wallet_number' => '0592632026',
                    'jawwal_pay_wallet_number' => '',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('778899', Setting::get('bank_account_number'));
        $this->assertSame('0592632026', Setting::get('palpay_wallet_number'));
        $this->actingAs($this->accountant)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_opening_balance_screen_uses_simple_amount_rows_and_excludes_subledgers(): void
    {
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.opening-balances'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/OpeningBalances')
                ->has('accounts')
                ->has('customers')
                ->has('suppliers')
                ->where('accounts', fn ($accounts) => collect($accounts)->doesntContain('code', AccountingService::ACCOUNTS_RECEIVABLE)
                    && collect($accounts)->doesntContain('code', AccountingService::ACCOUNTS_PAYABLE)
                    && collect($accounts)->doesntContain('code', AccountingService::SALES_REVENUE))
            );

        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();
        $currency = Currency::base()?->code ?? 'ILS';

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.opening-balances.store'), [
                'posted_on' => '2026-08-01',
                'description' => 'افتتاح مبسط',
                'auto_balance' => 1,
                'opening_mode' => 'accounts',
                'balances' => [[
                    'account_id' => $cash->id,
                    'amount' => 250,
                    'side' => 'debit',
                    'currency_code' => $currency,
                    'exchange_rate' => 1,
                ]],
            ])
            ->assertRedirect(route('admin.accounting.opening-balances'))
            ->assertSessionHasNoErrors();

        $entry = JournalEntry::where('event_type', 'opening_balance')->with('lines.account')->firstOrFail();
        $this->assertEqualsWithDelta(250, (float) $entry->lines->firstWhere('account.code', AccountingService::CASH)?->debit, 0.01);
        $this->assertEqualsWithDelta(250, (float) $entry->lines->firstWhere('account.code', AccountingService::OPENING_BALANCE_EQUITY)?->credit, 0.01);
    }

    public function test_fiscal_year_can_create_link_edit_and_remove_empty_months(): void
    {
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.fiscal-years.store'), [
                'name' => 'السنة 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
                'notes' => 'سنة اختبار',
                'create_monthly_periods' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $year = FiscalYear::where('name', 'السنة 2027')->firstOrFail();
        $this->assertSame(12, $year->periods()->count());
        $this->assertSame(12, AccountingPeriod::where('fiscal_year_id', $year->id)->count());

        $firstPeriod = $year->periods()->firstOrFail();
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->put(route('admin.accounting.periods.update', $firstPeriod), [
                'name' => 'يناير 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-01-31',
                'fiscal_year_id' => $year->id,
                'notes' => 'تمت مراجعته شكلياً',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('يناير 2027', $firstPeriod->fresh()->name);

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.fiscal-years.close', $year))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertFalse($year->fresh()->isClosed(), 'A year with open linked months must not close.');

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->put(route('admin.accounting.fiscal-years.update', $year), [
                'name' => 'السنة المالية 2027',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-12-31',
                'notes' => 'اسم معدل',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->delete(route('admin.accounting.fiscal-years.destroy', $year))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('fiscal_years', ['id' => $year->id]);
        $this->assertDatabaseMissing('accounting_periods', ['fiscal_year_id' => $year->id]);
    }

    public function test_period_dates_are_locked_after_a_journal_exists(): void
    {
        $period = AccountingPeriod::create([
            'branch_id' => $this->branch->id,
            'name' => 'أغسطس 2026',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'status' => 'open',
        ]);

        app(AccountingService::class)->post(
            eventType: 'manual_journal',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-08-10',
            description: 'قيد اختبار',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 10, 'credit' => 0],
                ['account' => AccountingService::OPENING_BALANCE_EQUITY, 'debit' => 0, 'credit' => 10],
            ],
            createdBy: $this->accountant->id,
        );

        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->put(route('admin.accounting.periods.update', $period), [
                'name' => 'أغسطس معدل',
                'starts_on' => '2026-08-02',
                'ends_on' => '2026-08-31',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('2026-08-01', $period->fresh()->starts_on->toDateString());
    }

    public function test_account_ledger_shows_opening_movement_and_running_balance(): void
    {
        app(AccountingService::class)->post(
            eventType: 'manual_journal',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-07-31',
            description: 'رصيد قبل المدة',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 25, 'credit' => 0],
                ['account' => AccountingService::OPENING_BALANCE_EQUITY, 'debit' => 0, 'credit' => 25],
            ],
            createdBy: $this->accountant->id,
        );
        app(AccountingService::class)->post(
            eventType: 'manual_journal',
            source: null,
            branchId: $this->branch->id,
            postedOn: '2026-08-10',
            description: 'حركة داخل المدة',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => 10, 'credit' => 0],
                ['account' => AccountingService::OPENING_BALANCE_EQUITY, 'debit' => 0, 'credit' => 10],
            ],
            createdBy: $this->accountant->id,
        );

        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();
        $this->actingAs($this->accountant)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.ledger', [
                'account_id' => $cash->id,
                'from' => '2026-08-01',
                'to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Accounting/Ledger')
                ->where('summary.opening', 25)
                ->where('summary.closing', 35)
                ->has('lines.data', 1, fn (Assert $line) => $line
                    ->where('description', 'حركة داخل المدة')
                    ->where('runningBalance', 35)
                    ->etc())
            );
    }

    public function test_cashier_cannot_bypass_accounting_center_through_direct_ledger_urls(): void
    {
        Role::firstOrCreate(['name' => 'cashier'], ['label' => 'كاشير', 'is_system' => true]);
        $cashier = User::create([
            'name' => 'كاشير',
            'username' => 'ledger-cashier',
            'role' => 'cashier',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $cashier->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.journal'))
            ->assertForbidden();
        $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.trial-balance'))
            ->assertForbidden();
        $this->actingAs($cashier)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.accounting.ledger'))
            ->assertForbidden();
    }
}
