<?php

namespace App\Observers;

use App\Models\Ingredient;
use App\Services\RecipeCostService;

/**
 * Watches Ingredient updates. When cost_per_unit changes, every MenuItem whose
 * recipe uses this ingredient has its denormalized `cost` column recomputed.
 *
 * This keeps COGS/profit reports accurate without running a full recompute
 * every page load.
 */
class IngredientObserver
{
    public function __construct(protected RecipeCostService $cost) {}

    public function updated(Ingredient $ingredient): void
    {
        // Only fire when the PRICE changed — stock-level changes (current_stock)
        // or metadata edits don't affect recipe cost.
        if ($ingredient->wasChanged('cost_per_unit')) {
            $this->cost->recomputeForIngredient($ingredient);
        }
    }
}
