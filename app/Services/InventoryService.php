<?php

namespace App\Services;

use App\Helpers\UnitConverter;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\OrderItem;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(protected ?BatchInventoryService $batches = null)
    {
        $this->batches = $this->batches ?? app(BatchInventoryService::class);
    }

    /**
     * Compute stock deduction lines for a cart item (before committing).
     * Returns array of [ingredient_id, qty_in_base, unit_cost, will_be_negative]
     */
    public function previewDeductionForItem(MenuItem $item, float $quantity, array $modifierIds = []): array
    {
        $lines = [];

        foreach ($item->recipeItems as $recipe) {
            $ingredient = $recipe->ingredient;
            $qtyBase = UnitConverter::convert((float) $recipe->quantity, $recipe->unit_id, $ingredient->base_unit_id) * $quantity;
            $lines[] = [
                'ingredient_id' => $ingredient->id,
                'ingredient' => $ingredient,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => (float) $ingredient->cost_per_unit,
                'current_stock' => (float) $ingredient->current_stock,
                'will_be_negative' => $ingredient->track_stock && ((float) $ingredient->current_stock - $qtyBase) < 0,
            ];
        }

        foreach (Modifier::with('recipeItems.ingredient')->findMany($modifierIds) as $modifier) {
            foreach ($modifier->recipeItems as $recipe) {
                $ingredient = $recipe->ingredient;
                $qtyBase = UnitConverter::convert((float) $recipe->quantity, $recipe->unit_id, $ingredient->base_unit_id) * $quantity;
                $lines[] = [
                    'ingredient_id' => $ingredient->id,
                    'ingredient' => $ingredient,
                    'quantity_in_base' => $qtyBase,
                    'unit_cost' => (float) $ingredient->cost_per_unit,
                    'current_stock' => (float) $ingredient->current_stock,
                    'will_be_negative' => $ingredient->track_stock && ((float) $ingredient->current_stock - $qtyBase) < 0,
                ];
            }
        }

        return $lines;
    }

    /**
     * Commit the deduction for an OrderItem.
     *
     * Behavior depends on whether the ingredient has any open batches:
     *   - With batches → consume FIFO (earliest-expiry first), creating one
     *     `out` movement per batch touched, each linked to its `batch_id`.
     *     This gives full reverse-traceability (which delivery was sold).
     *   - Without batches → fall back to a single aggregate movement using
     *     the ingredient's weighted-average cost. Keeps backward compat for
     *     ingredients introduced before batch tracking.
     */
    public function deductForOrderItem(OrderItem $orderItem): void
    {
        $orderItem->loadMissing('order', 'station.storageLocation');

        $modifierIds = $orderItem->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray();
        $item = $orderItem->menuItem()->with('recipeItems.ingredient')->first();
        $lines = $this->previewDeductionForItem($item, (float) $orderItem->quantity, $modifierIds);
        $storageLocationId = $orderItem->station?->storage_location_id
            ?: StorageLocation::default()?->id;

        foreach ($lines as $line) {
            $this->deductWithBatchTrace(
                ingredient: $line['ingredient'],
                qtyBase:    $line['quantity_in_base'],
                fallbackUnitCost: $line['unit_cost'],
                reference:  $orderItem,
                reason:     'خصم طلب '.$orderItem->order->number,
                storageLocationId: $storageLocationId,
            );
        }
    }

    /**
     * Deduct stock with FIFO batch tracing when batches exist.
     *
     * Always returns the list of movements created (one per batch when batched,
     * one aggregate when not). Callers don't need to know which path was taken.
     */
    public function deductWithBatchTrace(
        Ingredient $ingredient,
        float $qtyBase,
        float $fallbackUnitCost = 0,
        $reference = null,
        ?string $reason = null,
        ?int $userId = null,
        string $type = 'out',
        ?int $storageLocationId = null,
    ): array {
        if ($qtyBase <= 0) return [];

        // Probe whether batches exist for this ingredient. If not, single aggregate movement.
        $hasBatches = \App\Models\IngredientBatch::where('ingredient_id', $ingredient->id)
            ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
            ->where('remaining_qty', '>', 0)
            ->exists();

        if (! $hasBatches && $storageLocationId) {
            $hasBatchesInOtherLocation = \App\Models\IngredientBatch::where('ingredient_id', $ingredient->id)
                ->where('remaining_qty', '>', 0)
                ->exists();

            if ($hasBatchesInOtherLocation) {
                throw ValidationException::withMessages([
                    'stock' => "لا توجد دفعات متاحة للمكوّن {$ingredient->name} في موقع التخزين المختار.",
                ]);
            }
        }

        if (! $hasBatches) {
            return [
                $this->recordMovement(
                    ingredient: $ingredient,
                    type:       $type,
                    qtyBase:    $qtyBase,
                    unitCost:   $fallbackUnitCost,
                    reference:  $reference,
                    reason:     $reason,
                    userId:     $userId,
                    storageLocationId: $storageLocationId,
                )
            ];
        }

        // FIFO consumption — batch service handles the per-batch decrement
        // inside its own transaction; we then write one movement per batch
        // touched, carrying the batch's actual unit_cost (not just average).
        $taken = $this->batches->deductFifo($ingredient, $qtyBase, $storageLocationId);

        $movements = [];
        foreach ($taken as $row) {
            /** @var \App\Models\IngredientBatch $batch */
            $batch = $row['batch'];
            $qty   = (float) $row['qty'];

            $movements[] = $this->recordMovement(
                ingredient: $ingredient,
                type:       $type,
                qtyBase:    $qty,
                unitCost:   (float) $batch->unit_cost,
                reference:  $reference,
                reason:     $reason,
                userId:     $userId,
                batchId:    $batch->id,
                storageLocationId: $storageLocationId,
            );
        }

        return $movements;
    }

    /**
     * Return stock for a cancelled OrderItem.
     */
    public function returnForOrderItem(OrderItem $orderItem): void
    {
        $movements = InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $orderItem->id)
            ->where('type', 'out')
            ->with(['ingredient', 'batch'])
            ->get();

        foreach ($movements as $mv) {
            if ($mv->batch) {
                $this->batches->returnToBatch($mv->batch, (float) $mv->quantity_in_base);
            }

            $this->recordMovement(
                ingredient: $mv->ingredient,
                type: 'return',
                qtyBase: (float) $mv->quantity_in_base,
                unitCost: (float) $mv->unit_cost,
                reference: $orderItem,
                reason: 'إرجاع - إلغاء صنف '.$orderItem->name_snapshot,
                batchId: $mv->batch_id,
                storageLocationId: $mv->storage_location_id,
            );
        }
    }

    public function recordMovement(
        Ingredient $ingredient,
        string $type,
        float $qtyBase,
        float $unitCost = 0,
        $reference = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $batchId = null,
        ?string $wasteReason = null,
        ?int $storageLocationId = null,
        ?int $wasteReasonLookupId = null,
    ): InventoryMovement {
        [$mv, $crossedLowStock, $freshIngredient] = DB::transaction(function () use ($ingredient, $type, $qtyBase, $unitCost, $reference, $reason, $userId, $batchId, $wasteReason, $storageLocationId, $wasteReasonLookupId) {
            // Lock the ingredient row for the duration of this transaction.
            // Concurrent receipts/deductions on the same SKU are now serialized,
            // preventing weighted-average cost interleaving.
            $ingredient = Ingredient::whereKey($ingredient->id)->lockForUpdate()->first();
            $stockBefore = (float) $ingredient->current_stock;

            $direction = in_array($type, ['out', 'waste']) ? -1 : 1;
            $delta = $qtyBase * $direction;
            $stockAfter = $stockBefore + $delta;

            if ($ingredient->track_stock) {
                // Per-location row is the source of truth. We always touch one,
                // falling back to a sensible default location when the caller
                // doesn't supply one — otherwise the global counter and the
                // per-branch sum drift apart and the index page shows
                // different totals depending on the active branch filter.
                $locId = $storageLocationId ?: $this->resolveFallbackLocationId($reference);

                if ($locId) {
                    $locationStock = IngredientStock::where([
                        'ingredient_id' => $ingredient->id,
                        'storage_location_id' => $locId,
                    ])->lockForUpdate()->first();

                    if (! $locationStock) {
                        $locationStock = IngredientStock::create([
                            'ingredient_id' => $ingredient->id,
                            'storage_location_id' => $locId,
                            'quantity' => 0,
                            'reorder_threshold' => (float) $ingredient->reorder_threshold,
                        ]);
                    }

                    $locationStock->update([
                        'quantity' => (float) $locationStock->quantity + $delta,
                    ]);

                    // Echo the resolved location back to the movement record
                    // so the audit trail and downstream views (transfer show
                    // page, etc.) reference the actual storage row.
                    $storageLocationId = $locId;
                }

                // Derive the global counter from the per-location truth.
                // Guarantees current_stock == SUM(ingredient_stock.quantity).
                $newGlobal = (float) IngredientStock::where('ingredient_id', $ingredient->id)
                    ->sum('quantity');
                $ingredient->update(['current_stock' => $newGlobal]);
                $stockAfter = $newGlobal;
            }

            // inventory_movements has a NOT NULL branch_id. The trait
            // auto-stamps from BranchContext, but receipts/queues run with
            // no context bound. Resolve from the strongest available signal:
            // location → reference's own branch_id → reference's parent
            // (PO line carries it via purchaseOrder) → BranchContext.
            $branchId = null;
            if ($storageLocationId) {
                $branchId = \App\Models\StorageLocation::whereKey($storageLocationId)->value('branch_id');
            }
            if (! $branchId && $reference) {
                if (isset($reference->branch_id) && $reference->branch_id) {
                    $branchId = (int) $reference->branch_id;
                } elseif (method_exists($reference, 'purchaseOrder')
                          && ($parent = $reference->purchaseOrder)
                          && $parent->branch_id) {
                    $branchId = (int) $parent->branch_id;
                }
            }
            $branchId = $branchId ?: \App\Support\BranchContext::current();

            $mv = InventoryMovement::create([
                'branch_id'     => $branchId,
                'ingredient_id' => $ingredient->id,
                'batch_id'      => $batchId,
                'storage_location_id' => $storageLocationId,
                'type' => $type,
                'quantity' => $qtyBase,
                'unit_id' => $ingredient->base_unit_id,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => $unitCost,
                'total_cost' => $qtyBase * $unitCost,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->getKey(),
                'reason' => $reason,
                'waste_reason' => $type === 'waste' ? $wasteReason : null,
                'waste_reason_lookup_id' => $type === 'waste' ? $wasteReasonLookupId : null,
                'user_id' => $userId ?? auth()->id(),
                'occurred_at' => now(),
            ]);

            // Edge-trigger: notify only when stock CROSSES the threshold,
            // never on every subsequent deduction that stays below. This
            // keeps the inbox clean — one alert per "you should reorder"
            // event, not one per ticket.
            $threshold = (float) $ingredient->reorder_threshold;
            $crossed   = $ingredient->track_stock
                && $threshold > 0
                && $stockBefore > $threshold
                && $stockAfter  <= $threshold;

            return [$mv, $crossed, $ingredient->fresh()];
        });

        if ($crossedLowStock && $freshIngredient) {
            app(NotifyService::class)->lowStock($freshIngredient);
        }

        return $mv;
    }

    /**
     * Pick a storage location to attribute a movement to when the caller
     * didn't supply one. Walks the strongest-signal chain:
     *   1. The reference's own branch_id (PO line, transfer item, etc.) →
     *      that branch's default → first active location.
     *   2. The reference's parent (e.g. PO line → PO header) branch.
     *   3. Active BranchContext.
     *   4. Any branch's default → any first active location (truly last resort).
     * Returns null only when the system has zero active storage locations.
     */
    protected function resolveFallbackLocationId($reference = null): ?int
    {
        $branchId = null;
        if ($reference) {
            if (isset($reference->branch_id) && $reference->branch_id) {
                $branchId = (int) $reference->branch_id;
            } elseif (method_exists($reference, 'purchaseOrder')
                      && ($parent = $reference->purchaseOrder)
                      && $parent->branch_id) {
                $branchId = (int) $parent->branch_id;
            }
        }
        $branchId ??= \App\Support\BranchContext::current();

        if ($branchId) {
            $locId = \App\Models\StorageLocation::where('branch_id', $branchId)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->value('id');
            if ($locId) return (int) $locId;
        }

        // No branch context — pick any active location, default first.
        return \App\Models\StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->value('id');
    }

    public function checkStockForOrderPreview(array $cartItems): array
    {
        $issues = [];
        $aggregated = [];
        foreach ($cartItems as $ci) {
            $item = MenuItem::with('recipeItems.ingredient')->find($ci['menu_item_id']);
            if (! $item) continue;
            $modifierIds = $ci['modifier_ids'] ?? [];
            $lines = $this->previewDeductionForItem($item, (float) $ci['quantity'], $modifierIds);
            foreach ($lines as $line) {
                $key = $line['ingredient_id'];
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = ['ingredient' => $line['ingredient'], 'qty' => 0];
                }
                $aggregated[$key]['qty'] += $line['quantity_in_base'];
            }
        }

        foreach ($aggregated as $a) {
            if ($a['ingredient']->track_stock && $a['qty'] > (float) $a['ingredient']->current_stock) {
                $issues[] = [
                    'ingredient' => $a['ingredient']->name,
                    'ingredient_id' => $a['ingredient']->id,
                    'required' => $a['qty'],
                    'available' => (float) $a['ingredient']->current_stock,
                ];
            }
        }

        return $issues;
    }

    /**
     * Stock report for a pending order, shaped for the waiter board:
     *   - `issues`: short ingredients (name + id + required/available)
     *   - `short_item_ids`: order_item ids whose recipe depends on a short
     *     ingredient, so the board can flag exactly which lines to cancel.
     *
     * Note `short_item_ids` is a heuristic — ingredients are shared across
     * lines, so cancelling one flagged item may free enough stock for
     * another. It's a "look here first" hint for the waiter, not a solver.
     *
     * @return array{issues: array, short_item_ids: int[]}
     */
    public function orderStockReport(\App\Models\Order $order): array
    {
        $issues = $this->validateStockForOrder($order);
        if (empty($issues)) {
            return ['issues' => [], 'short_item_ids' => []];
        }

        $shortIngredientIds = collect($issues)->pluck('ingredient_id')->filter()->all();

        $items = $order->items()
            ->where('status', \App\Enums\OrderItemStatus::Pending->value)
            ->with('menuItem.recipeItems', 'modifiers.modifier.recipeItems')
            ->get();

        $shortItemIds = [];
        foreach ($items as $oi) {
            $recipeIngredientIds = collect($oi->menuItem?->recipeItems?->pluck('ingredient_id') ?? []);
            foreach ($oi->modifiers as $mod) {
                $recipeIngredientIds = $recipeIngredientIds->merge(
                    $mod->modifier?->recipeItems?->pluck('ingredient_id') ?? []
                );
            }
            if ($recipeIngredientIds->intersect($shortIngredientIds)->isNotEmpty()) {
                $shortItemIds[] = $oi->id;
            }
        }

        return ['issues' => $issues, 'short_item_ids' => $shortItemIds];
    }

    /**
     * Validate that every tracked ingredient needed to fulfill the given Order
     * has enough stock. Aggregates across all pending line items (so two items
     * sharing an ingredient are considered together). Returns an array of issues.
     *
     * Pass this to ::throwIfInsufficient() from OrderService.approve() to block
     * over-selling.
     */
    public function validateStockForOrder(\App\Models\Order $order): array
    {
        $items = $order->items()
            ->where('status', \App\Enums\OrderItemStatus::Pending->value)
            ->with('modifiers')
            ->get();

        $cart = [];
        foreach ($items as $oi) {
            $cart[] = [
                'menu_item_id' => $oi->menu_item_id,
                'quantity'     => (float) $oi->quantity,
                'modifier_ids' => $oi->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray(),
            ];
        }

        return $this->checkStockForOrderPreview($cart);
    }

    /**
     * Throw a descriptive exception if any stock issue exists.
     * Used as a guard before approving an order.
     */
    public function throwIfInsufficient(array $issues): void
    {
        if (empty($issues)) return;

        $lines = array_map(function ($i) {
            $req = number_format($i['required'], 2);
            $avail = number_format($i['available'], 2);
            return "  • {$i['ingredient']}: مطلوب {$req}، متاح {$avail}";
        }, $issues);

        throw new \RuntimeException(
            "لا يمكن اعتماد الطلب — المخزون غير كافٍ:\n" . implode("\n", $lines)
        );
    }
}
