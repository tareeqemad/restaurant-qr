<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SupplierInvoiceIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $manager;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-15 10:00:00');

        $this->branch = Branch::create([
            'code' => 'supplier-filter',
            'name' => 'Supplier Filter Branch',
            'is_active' => true,
        ]);

        BranchContext::set($this->branch->id);

        $this->manager = User::create([
            'name' => 'Supplier Manager',
            'username' => 'supplier-manager',
            'role' => 'manager',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $this->manager->branches()->attach($this->branch->id, [
            'is_primary' => true,
            'joined_at' => now(),
        ]);

        $this->supplier = Supplier::create([
            'name' => 'Test Supplier',
            'active' => true,
        ]);
        $this->supplier->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_date_range_filters_supplier_invoices(): void
    {
        $this->invoice('INV-DUE-MAY', '2026-04-01', '2026-05-10');
        $this->invoice('INV-DUE-JUN', '2026-05-01', '2026-06-02');

        $response = $this->actingAs($this->manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.supplier-invoices.index', [
                'date_field' => 'due_date',
                'from' => '2026-05-01',
                'to' => '2026-05-31',
            ]));

        $response->assertOk();
        $response->assertSee('INV-DUE-MAY');
        $response->assertDontSee('INV-DUE-JUN');
    }

    public function test_reversed_from_to_dates_are_normalized(): void
    {
        $this->invoice('INV-SWAPPED-RANGE', '2026-04-01', '2026-05-10');
        $this->invoice('INV-OUTSIDE-RANGE', '2026-04-01', '2026-06-10');

        $response = $this->actingAs($this->manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.supplier-invoices.index', [
                'date_field' => 'due_date',
                'from' => '2026-05-31',
                'to' => '2026-05-01',
            ]));

        $response->assertOk();
        $response->assertSee('INV-SWAPPED-RANGE');
        $response->assertDontSee('INV-OUTSIDE-RANGE');
    }

    public function test_text_search_does_not_search_supplier_name(): void
    {
        $this->invoice('INV-NUMBER-ONLY', '2026-05-01', '2026-05-10');

        $response = $this->actingAs($this->manager)
            ->withSession(['active_branch_id' => $this->branch->id])
            ->get(route('admin.supplier-invoices.index', [
                'search' => 'Test Supplier',
            ]));

        $response->assertOk();
        $response->assertDontSee('INV-NUMBER-ONLY');
    }

    protected function invoice(string $number, string $invoiceDate, string $dueDate): SupplierInvoice
    {
        return SupplierInvoice::create([
            'number' => $number,
            'supplier_id' => $this->supplier->id,
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'paid_total' => 0,
            'balance' => 100,
            'status' => 'unpaid',
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'created_by' => $this->manager->id,
        ]);
    }
}
