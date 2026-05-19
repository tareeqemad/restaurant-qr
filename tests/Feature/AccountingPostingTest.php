<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
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
