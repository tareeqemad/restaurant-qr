<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Refund;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 audit's document-number-generator family:
 * every `number` column carries a GLOBAL unique index, but the old
 * generators computed their daily sequence per-branch (BranchScope) and
 * blind to soft-deleted rows. Consequences before the fix:
 *
 *   - branch B's FIRST document of the day collided with branch A's
 *     ...-0001 → unique-index violation → every subsequent attempt that
 *     day failed too (a full-day billing outage for the second branch);
 *   - soft-deleting the day's last document made COUNT+1 / last-id+1
 *     reissue the tombstone's number even single-branch.
 *
 * The fix (ported verbatim from Order::generateNumber): unscoped +
 * trashed-inclusive MAX on the day prefix, plus an existence-bump loop.
 */
class DocumentNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::create(['code' => 'dng-a', 'name' => 'DNG A', 'is_active' => true]);
        $this->branchB = Branch::create(['code' => 'dng-b', 'name' => 'DNG B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /** Minimal invoice in the ACTIVE branch context (direct-order origin). */
    private function makeInvoice(): Invoice
    {
        $order = Order::create(['order_type' => 'takeaway']);

        return Invoice::create(['order_id' => $order->id, 'status' => 'issued']);
    }

    /** Mirrors RefundService::issue — branch pinned from the invoice, number from the generator. */
    private function makeRefund(): Refund
    {
        $invoice = $this->makeInvoice();

        return Refund::create([
            'branch_id'  => $invoice->branch_id,
            'number'     => Refund::generateNumber(),
            'invoice_id' => $invoice->id,
            'amount'     => 10,
            'method'     => 'cash',
            'status'     => 'completed',
            'reason'     => 'مرتجع اختبار',
        ]);
    }

    /** Mirrors PurchaseOrderService — a PO plus its GRN in the active branch context. */
    private function makeProcurementPair(Supplier $supplier): array
    {
        $po = PurchaseOrder::create([
            'number'      => PurchaseOrder::generateNumber(),
            'supplier_id' => $supplier->id,
            'status'      => 'draft',
        ]);

        $grn = PurchaseReceipt::create([
            'number'            => PurchaseReceipt::generateNumber(),
            'purchase_order_id' => $po->id,
            'supplier_id'       => $supplier->id,
            'received_at'       => now(),
        ]);

        return [$po, $grn];
    }

    // ─── Invoice ────────────────────────────────────────────────────────

    /** Branch B's first invoice of the day must CONTINUE the global sequence,
     *  not restart at 0001 and hit the global unique index. */
    public function test_same_day_invoices_from_two_branches_get_distinct_numbers(): void
    {
        $today = now()->format('Ymd');

        $invA = BranchContext::forBranch($this->branchA->id, fn () => $this->makeInvoice());
        $invB = BranchContext::forBranch($this->branchB->id, fn () => $this->makeInvoice());

        $this->assertSame("INV-{$today}-0001", $invA->number);
        $this->assertSame("INV-{$today}-0002", $invB->number,
            'Branch-scoped COUNT+1 would mint INV-…-0001 again and blow up on the unique index.');
        $this->assertNotSame($invA->number, $invB->number);
    }

    /** A soft-deleted invoice still occupies its number — the next invoice
     *  must skip past the tombstone, not reissue it. */
    public function test_invoice_number_of_a_soft_deleted_invoice_is_never_reissued(): void
    {
        $today = now()->format('Ymd');

        BranchContext::forBranch($this->branchA->id, function () use ($today) {
            $first = $this->makeInvoice();
            $this->assertSame("INV-{$today}-0001", $first->number);

            $first->delete();   // soft delete — the row (and its number) remain in the table

            $second = $this->makeInvoice();
            $this->assertSame("INV-{$today}-0002", $second->number,
                'Trashed-blind COUNT+1 would reissue -0001 here and violate the unique index.');
        });
    }

    // ─── Refund ─────────────────────────────────────────────────────────

    public function test_same_day_refunds_from_two_branches_get_distinct_numbers(): void
    {
        $today = now()->format('Ymd');

        $refA = BranchContext::forBranch($this->branchA->id, fn () => $this->makeRefund());
        $refB = BranchContext::forBranch($this->branchB->id, fn () => $this->makeRefund());

        $this->assertSame("REF-{$today}-0001", $refA->number);
        $this->assertSame("REF-{$today}-0002", $refB->number);
        $this->assertNotSame($refA->number, $refB->number);
    }

    // ─── Purchase order + goods receipt (GRN) ───────────────────────────

    public function test_same_day_purchase_documents_from_two_branches_get_distinct_numbers(): void
    {
        $today = now()->format('Ymd');
        $supplier = Supplier::create(['name' => 'مورد الاختبار', 'active' => true]);

        [$poA, $grnA] = BranchContext::forBranch($this->branchA->id, fn () => $this->makeProcurementPair($supplier));
        [$poB, $grnB] = BranchContext::forBranch($this->branchB->id, fn () => $this->makeProcurementPair($supplier));

        $this->assertSame("PO-{$today}-0001", $poA->number);
        $this->assertSame("PO-{$today}-0002", $poB->number);
        $this->assertSame("GRN-{$today}-0001", $grnA->number);
        $this->assertSame("GRN-{$today}-0002", $grnB->number);
    }

    // ─── Stock count ────────────────────────────────────────────────────

    public function test_same_day_stock_counts_from_two_branches_get_distinct_numbers(): void
    {
        $today = now()->format('Ymd');
        $make = fn () => StockCount::create([
            'number'     => StockCount::generateNumber(),
            'count_date' => now()->toDateString(),
            'status'     => 'draft',
        ]);

        $countA = BranchContext::forBranch($this->branchA->id, $make);
        $countB = BranchContext::forBranch($this->branchB->id, $make);

        $this->assertSame("CNT-{$today}-0001", $countA->number);
        $this->assertSame("CNT-{$today}-0002", $countB->number);
    }

    // ─── Branch transfer ────────────────────────────────────────────────

    /** BranchTransfer has no BranchScope (it spans two branches), so its
     *  half of the disease is soft-delete blindness: deleting the day's
     *  last transfer must not make the next one reissue its number. */
    public function test_branch_transfer_number_of_a_soft_deleted_transfer_is_never_reissued(): void
    {
        $today = now()->format('Ymd');
        $make = fn () => BranchTransfer::create([
            'number'         => BranchTransfer::generateNumber(),
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'draft',
        ]);

        $first = $make();
        $this->assertSame("BT-{$today}-0001", $first->number);

        $first->delete();   // soft delete — tombstone keeps the number

        $second = $make();
        $this->assertSame("BT-{$today}-0002", $second->number);
    }

    // ─── Expense ────────────────────────────────────────────────────────

    public function test_same_day_expenses_from_two_branches_get_distinct_numbers(): void
    {
        $today = now()->format('Ymd');

        $category = Lookup::create([
            'group' => 'expense_categories', 'code' => 'misc', 'label' => 'متفرقات', 'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'م', 'username' => 'dng-user', 'role' => 'admin',
            'status' => 'active', 'password' => bcrypt('x'), 'primary_branch_id' => $this->branchA->id,
        ]);

        $make = fn () => Expense::create([
            'expense_category_id' => $category->id,
            'description'         => 'مصروف اختبار',
            'amount'              => 5,
            'payment_method'      => 'cash',
            'expense_date'        => now()->toDateString(),
            'created_by_user_id'  => $user->id,
        ]);

        $expA = BranchContext::forBranch($this->branchA->id, $make);
        $expB = BranchContext::forBranch($this->branchB->id, $make);

        $this->assertSame("EXP-{$today}-0001", $expA->expense_number);
        $this->assertSame("EXP-{$today}-0002", $expB->expense_number,
            'Per-branch sequencing would mint EXP-…-0001 twice and violate the global unique index.');
    }
}
