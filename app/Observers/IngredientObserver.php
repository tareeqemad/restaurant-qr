<?php

namespace App\Observers;

use App\Models\Ingredient;
use App\Models\Setting;
use App\Services\RecipeCostService;

/**
 * Watches Ingredient lifecycle:
 *   - `saving()` — defensive guard against direct negative-stock writes.
 *   - `updated()` — recompute every dependent MenuItem.cost when price changes.
 */
class IngredientObserver
{
    public function __construct(protected RecipeCostService $cost) {}

    /**
     * The "blessed" path for stock changes is `InventoryService::recordMovement()`,
     * which writes a ledger row + updates `current_stock` inside a transaction.
     * Nothing prevents a controller / seeder / dev script from doing
     * `$ingredient->update(['current_stock' => -50])` directly — that bypasses
     * the ledger and produces data no report can explain.
     *
     * Reject that here when:
     *   - The SKU is tracked (`track_stock = true`)
     *   - The new value would go below zero
     *   - The global `restaurant.inventory.strict_stock` flag is on
     *
     * Doesn't block: untracked ingredients, strict mode off, or zero (a
     * legitimate "fully consumed" state).
     */
    public function saving(Ingredient $ingredient): void
    {
        if (! $ingredient->isDirty('current_stock')) return;
        if (! $ingredient->track_stock) return;
        if (! (bool) Setting::get('strict_stock', config('restaurant.inventory.strict_stock', true))) return;

        $newStock = (float) $ingredient->current_stock;
        if ($newStock < -0.0001) {
            throw new \RuntimeException(
                "رفض كتابة مخزون سالب على «{$ingredient->name}» ({$newStock})."
                . " استخدم InventoryService::recordMovement() لخصم المخزون،"
                . " أو عطّل strict_stock إذا أردت السماح."
            );
        }
    }

    public function updated(Ingredient $ingredient): void
    {
        // Only fire when the PRICE changed — stock-level changes (current_stock)
        // or metadata edits don't affect recipe cost.
        if ($ingredient->wasChanged('cost_per_unit')) {
            $this->cost->recomputeForIngredient($ingredient);
        }
    }
}
