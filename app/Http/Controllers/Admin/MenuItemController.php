<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Allergen;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientUnit;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\ModifierGroup;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Station;
use App\Models\Unit;
use App\Services\PromotionService;
use App\Services\RecipeCostService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MenuWorkspace;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);

        // One card needs its recipe, resolved preparation station and the
        // labels customers see. Eager-load those once; stockShortages() still
        // owns the live inventory calculation and therefore stays canonical.
        $q = MenuItem::with([
            'category:id,name,default_station_id',
            'category.station:id,name,color',
            'station:id,name,color',
            'recipeItems.ingredient.baseUnit',
            'allergens:id,name,icon',
            'modifierGroups:id,name',
        ]);

        if ($s = trim((string) $request->get('search'))) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%");
            });
        }
        if ($c = $request->get('category_id')) {
            $q->where('category_id', $c);
        }
        if ($station = $request->get('station_id')) {
            $q->where('station_id', $station);
        }
        if ($status = $request->get('status')) {
            match ($status) {
                'available' => $q->where('is_available', true),
                'unavailable' => $q->where('is_available', false),
                'featured' => $q->where('is_featured', true),
                'without_recipe' => $q->doesntHave('recipeItems'),
                default => null,
            };
        } elseif ($request->filled('only_unavailable')) {
            // Backward-compatible dashboard links.
            $q->where('is_available', false);
        }

        $items = $q->orderBy('display_order')->paginate(20)->withQueryString();
        $resolvedPrices = app(PromotionService::class)->resolveBulk(
            $items->getCollection(),
            now(),
            BranchContext::current(),
        );

        $stats = [
            'total' => MenuItem::count(),
            'available' => MenuItem::where('is_available', true)->count(),
            'featured' => MenuItem::where('is_featured', true)->count(),
            'unavailable' => MenuItem::where('is_available', false)->count(),
            'withoutRecipe' => MenuItem::doesntHave('recipeItems')->count(),
        ];

        $user = auth()->user();
        $canUpdate = (bool) $user?->can('update', MenuItem::class);
        $canDelete = (bool) $user?->can('delete', MenuItem::class);
        $canToggle = (bool) $user?->can('toggleAvailability', MenuItem::class);

        $items->through(function (MenuItem $item) use ($canUpdate, $canDelete, $canToggle, $resolvedPrices) {
            $basePrice = (float) $item->price;
            $priceData = $resolvedPrices[$item->id] ?? ['promotion' => null, 'price' => $basePrice];
            $promotion = $priceData['promotion'];
            $price = (float) $priceData['price'];
            $hasPromotion = $promotion !== null && $price < $basePrice;
            $cost = (float) $item->cost;
            $profit = $price - $cost;
            $margin = $price > 0 && $cost > 0 ? round(($profit / $price) * 100, 1) : null;
            $shortages = $item->recipeItems->isNotEmpty() ? $item->stockShortages() : [];

            return [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'description' => $item->description,
                'imageUrl' => $item->imageUrl(),
                'placeholderUrl' => MenuItem::placeholderImageUrl(),
                'category' => $item->category?->name,
                'station' => $item->station?->name ?: $item->category?->station?->name,
                'price' => Money::format($price),
                'basePrice' => Money::format($basePrice),
                'hasPromotion' => $hasPromotion,
                'promotionName' => $hasPromotion ? $promotion->name : null,
                'cost' => $cost > 0 ? Money::format($cost) : null,
                'profit' => $cost > 0 ? Money::format($profit) : null,
                'margin' => $margin,
                'prepMinutes' => (int) ($item->prep_time_minutes ?? 0),
                'isAvailable' => (bool) $item->is_available,
                'isFeatured' => (bool) $item->is_featured,
                'unavailableReason' => $item->unavailable_reason,
                'recipe' => $item->recipeItems->map(fn (RecipeItem $row) => [
                    'name' => $row->ingredient?->name ?? 'مكوّن محذوف',
                    'quantity' => (float) $row->quantity,
                    'unit' => $row->ingredient?->baseUnit?->name,
                ])->values(),
                'allergens' => $item->allergens->map(fn (Allergen $allergen) => [
                    'name' => $allergen->name,
                    'icon' => $allergen->icon,
                ])->values(),
                'modifierGroups' => $item->modifierGroups->pluck('name')->values(),
                'shortages' => collect($shortages)->map(fn (array $shortage) => [
                    'ingredient' => $shortage['ingredient'],
                    'required' => round((float) $shortage['required'], 4),
                    'available' => round((float) $shortage['available'], 4),
                ])->values(),
                'can' => [
                    'update' => $canUpdate,
                    'delete' => $canDelete,
                    'toggle' => $canToggle,
                ],
                'urls' => [
                    'show' => route('admin.menu-items.show', $item),
                    'edit' => route('admin.menu-items.edit', $item),
                    'destroy' => route('admin.menu-items.destroy', $item),
                    'toggle' => route('admin.menu-items.toggle-availability', $item),
                ],
            ];
        });

        return AdminShell::render('Admin/MenuItems/Index', [
            'navigation' => MenuWorkspace::navigation(),
            'items' => $items,
            'categories' => Category::where('active', true)->orderBy('display_order')->get(['id', 'name']),
            'stations' => Station::where('active', true)->orderBy('display_order')->get(['id', 'name']),
            'stats' => $stats,
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'categoryId' => (string) $request->get('category_id', ''),
                'stationId' => (string) $request->get('station_id', ''),
                'status' => (string) ($request->get('status') ?: ($request->filled('only_unavailable') ? 'unavailable' : '')),
            ],
            'can' => [
                'create' => (bool) $user?->can('create', MenuItem::class),
                'recomputeCosts' => (bool) $user?->can('create', MenuItem::class),
            ],
            'urls' => [
                'index' => route('admin.menu-items.index'),
                'create' => route('admin.menu-items.create'),
                'recomputeCosts' => route('admin.menu-items.recompute-costs'),
            ],
        ]);
    }

    public function show(Request $request, MenuItem $menuItem)
    {
        $this->authorize('view', $menuItem);

        $menuItem->load([
            'category:id,name,default_station_id',
            'category.station:id,name,color',
            'station:id,name,color',
            'recipeItems.ingredient.baseUnit',
            'allergens:id,name,icon',
            'modifierGroups:id,name',
            'priceHistory.changer:id,name',
        ]);

        $basePrice = (float) $menuItem->price;
        $promotion = $menuItem->activePromotion();
        $effectivePrice = $promotion ? $promotion->applyTo($basePrice) : $basePrice;
        $hasPromotion = $promotion !== null && $effectivePrice < $basePrice;
        $cost = (float) $menuItem->cost;
        $profit = $effectivePrice - $cost;
        $margin = $effectivePrice > 0 && $cost > 0
            ? round(($profit / $effectivePrice) * 100, 1)
            : null;

        $sales = OrderItem::query()
            ->where('menu_item_id', $menuItem->id)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity_sold')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM(unit_price * quantity), 0) as item_revenue')
            ->selectRaw('MAX(created_at) as last_sold_at')
            ->first();
        $quantitySold = (float) ($sales?->quantity_sold ?? 0);

        $promotions = MenuPromotion::withTrashed()
            ->where(function ($query) use ($menuItem) {
                $query->whereNull('branch_id')->orWhere('branch_id', $menuItem->branch_id);
            })
            ->where(function ($query) use ($menuItem) {
                $query->where(function ($target) use ($menuItem) {
                    $target->where('target_type', MenuPromotion::TARGET_MENU_ITEM)
                        ->where('target_id', $menuItem->id);
                })->orWhere(function ($target) use ($menuItem) {
                    $target->where('target_type', MenuPromotion::TARGET_CATEGORY)
                        ->where('target_id', $menuItem->category_id);
                });
            })
            ->latest('id')
            ->get()
            ->filter(fn (MenuPromotion $row) => $row->appliesToItem($menuItem))
            ->map(function (MenuPromotion $row) use ($basePrice) {
                $now = now();
                $offerPrice = $row->applyTo($basePrice);
                $status = $row->trashed()
                    ? 'deleted'
                    : (! $row->active
                        ? 'paused'
                        : ($row->starts_at?->gt($now)
                            ? 'upcoming'
                            : ($row->ends_at?->lt($now)
                                ? 'expired'
                                : ($row->isLiveAt($now) ? 'live' : 'outside'))));

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'typeLabel' => $row->typeLabel(),
                    'valueLabel' => $row->valueLabel(),
                    'scheduleLabel' => $row->scheduleLabel(),
                    'status' => $status,
                    'offerPrice' => Money::format($offerPrice),
                    'hasPriceDiscount' => $offerPrice < $basePrice,
                    'scope' => $row->target_type === MenuPromotion::TARGET_MENU_ITEM ? 'هذا الصنف' : 'قسم '.$menuItem->category?->name,
                ];
            })
            ->values();

        $card = [
            'item' => [
                'id' => $menuItem->id,
                'name' => $menuItem->name,
                'sku' => $menuItem->sku,
                'description' => $menuItem->description,
                'imageUrl' => $menuItem->imageUrl(),
                'category' => $menuItem->category?->name,
                'station' => $menuItem->station?->name ?: $menuItem->category?->station?->name,
                'basePrice' => Money::format($basePrice),
                'effectivePrice' => Money::format($effectivePrice),
                'hasPromotion' => $hasPromotion,
                'promotionName' => $hasPromotion ? $promotion->name : null,
                'cost' => Money::format($cost),
                'profit' => Money::format($profit),
                'margin' => $margin,
                'isAvailable' => (bool) $menuItem->is_available,
                'isFeatured' => (bool) $menuItem->is_featured,
                'prepMinutes' => (int) $menuItem->prep_time_minutes,
                'recipe' => $menuItem->recipeItems->map(fn (RecipeItem $row) => [
                    'id' => $row->id,
                    'ingredient' => $row->ingredient?->name ?? 'مكوّن محذوف',
                    'quantity' => (float) $row->quantity,
                    'unit' => $row->ingredient?->baseUnit?->name,
                    'optional' => (bool) $row->is_optional,
                    'url' => $row->ingredient ? route('admin.ingredients.show', $row->ingredient) : null,
                ])->values(),
                'allergens' => $menuItem->allergens->pluck('name')->values(),
                'modifierGroups' => $menuItem->modifierGroups->pluck('name')->values(),
            ],
            'sales' => [
                'quantity' => $quantitySold,
                'revenue' => Money::format((float) ($sales?->revenue ?? 0)),
                'averagePrice' => Money::format($quantitySold > 0 ? (float) $sales->item_revenue / $quantitySold : 0),
                'lastSoldAt' => $sales?->last_sold_at ? Carbon::parse($sales->last_sold_at)->format('Y-m-d H:i') : null,
            ],
            'priceHistory' => $menuItem->priceHistory->take(50)->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->change_type,
                'oldPrice' => $row->old_price !== null ? Money::format((float) $row->old_price) : null,
                'newPrice' => Money::format((float) $row->new_price),
                'reason' => $row->reason,
                'changedBy' => $row->changer?->name ?: 'النظام',
                'changedAt' => $row->changed_at?->format('Y-m-d H:i'),
            ])->values(),
            'promotions' => $promotions,
            'can' => [
                'update' => (bool) auth()->user()?->can('update', $menuItem),
                'createPromotion' => (bool) auth()->user()?->hasPermission('promotions.create'),
            ],
            'urls' => [
                'index' => route('admin.menu-items.index'),
                'edit' => route('admin.menu-items.edit', $menuItem),
                'promotions' => route('admin.promotions.index'),
                'createPromotion' => route('admin.promotions.create', [
                    'target_type' => 'menu_item',
                    'target_id' => $menuItem->id,
                ]),
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json($card);
        }

        return AdminShell::render('Admin/MenuItems/Show', [
            'navigation' => MenuWorkspace::navigation(),
            ...$card,
        ]);
    }

    public function create()
    {
        $this->authorize('create', MenuItem::class);

        return $this->renderForm('create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $this->valid($request);
        $recipe = $data['recipe'] ?? [];
        $allergenIds = $data['allergens'] ?? [];
        $modifierGroupIds = $data['modifier_groups'] ?? [];
        unset($data['recipe'], $data['allergens'], $data['modifier_groups'], $data['price_change_reason']);

        if ($request->hasFile('image')) {
            // 'menu-items' (NOT 'menu'): it's the only dir whitelisted in
            // storage/app/public/.gitignore, so uploads there survive the
            // manual git-pull deploy flow. Old 'menu/...' paths in the DB
            // keep working — imageUrl() reads the stored path as-is.
            $data['image'] = $request->file('image')->store('menu-items', 'public');
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

        return $this->renderForm('edit', $menuItem);
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $this->authorize('update', MenuItem::class);
        $data = $this->valid($request);
        $recipe = $data['recipe'] ?? [];
        $allergenIds = $data['allergens'] ?? [];
        $modifierGroupIds = $data['modifier_groups'] ?? [];
        $priceChangeReason = $data['price_change_reason'] ?? null;
        unset($data['recipe'], $data['allergens'], $data['modifier_groups'], $data['price_change_reason']);
        $oldPrice = (float) $menuItem->price;

        if ($request->hasFile('image')) {
            if ($menuItem->image) {
                Storage::disk('public')->delete($menuItem->image);
            }
            // See store(): 'menu-items' is the deploy-whitelisted upload dir.
            $data['image'] = $request->file('image')->store('menu-items', 'public');
        }

        DB::transaction(function () use ($menuItem, $data, $recipe, $allergenIds, $modifierGroupIds, $priceChangeReason) {
            $menuItem->priceChangeReason = $priceChangeReason;
            $menuItem->priceChangedByUserId = auth()->id();
            $menuItem->update($data);
            $this->syncRecipe($menuItem, $recipe);
            $menuItem->allergens()->sync($allergenIds);
            $menuItem->modifierGroups()->sync($modifierGroupIds);
        });

        if (abs($oldPrice - (float) $menuItem->price) > 0.001) {
            ActivityLog::log(
                'menu_item.price_changed',
                "تغيير السعر الأساسي للصنف {$menuItem->name}",
                $menuItem,
                [
                    'old_price' => $oldPrice,
                    'new_price' => (float) $menuItem->price,
                    'reason' => $priceChangeReason,
                ],
            );
        }

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

    protected function renderForm(string $mode, ?MenuItem $item = null)
    {
        $data = $this->formData();

        return AdminShell::render('Admin/MenuItems/Form', [
            'navigation' => MenuWorkspace::navigation(),
            'mode' => $mode,
            'item' => $item ? [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'description' => $item->description,
                'categoryId' => $item->category_id,
                'stationId' => $item->station_id,
                'price' => (float) $item->price,
                'prepMinutes' => $item->prep_time_minutes,
                'calories' => $item->calories,
                'displayOrder' => $item->display_order,
                'isAvailable' => (bool) $item->is_available,
                'isFeatured' => (bool) $item->is_featured,
                'unavailableReason' => $item->unavailable_reason,
                'imageUrl' => $item->imageUrl(),
                'allergenIds' => $item->allergens->pluck('id')->values(),
                'modifierGroupIds' => $item->modifierGroups->pluck('id')->values(),
                'recipe' => $item->recipeItems->map(fn (RecipeItem $row) => [
                    'ingredient_id' => $row->ingredient_id,
                    'quantity' => (float) $row->quantity,
                    'unit_id' => $row->ingredient_unit_id ? 'iu:'.$row->ingredient_unit_id : 'u:'.$row->unit_id,
                    'is_optional' => (bool) $row->is_optional,
                ])->values(),
            ] : null,
            'categories' => $data['categories'],
            'stations' => $data['stations'],
            'allergens' => $data['allergens'],
            'modifierGroups' => $data['modifierGroups'],
            'ingredients' => $data['ingredients'],
            'currencySymbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol')),
            'submitUrl' => $mode === 'create'
                ? route('admin.menu-items.store')
                : route('admin.menu-items.update', $item),
            'urls' => [
                'index' => route('admin.menu-items.index'),
                'categories' => route('admin.categories.index'),
                'modifiers' => route('admin.modifiers.index'),
                'allergens' => route('admin.allergens.index'),
                'ingredients' => route('admin.ingredients.index'),
                'promotions' => route('admin.promotions.index'),
            ],
        ]);
    }

    protected function formData(): array
    {
        return [
            'categories' => Category::where('active', true)->orderBy('display_order')->get(['id', 'name']),
            'stations' => Station::where('active', true)->orderBy('display_order')->get(['id', 'name']),
            'allergens' => Allergen::orderBy('display_order')->get(['id', 'name', 'icon']),
            'modifierGroups' => ModifierGroup::with(['modifiers' => fn ($q) => $q->orderBy('display_order')])
                ->where('active', true)
                ->orderBy('display_order')
                ->get()
                ->map(fn (ModifierGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'required' => (bool) $group->required,
                    'minSelect' => (int) $group->min_select,
                    'maxSelect' => (int) $group->max_select,
                    'options' => $group->modifiers->map(fn ($modifier) => [
                        'id' => $modifier->id,
                        'name' => $modifier->name,
                        'priceDelta' => (float) $modifier->price_delta,
                    ])->values(),
                ])->values(),
            'ingredients' => Ingredient::with('baseUnit')
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Ingredient $ingredient) => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'baseUnitId' => $ingredient->base_unit_id,
                    'baseUnitName' => $ingredient->baseUnit?->name,
                    'baseUnitCode' => $ingredient->baseUnit?->code,
                    'unitType' => $ingredient->baseUnit?->unit_type,
                ])->values(),
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
            // Split it back into the two FK columns the schema expects. A newly
            // added row may arrive before the user picked a unit (empty / "u:"
            // / "iu:" with no id), so every branch falls back to the
            // ingredient's base unit — never 0/null, which would violate the
            // recipe_items.unit_id foreign key and 500 the save.
            $ingredient = Ingredient::find($r['ingredient_id']);
            $baseUnitId = $ingredient?->base_unit_id;

            $unitId = $baseUnitId;
            $ingredientUnitId = null;
            $raw = trim((string) ($r['unit_id'] ?? ''));

            if (str_starts_with($raw, 'iu:')) {
                $iuId = (int) substr($raw, 3);
                // Only honour the per-ingredient unit if it really exists and
                // belongs to this ingredient; otherwise fall back to base unit.
                $iu = $iuId > 0
                    ? IngredientUnit::where('id', $iuId)
                        ->where('ingredient_id', $r['ingredient_id'])
                        ->first()
                    : null;
                $ingredientUnitId = $iu?->id;
                // unit_id stays the ingredient base unit (set above).
            } elseif (str_starts_with($raw, 'u:')) {
                $picked = (int) substr($raw, 2);
                $unitId = $picked > 0 ? $picked : $baseUnitId;
            } elseif ($raw !== '') {
                $picked = (int) $raw;
                $unitId = $picked > 0 ? $picked : $baseUnitId;
            }

            // Last line of defence: a free-form ingredient with no base unit and
            // no valid pick can't be stored against the FK — skip rather than 500.
            if (! $unitId) {
                continue;
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
        $validator = Validator::make($request->all(), [
            'category_id' => ['required', 'exists:categories,id'],
            'station_id' => ['nullable', 'exists:stations,id'],
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'price_change_reason' => ['nullable', 'string', 'max:300'],
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

        // Catch incompatible unit pairings up front with a clear message that
        // names the ingredient and both units — instead of silently storing a
        // line that later breaks cost/stock math. Only the "u:" (global unit)
        // path can mismatch; per-ingredient units ("iu:") are by definition
        // expressed in the ingredient's own base unit.
        $validator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('recipe', []) as $i => $row) {
                $ingredientId = $row['ingredient_id'] ?? null;
                $raw = trim((string) ($row['unit_id'] ?? ''));

                if (! $ingredientId || $raw === '') {
                    continue;
                }

                if (str_starts_with($raw, 'iu:')) {
                    $ingredientUnitId = (int) substr($raw, 3);
                    $validIngredientUnit = $ingredientUnitId > 0
                        && IngredientUnit::whereKey($ingredientUnitId)
                            ->where('ingredient_id', $ingredientId)
                            ->exists();
                    if (! $validIngredientUnit) {
                        $validator->errors()->add(
                            "recipe.{$i}.unit_id",
                            'وحدة الصنف المختارة لا تتبع المكوّن المحدد.'
                        );
                    }

                    continue;
                }

                // Modern values are "u:5"; bare numeric ids are accepted for
                // older forms but receive the exact same existence/type checks.
                $unitId = str_starts_with($raw, 'u:')
                    ? (int) substr($raw, 2)
                    : (int) $raw;
                if ($unitId <= 0) {
                    continue;
                }

                $ingredient = Ingredient::with('baseUnit')->find($ingredientId);
                $unit = Unit::find($unitId);
                $baseUnit = $ingredient?->baseUnit;

                if (! $unit) {
                    $validator->errors()->add(
                        "recipe.{$i}.unit_id",
                        'وحدة القياس المختارة غير موجودة.'
                    );

                    continue;
                }

                if (! $ingredient || ! $baseUnit) {
                    continue;
                }

                if ($unit->unit_type !== $baseUnit->unit_type) {
                    $validator->errors()->add(
                        "recipe.{$i}.unit_id",
                        "وحدة «{$unit->name}» لا تتوافق مع المكوّن «{$ingredient->name}» ".
                        "الذي يُقاس بـ«{$baseUnit->name}». اختر وحدة من نفس النوع."
                    );
                }
            }
        });

        return $validator->validate();
    }
}
