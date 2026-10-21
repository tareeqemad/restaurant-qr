<?php

namespace App\Services;

use App\Models\RecipeItem;
use App\Models\StorageLocation;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Collection;

class MenuAvailabilityService
{
    public function __construct(private InventoryService $inventory) {}

    /** One fresh snapshot per catalogue; recipe calculation remains in InventoryService. */
    public function forItems(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $items->loadMissing(['station:id,storage_location_id', 'category.station:id,storage_location_id', 'recipeItems.unit', 'recipeItems.ingredientUnit', 'recipeItems.ingredient.baseUnit']);
        $recipes = $items->flatMap->recipeItems;
        $ingredients = $recipes->pluck('ingredient')->filter()->keyBy('id');
        foreach ($recipes as $recipe) {
            if ($recipe->ingredient) {
                $recipe->setRelation('ingredient', $ingredients[$recipe->ingredient_id]);
            }
        }

        // Breadth-first loading is bounded by recipe depth. Canonical instances
        // let shared ingredients reuse the graph; the domain cycle guard remains active.
        $visited = [];
        while (true) {
            $frontier = $ingredients->filter(fn ($ingredient) => $ingredient->is_composite && ! isset($visited[$ingredient->id]));
            if ($frontier->isEmpty()) {
                break;
            }
            $children = RecipeItem::whereIn('parent_ingredient_id', $frontier->keys())
                ->with(['unit', 'ingredientUnit', 'ingredient.baseUnit'])->get();
            foreach ($frontier as $ingredient) {
                $visited[$ingredient->id] = true;
                $ingredient->setRelation('subRecipe', $children->where('parent_ingredient_id', $ingredient->id)->values());
            }
            foreach ($children as $recipe) {
                if ($recipe->ingredient) {
                    $ingredients->put($recipe->ingredient_id, $ingredients->get($recipe->ingredient_id, $recipe->ingredient));
                    $recipe->setRelation('ingredient', $ingredients[$recipe->ingredient_id]);
                }
            }
        }

        $stock = UsableStockSnapshot::capture($ingredients->keys()->all());
        $availability = [];
        $defaultLocations = [];
        foreach ($items as $item) {
            $needs = [];
            foreach ($this->inventory->previewDeductionForItem($item, 1.0) as $line) {
                if (! $line['ingredient']->track_stock) {
                    continue;
                }
                $id = $line['ingredient_id'];
                $needs[$id] = ($needs[$id] ?? 0.0) + (float) $line['quantity_in_base'];
            }
            $available = true;
            $branchId = (int) ($item->branch_id ?: BranchContext::current());
            $locationId = $item->station?->storage_location_id ?: $item->category?->station?->storage_location_id;
            if (! $locationId && $needs) {
                if (! array_key_exists($branchId, $defaultLocations)) {
                    $defaultLocations[$branchId] = BranchContext::forBranch($branchId, fn () => StorageLocation::default()?->id);
                }
                $locationId = $defaultLocations[$branchId];
            }
            foreach ($needs as $id => $quantity) {
                $balance = $locationId
                    ? $stock->atLocation($id, $locationId)
                    : ($branchId ? $stock->atBranch($id, $branchId) : $stock->allBranches($id));
                if ($quantity > $balance + 0.0001) {
                    $available = false;
                    break;
                }
            }
            $availability[$item->id] = $available;
        }

        return $availability;
    }
}
