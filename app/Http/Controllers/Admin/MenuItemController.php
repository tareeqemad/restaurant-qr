<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\RecipeItem;
use App\Models\Station;
use App\Models\Unit;
use App\Services\RecipeCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);
        // Eager-load recipe + ingredient so the index view can render
        // a live "نفد المخزون" badge without an N+1 across the rows.
        $q = MenuItem::with(['category', 'station', 'recipeItems.ingredient']);
        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")->orWhere('sku', 'like', "%$s%");
            });
        }
        if ($c = $request->get('category_id')) {
            $q->where('category_id', $c);
        }
        if ($request->filled('only_unavailable')) {
            $q->where('is_available', false);
        }
        $items = $q->orderBy('display_order')->paginate(20)->withQueryString();

        $stats = [
            'total' => MenuItem::count(),
            'available' => MenuItem::where('is_available', true)->count(),
            'featured' => MenuItem::where('is_featured', true)->count(),
            'unavailable' => MenuItem::where('is_available', false)->count(),
        ];

        return view('admin.menu-items.index', [
            'items' => $items,
            'categories' => Category::where('active', true)->get(),
            'stats' => $stats,
        ]);
    }

    public function create()
    {
        $this->authorize('create', MenuItem::class);

        return view('admin.menu-items.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $this->valid($request);
        $recipe = $data['recipe'] ?? [];
        $allergenIds = $data['allergens'] ?? [];
        $modifierGroupIds = $data['modifier_groups'] ?? [];
        unset($data['recipe'], $data['allergens'], $data['modifier_groups']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('menu', 'public');
        }

        DB::transaction(function () use ($data, $recipe, $allergenIds, $modifierGroupIds) {
            $item = MenuItem::create($data);
            $this->syncRecipe($item, $recipe);
            $item->allergens()->sync($allergenIds);
            $item->modifierGroups()->sync($modifierGroupIds);
        });

        return redirect()->route('admin.menu-items.index')->with('success', 'تم إنشاء الصنف');
    }

    public function edit(MenuItem $menuItem)
    {
        $this->authorize('update', MenuItem::class);
        $menuItem->load('recipeItems.ingredient', 'allergens', 'modifierGroups');

        return view('admin.menu-items.edit', array_merge($this->formData(), ['item' => $menuItem]));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $this->authorize('update', MenuItem::class);
        $data = $this->valid($request);
        $recipe = $data['recipe'] ?? [];
        $allergenIds = $data['allergens'] ?? [];
        $modifierGroupIds = $data['modifier_groups'] ?? [];
        unset($data['recipe'], $data['allergens'], $data['modifier_groups']);

        if ($request->hasFile('image')) {
            if ($menuItem->image) {
                Storage::disk('public')->delete($menuItem->image);
            }
            $data['image'] = $request->file('image')->store('menu', 'public');
        }

        DB::transaction(function () use ($menuItem, $data, $recipe, $allergenIds, $modifierGroupIds) {
            $menuItem->update($data);
            $this->syncRecipe($menuItem, $recipe);
            $menuItem->allergens()->sync($allergenIds);
            $menuItem->modifierGroups()->sync($modifierGroupIds);
        });

        return redirect()->route('admin.menu-items.index')->with('success', 'تم التحديث');
    }

    public function destroy(MenuItem $menuItem)
    {
        $this->authorize('delete', MenuItem::class);
        $menuItem->delete();

        return back()->with('success', 'تم الحذف');
    }

    public function toggleAvailability(Request $request, MenuItem $menuItem)
    {
        $this->authorize('toggleAvailability', MenuItem::class);
        $menuItem->update([
            'is_available' => ! $menuItem->is_available,
            'unavailable_reason' => $menuItem->is_available ? $request->input('reason') : null,
        ]);

        return back()->with('success', 'تم تغيير توفر الصنف');
    }

    /**
     * Recompute the stored cost of every menu item from its recipe.
     * Useful after bulk ingredient price changes.
     */
    public function recomputeCosts(RecipeCostService $service)
    {
        $this->authorize('create', MenuItem::class);
        $changed = $service->recomputeAll();

        return back()->with('success', "تم تحديث تكلفة {$changed} صنف من الوصفات.");
    }

    protected function formData(): array
    {
        return [
            'categories' => Category::where('active', true)->orderBy('display_order')->get(),
            'stations' => Station::where('active', true)->get(),
            'allergens' => Allergen::orderBy('display_order')->get(),
            'modifierGroups' => ModifierGroup::with(['modifiers' => fn ($q) => $q->orderBy('display_order')])
                ->where('active', true)
                ->orderBy('display_order')
                ->get(),
            'ingredients' => Ingredient::where('active', true)->orderBy('name')->get(),
            'units' => Unit::all(),
        ];
    }

    protected function syncRecipe(MenuItem $item, array $recipe): void
    {
        $item->recipeItems()->delete();

        // recipe_items carries a UNIQUE(menu_item_id, ingredient_id), so the
        // same ingredient must appear at most once. If the form sends it twice
        // (a duplicate dropdown pick, or a leftover blank row) a naive insert
        // loop hits a 1062 duplicate-key error → 500. Collapse duplicates by
        // ingredient first: last row wins for the unit, quantities sum.
        $rows = [];
        foreach ($recipe as $r) {
            if (empty($r['ingredient_id']) || empty($r['quantity'])) {
                continue;
            }

            // The form sends ONE select for "unit" with a value prefix:
            //   "u:5"  → global Unit id 5
            //   "iu:9" → IngredientUnit id 9 (tbsp/scoop for this ingredient)
            // Split it back into the two FK columns the schema expects.
            $unitId = null;
            $ingredientUnitId = null;
            $raw = (string) ($r['unit_id'] ?? '');
            if (str_starts_with($raw, 'iu:')) {
                $ingredientUnitId = (int) substr($raw, 3);
                // Default unit_id to the ingredient's base unit so the
                // NOT NULL constraint stays happy and a stale lookup
                // through `unit` still resolves to something sensible.
                $ingredientUnitId = $ingredientUnitId ?: null;
                $ingredient = Ingredient::find($r['ingredient_id']);
                $unitId = $ingredient?->base_unit_id;
            } else {
                $unitId = (int) (str_starts_with($raw, 'u:') ? substr($raw, 2) : $raw);
            }

            $ingredientId = (int) $r['ingredient_id'];
            if (isset($rows[$ingredientId])) {
                // Same ingredient again → fold the quantity in, keep this row's
                // unit/optional as the latest intent.
                $rows[$ingredientId]['quantity'] += (float) $r['quantity'];
                $rows[$ingredientId]['unit_id'] = $unitId;
                $rows[$ingredientId]['ingredient_unit_id'] = $ingredientUnitId;
                $rows[$ingredientId]['is_optional'] = ! empty($r['is_optional']);

                continue;
            }

            $rows[$ingredientId] = [
                'menu_item_id' => $item->id,
                'ingredient_id' => $ingredientId,
                'quantity' => (float) $r['quantity'],
                'unit_id' => $unitId,
                'ingredient_unit_id' => $ingredientUnitId,
                'is_optional' => ! empty($r['is_optional']),
            ];
        }

        foreach ($rows as $row) {
            RecipeItem::create($row);
        }
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'station_id' => ['nullable', 'exists:stations,id'],
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0'],
            'calories' => ['nullable', 'integer'],
            'display_order' => ['nullable', 'integer'],
            'is_available' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'unavailable_reason' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:5120'],
            'allergens' => ['array'],
            'allergens.*' => ['exists:allergens,id'],
            'modifier_groups' => ['array'],
            'modifier_groups.*' => ['exists:modifier_groups,id'],
            'recipe' => ['array'],
            'recipe.*.ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'recipe.*.quantity' => ['nullable', 'numeric', 'min:0'],
            // The unit select sends a prefixed value — "u:5" (global Unit) or
            // "iu:9" (per-ingredient unit), a bare id for legacy rows. syncRecipe()
            // resolves it into the real unit_id / ingredient_unit_id FKs, so here
            // we only validate the shape, not exists:units,id (which "u:5" fails).
            'recipe.*.unit_id' => ['nullable', 'string', 'regex:/^(u:|iu:)?\d+$/'],
            'recipe.*.is_optional' => ['sometimes', 'boolean'],
        ]);
    }
}
