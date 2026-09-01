<?php

namespace App\Services;

use App\Helpers\UnitConverter;
use App\Models\Ingredient;
use App\Models\IngredientSupplierPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\StorageLocation;
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
            // BelongsToBranch normally auto-stamps branch_id from the active
            // BranchContext, but an owner-level user in "view all branches"
            // mode (or any flow that hasn't passed through SetActiveBranch)
            // has no context bound — the insert would fail with a NOT NULL
            // violation. Resolve the branch with a tiered fallback:
            //   context → user's primary branch → user's first member branch
            //   → first active branch (only used for owner-level, who never
            //      sit in branch_user).
            $user = $userId ? \App\Models\User::find($userId) : null;
            $branchId = BranchContext::current();
            if (! $branchId && $userId) {
                $branchId = optional($user?->primaryBranch())->id
                    ?? optional($user?->branches()->first())->id;
                if (! $branchId && $user?->isOwnerLevel()) {
                    $branchId = \App\Models\Branch::active()
                        ->orderBy('display_order')
                        ->value('id');
                }
            }
            if (! $branchId) {
                throw ValidationException::withMessages([
                    'branch_id' => 'تعذّر تحديد فرع للأمر. اختر فرعاً نشطاً من شريط التبديل في الأعلى ثم أعد المحاولة.',
                ]);
            }
            if ($user && ! $user->belongsToBranch((int) $branchId)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'لا تملك صلاحية إنشاء أمر شراء على هذا الفرع.',
                ]);
            }

            $this->assertSupplierServesBranch((int) $data['supplier_id'], (int) $branchId);

            $exchangeRates = app(ExchangeRateService::class);
            $baseCurrency = $exchangeRates->baseCurrencyCode();
            $currencyCode = $exchangeRates->normalizeCode($data['currency_code'] ?? $baseCurrency);
            $exchangeRate = $currencyCode === $baseCurrency
                ? 1.0
                : (float) ($data['exchange_rate'] ?? $exchangeRates->rateFor($currencyCode, $baseCurrency, now()));

            if ($exchangeRate <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => 'سعر الصرف يجب أن يكون أكبر من صفر.']);
            }

            $po = PurchaseOrder::create([
                'branch_id'   => $branchId,
                'number'      => PurchaseOrder::generateNumber(),
                'supplier_id' => $data['supplier_id'],
                'status'      => 'draft',
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
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
        if (! $po->approved_at) {
            throw ValidationException::withMessages(['status' => 'اعتمد أمر الشراء قبل إرساله للمورد.']);
        }
        if ($po->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'أضف بنوداً لأمر الشراء قبل إرساله.']);
        }
        $po->update(['status' => 'sent', 'sent_at' => now()]);
        return $po->fresh();
    }

    public function approve(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        if (! $po->isApprovable()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن اعتماد أمر الشراء بهذه الحالة.']);
        }
        if ($po->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'أضف بنوداً لأمر الشراء قبل اعتماده.']);
        }

        $po->update([
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

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
            $po = PurchaseOrder::withoutGlobalScopes()
                ->whereKey($po->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $po->isReceivable()) {
                throw ValidationException::withMessages(['status' => 'أمر الشراء لم يعد قابلاً للاستلام.']);
            }
            $receiver = $userId ? \App\Models\User::find($userId) : null;
            if ($receiver && ! $receiver->belongsToBranch((int) $po->branch_id)) {
                throw ValidationException::withMessages([
                    'branch_id' => 'لا تملك صلاحية استلام مخزون لهذا الفرع.',
                ]);
            }

            $lines = $po->items()
                ->with('ingredient.baseUnit', 'unit', 'ingredientUnit')
                ->lockForUpdate()->get();
            $receipt = null;

            // A crafted payload must never be able to hide a foreign PO line
            // among the keyed receipt quantities. Silently ignoring it makes
            // the request look successful while no matching stock was received.
            $lineIds = $lines->pluck('id')->map(fn ($id) => (int) $id)->all();
            $submittedPositiveIds = collect($receipts)
                ->filter(fn ($qty) => (float) $qty > 0)
                ->keys()
                ->map(fn ($id) => (int) $id)
                ->all();
            if (array_diff($submittedPositiveIds, $lineIds)) {
                throw ValidationException::withMessages([
                    'items' => 'توجد بنود استلام لا تنتمي إلى أمر الشراء المحدد.',
                ]);
            }

            // Every receipt lands in exactly one active location owned by the
            // PO branch. This is the hard boundary that keeps branch warehouses,
            // batches, movements and accounting in sync.
            $fallbackLocationId = StorageLocation::withoutGlobalScopes()
                ->where('branch_id', $po->branch_id)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->value('id');

            foreach ($lines as $line) {
                $qtyReceived = (float) ($receipts[$line->id] ?? 0);
                if ($qtyReceived <= 0) continue;

                $lineMeta = $meta[$line->id] ?? [];
                $storageLocationId = ! empty($lineMeta['storage_location_id'])
                    ? (int) $lineMeta['storage_location_id']
                    : ($fallbackLocationId ? (int) $fallbackLocationId : null);

                if (! $storageLocationId) {
                    throw ValidationException::withMessages([
                        'storage_location_id' => 'أنشئ موقع تخزين نشطاً لهذا الفرع قبل استلام المشتريات.',
                    ]);
                }

                $locationIsValid = StorageLocation::withoutGlobalScopes()
                    ->whereKey($storageLocationId)
                    ->where('branch_id', $po->branch_id)
                    ->where('active', true)
                    ->exists();
                if (! $locationIsValid) {
                    throw ValidationException::withMessages([
                        'storage_location_id' => 'موقع التخزين المختار لا يتبع فرع أمر الشراء أو أنه غير نشط.',
                    ]);
                }
                $batchNumber = trim((string) ($lineMeta['batch_number'] ?? '')) ?: null;
                $expiryDate = ! empty($lineMeta['expiry_date'])
                    ? (string) $lineMeta['expiry_date']
                    : null;
                // Optional price override: the supplier delivered at a
                // price different from the PO. Falls back to the PO line
                // price if the receiver didn't touch the field.
                $actualUnitPrice = isset($lineMeta['actual_unit_price']) && (float) $lineMeta['actual_unit_price'] > 0
                    ? (float) $lineMeta['actual_unit_price']
                    : (float) $line->unit_price;

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

                // Convert qty to base unit. Two paths:
                //   1. Line has an `ingredient_unit_id` (pack-size mode)
                //      → multiply by that unit's factor_to_base.
                //   2. Otherwise fall back to global Unit conversion
                //      (existing behavior — kg→g via factor_to_base).
                if ($line->ingredient_unit_id && $line->ingredientUnit) {
                    $qtyBaseRaw = $qtyReceived * (float) $line->ingredientUnit->factor_to_base;
                } else {
                    $qtyBaseRaw = UnitConverter::convert($qtyReceived, $orderedUnit, $baseUnit);
                }

                // Yield adjustment for raw ingredients: 5kg whole chicken
                // at 70% yield = 3.5kg usable lands on the shelf and the
                // effective cost grosses up by 1/0.7. The supplier still
                // gets paid for 5kg, so receipt totals don't change — only
                // the stocked quantity and per-base-unit cost do.
                $yieldPct = (float) ($ingredient->yield_pct ?? 100);
                if ($yieldPct <= 0 || $yieldPct > 100) {
                    $yieldPct = 100;
                }
                $yieldFactor = $yieldPct / 100;
                $qtyBase = round($qtyBaseRaw * $yieldFactor, 4);

                // Unit cost in BASE unit (price paid / factor). Use the
                // actual receipt price when the user overrode it — otherwise
                // the moving-average wouldn't reflect what actually got paid.
                // Same two-path logic as quantity: ingredient-unit takes
                // priority over global Unit conversion when set.
                if ($line->ingredient_unit_id && $line->ingredientUnit) {
                    $oneOrderedUnitInBase = (float) $line->ingredientUnit->factor_to_base;
                } else {
                    $oneOrderedUnitInBase = UnitConverter::convert(1.0, $orderedUnit, $baseUnit);
                }
                $actualUnitPriceBase = $actualUnitPrice * (float) ($po->exchange_rate ?: 1);
                $baseUnitCost = $oneOrderedUnitInBase > 0
                    ? $actualUnitPriceBase / $oneOrderedUnitInBase
                    : $actualUnitPriceBase;
                // Yield-adjust the cost so $/usable kg reflects reality.
                if ($yieldFactor < 1) {
                    $baseUnitCost = round($baseUnitCost / $yieldFactor, 6);
                }

                if (! $receipt) {
                    $receipt = PurchaseReceipt::create([
                        'branch_id' => $po->branch_id,
                        'number' => PurchaseReceipt::generateNumber(),
                        'purchase_order_id' => $po->id,
                        'supplier_id' => $po->supplier_id,
                        'received_by' => $userId,
                        'received_at' => now(),
                    ]);
                }

                // Weighted average cost update — read INSIDE the lock so we
                // never compute against stale numbers.
                $oldStock = (float) $ingredient->current_stock;
                $oldCpu   = (float) $ingredient->cost_per_unit;

                // Weighted average must not be computed against a NEGATIVE
                // starting stock (a prior sale drove current_stock below zero).
                // The negative term extrapolates the cost outside any real
                // purchase price — e.g. −10@4 receiving 20@5 → 6.00, dearer than
                // any lot ever bought, which then over-prices every later COGS.
                // Clamp the basis at zero: the incoming lot sets the cost and the
                // back-filled deficit simply inherits that price.
                $basisStock = max(0.0, $oldStock);
                $newCpu     = ($basisStock + $qtyBase) > 0
                    ? (($basisStock * $oldCpu) + ($qtyBase * $baseUnitCost)) / ($basisStock + $qtyBase)
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
                $movement = $this->inventory->recordMovement(
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

                $receipt->items()->create([
                    'purchase_order_item_id' => $line->id,
                    'ingredient_id' => $ingredient->id,
                    'unit_id' => $line->unit_id,
                    'storage_location_id' => $storageLocationId,
                    'batch_id' => $batch->id,
                    'quantity_received' => $qtyReceived,
                    'quantity_in_base' => $qtyBase,
                    // Record the price the supplier actually delivered at,
                    // not the notional PO price. This is what the
                    // weighted-average and the supplier-invoice match
                    // need to read back later.
                    'unit_price' => $actualUnitPrice,
                    'unit_price_in_base' => $baseUnitCost,
                    'subtotal' => $qtyReceived * $actualUnitPrice,
                    'notes' => $movement->reason,
                ]);

                // 3) Update ingredient price (recordMovement re-reads current_stock).
                Ingredient::whereKey($ingredient->id)->update(['cost_per_unit' => round($newCpu, 4)]);

                // 4) Vendor price history — append-only audit log of every
                //    receipt's price. Computes change_pct vs prior observation.
                $this->recordPriceObservation($po, $line, $ingredient, $baseUnitCost, $actualUnitPrice, $userId);

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

    /** A supplier may serve many branches, but each PO belongs to one. */
    public function assertSupplierServesBranch(int $supplierId, int $branchId): void
    {
        $valid = \App\Models\Supplier::whereKey($supplierId)
            ->where('active', true)
            ->servingBranch($branchId)
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages([
                'supplier_id' => 'المورد غير نشط أو غير مرتبط بفرع أمر الشراء.',
            ]);
        }
    }

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
        float $unitPriceOrdered,
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
            'unit_price'             => $unitPriceOrdered,
            'currency_code'          => $po->currency_code,
            'exchange_rate'          => (float) ($po->exchange_rate ?: 1),
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

            $ingredient = Ingredient::with('baseUnit')->find((int) ($line['ingredient_id'] ?? 0));
            $unit = \App\Models\Unit::find((int) ($line['unit_id'] ?? 0));
            if (! $ingredient || ! $unit) {
                throw ValidationException::withMessages(['items' => 'يوجد صنف أو وحدة شراء غير صالحة.']);
            }

            $ingredientUnitId = ! empty($line['ingredient_unit_id']) ? (int) $line['ingredient_unit_id'] : null;
            if ($ingredientUnitId) {
                $validPack = \App\Models\IngredientUnit::whereKey($ingredientUnitId)
                    ->where('ingredient_id', $ingredient->id)
                    ->exists();
                if (! $validPack) {
                    throw ValidationException::withMessages(['items' => "وحدة العبوة لا تخص الصنف «{$ingredient->name}»."]);
                }
            } elseif ($ingredient->baseUnit && $unit->unit_type !== $ingredient->baseUnit->unit_type) {
                throw ValidationException::withMessages([
                    'items' => "وحدة الشراء «{$unit->name}» لا تطابق نوع قياس الصنف «{$ingredient->name}».",
                ]);
            }

            $lineSubtotal = round($qty * $price, 4);
            $subtotal    += $lineSubtotal;

            $po->items()->create([
                'ingredient_id'      => $line['ingredient_id'],
                'unit_id'            => $line['unit_id'],
                'ingredient_unit_id' => $ingredientUnitId,
                'quantity_ordered'   => $qty,
                'unit_price'         => $price,
                'subtotal'           => $lineSubtotal,
                'notes'              => $line['notes'] ?? null,
            ]);
        }

        $po->update([
            'subtotal'  => $subtotal,
            'tax_total' => 0,              // reserved; suppliers usually price-inclusive in JO
            'total'     => $subtotal,
        ]);
    }
}
