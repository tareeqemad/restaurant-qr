<?php

namespace App\Services;

use App\Helpers\UnitConverter;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\RecipeItem;
use Illuminate\Support\Collection;

/**
 * Computes MenuItem.cost (COGS) from recipe ingredients.
 *
 * For a menu item, the cost is the sum over every recipe line of:
 *     quantity_in_ingredient_base_unit × ingredient.cost_per_unit
 *
 * Optional ingredients (is_optional) are excluded by default — they're paid add-ons
 * that apply only when the modifier is selected, so they don't belong in the base cost.
 *
 * The computed value is written back to `menu_items.cost` so it can be joined
 * into reports (P&L, margins) without expensive recomputation every read.
 */
class RecipeCostService
{
    /**
     * Calculate (without saving) the base cost of producing one menu item unit.
     */
    public function costForMenuItem(MenuItem $item): float
    {
        $item->loadMissing('recipeItems.ingredient.baseUnit', 'recipeItems.unit');

        $total = 0.0;
        foreach ($item->recipeItems as $line) {
            if ($line->is_optional) continue;
            $total += $this->lineCost($line);
        }
        return round($total, 4);
    }

    /**
     * Calculate the incremental cost added when a customer picks this modifier
     * (e.g., extra cheese). Returns 0 if the modifier has no recipe.
     */
    public function costForModifier(Modifier $modifier): float
    {
        $modifier->loadMissing('recipeItems.ingredient.baseUnit', 'recipeItems.unit');

        $total = 0.0;
        foreach ($modifier->recipeItems ?? collect() as $line) {
            $total += $this->lineCost($line);
        }
        return round($total, 4);
    }

    /**
     * Persist the computed cost back onto the menu item.
     * Returns the new cost.
     */
    public function recomputeMenuItem(MenuItem $item): float
    {
        $cost = $this->costForMenuItem($item);
        if ((float) $item->cost !== $cost) {
            $item->forceFill(['cost' => $cost])->save();
        }
        return $cost;
    }

    /**
     * Recompute costs for ALL menu items. Returns number of items whose cost changed.
     * Use after a bulk ingredient price update or during initial seeding.
     */
    public function recomputeAll(): int
    {
        $changed = 0;
        MenuItem::with('recipeItems.ingredient.baseUnit', 'recipeItems.unit')
            ->chunk(100, function ($items) use (&$changed) {
                foreach ($items as $item) {
                    $old = (float) $item->cost;
                    $new = $this->recomputeMenuItem($item);
                    if ($old !== $new) $changed++;
                }
            });
        return $changed;
    }

    /**
     * Given an ingredient, recompute costs of every menu item that uses it.
     * Called from the IngredientObserver when cost_per_unit changes.
     * Returns number of affected menu items.
     */
    public function recomputeForIngredient(Ingredient $ingredient): int
    {
        $affected = MenuItem::whereHas('recipeItems', fn($q) => $q->where('ingredient_id', $ingredient->id))
            ->with('recipeItems.ingredient.baseUnit', 'recipeItems.unit')
            ->get();

        foreach ($affected as $item) {
            $this->recomputeMenuItem($item);
        }
        return $affected->count();
    }

    /**
     * Cost breakdown for the UI — returns a line-by-line array so the admin
     * can see exactly where the cost comes from.
     */
    public function breakdownForMenuItem(MenuItem $item): array
    {
        $item->loadMissing('recipeItems.ingredient.baseUnit', 'recipeItems.unit');

        $lines = [];
        $total = 0.0;
        foreach ($item->recipeItems as $line) {
            $cost = $line->is_optional ? 0.0 : $this->lineCost($line);
            if (!$line->is_optional) $total += $cost;
            $lines[] = [
                'ingredient'  => $line->ingredient?->name ?? '—',
                'quantity'    => (float) $line->quantity,
                'unit'        => $line->unit?->code ?? '',
                'cost_per_unit'=> (float) ($line->ingredient?->cost_per_unit ?? 0),
                'line_cost'   => $cost,
                'is_optional' => (bool) $line->is_optional,
            ];
        }

        return [
            'lines' => $lines,
            'total' => round($total, 4),
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Convert one recipe line into base-unit cost:
     *   qty_in_base = qty × (factor_of_recipe_unit / factor_of_ingredient_base_unit)
     *   cost = qty_in_base × ingredient.cost_per_unit
     *
     * Returns 0 if the line is malformed (missing ingredient or unit mismatch).
     */
    protected function lineCost(RecipeItem|\App\Models\ModifierRecipeItem $line): float
    {
        $ingredient = $line->ingredient;
        if (!$ingredient) return 0.0;

        // Path 1: ingredient-specific unit (tbsp, scoop) — direct
        // multiplication, no UnitConverter detour. Matches the
        // deduction math in InventoryService so cost + actual usage
        // are computed against the same base-unit quantity.
        if ($line->ingredient_unit_id) {
            $unit = $line->ingredientUnit;
            if ($unit) {
                return (float) $line->quantity
                    * (float) $unit->factor_to_base
                    * (float) $ingredient->cost_per_unit;
            }
        }

        // Path 2: global unit conversion (legacy).
        $recipeUnitId = $line->unit_id ?? $ingredient->base_unit_id;
        $baseUnitId   = $ingredient->base_unit_id;

        try {
            $qtyBase = UnitConverter::convert((float) $line->quantity, $recipeUnitId, $baseUnitId);
        } catch (\Throwable $e) {
            // Unit mismatch (e.g., trying to use a weight unit for a volume ingredient)
            // — skip gracefully rather than crash the whole computation.
            return 0.0;
        }

        return $qtyBase * (float) $ingredient->cost_per_unit;
    }
}
