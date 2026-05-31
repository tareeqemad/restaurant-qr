<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Expense;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\StorageLocation;
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
     * NOT the legacy 1020 (Card Clearing) account — which is now inactive
     * per the 2026_05_19_120000 migration.
     */
    public function test_card_payment_posts_directly_to_bank_account_not_clearing(): void
    {
        $invoice = Invoice::create([
            'branch_id'        => $this->branch->id,
            'table_session_id' => $this->session->id,
            'subtotal'         => 80,
            'tax_total'        => 0,
            'service_total'    => 0,
            'total'            => 80,
            'balance'          => 80,
            'status'           => 'issued',
            'issued_at'        => now(),
        ]);

        $payment = \App\Models\Payment::create([
            'branch_id'           => $this->branch->id,
            'invoice_id'          => $invoice->id,
            'method'              => 'card',
            'amount'              => 80,
            'received_by_user_id' => $this->cashier->id,
            'paid_at'             => now(),
        ]);

        $entry = app(AccountingService::class)->recordPaymentReceived($payment);

        $this->assertEntryBalances($entry->load('lines.account'));
        // The whole 80 should land on Bank 1010, not Card Clearing 1020.
        $this->assertLineAmount($entry, AccountingService::BANK, 'debit', 80);
        $cardClearingLine = $entry->lines->first(
            fn ($line) => $line->account?->code === AccountingService::CARD_CLEARING,
        );
        $this->assertNull($cardClearingLine,
            'Card payments must NOT touch the (now inactive) 1020 clearing account.');
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
        AccountMapping::create([
            'context' => AccountMapping::CONTEXT_PAYMENT_METHOD,
            'key' => 'card',
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

        $payment = \App\Models\Payment::create([
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
        AccountMapping::create([
            'context' => AccountMapping::CONTEXT_EXPENSE_CATEGORY,
            'key' => AccountMapping::keyForLookup($category),
            'account_id' => $utilities->id,
        ]);

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

    public function test_shift_cash_variance_creates_accounting_entry(): void
    {
        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->cashier->id,
            'cash_opening' => 100,
            'cash_closing' => 112,
            'expected_cash' => 100,
            'cash_variance' => 12,
            'status' => 'closed',
            'opened_at' => now()->subHours(2),
            'closed_at' => now(),
        ]);

        $entry = app(AccountingService::class)->recordShiftClosed($shift);

        $this->assertEntryBalances($entry->load('lines.account'));
        $this->assertLineAmount($entry, AccountingService::CASH, 'debit', 12);
        $this->assertLineAmount($entry, AccountingService::CASH_OVER_SHORT_INCOME, 'credit', 12);
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
        $cash = \App\Models\Account::where('code', AccountingService::CASH)->firstOrFail();

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
}
