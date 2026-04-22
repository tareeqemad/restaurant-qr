<?php

namespace App\Observers;

use App\Models\MenuItem;
use App\Models\RecipeItem;
use App\Services\RecipeCostService;

/**
 * When a recipe line is added/edited/removed, recompute its parent menu item's cost.
 *
 * Because `deleted` doesn't have access to fresh relations, we resolve the
 * parent MenuItem via menu_item_id directly.
 */
class RecipeItemObserver
{
    public function __construct(protected RecipeCostService $cost) {}

    public function saved(RecipeItem $line): void
    {
        $this->recompute($line->menu_item_id);
    }

    public function deleted(RecipeItem $line): void
    {
        $this->recompute($line->menu_item_id);
    }

    protected function recompute(?int $menuItemId): void
    {
        if (!$menuItemId) return;
        $item = MenuItem::with('recipeItems.ingredient.baseUnit', 'recipeItems.unit')->find($menuItemId);
        if ($item) {
            $this->cost->recomputeMenuItem($item);
        }
    }
}
