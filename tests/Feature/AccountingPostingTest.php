<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\BillingService;
use App\Services\InventoryService;
use App\Services\Reports\ProfitLossReport;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPostingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $cashier;

    protected TableSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'acct-main',
            'name' => 'Accounting Main',
            'is_active' => true,
        ]);

        BranchContext::set($this->branch->id);

        $this->cashier = User::create([
            'name' => 'Accounting Cashier',
            'username' => 'accounting-cashier',
            'role' => 'cashier',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $this->cashier->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        $table = Table::create([
            'number' => 'A1',
            'capacity' => 2,
            'status' => 'occupied',
            'active' => true,
        ]);

        $this->session = TableSession::create([
            'table_id' => $table->id,
            'cover_count' => 2,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();

        parent::tearDown();
    }

    public function test_invoice_and_payment_create_balanced_journal_entries(): void
    {
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal' => 100,
            'discount_total' => 10,
            'tax_total' => 14.4,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 104.4,
            'balance' => 104.4,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);
        app(AccountingService::class)->recordInvoiceIssued($invoice);

        $this->assertSame(1, JournalEntry::where('event_type', 'invoice_issued')->count());
        $this->assertEntryBalances($entry->fresh('lines'));
        $this->assertDatabaseHas('journal_lines', ['debit' => 104.4, 'credit' => 0, 'branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('journal_lines', ['debit' => 10, 'credit' => 0, 'branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('journal_lines', ['debit' => 0, 'credit' => 100, 'branch_id' => $this->branch->id]);
        $this->assertDatabaseHas('journal_lines', ['debit' => 0, 'credit' => 14.4, 'branch_id' => $this->branch->id]);

        $payment = app(BillingService::class)->addPayment($invoice, 104.4, 'cash', $this->cashier->id);
        $paymentEntry = JournalEntry::where('source_type', $payment::class)
            ->where('source_id', $payment->id)
            ->where('event_type', 'payment_received')
            ->firstOrFail();

        $this->assertEntryBalances($paymentEntry->load('lines'));
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_posting_role_account_mapping_overrides_invoice_revenue_account(): void
    {
        $customRevenue = Account::create([
            'code' => '4999',
            'name' => 'Custom Food Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);
        AccountMapping::create([
            'context' => AccountMapping::CONTEXT_POSTING_ROLE,
            'key' => 'sales_revenue',
            'account_id' => $customRevenue->id,
        ]);

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 100,
            'balance' => 100,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);

        $this->assertEntryBalances($entry->load('lines.account'));
        $this->assertLineAmount($entry, '4999', 'credit', 100);
        $this->assertEqualsWithDelta(0.0, (float) $entry->lines
            ->first(fn ($line) => $line->account?->code === AccountingService::SALES_REVENUE)?->credit, 0.0001);
    }

    public function test_posting_role_mapping_with_wrong_type_falls_back_to_default_account(): void
    {
        $wrongTypeAccount = Account::create([
            'code' => '1999',
            'name' => 'Not Revenue',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        AccountMapping::create([
            'context' => AccountMapping::CONTEXT_POSTING_ROLE,
            'key' => 'sales_revenue',
            'account_id' => $wrongTypeAccount->id,
        ]);

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal' => 40,
            'tax_total' => 0,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 40,
            'balance' => 40,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordInvoiceIssued($invoice);

        $this->assertEntryBalances($entry->load('lines.account'));
        $this->assertLineAmount($entry, AccountingService::SALES_REVENUE, 'credit', 40);
        $this->assertEqualsWithDelta(0.0, (float) $entry->lines
            ->first(fn ($line) => $line->account?->code === '1999')?->credit, 0.0001);
    }

    public function test_inventory_movements_create_restaurant_accounting_entries(): void
    {
        $unit = Unit::create([
            'code' => 'pcs-test',
            'name' => 'قطعة',
            'unit_type' => 'count',
            'factor_to_base' => 1,
            'is_base' => true,
        ]);
        $location = StorageLocation::create([
            'branch_id' => $this->branch->id,
            'code' => 'acct-main-store',
            'name' => 'مخزن رئيسي',
            'is_default' => true,
            'active' => true,
        ]);
        $ingredient = Ingredient::create([
            'name' => 'مكون اختبار',
            'base_unit_id' => $unit->id,
            'current_stock' => 10,
            'cost_per_unit' => 3,
            'track_stock' => true,
            'active' => true,
        ]);
        IngredientStock::create([
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $location->id,
            'quantity' => 10,
            'reorder_threshold' => 0,
        ]);

        $category = Category::create([
            'branch_id' => $this->branch->id,
            'name' => 'قسم اختبار',
            'active' => true,
        ]);
        $menuItem = MenuItem::create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'name' => 'صنف اختبار',
            'price' => 15,
            'cost' => 3,
            'is_available' => true,
        ]);
        $order = Order::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'status' => 'approved',
            'subtotal' => 15,
            'total' => 15,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'menu_item_id' => $menuItem->id,
            'name_snapshot' => 'صنف اختبار',
            'quantity' => 2,
            'unit_price' => 15,
            'subtotal' => 30,
            'status' => 'approved',
        ]);

        app(InventoryService::class)->recordMovement(
            ingredient: $ingredient,
            type: 'out',
            qtyBase: 2,
            unitCost: 3,
            reference: $orderItem,
            reason: 'خصم طلب اختبار',
            userId: $this->cashier->id,
            storageLocationId: $location->id,
        );

        $cogsEntry = JournalEntry::where('event_type', 'inventory_cogs_recognized')->firstOrFail();
        $this->assertEntryBalances($cogsEntry->load('lines.account'));
        $this->assertLineAmount($cogsEntry, AccountingService::COST_OF_GOODS_SOLD, 'debit', 6);
        $this->assertLineAmount($cogsEntry, AccountingService::INVENTORY, 'credit', 6);

        app(InventoryService::class)->recordMovement(
            ingredient: $ingredient->fresh(),
            type: 'waste',
            qtyBase: 1,
            unitCost: 3,
            reason: 'تالف اختبار',
            userId: $this->cashier->id,
            storageLocationId: $location->id,
        );

        $wasteEntry = JournalEntry::where('event_type', 'inventory_waste_recognized')->firstOrFail();
        $this->assertEntryBalances($wasteEntry->load('lines.account'));
        $this->assertLineAmount($wasteEntry, AccountingService::WASTE_EXPENSE, 'debit', 3);
        $this->assertLineAmount($wasteEntry, AccountingService::INVENTORY, 'credit', 3);
    }

    /**
     * Visa/card payments settle instantly to the restaurant's bank account.
     * Confirms AccountingService routes method='card' to 1010 (Bank) and
     * NOT either wallet asset account.
     */
    public function test_card_payment_posts_directly_to_bank_account_not_clearing(): void
    {
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'subtotal' => 80,
            'tax_total' => 0,
            'service_total' => 0,
            'total' => 80,
            'balance' => 80,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'card',
            'amount' => 80,
            'received_by_user_id' => $this->cashier->id,
            'paid_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordPaymentReceived($payment);

        $this->assertEntryBalances($entry->load('lines.account'));
        // The whole 80 lands on Bank 1010, never on a wallet balance.
        $this->assertLineAmount($entry, AccountingService::BANK, 'debit', 80);
        $walletLine = $entry->lines->first(
            fn ($line) => in_array($line->account?->code, [AccountingService::PALPAY_WALLET, AccountingService::JAWWAL_PAY_WALLET], true),
        );
        $this->assertNull($walletLine, 'Card payments must not touch wallet balances.');
    }

    public function test_wallet_payment_stays_in_wallet_until_accountant_transfers_it(): void
    {
        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'subtotal' => 60,
            'tax_total' => 0,
            'service_total' => 0,
            'total' => 60,
            'balance' => 60,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'palpay',
            'amount' => 60,
            'received_by_user_id' => $this->cashier->id,
            'paid_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordPaymentReceived($payment)->load('lines.account');

        $this->assertEntryBalances($entry);
        $this->assertLineAmount($entry, AccountingService::PALPAY_WALLET, 'debit', 60);
        $bankLine = $entry->lines->first(fn ($line) => $line->account?->code === AccountingService::BANK);
        $this->assertNull($bankLine, 'Wallet receipts must not reach the bank before the accountant transfers them.');
    }

    public function test_payment_method_account_mapping_overrides_default_bank_account(): void
    {
        $visaDeposits = Account::create([
            'code' => '1099',
            'name' => 'Visa Deposits',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        AccountMapping::updateOrCreate([
            'context' => AccountMapping::CONTEXT_PAYMENT_METHOD,
            'key' => 'card',
        ], [
            'account_id' => $visaDeposits->id,
        ]);

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'subtotal' => 80,
            'tax_total' => 0,
            'service_total' => 0,
            'total' => 80,
            'balance' => 80,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'card',
            'amount' => 80,
            'received_by_user_id' => $this->cashier->id,
            'paid_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordPaymentReceived($payment);

        $this->assertEntryBalances($entry->load('lines.account'));
        $this->assertLineAmount($entry, '1099', 'debit', 80);
        $this->assertEqualsWithDelta(0.0, (float) $entry->lines
            ->first(fn ($line) => $line->account?->code === AccountingService::BANK)?->debit, 0.0001);
    }

    public function test_customer_payment_posts_foreign_exchange_gain_when_settlement_rate_increases(): void
    {
        $this->configureFxCurrencies();

        $invoice = Invoice::create([
            'branch_id' => $this->branch->id,
            'table_session_id' => $this->session->id,
            'issued_by_user_id' => $this->cashier->id,
            'subtotal' => 100,
            'tax_total' => 0,
            'service_total' => 0,
            'delivery_fee' => 0,
            'tip' => 0,
            'total' => 100,
            'balance' => 100,
            'status' => 'issued',
            'issued_at' => '2026-05-01 10:00:00',
        ]);
        app(AccountingService::class)->recordInvoiceIssued($invoice);

        $payment = Payment::create([
            'branch_id' => $this->branch->id,
            'invoice_id' => $invoice->id,
            'method' => 'cash',
            'amount' => 100,
            'received_by_user_id' => $this->cashier->id,
            'paid_at' => '2026-05-02 10:00:00',
        ]);

        $entry = app(AccountingService::class)->recordPaymentReceived($payment)->load('lines.account');

        $this->assertEntryBalances($entry);
        $this->assertAccountTotals($entry, AccountingService::CASH, 27, 0);
        $this->assertAccountTotals($entry, AccountingService::ACCOUNTS_RECEIVABLE, 2, 27);
        $this->assertAccountTotals($entry, AccountingService::FOREIGN_EXCHANGE_GAIN, 0, 2);
    }

    public function test_supplier_payment_posts_foreign_exchange_loss_when_settlement_rate_increases(): void
    {
        $this->configureFxCurrencies();

        $supplier = Supplier::create(['name' => 'FX Supplier']);
        $supplierInvoice = SupplierInvoice::create([
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'number' => 'SUP-FX-001',
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'paid_total' => 0,
            'balance' => 100,
            'status' => 'unpaid',
            'invoice_date' => '2026-05-01',
            'created_by' => $this->cashier->id,
        ]);
        app(AccountingService::class)->recordSupplierInvoiceCreated($supplierInvoice);

        $payment = SupplierPayment::create([
            'supplier_invoice_id' => $supplierInvoice->id,
            'amount' => 100,
            'method' => 'bank_transfer',
            'paid_on' => '2026-05-02',
            'paid_by' => $this->cashier->id,
        ]);

        $entry = app(AccountingService::class)->recordSupplierPayment($payment)->load('lines.account');

        $this->assertEntryBalances($entry);
        $this->assertAccountTotals($entry, AccountingService::BANK, 0, 27);
        $this->assertAccountTotals($entry, AccountingService::ACCOUNTS_PAYABLE, 27, 2);
        $this->assertAccountTotals($entry, AccountingService::FOREIGN_EXCHANGE_LOSS, 2, 0);
    }

    public function test_expense_category_account_mapping_overrides_default_operating_expense_account(): void
    {
        $category = Lookup::create([
            'group' => 'expense_categories',
            'code' => 'utilities',
            'label' => 'Utilities',
            'is_active' => true,
            'is_system' => true,
        ]);
        $utilities = Account::create([
            'code' => '5199',
            'name' => 'Utilities Expense',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        AccountMapping::updateOrCreate(
            [
                'context' => AccountMapping::CONTEXT_EXPENSE_CATEGORY,
                'key' => AccountMapping::keyForLookup($category),
            ],
            ['account_id' => $utilities->id],
        );

        $expense = Expense::create([
            'branch_id' => $this->branch->id,
            'expense_category_id' => $category->id,
            'description' => 'Electricity',
            'amount' => 45,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'status' => 'approved',
            'created_by_user_id' => $this->cashier->id,
            'approved_by_user_id' => $this->cashier->id,
            'approved_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordExpenseApproved($expense);

        $this->assertEntryBalances($entry->load('lines.account'));
        $this->assertLineAmount($entry, '5199', 'debit', 45);
        $this->assertLineAmount($entry, AccountingService::CASH, 'credit', 45);
        $this->assertEqualsWithDelta(0.0, (float) $entry->lines
            ->first(fn ($line) => $line->account?->code === AccountingService::OPERATING_EXPENSES)?->debit, 0.0001);
    }

    public function test_profit_loss_report_can_be_built_from_journal_ledger(): void
    {
        $date = now()->toDateString();
        $accounting = app(AccountingService::class);

        $accounting->post(
            eventType: 'ledger_report_revenue_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: $date,
            description: 'Ledger report revenue probe',
            lines: [
                ['account' => AccountingService::ACCOUNTS_RECEIVABLE, 'debit' => 90, 'credit' => 0],
                ['account' => AccountingService::SALES_DISCOUNTS, 'debit' => 10, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 100],
            ],
        );

        $accounting->post(
            eventType: 'ledger_report_cogs_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: $date,
            description: 'Ledger report COGS probe',
            lines: [
                ['account' => AccountingService::COST_OF_GOODS_SOLD, 'debit' => 30, 'credit' => 0],
                ['account' => AccountingService::INVENTORY, 'debit' => 0, 'credit' => 30],
            ],
        );

        $accounting->post(
            eventType: 'ledger_report_expense_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: $date,
            description: 'Ledger report expense probe',
            lines: [
                ['account' => AccountingService::OPERATING_EXPENSES, 'debit' => 12, 'credit' => 0],
                ['account' => AccountingService::CASH, 'debit' => 0, 'credit' => 12],
            ],
        );

        $report = (new ProfitLossReport($date, $date, $this->branch->id, false, 'ledger'))->compute();

        $this->assertSame('ledger', $report['period']['source']);
        $this->assertEqualsWithDelta(100.0, $report['sales']['gross_sales'], 0.01);
        $this->assertEqualsWithDelta(10.0, $report['sales']['discounts_total'], 0.01);
        $this->assertEqualsWithDelta(90.0, $report['sales']['net_sales'], 0.01);
        $this->assertEqualsWithDelta(30.0, $report['costs']['cogs'], 0.01);
        $this->assertEqualsWithDelta(12.0, $report['costs']['expenses'], 0.01);
        $this->assertEqualsWithDelta(48.0, $report['profit']['net_profit'], 0.01);
    }

    public function test_profit_loss_report_classifies_mapped_cogs_account_from_ledger(): void
    {
        $date = now()->toDateString();
        $mappedCogs = Account::create([
            'code' => '5099',
            'name' => 'Mapped COGS',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        AccountMapping::create([
            'context' => AccountMapping::CONTEXT_POSTING_ROLE,
            'key' => 'cost_of_goods_sold',
            'account_id' => $mappedCogs->id,
        ]);

        $accounting = app(AccountingService::class);
        $accounting->post(
            eventType: 'ledger_report_mapped_revenue_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: $date,
            description: 'Mapped ledger report revenue probe',
            lines: [
                ['account' => AccountingService::ACCOUNTS_RECEIVABLE, 'debit' => 100, 'credit' => 0],
                ['account' => AccountingService::SALES_REVENUE, 'debit' => 0, 'credit' => 100],
            ],
        );
        $accounting->post(
            eventType: 'ledger_report_mapped_cogs_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: $date,
            description: 'Mapped ledger report COGS probe',
            lines: [
                ['account' => '5099', 'debit' => 35, 'credit' => 0],
                ['account' => AccountingService::INVENTORY, 'debit' => 0, 'credit' => 35],
            ],
        );

        $report = (new ProfitLossReport($date, $date, $this->branch->id, false, 'ledger'))->compute();

        $this->assertEqualsWithDelta(35.0, $report['costs']['cogs'], 0.01);
        $this->assertEqualsWithDelta(0.0, $report['costs']['expenses'], 0.01);
        $this->assertEqualsWithDelta(65.0, $report['profit']['net_profit'], 0.01);
    }

    public function test_accounting_service_rejects_negative_journal_line_amounts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/سالبة|negative/u');

        app(AccountingService::class)->post(
            eventType: 'negative_line_probe',
            source: null,
            branchId: $this->branch->id,
            postedOn: now(),
            description: 'negative line probe',
            lines: [
                ['account' => AccountingService::CASH, 'debit' => -10, 'credit' => 0],
                ['account' => AccountingService::BANK, 'debit' => 0, 'credit' => 10],
            ],
        );
    }

    public function test_journal_line_model_rejects_two_sided_lines(): void
    {
        $entry = JournalEntry::create([
            'branch_id' => $this->branch->id,
            'posted_on' => now()->toDateString(),
            'description' => 'two-sided line probe',
            'status' => 'posted',
        ]);
        $cash = Account::where('code', AccountingService::CASH)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/مدينا ودائنا|both debit and credit/u');

        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'branch_id' => $this->branch->id,
            'line_no' => 1,
            'debit' => 10,
            'credit' => 10,
        ]);
    }

    private function assertEntryBalances(JournalEntry $entry): void
    {
        $debit = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('debit');
        $credit = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('credit');

        $this->assertEqualsWithDelta($debit, $credit, 0.0001);
        $this->assertGreaterThan(0, $debit);
    }

    private function assertLineAmount(JournalEntry $entry, string $accountCode, string $side, float $expected): void
    {
        $actual = (float) $entry->lines
            ->first(fn ($line) => $line->account?->code === $accountCode)?->{$side};

        $this->assertEqualsWithDelta($expected, $actual, 0.0001);
    }

    private function assertAccountTotals(JournalEntry $entry, string $accountCode, float $expectedDebit, float $expectedCredit): void
    {
        $lines = $entry->lines->filter(fn ($line) => $line->account?->code === $accountCode);

        $this->assertEqualsWithDelta($expectedDebit, (float) $lines->sum('debit'), 0.0001);
        $this->assertEqualsWithDelta($expectedCredit, (float) $lines->sum('credit'), 0.0001);
    }

    private function configureFxCurrencies(): void
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
            'rate_to_base' => 0.27,
            'is_base' => false,
            'is_active' => true,
        ]);

        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.25,
            'valid_from' => '2026-05-01',
            'valid_to' => '2026-05-01',
            'is_active' => true,
            'source' => 'test',
        ]);
        CurrencyExchangeRate::create([
            'currency_code' => 'ILS',
            'base_currency_code' => 'USD',
            'rate' => 0.27,
            'valid_from' => '2026-05-02',
            'valid_to' => '2026-05-02',
            'is_active' => true,
            'source' => 'test',
        ]);
    }
}
