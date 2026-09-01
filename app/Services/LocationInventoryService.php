<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Location-aware inventory service.
 *
 * Lives alongside InventoryService (which handles the global current_stock
 * for backward compatibility). This service manages the per-location
 * ingredient_stock pivot rows and supports TRANSFERS between locations.
 *
 * Design rules:
 *   - Every location-aware change writes both to ingredient_stock AND to
 *     ingredient.current_stock (sum) via syncIngredientTotal()
 *   - Transfers are 2 movements in a transaction: 'out' from source, 'in' to dest
 *   - Low-stock at a location uses ingredient_stock.reorder_threshold OR
 *     falls back to ingredients.reorder_threshold
 */
class LocationInventoryService
{
    /**
     * Move stock from one location to another within a transaction.
     *
     * @param Ingredient       $ingredient
     * @param StorageLocation  $from
     * @param StorageLocation  $to
     * @param float            $qtyBase
     * @param string|null      $reason
     * @param int|null         $userId
     */
    public function transfer(
        Ingredient      $ingredient,
        StorageLocation $from,
        StorageLocation $to,
        float           $qtyBase,
        ?string         $reason = null,
        ?int            $userId = null,
    ): array {
        if ($qtyBase <= 0) {
            throw ValidationException::withMessages(['qty' => 'الكمية المنقولة يجب أن تكون أكبر من صفر.']);
        }
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['locations' => 'لا يمكن النقل لنفس الموقع.']);
        }
        if ((int) $from->branch_id !== (int) $to->branch_id) {
            throw ValidationException::withMessages([
                'locations' => 'النقل بين فرعين يجب أن يتم عبر تحويل فروع، وليس كنقل داخلي بين المواقع.',
            ]);
        }
        if (! $from->active || ! $to->active) {
            throw ValidationException::withMessages([
                'locations' => 'موقع المصدر والوجهة يجب أن يكونا نشطين.',
            ]);
        }

        return DB::transaction(function () use ($ingredient, $from, $to, $qtyBase, $reason, $userId) {
            // Check source stock (read-only — recordLocationMovement handles the decrement)
            $sourceQty = (float) (IngredientStock::where([
                'ingredient_id'       => $ingredient->id,
                'storage_location_id' => $from->id,
            ])->lockForUpdate()->value('quantity') ?? 0);

            if ($sourceQty < $qtyBase) {
                throw ValidationException::withMessages([
                    'qty' => "المخزون في {$from->name} ({$sourceQty}) أقل من المطلوب نقله ({$qtyBase}).",
                ]);
            }

            // Both movements handle their own stock updates + upserts.
            $cost = (float) $ingredient->costAtBranch((int) $from->branch_id);
            $out  = $this->recordLocationMovement($ingredient, 'out', $from, $qtyBase, $cost, $reason ?? "نقل إلى {$to->name}", $userId);
            $in   = $this->recordLocationMovement($ingredient, 'in',  $to,   $qtyBase, $cost, $reason ?? "وارد من {$from->name}", $userId);

            // Move the FIFO batch layers too, not just the stock quantity.
            // Without this the batches stay pinned to the source location, so
            // ingredient_stock says the goods are at the destination while the
            // batch sub-ledger says they're at the source — and the next batched
            // sale at the destination hard-blocks ('no_batches_for_storage').
            // Only for batch-tracked ingredients; legacy non-batched ones keep
            // the stock-only move. Consume FIFO at source, re-create each lot at
            // the destination carrying its real unit_cost + expiry (FIFO + cost
            // preserved), instead of a single global-average layer.
            $batchSvc = app(BatchInventoryService::class);
            $sourceHasBatches = \App\Models\IngredientBatch::where('ingredient_id', $ingredient->id)
                ->where('storage_location_id', $from->id)
                ->where('remaining_qty', '>', 0)
                ->exists();
            if ($sourceHasBatches) {
                foreach ($batchSvc->deductFifo($ingredient, $qtyBase, $from->id) as $row) {
                    $batchSvc->createBatchOnReceipt(
                        ingredient: $ingredient,
                        qtyBase: (float) $row['qty'],
                        unitCost: (float) $row['batch']->unit_cost,
                        expiryDate: $row['batch']->expiry_date?->toDateString(),
                        storageLocationId: $to->id,
                    );
                }
            }

            // Total hasn't changed (shifted between locations) — but ensure sum matches
            $this->syncIngredientTotal($ingredient);

            ActivityLog::log(
                'stock.transferred',
                "نقل {$qtyBase} {$ingredient->baseUnit?->code} من {$ingredient->name}: {$from->name} → {$to->name}",
                $ingredient
            );

            return ['out_movement' => $out, 'in_movement' => $in];
        });
    }

    /**
     * Apply a location-aware stock change (used for location-aware receipts or consumption).
     * Unlike InventoryService::recordMovement which touches ingredients.current_stock,
     * this writes to ingredient_stock first, then syncs the global total.
     */
    public function recordLocationMovement(
        Ingredient      $ingredient,
        string          $type,
        StorageLocation $location,
        float           $qtyBase,
        float           $unitCost = 0,
        ?string         $reason = null,
        ?int            $userId = null,
        $reference = null,
    ): InventoryMovement {
        $direction = in_array($type, ['out', 'waste'], true) ? -1 : 1;

        // Load or create the location stock row
        $stock = IngredientStock::firstOrCreate(
            [
                'ingredient_id'       => $ingredient->id,
                'storage_location_id' => $location->id,
            ],
            ['quantity' => 0, 'reorder_threshold' => (float) $ingredient->reorder_threshold]
        );

        $before = (float) $stock->quantity;
        $after  = $before + ($qtyBase * $direction);
        $stock->update(['quantity' => $after]);

        // inventory_movements.branch_id is NOT NULL. The trait normally
        // auto-stamps from BranchContext, but transfers run with no
        // context bound — explicitly inherit from the location.
        $mv = InventoryMovement::create([
            'branch_id'           => $location->branch_id,
            'ingredient_id'       => $ingredient->id,
            'storage_location_id' => $location->id,
            'type'                => $type,
            'quantity'            => $qtyBase,
            'unit_id'             => $ingredient->base_unit_id,
            'quantity_in_base'    => $qtyBase,
            'unit_cost'           => $unitCost,
            'total_cost'          => $qtyBase * $unitCost,
            'stock_before'        => $before,
            'stock_after'         => $after,
            'reference_type'      => $reference ? get_class($reference) : null,
            'reference_id'        => $reference?->getKey(),
            'reason'              => $reason,
            'user_id'             => $userId ?? auth()->id(),
            'occurred_at'         => now(),
        ]);

        return $mv;
    }

    /**
     * Recompute ingredient.current_stock as SUM(ingredient_stock.quantity) for that ingredient.
     * Call after any location-aware change so legacy reports (which read current_stock) are in sync.
     */
    public function syncIngredientTotal(Ingredient $ingredient): void
    {
        $total = (float) IngredientStock::where('ingredient_id', $ingredient->id)->sum('quantity');
        Ingredient::whereKey($ingredient->id)->update(['current_stock' => $total]);
    }

    /**
     * Low-stock alerts scoped to a specific location.
     */
    public function lowStockAtLocation(StorageLocation $location)
    {
        return IngredientStock::with('ingredient.baseUnit')
            ->where('storage_location_id', $location->id)
            ->whereColumn('quantity', '<=', 'reorder_threshold')
            ->where('reorder_threshold', '>', 0)
            ->get();
    }
}
