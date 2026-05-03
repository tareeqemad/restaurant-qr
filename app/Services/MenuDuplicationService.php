<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\ModifierRecipeItem;
use App\Models\RecipeItem;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Duplicates the entire menu (categories + items + modifier groups + modifiers
 * + recipes + allergen + modifier-group associations) from one branch to
 * another.
 *
 * Use case: a partner just provisioned فرع غزة and wants the same starter
 * menu as فرع خانيونس instead of typing 75 dishes by hand.
 *
 * Contract:
 *   - The TARGET branch must be empty of categories AND menu items. We refuse
 *     to merge into a non-empty target — partial copies create duplicate-slug
 *     conflicts and silently lose customizations the owner had added; better
 *     a hard failure they can resolve than a quiet mess.
 *   - Source and target must be different branches.
 *   - Stations are mapped BY CODE: source's `kitchen` station maps to target's
 *     `kitchen` station. If the target lacks a matching code, the copied item
 *     gets `station_id = null` (won't appear on a KDS until the owner picks
 *     a station for it). Same logic for `categories.default_station_id`.
 *   - Recipes carry over as-is — `ingredients` and `units` are global, so
 *     ingredient_id / unit_id stay valid across branches.
 *   - The whole copy runs inside a DB transaction. A failure mid-stream
 *     leaves the target branch untouched.
 */
