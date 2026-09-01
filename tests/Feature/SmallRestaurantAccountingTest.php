<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\JournalEntry;
use App\Models\Lookup;
use App\Models\Role;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Services\PurchaseOrderService;
use App\Services\SupplierInvoiceService;
use App\Support\BranchContext;
use App\Support\PaymentMethods;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmallRestaurantAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['code' => 'small-acct', 'name' => 'Small Restaurant', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        $this->seed(ExpenseCategorySeeder::class);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Accountant',
            'username' => 'small-accountant',
            'role' => 'admin',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true, 'joined_at' => now()]);

        $this->supplier = Supplier::create(['name' => 'USD Supplier', 'active' => true]);
        $this->supplier->branches()->attach($this->branch->id);

        Currency::where('code', 'USD')->update([
            'rate_to_base' => 3.7,
            'rate_updated_at' => now(),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_restaurant_chart_categories_and_direct_payment_defaults_are_ready(): void
    {
        $this->assertSame(['cash', 'transfer'], PaymentMethods::enabled());
        $this->assertSame(
            ['cash', 'transfer', 'card', 'palpay', 'jawwal_pay'],
            array_keys(PaymentMethods::catalog()),
        );

        foreach (['1040', '5050', '5300'] as $removedCode) {
            $this->assertDatabaseMissing('accounts', ['code' => $removedCode]);
        }
        $this->assertDatabaseHas('accounts', ['code' => AccountingService::PALPAY_WALLET, 'is_active' => true]);
        $this->assertDatabaseHas('accounts', ['code' => AccountingService::JAWWAL_PAY_WALLET, 'is_active' => true]);
        $this->assertArrayNotHasKey('wallet_clearing', AccountingService::postingRoleDefinitions());
        $this->assertArrayNotHasKey('customer_credit_clearing', AccountingService::postingRoleDefinitions());
        $this->assertArrayNotHasKey('bank_and_card_fees', AccountingService::postingRoleDefinitions());

        foreach (['2050', '5110', '5120', '5130', '5140', '5150', '5160', '5170', '5180', '5190'] as $code) {
            $this->assertDatabaseHas('accounts', ['code' => $code, 'is_active' => true]);
        }

        $rent = Lookup::where('group', 'expense_categories')->where('code', 'rent')->firstOrFail();
        $rentAccount = Account::where('code', '5110')->firstOrFail();
        $this->assertDatabaseHas('account_mappings', [
            'context' => AccountMapping::CONTEXT_EXPENSE_CATEGORY,
            'key' => AccountMapping::keyForLookup($rent),
            'account_id' => $rentAccount->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.settlements'))
            ->assertOk()
            ->assertDontSee('تسوية بوابة الدفع');
    }

    public function test_opening_customer_and_usd_supplier_debts_are_collectable_documents(): void
    {
        $customer = Customer::create([
            'name' => 'زبون قديم',
            'phone' => '0599000777',
            'password' => '123456',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.opening-balances.customer-debt.store'), [
                'customer_id' => $customer->id,
                'amount' => 150,
                'posted_on' => '2026-08-01',
                'description' => 'دين سابق للنظام',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $customerInvoice = $customer->invoices()->firstOrFail();
        $this->assertTrue($customerInvoice->is_opening_balance);
        $this->assertNotNull($customerInvoice->settled_on_account_at);
        $this->assertEqualsWithDelta(150, $customer->refresh()->outstandingDebt(), 0.01);

        $customerEntry = JournalEntry::where('event_type', 'customer_opening_debt')->with('lines.account')->firstOrFail();
        $this->assertLine($customerEntry, AccountingService::ACCOUNTS_RECEIVABLE, 'debit', 150);
        $this->assertLine($customerEntry, AccountingService::OPENING_BALANCE_EQUITY, 'credit', 150);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.accounting.opening-balances.supplier-debt.store'), [
                'supplier_id' => $this->supplier->id,
                'amount' => 100,
                'currency_code' => 'USD',
                'exchange_rate' => 3.7,
                'posted_on' => '2026-08-01',
                'reference' => 'OLD-USD-1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $supplierInvoice = SupplierInvoice::where('number', 'OLD-USD-1')->firstOrFail();
        $this->assertTrue($supplierInvoice->is_opening_balance);
        $this->assertSame('USD', $supplierInvoice->currency_code);

        $supplierEntry = JournalEntry::where('event_type', 'supplier_opening_debt')->with('lines.account')->firstOrFail();
        $this->assertLine($supplierEntry, AccountingService::ACCOUNTS_PAYABLE, 'credit', 370);
        $apLine = $supplierEntry->lines->first(fn ($line) => $line->account?->code === AccountingService::ACCOUNTS_PAYABLE);
        $this->assertEqualsWithDelta(100, (float) $apLine->foreign_credit, 0.0001);
    }

    public function test_usd_purchase_receipt_invoice_and_bank_payment_keep_base_cost_and_fx_effect(): void
    {
        $unit = Unit::create(['code' => 'pcs-usd', 'name' => 'قطعة', 'unit_type' => 'count', 'factor_to_base' => 1, 'is_base' => true]);
        $location = StorageLocation::create(['code' => 'usd-store', 'name' => 'مخزن الدولار', 'is_default' => true, 'active' => true]);
        $ingredient = Ingredient::create([
            'sku' => 'USD-ING',
            'name' => 'مادة مستوردة',
            'base_unit_id' => $unit->id,
            'current_stock' => 0,
            'cost_per_unit' => 0,
            'reorder_threshold' => 0,
            'track_stock' => true,
            'active' => true,
        ]);

        $po = app(PurchaseOrderService::class)->create([
            'supplier_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'exchange_rate' => 3.7,
        ], [[
            'ingredient_id' => $ingredient->id,
            'unit_id' => $unit->id,
            'quantity_ordered' => 10,
            'unit_price' => 2,
        ]], $this->admin->id);
        app(PurchaseOrderService::class)->approve($po, $this->admin->id);
        app(PurchaseOrderService::class)->send($po->fresh());
        app(PurchaseOrderService::class)->receive($po->fresh(), [$po->items->first()->id => 10], $this->admin->id, [
            $po->items->first()->id => ['storage_location_id' => $location->id],
        ]);

        $this->assertEqualsWithDelta(7.4, (float) $ingredient->fresh()->cost_per_unit, 0.0001);
        $receiptEntry = JournalEntry::where('event_type', 'inventory_goods_received')->with('lines.account')->firstOrFail();
        $this->assertLine($receiptEntry, AccountingService::INVENTORY, 'debit', 74);
        $this->assertLine($receiptEntry, AccountingService::GOODS_RECEIVED_NOT_INVOICED, 'credit', 74);

        $po = $po->fresh('items');
        $invoice = app(SupplierInvoiceService::class)->create([
            'number' => 'USD-STOCK-1',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'currency_code' => 'USD',
            'exchange_rate' => 3.8,
            'invoice_date' => '2026-08-02',
            'total' => 20,
            'lines' => [[
                'purchase_order_item_id' => $po->items->first()->id,
                'ingredient_id' => $ingredient->id,
                'unit_id' => $unit->id,
                'description' => $ingredient->name,
                'quantity' => 10,
                'unit_price' => 2,
                'tax_total' => 0,
            ]],
        ], $this->admin->id);

        $invoiceEntry = JournalEntry::where('event_type', 'supplier_invoice_created')->with('lines.account')->firstOrFail();
        $this->assertLine($invoiceEntry, AccountingService::GOODS_RECEIVED_NOT_INVOICED, 'debit', 74);
        $this->assertLine($invoiceEntry, AccountingService::PURCHASE_PRICE_VARIANCE, 'debit', 2);
        $this->assertLine($invoiceEntry, AccountingService::ACCOUNTS_PAYABLE, 'credit', 76);

        app(SupplierInvoiceService::class)->recordPayment($invoice->fresh(), [
            'amount' => 20,
            'method' => 'bank_transfer',
            'exchange_rate' => 3.9,
            'paid_on' => '2026-08-03',
        ], $this->admin->id);

        $paymentEntry = JournalEntry::where('event_type', 'supplier_payment_recorded')->with('lines.account')->firstOrFail();
        $this->assertLine($paymentEntry, AccountingService::BANK, 'credit', 78);
        $this->assertLine($paymentEntry, AccountingService::FOREIGN_EXCHANGE_LOSS, 'debit', 2);
        $this->assertEqualsWithDelta(76, (float) $paymentEntry->lines
            ->where(fn ($line) => $line->account?->code === AccountingService::ACCOUNTS_PAYABLE)
            ->sum(fn ($line) => (float) $line->debit - (float) $line->credit), 0.0001);
    }

    public function test_usd_operating_expense_posts_to_its_category_in_base_currency(): void
    {
        $rent = Lookup::where('group', 'expense_categories')->where('code', 'rent')->firstOrFail();

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.expenses.store'), [
                'expense_category_id' => $rent->id,
                'description' => 'إيجار بالدولار',
                'amount' => 100,
                'currency_code' => 'USD',
                'exchange_rate' => 3.7,
                'payment_method' => 'bank_transfer',
                'expense_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $expense = Expense::where('description', 'إيجار بالدولار')->firstOrFail();
        $this->assertSame('USD', $expense->currency_code);
        $this->assertEqualsWithDelta(370, $expense->baseAmount(), 0.0001);

        $this->actingAs($this->admin)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->post(route('admin.expenses.approve', $expense))
            ->assertRedirect();

        $entry = JournalEntry::where('event_type', 'expense_approved')->with('lines.account')->firstOrFail();
        $this->assertLine($entry, '5110', 'debit', 370);
        $this->assertLine($entry, AccountingService::BANK, 'credit', 370);

        $rentLine = $entry->lines->first(fn ($line) => $line->account?->code === '5110');
        $this->assertEqualsWithDelta(100, (float) $rentLine->foreign_debit, 0.0001);
    }

    private function assertLine(JournalEntry $entry, string $accountCode, string $side, float $expected): void
    {
        $actual = (float) $entry->lines
            ->where(fn ($line) => $line->account?->code === $accountCode)
            ->sum($side);

        $this->assertEqualsWithDelta($expected, $actual, 0.0001);
    }
}
