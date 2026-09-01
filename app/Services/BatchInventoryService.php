<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\StorageLocation;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages ingredient batches for FIFO-by-expiry picking and near-expiry alerts.
 *
 * This service is additive — it lives alongside InventoryService without
 * replacing it. When a restaurant wants batch tracking, callers use
 * `createBatchOnReceipt()` after a purchase receipt, and `deductFifo()` when
 * stock is consumed.
 *
 * Key operations:
 *   createBatchOnReceipt(ing, qty, unitCost, expiryDate?, batchNumber?, source?, storageLocationId?)
 *       → Creates a new batch row + returns it. Caller separately records
 *         the inventory movement; this service just manages batches.
 *
 *   deductFifo(ing, qtyBase) → array of [batch, qtyTaken] consumed
 *       → Walks batches in FIFO order, subtracting remaining_qty.
 *         Throws if insufficient stock across all batches.
 *
 *   expiringSoon(days = 7) → Collection of batches expiring within N days
 *   expired()              → Collection of batches already expired (still has stock)
 */
class BatchInventoryService
{
    /**
     * Create a new batch when stock arrives (typically from a PO receipt).
     */
    public function createBatchOnReceipt(
        Ingredient $ingredient,
        float $qtyBase,
        float $unitCost,
        ?string $expiryDate = null,
        ?string $batchNumber = null,
        $source = null,
        ?string $notes = null,
        ?int $storageLocationId = null,
        bool $allowExpired = false,
    ): IngredientBatch {
        if ($qtyBase <= 0) {
            throw ValidationException::withMessages(['qty' => 'كمية الدفعة يجب أن تكون أكبر من صفر.']);
        }

        if (! $allowExpired && $expiryDate && $expiryDate < now()->toDateString()) {
            throw ValidationException::withMessages([
                'expiry_date' => 'لا يمكن إدخال دفعة منتهية الصلاحية إلى المخزون المتاح.',
            ]);
        }

        if ($ingredient->tracks_expiry && ! $expiryDate) {
            throw ValidationException::withMessages([
                'expiry_date' => "تاريخ الصلاحية مطلوب للصنف «{$ingredient->name}» لأنه مفعّل لتتبع الصلاحية.",
            ]);
        }

        if (! $storageLocationId) {
            throw ValidationException::withMessages([
                'storage_location_id' => 'يجب تحديد موقع التخزين الذي ستدخل إليه الدفعة.',
            ]);
        }

        $location = StorageLocation::withoutGlobalScopes()
            ->whereKey($storageLocationId)
            ->where('active', true)
            ->first();
        if (! $location) {
            throw ValidationException::withMessages([
                'storage_location_id' => 'موقع التخزين غير موجود أو غير نشط.',
            ]);
        }

        $sourceBranchId = $this->resolveSourceBranchId($source);
        if ($sourceBranchId && $sourceBranchId !== (int) $location->branch_id) {
            throw ValidationException::withMessages([
                'storage_location_id' => 'موقع التخزين لا يتبع فرع عملية التوريد.',
            ]);
        }

        // ingredient_batches has a NOT NULL branch_id. The BelongsToBranch
        // trait auto-stamps from BranchContext, but receipt flows often run
        // in transactions where the context isn't bound (queue, owner-level
        // in view-all mode, CLI). Derive from the strongest available
        // signal: the storage location belongs to a specific branch, so
        // use that first; fall back to the source PO/receipt's branch_id;
        // finally to the runtime context.
        $branchId = (int) $location->branch_id;
        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'تعذّر تحديد فرع الدفعة. تأكد من اختيار موقع تخزين أو من ضبط الفرع النشط.',
            ]);
        }

        return IngredientBatch::create([
            'branch_id' => $branchId,
            'ingredient_id' => $ingredient->id,
            'storage_location_id' => $storageLocationId,
            'batch_number' => $batchNumber,
            'received_date' => now()->toDateString(),
            'expiry_date' => $expiryDate,
            'initial_qty' => $qtyBase,
            'remaining_qty' => $qtyBase,
            'unit_cost' => $unitCost,
            'source_type' => $source ? get_class($source) : null,
            'source_id' => $source?->getKey(),
            'notes' => $notes,
        ]);
    }

    /**
     * Storage location is the most reliable signal for branch ownership —
     * goods physically sit in a location, and locations belong to one
     * branch. Fall back to the source record's branch_id (PO line / PO
     * carries it directly) and finally to the runtime context for
     * legacy callers.
     */
    protected function resolveBranchId(?int $storageLocationId, $source): ?int
    {
        if ($storageLocationId) {
            $bid = StorageLocation::whereKey($storageLocationId)->value('branch_id');
            if ($bid) {
                return (int) $bid;
            }
        }

        if ($source) {
            if (isset($source->branch_id) && $source->branch_id) {
                return (int) $source->branch_id;
            }
            // PO line carries branch_id via its parent PO, not directly
            if (method_exists($source, 'purchaseOrder') && ($parent = $source->purchaseOrder) && $parent->branch_id) {
                return (int) $parent->branch_id;
            }
        }

        return BranchContext::current();
    }

    protected function resolveSourceBranchId($source): ?int
    {
        if (! $source) {
            return null;
        }
        if (isset($source->branch_id) && $source->branch_id) {
            return (int) $source->branch_id;
        }
        if (method_exists($source, 'purchaseOrder')) {
            $parent = $source->purchaseOrder()->withoutGlobalScopes()->first();
            if ($parent?->branch_id) {
                return (int) $parent->branch_id;
            }
        }

        return null;
    }

    /**
     * Deduct stock from batches in FIFO-by-expiry order.
     *
     * @return array List of [ 'batch' => IngredientBatch, 'qty' => float ] taken
     *
     * @throws ValidationException if total stock across batches is insufficient
     */
    public function deductFifo(
        Ingredient $ingredient,
        float $qtyBase,
        ?int $storageLocationId = null,
        bool $includeExpired = false,
    ): array {
        if ($qtyBase <= 0) {
            return [];
        }

        return DB::transaction(function () use ($ingredient, $qtyBase, $storageLocationId, $includeExpired) {
            // Lock all candidate batches up-front. The FIFO scope filters out
            // depleted ones; the lock holds for the rest of this transaction
            // so a concurrent deductFifo() can't double-spend the same batch.
            $batches = IngredientBatch::where('ingredient_id', $ingredient->id)
                ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
                ->fifo($includeExpired)
                ->lockForUpdate()
                ->get();

            $totalAvailable = (float) $batches->sum('remaining_qty');
            if ($totalAvailable + 0.0001 < $qtyBase) {
                throw ValidationException::withMessages([
                    'stock' => "المخزون المتاح في الدفعات ({$totalAvailable}) أقل من المطلوب ({$qtyBase}) للمكوّن {$ingredient->name}.",
                ]);
            }

            $taken = [];
            $remaining = $qtyBase;
            $updates = []; // [id => newRemaining] — applied as bulk decrement at the end

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $batchQty = (float) $batch->remaining_qty;
                $takeNow = min($batchQty, $remaining);

                $updates[$batch->id] = $batchQty - $takeNow;
                // Reflect in-memory so caller sees consistent state without re-query.
                $batch->remaining_qty = $batchQty - $takeNow;

                $taken[] = ['batch' => $batch, 'qty' => $takeNow];
                $remaining -= $takeNow;
            }

            // Bulk update — one query for all touched batches (was N queries).
            // Skipped when there are no updates (defensive).
            if (! empty($updates)) {
                $cases = [];
                $bindings = [];
                $ids = array_keys($updates);
                foreach ($updates as $id => $remainingQty) {
                    $cases[] = 'WHEN ? THEN ?';
                    $bindings[] = $id;
                    $bindings[] = $remainingQty;
                }
                $idsPlaceholder = implode(',', array_fill(0, count($ids), '?'));
                // Bind the timestamp so the whole bulk update has one exact
                // application timestamp and remains straightforward to test.
                $sql = 'UPDATE ingredient_batches
                        SET remaining_qty = CASE id '.implode(' ', $cases)." END,
                            updated_at = ?
                        WHERE id IN ($idsPlaceholder)";
                DB::statement($sql, array_merge($bindings, [now()->format('Y-m-d H:i:s')], $ids));
            }

            return $taken;
        });
    }

    /**
     * Return to batch on cancellation — adds qty back to the original batch.
     * If the batch was deleted, creates a new reversal batch.
     */
    public function returnToBatch(IngredientBatch $batch, float $qtyBase): IngredientBatch
    {
        $batch->increment('remaining_qty', $qtyBase);

        return $batch->fresh();
    }

    /**
     * Batches expiring within the given window.
     */
    public function expiringSoon(int $withinDays = 7)
    {
        return IngredientBatch::where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($withinDays)->toDateString()])
            ->with('ingredient.baseUnit', 'storageLocation')
            ->orderBy('expiry_date')
            ->get();
    }

    /**
     * Batches that have already expired but still have stock (should be wasted).
     */
    public function expired()
    {
        return IngredientBatch::where('remaining_qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->with('ingredient.baseUnit', 'storageLocation')
            ->orderBy('expiry_date')
            ->get();
    }
}