class MenuDuplicationService
{
    /**
     * Run the copy. Returns a counts breakdown for the UI / CLI to show.
     *
     * @return array{
     *   categories:int, items:int, modifier_groups:int, modifiers:int,
     *   recipes:int, modifier_recipes:int, item_modifier_links:int,
     *   item_allergen_links:int, items_without_station:int
     * }
     */
    public function duplicate(Branch $from, Branch $to): array
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('لا يمكن نسخ القائمة من فرع إلى نفسه.');
        }

        $existingCategories = Category::withoutGlobalScopes()->where('branch_id', $to->id)->count();
        $existingItems      = MenuItem::withoutGlobalScopes()->where('branch_id', $to->id)->count();
        if ($existingCategories > 0 || $existingItems > 0) {
            throw new RuntimeException(sprintf(
                'فرع «%s» يحتوي على بيانات قائمة بالفعل (%d تصنيف، %d صنف). أفرغها أولاً قبل النسخ.',
                $to->name, $existingCategories, $existingItems
            ));
        }

        return DB::transaction(function () use ($from, $to) {
            $stationMap = $this->buildStationMap($from->id, $to->id);

            $catMap = $this->copyCategories($from->id, $to->id, $stationMap);
            [$mgMap, $modMap, $modCount] = $this->copyModifierGroups($from->id, $to->id);
            [$itemMap, $itemsWithoutStation] = $this->copyMenuItems($from->id, $to->id, $catMap, $stationMap);

            $allergenLinks = $this->copyMenuItemAllergens($itemMap);
            $modGroupLinks = $this->copyMenuItemModifierGroupLinks($itemMap, $mgMap);
            $recipeCount   = $this->copyRecipeItems($itemMap);
            $modRecipeCount = $this->copyModifierRecipeItems($modMap);

            return [
                'categories'           => count($catMap),
                'items'                => count($itemMap),
                'modifier_groups'      => count($mgMap),
                'modifiers'            => $modCount,
                'recipes'              => $recipeCount,
                'modifier_recipes'     => $modRecipeCount,
                'item_modifier_links'  => $modGroupLinks,
                'item_allergen_links'  => $allergenLinks,
                'items_without_station' => $itemsWithoutStation,
            ];
        });
    }

    /**
     * Build a code→target_station_id map. Source station ids are translated
     * by looking up the station with the same `code` in the target branch.
     * Codes that don't exist in the target map to null — the calling copy
     * step writes null on the new row (item then has no KDS routing until
     * the owner adds the station and re-routes the item).
     *
     * @return array<int,?int>  source_station_id => target_station_id|null
     */
    protected function buildStationMap(int $fromId, int $toId): array
    {
        $sourceStations = Station::withoutGlobalScopes()->where('branch_id', $fromId)->get();
        $targetByCode   = Station::withoutGlobalScopes()->where('branch_id', $toId)->pluck('id', 'code');

        $map = [];
        foreach ($sourceStations as $s) {
            $map[$s->id] = $targetByCode[$s->code] ?? null;
        }
        return $map;
    }

    /**
     * @param array<int,?int> $stationMap
     * @return array<int,int>  source_category_id => target_category_id
     */
    protected function copyCategories(int $fromId, int $toId, array $stationMap): array
    {
        $sources = Category::withoutGlobalScopes()->where('branch_id', $fromId)->orderBy('display_order')->get();
        $map = [];

        foreach ($sources as $src) {
            $copy = Category::create([
                'branch_id'          => $toId,
                'slug'               => $src->slug,
                'name'               => $src->name,
                'name_en'            => $src->name_en,
                'description'        => $src->description,
                'description_en'     => $src->description_en,
                'image'              => $src->image,
                'icon'               => $src->icon,
                'color'              => $src->color,
                'default_station_id' => $src->default_station_id ? ($stationMap[$src->default_station_id] ?? null) : null,
                'display_order'      => $src->display_order,
                'active'             => $src->active,
            ]);
            $map[$src->id] = $copy->id;
        }

        return $map;
    }

    /**
     * @return array{0: array<int,int>, 1: array<int,int>, 2: int}
     *   [modifierGroupMap, modifierMap, modifierCount]
     */
    protected function copyModifierGroups(int $fromId, int $toId): array
    {
        $sources = ModifierGroup::withoutGlobalScopes()->where('branch_id', $fromId)->orderBy('display_order')->get();

        $mgMap  = [];
        $modMap = [];
        $modCount = 0;

        foreach ($sources as $src) {
            $copy = ModifierGroup::create([
                'branch_id'     => $toId,
                'slug'          => $src->slug,
                'name'          => $src->name,
                'name_en'       => $src->name_en,
                'min_select'    => $src->min_select,
                'max_select'    => $src->max_select,
                'required'      => $src->required,
                'display_order' => $src->display_order,
                'active'        => $src->active,
            ]);
            $mgMap[$src->id] = $copy->id;

            // Copy this group's modifier options (children).
            $sourceModifiers = Modifier::where('modifier_group_id', $src->id)->orderBy('display_order')->get();
            foreach ($sourceModifiers as $m) {
                $newMod = Modifier::create([
                    'modifier_group_id' => $copy->id,
                    'name'              => $m->name,
                    'name_en'           => $m->name_en,
                    'price_delta'       => $m->price_delta,
                    'active'            => $m->active,
                    'display_order'     => $m->display_order,
                ]);
                $modMap[$m->id] = $newMod->id;
                $modCount++;
            }
        }

        return [$mgMap, $modMap, $modCount];
    }

    /**
     * @param array<int,int>  $catMap
     * @param array<int,?int> $stationMap
     * @return array{0: array<int,int>, 1: int}  [itemMap, itemsWithoutStation]
     */
    protected function copyMenuItems(int $fromId, int $toId, array $catMap, array $stationMap): array
    {
        $sources = MenuItem::withoutGlobalScopes()
            ->where('branch_id', $fromId)
            ->orderBy('category_id')
            ->orderBy('display_order')
            ->get();

        $map = [];
        $itemsWithoutStation = 0;

        foreach ($sources as $src) {
            $newStation = $src->station_id ? ($stationMap[$src->station_id] ?? null) : null;
            if ($src->station_id && $newStation === null) {
                $itemsWithoutStation++;
            }

            $copy = MenuItem::create([
                'branch_id'          => $toId,
                'category_id'        => $catMap[$src->category_id] ?? null,
                'station_id'         => $newStation,
                'sku'                => $src->sku,
                'slug'               => $src->slug,
                'name'               => $src->name,
                'name_en'            => $src->name_en,
                'description'        => $src->description,
                'description_en'     => $src->description_en,
                'price'              => $src->price,
                'cost'               => $src->cost,
                'image'              => $src->image,
                'prep_time_minutes'  => $src->prep_time_minutes,
                'calories'           => $src->calories,
                'is_available'       => $src->is_available,
                'is_featured'        => $src->is_featured,
                'unavailable_reason' => $src->unavailable_reason,
                'display_order'      => $src->display_order,
            ]);
            $map[$src->id] = $copy->id;
        }

        return [$map, $itemsWithoutStation];
    }

    /**
     * Allergens are global — just re-attach the same allergen ids to the new
     * item rows. Returns the total count of pivot rows created.
     *
     * @param array<int,int> $itemMap
     */
    protected function copyMenuItemAllergens(array $itemMap): int
    {
        $count = 0;
        foreach ($itemMap as $sourceId => $targetId) {
            $allergenIds = DB::table('menu_item_allergens')
                ->where('menu_item_id', $sourceId)
                ->pluck('allergen_id')
                ->all();

            foreach ($allergenIds as $aid) {
                DB::table('menu_item_allergens')->insert([
                    'menu_item_id' => $targetId,
                    'allergen_id'  => $aid,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int,int> $itemMap
     * @param array<int,int> $mgMap
     */
    protected function copyMenuItemModifierGroupLinks(array $itemMap, array $mgMap): int
    {
        $count = 0;
        foreach ($itemMap as $sourceItemId => $targetItemId) {
            $rows = DB::table('menu_item_modifier_group')
                ->where('menu_item_id', $sourceItemId)
                ->get(['modifier_group_id', 'display_order']);

            foreach ($rows as $row) {
                if (! isset($mgMap[$row->modifier_group_id])) {
                    continue; // Defensive — should never happen since we copied all groups.
                }
                DB::table('menu_item_modifier_group')->insert([
                    'menu_item_id'      => $targetItemId,
                    'modifier_group_id' => $mgMap[$row->modifier_group_id],
                    'display_order'     => $row->display_order,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int,int> $itemMap
     */
    protected function copyRecipeItems(array $itemMap): int
    {
        $count = 0;
        foreach ($itemMap as $sourceItemId => $targetItemId) {
            $rows = RecipeItem::where('menu_item_id', $sourceItemId)->get();

            foreach ($rows as $r) {
                RecipeItem::create([
                    'menu_item_id' => $targetItemId,
                    'ingredient_id' => $r->ingredient_id, // global
                    'quantity'      => $r->quantity,
                    'unit_id'       => $r->unit_id,        // global
                    'is_optional'   => $r->is_optional,
                    'notes'         => $r->notes,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<int,int> $modMap
     */
    protected function copyModifierRecipeItems(array $modMap): int
    {
        $count = 0;
        foreach ($modMap as $sourceModId => $targetModId) {
            $rows = ModifierRecipeItem::where('modifier_id', $sourceModId)->get();

            foreach ($rows as $r) {
                ModifierRecipeItem::create([
                    'modifier_id'  => $targetModId,
                    'ingredient_id' => $r->ingredient_id,
                    'quantity'      => $r->quantity,
                    'unit_id'       => $r->unit_id,
                ]);
                $count++;
            }
        }
        return $count;
    }
}
