<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Helpers\UnitConverter;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorageLocation;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
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
     *
     * Composite-ingredient expansion: if a recipe line points at a
     * composite ingredient (one with `is_composite=true` and a stored
     * sub-recipe), it is recursively replaced by its raw inputs scaled
     * by `requested_qty / composite_yield`. Cycles are guarded against
     * via a `seen` set so a misconfigured A→B→A composite throws
     * instead of stack-overflowing.
     */
    public function previewDeductionForItem(MenuItem $item, float $quantity, array $modifierIds = []): array
    {
        $lines = [];

        // Recipe lines use RecipeItem::quantityInBase() so the same
        // unit-resolution logic (ingredient-specific tbsp/scoop vs
        // global g/ml/pcs) is honored everywhere — preview, cost,
        // variance report — and can't drift between callers.
        foreach ($item->recipeItems as $recipe) {
            $ingredient = $recipe->ingredient;
            // Ingredient hard-deleted out from under the recipe row → skip it
            // rather than 500. (Soft-deleted ones still resolve via withTrashed.)
            if (! $ingredient) {
                continue;
            }
            try {
                // A misconfigured line (e.g. a weight unit on a count-based
                // ingredient) makes UnitConverter throw. Skip that line rather
                // than crash every page that previews stock — same tolerance as
                // RecipeCostService::lineCost.
                $qtyBase = $recipe->quantityInBase() * $quantity;
            } catch (\Throwable) {
                continue;
            }
            $lines = array_merge($lines, $this->expandIngredient($ingredient, $qtyBase));
        }

        foreach (Modifier::with('recipeItems.ingredient', 'recipeItems.ingredientUnit')->findMany($modifierIds) as $modifier) {
            foreach ($modifier->recipeItems as $recipe) {
                $ingredient = $recipe->ingredient;
                if (! $ingredient) {
                    continue;
                }
                // Modifier recipe lines aren't RecipeItem instances —
                // they're ModifierRecipeItem — but they carry the same
                // shape (ingredient_unit_id + unit_id + quantity). Use
                // the matching ingredient-unit factor if set, else fall
                // back to the global UnitConverter.
                try {
                    if ($recipe->ingredient_unit_id && $recipe->ingredientUnit) {
                        $qtyBase = (float) $recipe->quantity * (float) $recipe->ingredientUnit->factor_to_base * $quantity;
                    } else {
                        $qtyBase = UnitConverter::convert((float) $recipe->quantity, $recipe->unit_id, $ingredient->base_unit_id) * $quantity;
                    }
                } catch (\Throwable) {
                    // Misconfigured unit pairing — skip rather than 500 the page.
                    continue;
                }
                $lines = array_merge($lines, $this->expandIngredient($ingredient, $qtyBase));
            }
        }

        return $lines;
    }

    /**
     * Expand a single (ingredient, qty) demand into one or more deduction
     * lines, recursing through composite sub-recipes until only raw
     * (non-composite) ingredients remain.
     *
     * Composite math: if 200g sugar + 100ml water → 280g sauce, and the
     * caller wants 30g of sauce, each sub-line is scaled by 30/280:
     *   sugar  = 200 × 30/280 ≈ 21.43g
     *   water  = 100 × 30/280 ≈ 10.71ml
     *
     * @param  array<int,true>  $seen  Ingredient IDs already expanded on this
     *                                 call-chain (cycle guard).
     * @return array<int,array{ingredient_id:int,ingredient:Ingredient,quantity_in_base:float,unit_cost:float,current_stock:float,will_be_negative:bool}>
     */
    protected function expandIngredient(Ingredient $ingredient, float $qtyBase, array $seen = []): array
    {
        // Cycle guard: A composite that references itself (directly or
        // through a chain) would loop forever. Refuse with a clear error
        // so the misconfiguration surfaces at preview time, not after a
        // ticket is half-deducted.
        if (isset($seen[$ingredient->id])) {
            throw new \RuntimeException(
                __('ui.inventory.composite_cycle', ['ingredient' => $ingredient->localizedName()])
            );
        }

        // Non-composite (or composite with no sub-recipe defined yet) —
        // deduct directly.
        if (! $ingredient->is_composite || ! $ingredient->subRecipe()->exists()) {
            return [[
                'ingredient_id' => $ingredient->id,
                'ingredient' => $ingredient,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => (float) $ingredient->cost_per_unit,
                'current_stock' => (float) $ingredient->current_stock,
                'will_be_negative' => $ingredient->track_stock
                                      && ((float) $ingredient->current_stock - $qtyBase) < 0,
            ]];
        }

        $yield = (float) ($ingredient->composite_yield ?? 0);
        if ($yield <= 0) {
            // Composite flagged but yield not configured → can't scale.
            // Fall back to raw deduction so the system never silently
            // skips the line.
            return [[
                'ingredient_id' => $ingredient->id,
                'ingredient' => $ingredient,
                'quantity_in_base' => $qtyBase,
                'unit_cost' => (float) $ingredient->cost_per_unit,
                'current_stock' => (float) $ingredient->current_stock,
                'will_be_negative' => $ingredient->track_stock
                                      && ((float) $ingredient->current_stock - $qtyBase) < 0,
            ]];
        }

        $scale = $qtyBase / $yield;
        $seen[$ingredient->id] = true;
        $expanded = [];

        foreach ($ingredient->subRecipe()->with('ingredient', 'unit', 'ingredientUnit')->get() as $line) {
            $child = $line->ingredient;
            // Same unit-resolution logic as top-level recipe lines —
            // sub-recipe ingredients can also be measured in tbsp/scoop.
            $childQtyBase = $line->quantityInBase() * $scale;

            $expanded = array_merge(
                $expanded,
                $this->expandIngredient($child, $childQtyBase, $seen),
            );
        }

        return $expanded;
    }

    /**
     * Idempotently deduct stock for an OrderItem. Safe to call from multiple
     * lifecycle hooks (approve → preparing → ready → served → invoice close)
     * because each call checks whether an `out` movement already exists for
     * this exact order_item and skips if so. The configured
     * `inventory.deduction_stage` decides which hook actually fires it; this
     * method just ensures "exactly once" semantics.
     */
    public function ensureDeducted(OrderItem $orderItem): bool
    {
        $alreadyDeducted = InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $orderItem->id)
            ->where('type', 'out')
            ->exists();

        if ($alreadyDeducted) {
            return false;
        }

        $this->deductForOrderItem($orderItem);

        return true;
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
        // Eager-load the ingredient_unit too so previewDeductionForItem
        // can resolve "1 tbsp sugar" without hitting the DB per line.
        $item = $orderItem->menuItem()->with('recipeItems.ingredient', 'recipeItems.ingredientUnit')->first();
        $lines = $this->previewDeductionForItem($item, (float) $orderItem->quantity, $modifierIds);
        $storageLocationId = $orderItem->station?->storage_location_id
            ?: StorageLocation::default()?->id;

        foreach ($lines as $line) {
            $this->deductWithBatchTrace(
                ingredient: $line['ingredient'],
                qtyBase: $line['quantity_in_base'],
                fallbackUnitCost: $line['unit_cost'],
                reference: $orderItem,
                reason: __('ui.inventory.deduct_order', ['number' => $orderItem->order->number]),
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
        if ($qtyBase <= 0) {
            return [];
        }

        // Probe whether batches exist for this ingredient. If not, single aggregate movement.
        $hasBatches = IngredientBatch::where('ingredient_id', $ingredient->id)
            ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
            ->where('remaining_qty', '>', 0)
            ->exists();

        if (! $hasBatches && $storageLocationId) {
            $hasBatchesInOtherLocation = IngredientBatch::where('ingredient_id', $ingredient->id)
                ->where('remaining_qty', '>', 0)
                ->exists();

            if ($hasBatchesInOtherLocation) {
                throw ValidationException::withMessages([
                    'stock' => __('ui.inventory.no_batches_for_storage', ['ingredient' => $ingredient->localizedName()]),
                ]);
            }
        }

        if (! $hasBatches) {
            return [
                $this->recordMovement(
                    ingredient: $ingredient,
                    type: $type,
                    qtyBase: $qtyBase,
                    unitCost: $fallbackUnitCost,
                    reference: $reference,
                    reason: $reason,
                    userId: $userId,
                    storageLocationId: $storageLocationId,
                ),
            ];
        }

        // FIFO consumption — batch service handles the per-batch decrement
        // inside its own transaction; we then write one movement per batch
        // touched, carrying the batch's actual unit_cost (not just average).
        $taken = $this->batches->deductFifo($ingredient, $qtyBase, $storageLocationId);

        $movements = [];
        foreach ($taken as $row) {
            /** @var IngredientBatch $batch */
            $batch = $row['batch'];
            $qty = (float) $row['qty'];

            $movements[] = $this->recordMovement(
                ingredient: $ingredient,
                type: $type,
                qtyBase: $qty,
                unitCost: (float) $batch->unit_cost,
                reference: $reference,
                reason: $reason,
                userId: $userId,
                batchId: $batch->id,
                storageLocationId: $storageLocationId,
            );
        }

        return $movements;
    }

    /**
     * Mark a cancelled OrderItem's prior deduction as waste rather
     * than returning it. Used when the kitchen already started prep
     * (opened a bag, fried the patty) and the food can't be reused.
     *
     * Behaviour:
     *   - Stock stays decremented (we don't add anything back).
     *   - A `waste` movement is logged for each ingredient that was
     *     consumed, mirroring the original `out` movement's quantity
     *     and unit_cost. The waste report + end-of-day inventory
     *     section pick it up automatically because both already query
     *     `inventory_movements` filtering by type.
     *   - Accounting follows the existing waste path
     *     (Dr WASTE_EXPENSE, Cr INVENTORY) via recordMovement().
     */
    public function convertOrderItemToWaste(OrderItem $orderItem, string $reason, ?int $userId = null): void
    {
        $movements = InventoryMovement::where('reference_type', OrderItem::class)
            ->where('reference_id', $orderItem->id)
            ->where('type', 'out')
            ->with(['ingredient', 'batch'])
            ->get();

        foreach ($movements as $mv) {
            // Log a parallel waste movement. NOTE: stock is NOT moved
            // here — recordMovement() WILL decrement again because
            // type='waste' is a negative direction, but we counter that
            // by passing the same qty as a positive adjustment first?
            // No — simpler: skip the stock-mutation step entirely by
            // writing the movement row directly.
            //
            // Why a direct insert is safer than recordMovement():
            // recordMovement also touches IngredientStock and
            // current_stock, which would double-deduct. The original
            // `out` already did the physical decrement; this row is
            // purely a re-classification for reporting.
            $wasteMv = InventoryMovement::create([
                'branch_id' => $mv->branch_id,
                'ingredient_id' => $mv->ingredient_id,
                'batch_id' => $mv->batch_id,
                'storage_location_id' => $mv->storage_location_id,
                'type' => 'waste',
                'quantity' => $mv->quantity,
                'unit_id' => $mv->unit_id,
                'quantity_in_base' => $mv->quantity_in_base,
                'unit_cost' => $mv->unit_cost,
                'total_cost' => $mv->total_cost,
                'stock_before' => (float) $mv->ingredient->current_stock,
                'stock_after' => (float) $mv->ingredient->current_stock, // no change — re-classification only
                'reference_type' => OrderItem::class,
                'reference_id' => $orderItem->id,
                'reason' => __('ui.inventory.cancel_item_waste', ['item' => $orderItem->name_snapshot]),
                'waste_reason' => $reason,
                'user_id' => $userId ?? auth()->id(),
                'occurred_at' => now(),
            ]);

            // Reclassify the original sale-deduction's accounting from
            // COGS to waste. We post DR 5400 / CR 5000 so the waste
            // report finally sees this cost (it was previously
            // invisible — the COGS already-recorded was the only entry).
            //
            // Inventory (1200) is NOT touched again — the original `out`
            // already decremented it; the convert-to-waste path is
            // purely a P&L category shift.
            try {
                app(AccountingService::class)
                    ->recordWasteReclassification(
                        $wasteMv,
                        __('ui.inventory.waste_reclassification', [
                            'item' => $orderItem->name_snapshot,
                            'reason' => $reason,
                        ]),
                    );
            } catch (\Throwable $e) {
                // Accounting is best-effort here; the operational
                // waste row is what the waste report reads.
                \Log::warning('waste_reclassification.accounting_failed', [
                    'movement_id' => $wasteMv->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
                reason: __('ui.inventory.return_cancel_item', ['item' => $orderItem->name_snapshot]),
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
                $branchId = StorageLocation::whereKey($storageLocationId)->value('branch_id');
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
            $branchId = $branchId ?: BranchContext::current();

            $mv = InventoryMovement::create([
                'branch_id' => $branchId,
                'ingredient_id' => $ingredient->id,
                'batch_id' => $batchId,
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

            app(AccountingService::class)->recordInventoryMovement($mv);

            // Edge-trigger: notify only when stock CROSSES the threshold,
            // never on every subsequent deduction that stays below. This
            // keeps the inbox clean — one alert per "you should reorder"
            // event, not one per ticket.
            $threshold = (float) $ingredient->reorder_threshold;
            $crossed = $ingredient->track_stock
                && $threshold > 0
                && $stockBefore > $threshold
                && $stockAfter <= $threshold;

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
        $branchId ??= BranchContext::current();

        if ($branchId) {
            $locId = StorageLocation::where('branch_id', $branchId)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->value('id');
            if ($locId) {
                return (int) $locId;
            }
        }

        // No branch context — pick any active location, default first.
        return StorageLocation::where('active', true)
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
            if (! $item) {
                continue;
            }
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
                    'ingredient' => $a['ingredient']->localizedName(),
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
    public function orderStockReport(Order $order): array
    {
        $issues = $this->validateStockForOrder($order);
        if (empty($issues)) {
            return ['issues' => [], 'short_item_ids' => []];
        }

        $shortIngredientIds = collect($issues)->pluck('ingredient_id')->filter()->all();

        $items = $order->items()
            ->where('status', OrderItemStatus::Pending->value)
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
    public function validateStockForOrder(Order $order): array
    {
        $items = $order->items()
            ->where('status', OrderItemStatus::Pending->value)
            ->with('modifiers')
            ->get();

        $cart = [];
        foreach ($items as $oi) {
            $cart[] = [
                'menu_item_id' => $oi->menu_item_id,
                'quantity' => (float) $oi->quantity,
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
        if (empty($issues)) {
            return;
        }

        $lines = array_map(function ($i) {
            $req = number_format($i['required'], 2);
            $avail = number_format($i['available'], 2);

            return __('ui.inventory.shortage_line', [
                'ingredient' => $i['ingredient'],
                'required' => $req,
                'available' => $avail,
            ]);
        }, $issues);

        throw new \RuntimeException(
            __('ui.inventory.insufficient_order_stock', ['lines' => implode("\n", $lines)])
        );
    }
}
