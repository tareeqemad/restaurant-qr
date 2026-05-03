<?php

namespace App\Services;

use App\Helpers\UnitConverter;
use App\Models\Ingredient;
use App\Models\IngredientSupplierPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles the lifecycle of purchase orders:
 *   create (draft) → update lines → send → receive (partial or full) → cancel.
 *
 * Goods receipt is the money-in-motion step: it adds stock, creates an
 * InventoryMovement (type='in'), and updates the ingredient's cost_per_unit
 * using a weighted-average formula so the menu-item recipe cost stays accurate.
 */
class PurchaseOrderService
{
    public function __construct(
        protected InventoryService $inventory,
        protected ?BatchInventoryService $batches = null,
    ) {
        $this->batches = $this->batches ?? app(BatchInventoryService::class);
    }

    // ── Creation / editing ────────────────────────────────────────────────

    /**
     * Create a new draft PO with optional line items.
     *
     * @param array $data  ['supplier_id', 'expected_at', 'notes']
     * @param array $lines [['ingredient_id', 'unit_id', 'quantity_ordered', 'unit_price', 'notes'], ...]
     */
    public function create(array $data, array $lines, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $lines, $userId) {
            $po = PurchaseOrder::create([
                'number'      => PurchaseOrder::generateNumber(),
                'supplier_id' => $data['supplier_id'],
                'status'      => 'draft',
                'expected_at' => $data['expected_at'] ?? null,
                'notes'       => $data['notes'] ?? null,
                'created_by'  => $userId,
            ]);

            $this->syncLines($po, $lines);
            return $po->fresh('items');
        });
    }

    /**
     * Replace all lines on a DRAFT purchase order and recompute totals.
     */
    public function updateLines(PurchaseOrder $po, array $lines): PurchaseOrder
    {
        if (!$po->isEditable()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل أمر شراء بعد إرساله.']);
        }

        return DB::transaction(function () use ($po, $lines) {
            $po->items()->delete();
            $this->syncLines($po, $lines);
            return $po->fresh('items');
        });
    }

    /**
     * Transition a draft PO to "sent" state. After this, lines cannot be edited.
     */
    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'يمكن إرسال أوامر الشراء في حالة المسودة فقط.']);
        }
        if ($po->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'أضف بنوداً لأمر الشراء قبل إرساله.']);
        }
        $po->update(['status' => 'sent', 'sent_at' => now()]);
        return $po->fresh();
    }

    /**
     * Cancel a PO that hasn't been (fully) received.
     * If partial receipts already happened, they stay on the books but no
     * further receiving is possible.
     */
    public function cancel(PurchaseOrder $po, string $reason, ?int $userId = null): PurchaseOrder
    {
        if ($po->status === 'received' || $po->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء أمر شراء مستلم أو ملغي.']);
        }
        $po->update([
            'status'        => 'cancelled',
            'cancelled_at'  => now(),
            'cancel_reason' => $reason,
        ]);
        return $po->fresh();
    }

    // ── Goods receipt ─────────────────────────────────────────────────────

    /**
     * Receive quantities against PO lines. `receipts` is keyed by po_item_id:
     *   [ po_item_id => qty_received_this_time (in ordered unit), ... ]
     *
     * For each received line we:
     *  1. Convert ordered-unit qty to ingredient base unit
     *  2. Create an InventoryMovement (type='in') with unit_cost & total_cost
     *  3. Update ingredient.cost_per_unit via weighted average
     *     new_cpu = (old_stock × old_cpu + received_qty_base × unit_cost_base) / (old_stock + received_qty_base)
     *  4. Update PO line's quantity_received & fully_received_at
     *
     * After all lines processed, update PO status to `received` or `partially_received`.
     */
    public function receive(PurchaseOrder $po, array $receipts, ?int $userId = null, array $meta = []): PurchaseOrder
    {
        if (!$po->isReceivable()) {
            throw ValidationException::withMessages(['status' => 'أمر الشراء ليس في حالة تسمح بالاستلام.']);
        }

        $previousStatus = $po->status;

        $po = DB::transaction(function () use ($po, $receipts, $userId, $meta) {
            $lines = $po->items()->with('ingredient.baseUnit', 'unit')->lockForUpdate()->get();

            foreach ($lines as $line) {
                $qtyReceived = (float) ($receipts[$line->id] ?? 0);
                if ($qtyReceived <= 0) continue;

                $lineMeta = $meta[$line->id] ?? [];
                $storageLocationId = ! empty($lineMeta['storage_location_id'])
                    ? (int) $lineMeta['storage_location_id']
                    : null;
                $batchNumber = trim((string) ($lineMeta['batch_number'] ?? '')) ?: null;
                $expiryDate = ! empty($lineMeta['expiry_date'])
                    ? (string) $lineMeta['expiry_date']
                    : null;

                // Guard: can't receive more than outstanding
                $outstanding = $line->outstandingQty();
                if ($qtyReceived > $outstanding + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => "الكمية المستلمة ({$qtyReceived}) أكبر من المتبقي ({$outstanding}) لبند {$line->ingredient->name}.",
                    ]);
                }

                // Lock the ingredient row inside the same transaction. Without
                // this, two POs receiving the same ingredient in parallel can
                // interleave their cost-update reads and lose one of them.
                $ingredient  = Ingredient::whereKey($line->ingredient_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $orderedUnit = $line->unit_id;
                $baseUnit    = $ingredient->base_unit_id;

                // Convert qty to base unit
                $qtyBase = UnitConverter::convert($qtyReceived, $orderedUnit, $baseUnit);

                // Unit cost in BASE unit (price paid / factor)
                //   unit_price is per ORDERED unit, so base-unit cost = unit_price / (ordered_qty_base / ordered_qty)
                // Simpler: base_cpu = unit_price ÷ (1 ordered unit in base units)
                $oneOrderedUnitInBase = UnitConverter::convert(1.0, $orderedUnit, $baseUnit);
                $baseUnitCost = $oneOrderedUnitInBase > 0
                    ? (float) $line->unit_price / $oneOrderedUnitInBase
                    : (float) $line->unit_price;

                // Weighted average cost update — read INSIDE the lock so we
                // never compute against stale numbers.
                $oldStock = (float) $ingredient->current_stock;
                $oldCpu   = (float) $ingredient->cost_per_unit;
                $newStock = $oldStock + $qtyBase;
                $newCpu   = $newStock > 0
                    ? (($oldStock * $oldCpu) + ($qtyBase * $baseUnitCost)) / $newStock
                    : $baseUnitCost;

                // 1) Create the batch FIRST so we have an id to link to the movement.
                //    Both calls now share this transaction — a failure in either
                //    rolls back the batch creation, leaving no orphans.
                $batch = $this->batches->createBatchOnReceipt(
                    ingredient:  $ingredient,
                    qtyBase:     $qtyBase,
                    unitCost:    $baseUnitCost,
                    expiryDate:  $expiryDate,
                    batchNumber: $batchNumber,
                    source:      $line,
                    storageLocationId: $storageLocationId,
                );

                // 2) Record the inventory movement (type='in', linked to PO line + batch).
                //    The batch_id link is what enables reverse traceability:
                //    "this 5kg of flour came from this delivery."
                $this->inventory->recordMovement(
                    ingredient: $ingredient,
                    type:       'in',
                    qtyBase:    $qtyBase,
                    unitCost:   $baseUnitCost,
                    reference:  $line,
                    reason:     "استلام PO {$po->number}",
                    userId:     $userId,
                    batchId:    $batch->id,
                    storageLocationId: $storageLocationId,
                );

                // 3) Update ingredient price (recordMovement re-reads current_stock).
                Ingredient::whereKey($ingredient->id)->update(['cost_per_unit' => round($newCpu, 4)]);

                // 4) Vendor price history — append-only audit log of every
                //    receipt's price. Computes change_pct vs prior observation.
                $this->recordPriceObservation($po, $line, $ingredient, $baseUnitCost, $userId);

                // 5) Update the PO line
                $line->quantity_received = (float) $line->quantity_received + $qtyReceived;
                if ($line->quantity_received + 0.0001 >= (float) $line->quantity_ordered) {
                    $line->fully_received_at = now();
                }
                $line->save();
            }

            // Roll up PO status
            $po->refresh();
            $allDone = $po->items->every(fn($l) => (float) $l->quantity_received + 0.0001 >= (float) $l->quantity_ordered);
            $anyDone = $po->items->contains(fn($l) => (float) $l->quantity_received > 0);

            $po->update([
                'status'      => $allDone ? 'received' : ($anyDone ? 'partially_received' : $po->status),
                'received_at' => $allDone ? now() : $po->received_at,
                'received_by' => $userId ?? $po->received_by,
            ]);

            return $po->fresh('items.ingredient');
        });

        // Notify after commit. Only fire when status actually changed (skip
        // no-op receipts that updated zero lines and left status untouched).
        if (in_array($po->status, ['received', 'partially_received'], true)
            && $po->status !== $previousStatus) {
            app(\App\Services\NotifyService::class)
                ->purchaseReceived(
                    $po->load('supplier'),
                    partial: $po->status === 'partially_received'
                );
        }

        return $po;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Append a row to ingredient_supplier_prices for this receipt. Computes
     * the percentage change vs the prior observation for the same trio
     * (ingredient × supplier × branch) so the UI can show ↑/↓ deltas
     * without a window function at read time.
     *
     * Owner-level CLI runs without a branch context can pass a null branch;
     * the row will simply not be filterable by branch.
     */
    protected function recordPriceObservation(
        PurchaseOrder $po,
        PurchaseOrderItem $line,
        Ingredient $ingredient,
        float $unitPriceInBase,
        ?int $userId,
    ): void {
        $branchId = $po->branch_id ?? BranchContext::current();
        if (! $branchId || ! $po->supplier_id) return;

        $prev = IngredientSupplierPrice::latestFor(
            $ingredient->id, $po->supplier_id, $branchId
        );

        $changePct = null;
        if ($prev && (float) $prev->unit_price_in_base > 0) {
            $changePct = (((float) $unitPriceInBase - (float) $prev->unit_price_in_base)
                / (float) $prev->unit_price_in_base) * 100;
        }

        IngredientSupplierPrice::create([
            'branch_id'              => $branchId,
            'ingredient_id'          => $ingredient->id,
            'supplier_id'            => $po->supplier_id,
            'unit_id'                => $line->unit_id,
            'unit_price'             => $line->unit_price,
            'unit_price_in_base'     => round($unitPriceInBase, 4),
            'previous_price_in_base' => $prev?->unit_price_in_base,
            'change_pct'             => $changePct !== null ? round($changePct, 2) : null,
            'purchase_order_id'      => $po->id,
            'purchase_order_item_id' => $line->id,
            'source'                 => 'receipt',
            'recorded_by_user_id'    => $userId,
            'observed_at'            => now(),
        ]);
    }

    /**
     * Insert lines and recompute PO totals.
     */
    protected function syncLines(PurchaseOrder $po, array $lines): void
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $qty   = (float) ($line['quantity_ordered'] ?? 0);
            $price = (float) ($line['unit_price'] ?? 0);
            if ($qty <= 0) continue;

            $lineSubtotal = round($qty * $price, 4);
            $subtotal    += $lineSubtotal;

            $po->items()->create([
                'ingredient_id'    => $line['ingredient_id'],
                'unit_id'          => $line['unit_id'],
                'quantity_ordered' => $qty,
                'unit_price'       => $price,
                'subtotal'         => $lineSubtotal,
                'notes'            => $line['notes'] ?? null,
            ]);
        }

        $po->update([
            'subtotal'  => $subtotal,
            'tax_total' => 0,              // reserved; suppliers usually price-inclusive in JO
            'total'     => $subtotal,
        ]);
    }
}
