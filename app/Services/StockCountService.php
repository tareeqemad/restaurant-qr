<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Periodic physical inventory reconciliation.
 *
 * Flow:
 *  1. create() — snapshot all tracked ingredients' current_stock into count lines
 *  2. saveCount() — admin enters counted qty per ingredient (multiple passes OK)
 *  3. finalize() — for every variance, create an 'adjustment' inventory_movement,
 *                  updating ingredient.current_stock to match counted_qty.
 *                  Count becomes read-only.
 */
class StockCountService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Start a new draft count. Snapshots every tracked ingredient's current
     * stock into a StockCountItem row (counted_qty = null until entered).
     *
     * @param array $opts ['count_date', 'notes', 'ingredient_ids' (optional subset)]
     */
    public function create(array $opts, int $userId): StockCount
    {
        return DB::transaction(function () use ($opts, $userId) {
            $count = StockCount::create([
                'number'     => StockCount::generateNumber(),
                'count_date' => $opts['count_date'] ?? today()->toDateString(),
                'storage_location_id' => $opts['storage_location_id'] ?? null,
                'status'     => 'draft',
                'notes'      => $opts['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $query = Ingredient::query()->where('track_stock', true);
            if (!empty($opts['ingredient_ids'])) {
                $query->whereIn('id', $opts['ingredient_ids']);
            }

            $ingredients = $query->get();
            $locationStocks = collect();
            if (! empty($opts['storage_location_id'])) {
                $locationStocks = IngredientStock::where('storage_location_id', $opts['storage_location_id'])
                    ->whereIn('ingredient_id', $ingredients->pluck('id'))
                    ->pluck('quantity', 'ingredient_id');
            }

            foreach ($ingredients as $ing) {
                $systemQty = ! empty($opts['storage_location_id'])
                    ? (float) ($locationStocks[$ing->id] ?? 0)
                    : (float) $ing->current_stock;

                $count->items()->create([
                    'ingredient_id' => $ing->id,
                    'system_qty'    => $systemQty,
                    'counted_qty'   => null,
                    'variance'      => 0,
                    'variance_cost' => 0,
                ]);
            }

            ActivityLog::log('stock_count.created', "إنشاء جرد {$count->number} بـ ".$ingredients->count()." مكون", $count);

            return $count->fresh('items.ingredient');
        });
    }

    /**
     * Save counted quantities. Accepts partial counts (some lines can stay null).
     *
     * @param array $counts  [ stock_count_item_id => counted_qty, ... ]
     * @param array $notes   [ stock_count_item_id => note,        ... ]  (optional)
     */
    public function saveCounts(StockCount $count, array $counts, array $notes = []): StockCount
    {
        if (!$count->isEditable()) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل جرد مُعتمد.']);
        }

        return DB::transaction(function () use ($count, $counts, $notes) {
            $items = $count->items()->with('ingredient')->get()->keyBy('id');

            foreach ($counts as $itemId => $qty) {
                $item = $items->get($itemId);
                if (!$item) continue;

                $counted = $qty === '' || $qty === null ? null : (float) $qty;
                $variance = $counted === null
                    ? 0
                    : round($counted - (float) $item->system_qty, 4);
                $varianceCost = $counted === null
                    ? 0
                    : round($variance * (float) ($item->ingredient?->cost_per_unit ?? 0), 4);

                $item->update([
                    'counted_qty'   => $counted,
                    'variance'      => $variance,
                    'variance_cost' => $varianceCost,
                    'notes'         => $notes[$itemId] ?? $item->notes,
                ]);
            }

            return $count->fresh('items.ingredient');
        });
    }

    /**
     * Finalize the count — create adjustment movements for every variance and
     * lock the count from further edits.
     *
     * @return int Number of adjustment movements created.
     */
    public function finalize(StockCount $count, int $userId): int
    {
        if (!$count->isEditable()) {
            throw ValidationException::withMessages(['status' => 'الجرد مُعتمد مسبقاً.']);
        }

        return DB::transaction(function () use ($count, $userId) {
            // Lock + re-check status INSIDE the transaction. Without this two
            // concurrent finalizes (double-click / retried POST) both pass the
            // pre-tx guard and each posts a full set of adjustment movements —
            // double-applying every variance. recordMovement has no per-run
            // dedupe (it creates fresh movement rows), so the lock is the guard.
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'الجرد مُعتمد مسبقاً.']);
            }

            $items = $count->items()->with('ingredient')->whereNotNull('counted_qty')->get();

            $created = 0;
            foreach ($items as $item) {
                $ing = $item->ingredient;
                if (!$ing) continue;

                // SET-TO-COUNTED against LIVE stock, not the frozen open-time
                // variance. If stock moved between opening the count and now
                // (sales during service), replaying the stale variance leaves
                // the book wrong; the adjustment must be (counted − what's on
                // the books right now).
                $liveQty = $count->storage_location_id
                    ? (float) (IngredientStock::where('ingredient_id', $ing->id)
                        ->where('storage_location_id', $count->storage_location_id)
                        ->value('quantity') ?? 0)
                    : (float) $ing->fresh()->current_stock;

                $delta = round((float) $item->counted_qty - $liveQty, 4);

                // Persist the ACTUALLY-posted variance so reports reconcile to
                // the ledger (the stored open-time variance is now stale).
                $item->update([
                    'variance'      => $delta,
                    'variance_cost' => round($delta * (float) ($ing->cost_per_unit ?? 0), 4),
                ]);

                if (abs($delta) <= 0.0001) {
                    continue;
                }

                // Signed base qty; recordMovement adds it directly (positive =
                // found stock, negative = shrinkage). syncBatches keeps FIFO aligned.
                $this->inventory->recordMovement(
                    ingredient: $ing,
                    type:       'adjustment',
                    qtyBase:    $delta,
                    unitCost:   (float) $ing->cost_per_unit,
                    reference:  $item,
                    reason:     "جرد {$count->number}",
                    userId:     $userId,
                    storageLocationId: $count->storage_location_id,
                    syncBatches: true,
                );
                $created++;
            }

            $count->update([
                'status'       => 'finalized',
                'finalized_by' => $userId,
                'finalized_at' => now(),
            ]);

            ActivityLog::log(
                'stock_count.finalized',
                "اعتماد جرد {$count->number} — {$created} تعديل مخزون",
                $count
            );

            return $created;
        });
    }

    public function cancel(StockCount $count, int $userId, string $reason): StockCount
    {
        if ($count->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'يمكن إلغاء المسودات فقط.']);
        }
        $count->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'notes'        => trim(($count->notes ?? '').' | ألغي: '.$reason),
        ]);
        ActivityLog::log('stock_count.cancelled', "إلغاء جرد {$count->number}: {$reason}", $count);
        return $count->fresh();
    }
}
