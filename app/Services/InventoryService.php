<?php

namespace App\Services;

use App\Enums\OrderItemStatus;
use App\Helpers\UnitConverter;
use App\Models\BranchTransferItem;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StorageLocation;
use App\Services\Accounting\AccountingService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function previewDeductionForItem(
        MenuItem $item,
        float $quantity,
        array $modifierIds = [],
        array $excludedIngredientIds = [],
    ): array {
        $lines = [];
        $excluded = array_fill_keys(
            collect($excludedIngredientIds)->map(fn ($id) => (int) $id)->filter()->unique()->all(),
            true,
        );

        // Recipe lines use RecipeItem::quantityInBase() so the same
        // unit-resolution logic (ingredient-specific tbsp/scoop vs
        // global g/ml/pcs) is honored everywhere — preview, cost,
        // variance report — and can't drift between callers.
        foreach ($item->recipeItems as $recipe) {
            if (isset($excluded[(int) $recipe->ingredient_id])) {
                continue;
            }

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
        // Branch-pin first (same as deductForOrderItem): movements are
        // stamped with the ORDER's branch, so the idempotency sums must read
        // there too. Under a mismatched operator context the scoped sums saw
        // ZERO movements and deducted AGAIN — silent double-deduction.
        $order = $orderItem->order()->withoutGlobalScopes()->firstOrFail();
        $orderItem->setRelation('order', $order);

        return BranchContext::forBranch((int) $order->branch_id, function () use ($orderItem) {
            // NET-aware idempotency. Checking only for the existence of an 'out'
            // movement is wrong: unapprove() writes a 'return' but KEEPS the 'out',
            // so on a re-approve the stale 'out' would make us skip — leaking free
            // stock (deducted once, returned once, then served with no deduction).
            // Compare total out vs total returned: only skip when net is still out.
            $out = (float) InventoryMovement::where('reference_type', OrderItem::class)
                ->where('reference_id', $orderItem->id)
                ->where('type', 'out')
                ->sum('quantity_in_base');
            $returned = (float) InventoryMovement::where('reference_type', OrderItem::class)
                ->where('reference_id', $orderItem->id)
                ->where('type', 'return')
                ->sum('quantity_in_base');

            if ($out - $returned > 0.0001) {
                return false;
            }

            $this->deductForOrderItem($orderItem);

            return true;
        });
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
        // Resolve the parent order UNSCOPED first — Order itself is
        // branch-scoped, so under a mismatched operator context even the
        // relation load returns null (and would cache that null).
        $order = $orderItem->order()->withoutGlobalScopes()->firstOrFail();
        $orderItem->setRelation('order', $order);

        // Every scoped lookup below (menu item, station, default storage
        // location) must resolve inside the ORDER's branch — not the
        // operator's current context. An admin standing on branch A
        // approving branch B's order used to get a NULL menu item
        // (BranchScope filtered it out) and a TypeError from
        // previewDeductionForItem, and worse, StorageLocation::default()
        // would have picked branch A's store.
        BranchContext::forBranch((int) $order->branch_id, function () use ($orderItem) {
            DB::transaction(function () use ($orderItem) {
                $orderItem->loadMissing('station.storageLocation');
                $modifierIds = $orderItem->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray();
                // Eager-load the ingredient_unit too so previewDeductionForItem
                // can resolve "1 tbsp sugar" without hitting the DB per line.
                // withTrashed: a deleted dish on a historical order still owns
                // its recipe — deduction must not fatal on it.
                $item = $orderItem->menuItem()->withTrashed()
                    ->with('recipeItems.ingredient', 'recipeItems.ingredientUnit')
                    ->first();

                if (! $item) {
                    // Menu item hard-gone (never happens in normal flows) —
                    // nothing to deduct; log loudly instead of a TypeError.
                    Log::warning('deductForOrderItem: menu item missing, skipping deduction', [
                        'order_item_id' => $orderItem->id,
                        'menu_item_id' => $orderItem->menu_item_id,
                    ]);

                    return;
                }

                $excludedIngredientIds = $orderItem->exclusions()->whereNotNull('ingredient_id')->pluck('ingredient_id')->all();
                $lines = $this->previewDeductionForItem(
                    $item,
                    (float) $orderItem->quantity,
                    $modifierIds,
                    $excludedIngredientIds,
                );
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
            });
        });
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
        // Any batch history makes the batch ledger authoritative. If the only
        // remaining lots are expired, deductFifo() must report zero usable
        // stock instead of falling back to the aggregate counter.
        $hasBatches = IngredientBatch::where('ingredient_id', $ingredient->id)
            ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
            ->exists();

        if (! $hasBatches && $storageLocationId) {
            // Scope the "batches exist elsewhere" probe to the SAME branch as
            // the target location. An unrelated stranded lot in another branch
            // must not turn a legitimate local sale into a hard block; only a
            // same-branch batch (this ingredient IS batch-tracked here, just not
            // at this location) should force the operator to fix placement.
            $branchId = StorageLocation::whereKey($storageLocationId)->value('branch_id');
            $hasBatchesInOtherLocation = IngredientBatch::where('ingredient_id', $ingredient->id)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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

        // FIFO consumption and movement writes share this outer transaction,
        // so a strict location-stock failure rolls the batch rows back too.
        return DB::transaction(function () use ($ingredient, $qtyBase, $storageLocationId, $type, $reference, $reason, $userId) {
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
        });
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
        // Branch-pin (see deductForOrderItem): the original out-movements
        // live in the ORDER's branch; under a mismatched operator context
        // the scoped lookup found nothing and the waste silently vanished
        // from reports.
        $order = $orderItem->order()->withoutGlobalScopes()->firstOrFail();
        $orderItem->setRelation('order', $order);

        BranchContext::forBranch((int) $order->branch_id, function () use ($orderItem, $reason, $userId) {
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
        });
    }

    /**
     * Return stock for a cancelled OrderItem.
     */
    public function returnForOrderItem(OrderItem $orderItem): void
    {
        // Branch-pin (see deductForOrderItem): under a mismatched operator
        // context the scoped lookup found ZERO out-movements and the stock
        // return silently did nothing — cancelled food never went back.
        $order = $orderItem->order()->withoutGlobalScopes()->firstOrFail();
        $orderItem->setRelation('order', $order);

        BranchContext::forBranch((int) $order->branch_id, function () use ($orderItem) {
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
        });
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
        bool $syncBatches = false,
        ?string $movementUuid = null,
    ): InventoryMovement {
        $allowedTypes = ['in', 'out', 'waste', 'return', 'adjustment'];
        if (! in_array($type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['type' => 'نوع حركة المخزون غير صالح.']);
        }
        if (($type === 'adjustment' && abs($qtyBase) <= 0.0001)
            || ($type !== 'adjustment' && $qtyBase <= 0)) {
            throw ValidationException::withMessages(['quantity' => 'كمية حركة المخزون غير صالحة.']);
        }
        if ($unitCost < 0) {
            throw ValidationException::withMessages(['unit_cost' => 'تكلفة وحدة المخزون لا يمكن أن تكون سالبة.']);
        }

        [$mv, $crossedLowStock, $freshIngredient] = DB::transaction(function () use ($ingredient, $type, $qtyBase, $unitCost, $reference, $reason, $userId, $batchId, $wasteReason, $storageLocationId, $wasteReasonLookupId, $syncBatches, $movementUuid) {
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

                if (! $locId) {
                    throw ValidationException::withMessages([
                        'storage_location_id' => 'حدد موقع تخزين تابعاً للفرع قبل تسجيل حركة المخزون.',
                    ]);
                }

                $location = StorageLocation::withoutGlobalScopes()->whereKey($locId)->first();
                if (! $location || ! $location->active) {
                    throw ValidationException::withMessages([
                        'storage_location_id' => 'موقع التخزين غير موجود أو غير نشط.',
                    ]);
                }
                $expectedBranchId = $this->resolveReferenceBranchId($reference);
                if ($expectedBranchId && (int) $location->branch_id !== $expectedBranchId) {
                    throw ValidationException::withMessages([
                        'storage_location_id' => 'موقع التخزين لا يتبع فرع العملية.',
                    ]);
                }
                if ($batchId) {
                    $batchMatches = IngredientBatch::withoutGlobalScopes()
                        ->whereKey($batchId)
                        ->where('ingredient_id', $ingredient->id)
                        ->where('branch_id', $location->branch_id)
                        ->where(function ($query) use ($locId) {
                            $query->whereNull('storage_location_id')
                                ->orWhere('storage_location_id', $locId);
                        })
                        ->exists();
                    if (! $batchMatches) {
                        throw ValidationException::withMessages([
                            'batch_id' => 'الدفعة لا تطابق الصنف أو فرع وموقع حركة المخزون.',
                        ]);
                    }
                }

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

                    $locationBefore = (float) $locationStock->quantity;
                    if ($delta < 0
                        && (bool) Setting::get('strict_stock', config('restaurant.inventory.strict_stock', true))
                        && $locationBefore + $delta < -0.0001) {
                        throw ValidationException::withMessages([
                            'stock' => __('ui.inventory.shortage_line', [
                                'ingredient' => $ingredient->localizedName(),
                                'required' => number_format(abs($delta), 2),
                                'available' => number_format($locationBefore, 2),
                            ]),
                        ]);
                    }

                    $locationStock->update([
                        'quantity' => $locationBefore + $delta,
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

            // Keep FIFO batches in step with an UNMANAGED stock change (stock
            // count, manual ingredient adjustment, location/global waste).
            // Callers that manage batches themselves — PO receipt via
            // createBatchOnReceipt, a sale via deductFifo, or an explicit-batch
            // waste — leave $syncBatches false so we never double-count.
            // Without this, batch totals drift from ingredient_stock: a surplus
            // leaves batches SHORT (a later sale throws "batches < needed" and
            // blocks checkout), and a shortage leaves ghost batches that a later
            // sale re-costs, double-hitting COGS / inventory (1200).
            if ($syncBatches && $ingredient->track_stock && abs($delta) > 0.0001
                && IngredientBatch::where('ingredient_id', $ingredient->id)->exists()) {
                if ($delta > 0 && $storageLocationId) {
                    $this->batches->createBatchOnReceipt(
                        ingredient: $ingredient,
                        qtyBase: $delta,
                        unitCost: $unitCost,
                        source: $reference,
                        notes: $reason,
                        storageLocationId: $storageLocationId,
                    );
                } elseif ($delta < 0) {
                    $need = abs($delta);
                    $available = (float) IngredientBatch::where('ingredient_id', $ingredient->id)
                        ->when($storageLocationId, fn ($q) => $q->where('storage_location_id', $storageLocationId))
                        ->where('remaining_qty', '>', 0)
                        ->sum('remaining_qty');
                    if ($available > 0.0001) {
                        // Reconciliation/waste is allowed to remove quarantined
                        // expired lots; normal production FIFO never is.
                        $this->batches->deductFifo(
                            $ingredient,
                            min($need, $available),
                            $storageLocationId,
                            includeExpired: true,
                        );
                    }
                }
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
                'uuid' => $movementUuid,
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
        $branchId = $this->resolveReferenceBranchId($reference);

        if ($branchId) {
            $locId = StorageLocation::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->value('id');
            if ($locId) {
                return (int) $locId;
            }
        }

        // Never guess across branches. A movement without a branch signal is
        // rejected by recordMovement instead of leaking into an arbitrary
        // restaurant warehouse.
        return null;
    }

    /** Resolve the branch that owns a movement's business reference. */
    protected function resolveReferenceBranchId($reference = null): ?int
    {
        if ($reference && isset($reference->branch_id) && $reference->branch_id) {
            return (int) $reference->branch_id;
        }

        if ($reference instanceof PurchaseOrderItem) {
            $branchId = PurchaseOrder::withoutGlobalScopes()
                ->whereKey($reference->purchase_order_id)->value('branch_id');

            return $branchId ? (int) $branchId : null;
        }
        if ($reference instanceof StockCountItem) {
            $branchId = StockCount::withoutGlobalScopes()
                ->whereKey($reference->stock_count_id)->value('branch_id');

            return $branchId ? (int) $branchId : null;
        }
        if ($reference instanceof OrderItem) {
            $branchId = Order::withoutGlobalScopes()
                ->whereKey($reference->order_id)->value('branch_id');

            return $branchId ? (int) $branchId : null;
        }
        // A transfer item spans two branches; the current transfer leg is
        // explicitly pinned through BranchContext by BranchTransferService.
        if ($reference instanceof BranchTransferItem) {
            return BranchContext::current();
        }

        return BranchContext::current();
    }

    public function checkStockForOrderPreview(array $cartItems, ?int $branchId = null): array
    {
        $branchId ??= BranchContext::current();
        if (! $branchId && ($firstItemId = collect($cartItems)->pluck('menu_item_id')->filter()->first())) {
            $branchId = MenuItem::withoutGlobalScopes()->whereKey($firstItemId)->value('branch_id');
        }

        $check = function () use ($cartItems, $branchId) {
            $issues = [];
            $aggregated = [];

            foreach ($cartItems as $ci) {
                $item = MenuItem::with([
                    'recipeItems.ingredient',
                    'recipeItems.ingredientUnit',
                    'station.storageLocation',
                    'category.station.storageLocation',
                ])->find($ci['menu_item_id']);
                if (! $item) {
                    continue;
                }

                $storageLocationId = isset($ci['storage_location_id'])
                    ? (int) $ci['storage_location_id']
                    : null;
                if (! $storageLocationId && ! empty($ci['station_id'])) {
                    $storageLocationId = Station::whereKey($ci['station_id'])->value('storage_location_id');
                }
                $storageLocationId = $storageLocationId
                    ?: $item->station?->storage_location_id
                    ?: $item->category?->station?->storage_location_id
                    ?: StorageLocation::default()?->id;

                $modifierIds = $ci['modifier_ids'] ?? [];
                $lines = $this->previewDeductionForItem(
                    $item,
                    (float) $ci['quantity'],
                    $modifierIds,
                    $ci['excluded_ingredient_ids'] ?? [],
                );
                foreach ($lines as $line) {
                    // The same ingredient can be consumed at different stations.
                    // Keep those demands separate so stock at the bar never covers
                    // a shortage in the kitchen (or in another branch).
                    $key = $line['ingredient_id'].'@'.($storageLocationId ?: 'branch');
                    if (! isset($aggregated[$key])) {
                        $aggregated[$key] = [
                            'ingredient' => $line['ingredient'],
                            'storage_location_id' => $storageLocationId,
                            'qty' => 0,
                        ];
                    }
                    $aggregated[$key]['qty'] += $line['quantity_in_base'];
                }
            }

            foreach ($aggregated as $a) {
                $available = $a['storage_location_id']
                    ? $a['ingredient']->usableStockAtLocation((int) $a['storage_location_id'])
                    : ($branchId
                        ? $a['ingredient']->usableStockAtBranch((int) $branchId)
                        : $a['ingredient']->trackedUsableStock());

                if ($a['ingredient']->track_stock && $a['qty'] > $available + 0.0001) {
                    $issues[] = [
                        'ingredient' => $a['ingredient']->localizedName(),
                        'ingredient_id' => $a['ingredient']->id,
                        'storage_location_id' => $a['storage_location_id'],
                        'required' => $a['qty'],
                        'available' => $available,
                    ];
                }
            }

            return $issues;
        };

        return $branchId
            ? BranchContext::forBranch((int) $branchId, $check)
            : $check();
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
            ->with('menuItem.recipeItems', 'modifiers.modifier.recipeItems', 'exclusions')
            ->get();

        $shortItemIds = [];
        foreach ($items as $oi) {
            $excludedIngredientIds = $oi->exclusions->pluck('ingredient_id')->map(fn ($id) => (int) $id);
            $recipeIngredientIds = collect($oi->menuItem?->recipeItems?->pluck('ingredient_id') ?? [])
                ->map(fn ($id) => (int) $id)
                ->diff($excludedIngredientIds);
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
            ->with('modifiers', 'exclusions')
            ->get();

        $cart = [];
        foreach ($items as $oi) {
            $cart[] = [
                'menu_item_id' => $oi->menu_item_id,
                'quantity' => (float) $oi->quantity,
                'station_id' => $oi->station_id,
                'modifier_ids' => $oi->modifiers()->whereNotNull('modifier_id')->pluck('modifier_id')->toArray(),
                'excluded_ingredient_ids' => $oi->exclusions->pluck('ingredient_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            ];
        }

        return $this->checkStockForOrderPreview($cart, (int) $order->branch_id);
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
