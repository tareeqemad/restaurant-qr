<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\QuantityFormatter;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * The `currency` prop every migrated page consumes as `currency.symbol`.
     * Resolved exactly the way App\Helpers\Money::format() resolves it (DB
     * setting first, config fallback) so a restaurant that changed its
     * symbol in Settings sees the same symbol here as everywhere else.
     */
    protected function currencyProp(): array
    {
        return [
            'symbol'   => \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'decimals' => 2,
        ];
    }

    /** number_format(x, 4) with trailing zeros trimmed — the index cost column. */
    protected static function trim4(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ','), '0'), '.');
    }

    /** rtrim(rtrim((string) $value, '0'), '.') — the recipe/pack quantity display. */
    protected static function trimRaw($value): string
    {
        return rtrim(rtrim((string) $value, '0'), '.');
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $branchId = \App\Support\BranchContext::current();

        // Eager-load per-location stocks (with their location → branch) so
        // the index can show WHERE each ingredient is physically stored
        // without firing N+1 queries. When a branch is active we restrict
        // the loaded stocks to that branch's locations only.
        $q = Ingredient::with([
            'baseUnit',
            'supplier',
            'stocks' => function ($query) use ($branchId) {
                $query->where('quantity', '>', 0)
                      ->with(['location:id,name,code,branch_id']);
                if ($branchId) {
                    $query->whereHas('location', fn ($l) => $l->where('branch_id', $branchId));
                }
            },
        ]);
        if ($s = $request->get('search')) $q->where('name', 'like', "%$s%");

        // Location filter — restricts the result set to ingredients that have
        // ANY positive stock at the chosen storage location. This is what the
        // user actually means by "show me what's in المطبخ" — they want only
        // rows that physically exist there, not all ingredients with المطبخ
        // listed somewhere in their breakdown.
        if ($locationId = $request->get('storage_location_id')) {
            $q->whereHas('stocks', function ($s) use ($locationId) {
                $s->where('storage_location_id', $locationId)
                  ->where('quantity', '>', 0);
            });
        }

        // Stock-status filter — exactly the same predicate the stat cards
        // use, so clicking a card always lands you on the matching rows.
        // Old behaviour mixed "low" with "out" (stock=0 satisfies <=threshold)
        // which made the KPI count and the filtered table disagree.
        $statusFilter = $request->get('status') ?: ($request->filled('low_stock') ? 'low' : null);
        if ($statusFilter) {
            $tracked = Ingredient::where('track_stock', true)->get();
            $matchIds = match ($statusFilter) {
                'low' => $tracked->filter(function ($i) use ($branchId) {
                    $stock = $branchId ? $i->usableStockAtBranch($branchId) : $i->trackedUsableStock();
                    $threshold = $branchId ? $i->reorderThresholdAtBranch($branchId) : (float) $i->reorder_threshold;
                    return $stock > 0 && $threshold > 0 && $stock <= $threshold;
                })->pluck('id'),
                'out' => $tracked->filter(fn ($i) =>
                    ($branchId ? $i->usableStockAtBranch($branchId) : $i->trackedUsableStock()) <= 0
                )->pluck('id'),
                'healthy' => $tracked->filter(function ($i) use ($branchId) {
                    $stock = $branchId ? $i->usableStockAtBranch($branchId) : $i->trackedUsableStock();
                    $threshold = $branchId ? $i->reorderThresholdAtBranch($branchId) : (float) $i->reorder_threshold;
                    return $stock > 0 && ($threshold <= 0 || $stock > $threshold);
                })->pluck('id'),
                default => null,
            };
            if ($matchIds !== null) $q->whereIn('id', $matchIds);
        }

        // Export the filtered list as a real .xlsx file. Honours the same
        // search + status filters as the table.
        if ($request->get('export') === 'csv' || $request->get('export') === 'xlsx') {
            return $this->exportXlsx($q->orderBy('name')->get(), $branchId);
        }

        $ingredients = $q->orderBy('name')->paginate(20)->withQueryString();

        // Stats are also branch-aware when in branch context.
        if ($branchId) {
            $tracked  = Ingredient::where('track_stock', true)->get();
            $lowCount = $tracked->filter(fn ($i) =>
                $i->isLowStockAtBranch($branchId)
                && $i->usableStockAtBranch($branchId) > 0
            )->count();
            $outCount = $tracked->filter(fn ($i) => $i->usableStockAtBranch($branchId) <= 0)->count();
            $healthy  = $tracked->count() - $lowCount - $outCount;
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => $lowCount,
                'out_stock' => $outCount,
                'healthy'   => max(0, $healthy),
            ];
        } else {
            $tracked = Ingredient::where('track_stock', true)->get();
            $lowCount = $tracked->filter(function ($i) {
                $stock = $i->trackedUsableStock();
                return $stock > 0
                    && (float) $i->reorder_threshold > 0
                    && $stock <= (float) $i->reorder_threshold;
            })->count();
            $outCount = $tracked->filter(fn ($i) => $i->trackedUsableStock() <= 0)->count();
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => $lowCount,
                'out_stock' => $outCount,
                'healthy'   => max(0, $tracked->count() - $lowCount - $outCount),
            ];
        }

        // Total inventory value (capital tied up in stock) for the current
        // view. For a specific branch we use that branch's weighted-average
        // cost; for "all branches" we SUM the per-branch valuations rather
        // than multiplying total stock by the global cost — the latter
        // mixes a lifetime average with current quantities and disagrees
        // with the per-branch totals. Honours the active status filter so
        // the banner number matches the rows you can actually see.
        $totalInventoryValue = Ingredient::where('track_stock', true)->get()
            ->sum(function ($i) use ($branchId, $statusFilter) {
                $stock = $branchId ? $i->usableStockAtBranch($branchId) : $i->trackedUsableStock();
                $thr   = $branchId ? $i->reorderThresholdAtBranch($branchId) : (float) $i->reorder_threshold;

                $matches = match ($statusFilter) {
                    'low'     => $stock > 0 && $thr > 0 && $stock <= $thr,
                    'out'     => $stock <= 0,
                    'healthy' => $stock > 0 && ($thr <= 0 || $stock > $thr),
                    default   => true,
                };
                if (! $matches) return 0;

                return $branchId ? $i->valueAtBranch($branchId) : $i->trackedValue();
            });

        // Locations available for the filter dropdown — branch-scoped when a
        // branch is active so the user only sees their own locations. Owner-
        // level on "كل الفروع" sees every active location, prefixed with the
        // branch name in the option label so a "مطبخ" exists per branch.
        $filterLocations = StorageLocation::where('active', true)
            ->when($branchId, fn ($q2) => $q2->where('branch_id', $branchId))
            ->with('branch:id,name')
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'branch_id', 'is_default']);

        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->name : null;

        // Every unit, grouped by measurement type, for the per-row "تسجيل
        // حركة" sheet. The Blade fired one Unit query PER ROW inside the
        // loop; one grouped query renders the identical option list.
        $unitsByType = Unit::orderBy('name')->get()
            ->groupBy('unit_type')
            ->map(fn ($group) => $group->map(fn ($u) => [
                'id'    => (int) $u->id,
                'label' => $u->name.' ('.$u->code.')',
            ])->values()->all())
            ->toArray();

        // Each stat card links back to the index with its filter applied;
        // clicking the active card a second time clears it. `search` is
        // preserved so the user doesn't lose their query. (Was a closure
        // inside the Blade.)
        $linkFor = function (?string $status) use ($statusFilter, $request) {
            $params = $request->only('search');
            if ($status && $statusFilter !== $status) {
                $params['status'] = $status;
            }

            return route('admin.ingredients.index', $params);
        };
        $activeMark = fn (?string $key) => (($statusFilter === $key) || (! $statusFilter && $key === null)) ? ' • نشط' : '';

        $canManage = Gate::allows('manage', Ingredient::class);

        $ingredients->through(function (Ingredient $ing) use ($branchId, $canManage, $filterLocations) {
            // Per-branch view when a branch is active; otherwise the
            // cross-branch sum of ingredient_stock (the per-location truth
            // table). Identical formulas to the retired Blade.
            $bookStock = $branchId ? $ing->stockAtBranch($branchId)            : $ing->trackedStock();
            $stock     = $branchId ? $ing->usableStockAtBranch($branchId)      : $ing->trackedUsableStock();
            $unusable  = max(0, $bookStock - $stock);
            $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId) : (float) $ing->reorder_threshold;

            $value = $branchId ? $ing->valueAtBranch($branchId) : $ing->trackedValue();
            $cost  = $branchId
                ? $ing->costAtBranch($branchId)
                : ($bookStock > 0 ? $value / $bookStock : (float) $ing->cost_per_unit);

            $statusKey = ! $ing->track_stock
                ? 'untracked'
                : ($stock <= 0
                    ? 'out'
                    : ($threshold > 0 && $stock <= $threshold ? 'low' : 'healthy'));

            $baseCode = $ing->baseUnit?->code;

            return [
                'id'             => (int) $ing->id,
                'name'           => $ing->name,
                'sku'            => $ing->sku,
                'baseCode'       => $baseCode,
                'measurementType'=> $ing->baseUnit?->unit_type,
                'statusKey'      => $statusKey,
                'trackStock'     => (bool) $ing->track_stock,
                'tracksExpiry'   => (bool) $ing->tracks_expiry,
                'stockLabel'     => QuantityFormatter::smart($stock, $baseCode),
                'stockExact'     => number_format($stock, 2),
                'unusableLabel'  => $unusable > 0.0001 ? QuantityFormatter::smart($unusable, $baseCode) : null,
                'thresholdLabel' => QuantityFormatter::smart($threshold, $baseCode),
                'thresholdExact' => number_format($threshold, 2),
                'costLabel'      => static::trim4($cost),
                // Only when a branch context narrows the cost away from the
                // ingredient's global weighted average.
                'globalCostLabel' => ($branchId && abs($cost - (float) $ing->cost_per_unit) > 0.0001)
                    ? static::trim4((float) $ing->cost_per_unit)
                    : null,
                'value'        => round($value, 2),
                'valueTooltip' => 'القيمة الدفترية قبل إثبات الهالك: '
                    .number_format($bookStock, 2).' '.($baseCode ?? '')
                    .' × '.static::trim4($cost).' = '.number_format($value, 2),
                'locations' => $ing->stocks->sortByDesc('quantity')->values()->map(function ($st) use ($ing, $baseCode) {
                    $usable = $ing->usableStockAtLocation((int) $st->storage_location_id);

                    return [
                        'name'    => $st->location?->name ?? '—',
                        'qty'     => QuantityFormatter::smart($usable, $baseCode),
                        'tooltip' => ($st->location?->name ?? 'موقع محذوف')
                            .' — صالح: '.number_format($usable, 2).' '.($baseCode ?? ''),
                    ];
                })->all(),
                // Adjust-sheet seed — the modal the Blade rendered per row.
                'adjust' => [
                    'currentStockLabel' => QuantityFormatter::smart($stock, $baseCode),
                    'currentStockExact' => number_format($stock, 2),
                    'baseUnitId'        => (int) $ing->base_unit_id,
                    'costPerUnit'       => $cost,
                    'locations'         => $filterLocations->map(function ($location) use ($ing, $baseCode, $branchId) {
                        $locationStock = $ing->usableStockAtLocation((int) $location->id);
                        $locationCost = $ing->costAtBranch((int) $location->branch_id);

                        return [
                            'id'         => (int) $location->id,
                            'branchId'   => (int) $location->branch_id,
                            'label'      => (! $branchId && $location->branch
                                ? $location->branch->name.' · '
                                : '').$location->name,
                            'isDefault'  => (bool) $location->is_default,
                            'stockLabel' => QuantityFormatter::smart($locationStock, $baseCode),
                            'stockExact' => number_format($locationStock, 4),
                            'unitCost'   => round($locationCost, 4),
                        ];
                    })->values()->all(),
                ],
                'urls' => [
                    'show'         => route('admin.ingredients.show', $ing),
                    'edit'         => route('admin.ingredients.edit', $ing),
                    'destroy'      => route('admin.ingredients.destroy', $ing),
                    'adjust'       => route('admin.ingredients.adjust', $ing),
                    'vendorPrices' => route('admin.vendor-prices.ingredient', $ing),
                ],
                'can' => ['manage' => $canManage],
            ];
        });

        return AdminShell::render('Admin/Ingredients/Index', [
            'ingredients' => $ingredients,
            'stats'       => [
                'total'     => (int) $stats['total'],
                'low_stock' => (int) $stats['low_stock'],
                'out_stock' => (int) $stats['out_stock'],
                'healthy'   => (int) $stats['healthy'],
            ],
            'statCards' => [
                ['key' => null,        'label' => 'إجمالي المكونات'.$activeMark(null),    'value' => (int) $stats['total'],     'icon' => 'bi-basket2-fill',              'color' => 'primary', 'link' => $linkFor(null)],
                ['key' => 'healthy',   'label' => 'مخزون صحي'.$activeMark('healthy'),     'value' => (int) $stats['healthy'],   'icon' => 'bi-check-circle-fill',         'color' => 'success', 'link' => $linkFor('healthy')],
                ['key' => 'low',       'label' => 'مخزون منخفض'.$activeMark('low'),       'value' => (int) $stats['low_stock'], 'icon' => 'bi-exclamation-triangle-fill', 'color' => 'accent',  'link' => $linkFor('low')],
                ['key' => 'out',       'label' => 'نفد المخزون'.$activeMark('out'),       'value' => (int) $stats['out_stock'], 'icon' => 'bi-x-octagon-fill',            'color' => 'danger',  'link' => $linkFor('out')],
            ],
            'statusFilter' => $statusFilter,
            'filters'      => [
                'search'              => $request->get('search'),
                'storage_location_id' => $request->get('storage_location_id'),
                'status'              => $statusFilter,
            ],
            'hasActionFilters' => $request->hasAny(['search', 'status', 'low_stock']),
            'hasFilters'       => $request->hasAny(['search', 'storage_location_id', 'status']),
            'filterLocations'  => $filterLocations->map(fn ($loc) => [
                'id'    => (int) $loc->id,
                'label' => (! $branchId && $loc->branch ? $loc->branch->name.' · ' : '')
                    .$loc->name.($loc->code ? " ({$loc->code})" : ''),
            ])->values()->all(),
            'totalInventoryValue' => round((float) $totalInventoryValue, 2),
            'statusFilterLabel'   => ['healthy' => 'صحي', 'low' => 'منخفض', 'out' => 'نفد'][$statusFilter] ?? null,
            'branchName'          => $branchName,
            'unitsByType'         => $unitsByType,
            'today'               => now()->toDateString(),
            'currency'            => $this->currencyProp(),
            'can'                 => ['manage' => $canManage],
            'urls'                => [
                'index'  => route('admin.ingredients.index'),
                'create' => route('admin.ingredients.create'),
                'export' => route('admin.ingredients.index', array_merge(
                    $request->only(['search', 'status', 'storage_location_id']),
                    ['export' => 'xlsx'],
                )),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('manage', Ingredient::class);

        return AdminShell::render('Admin/Ingredients/Create', $this->formData() + [
            'ingredient' => null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('manage', Ingredient::class);
        $data = $this->valid($request);

        // Branch + location are required — they declare where this ingredient
        // *lives*, even before any stock arrives. Future POs and transfers
        // default to this home, and the ingredient never ends up with
        // phantom (location-less) stock.
        $stockData = $request->validate([
            'branch_id'           => ['required', 'exists:branches,id'],
            'storage_location_id' => ['required', 'exists:storage_locations,id'],
            'initial_quantity'    => ['nullable', 'numeric', 'min:0'],
            'initial_expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
        ], [], [
            'branch_id'           => 'الفرع',
            'storage_location_id' => 'موقع التخزين',
            'initial_quantity'    => 'الكمية الأولية',
        ]);

        $branchId   = (int) $stockData['branch_id'];
        $locationId = (int) $stockData['storage_location_id'];
        $initialQty = (float) ($stockData['initial_quantity'] ?? 0);
        $initialExpiry = $stockData['initial_expiry_date'] ?? null;

        if ($initialQty > 0 && ! empty($data['tracks_expiry']) && ! $initialExpiry) {
            throw ValidationException::withMessages([
                'initial_expiry_date' => 'تاريخ صلاحية الكمية الافتتاحية مطلوب لهذا الصنف.',
            ]);
        }

        // Cross-check that the chosen location actually belongs to the
        // chosen branch — otherwise the home would point at the wrong place.
        $loc = StorageLocation::find($locationId);
        if (! $loc || (int) $loc->branch_id !== $branchId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'storage_location_id' => 'موقع التخزين لا ينتمي إلى الفرع المختار.',
            ]);
        }

        // Lock branch-restricted users to their own branch.
        $user = auth()->user();
        if ($user && ! $user->isOwnerLevel()
            && ! in_array($branchId, $user->accessibleBranchIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'branch_id' => 'لا تملك صلاحية إضافة مكوّن لهذا الفرع.',
            ]);
        }

        $ingredient = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $initialQty, $initialExpiry, $locationId, $branchId) {
            $ingredient = Ingredient::create($data);

            if ($initialQty > 0) {
                // Seed real stock through the inventory service — creates
                // ingredient_stock + InventoryMovement audit + recomputes
                // current_stock from the per-location truth.
                \App\Support\BranchContext::forBranch($branchId, function () use ($ingredient, $initialQty, $initialExpiry, $locationId) {
                    $batch = app(\App\Services\BatchInventoryService::class)->createBatchOnReceipt(
                        ingredient: $ingredient,
                        qtyBase: $initialQty,
                        unitCost: (float) $ingredient->cost_per_unit,
                        expiryDate: $initialExpiry,
                        notes: 'كمية افتتاحية عند إنشاء الصنف',
                        storageLocationId: $locationId,
                    );
                    $this->inventory->recordMovement(
                        ingredient:        $ingredient,
                        type:              'in',
                        qtyBase:           $initialQty,
                        unitCost:          (float) $ingredient->cost_per_unit,
                        reason:            'كمية افتتاحية عند إنشاء المكوّن',
                        batchId:           $batch->id,
                        storageLocationId: $locationId,
                    );
                });
            } else {
                // qty = 0 → no stock movement, but still register a "home"
                // row at the chosen location so future POs/transfers can
                // default to it and the per-branch view shows the SKU
                // (with stock 0) instead of hiding it entirely.
                \App\Models\IngredientStock::firstOrCreate(
                    ['ingredient_id' => $ingredient->id, 'storage_location_id' => $locationId],
                    ['quantity' => 0, 'reorder_threshold' => 0],
                );
            }

            return $ingredient;
        });

        return redirect()->route('admin.ingredients.index')
            ->with('success', $initialQty > 0
                ? "تم إنشاء «{$ingredient->name}» مع كمية افتتاحية في الموقع المختار."
                : "تم إنشاء «{$ingredient->name}» وتعيين موقعه الافتراضي. أضف الكمية لاحقاً عبر أمر شراء.");
    }

    /**
     * Consolidated stock card ("كرت الصنف") — pulls every piece of
     * data the operator/accountant needs for ONE ingredient onto a
     * single page so they don't have to bounce between 4 screens to
     * understand "what's going on with sugar today?".
     *
     * Nine tabs:
     *   1. General info        — every catalog field on the ingredient
     *   2. Overview            — stat boxes + 30-day consumption trend
     *   3. Locations           — per-storage-location stock breakdown
     *   4. Batches             — FIFO batches with expiry warnings
     *   5. Movements           — log of in / out / waste / return
     *   6. Used in             — menu items + sub-recipes that consume it
     *   7. Manufacturing       — sub-recipe of composite ingredients + cost
     *   8. Item invoices       — every PO / supplier-invoice line that
     *                            bought this ingredient
     *   9. Vendor prices       — price history per supplier
     *
     * Everything is eager-loaded ONCE here; the view just renders
     * collections without follow-up queries (avoids the N+1 trap on
     * a high-traffic operator screen).
     *
     * Adjacent-ingredient navigation: we also return the IDs of the
     * first/prev/next/last ingredients (sorted by name, same scope as
     * the index) so the view can render keyboard shortcuts that let
     * the user flick through "كرت الصنف" without going back to the
     * list every time.
     */
    public function show(Ingredient $ingredient)
    {
        $this->authorize('viewAny', Ingredient::class);

        $ingredient->load(['baseUnit', 'supplier', 'units', 'subRecipe.ingredient.baseUnit', 'subRecipe.unit', 'subRecipe.ingredientUnit']);

        $user = auth()->user();
        $branchId = \App\Support\BranchContext::current();
        if (! $branchId && $user && ! $user->isOwnerLevel()) {
            $branchId = optional($user->primaryBranch())->id;
        }

        // ── Overview stats ────────────────────────────────────────
        $now30 = now()->subDays(30);
        $stock = $branchId
            ? $ingredient->usableStockAtBranch((int) $branchId)
            : $ingredient->trackedUsableStock();
        $value = $branchId
            ? $ingredient->valueAtBranch((int) $branchId)
            : $ingredient->trackedValue();

        $movements30 = \App\Models\InventoryMovement::query()
            ->where('ingredient_id', $ingredient->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween('occurred_at', [$now30, now()])
            ->get();

        $consumed30 = (float) $movements30->where('type', 'out')->sum('quantity_in_base');
        $wasted30   = (float) $movements30->where('type', 'waste')->sum('quantity_in_base');
        $received30 = (float) $movements30->where('type', 'in')->sum('quantity_in_base');
        $lastIn     = $movements30->where('type', 'in')->sortByDesc('occurred_at')->first();
        $lastOut    = $movements30->where('type', 'out')->sortByDesc('occurred_at')->first();

        // ── Daily consumption series for the chart ────────────────
        $daily = $movements30
            ->where('type', 'out')
            ->groupBy(fn ($m) => $m->occurred_at->toDateString())
            ->map(fn ($group) => (float) $group->sum('quantity_in_base'));

        $dailySeries = collect(range(0, 29))->map(function ($i) use ($daily) {
            $date = now()->subDays(29 - $i)->toDateString();
            return ['date' => $date, 'qty' => (float) ($daily->get($date) ?? 0)];
        });

        // ── Locations ────────────────────────────────────────────
        $locations = $ingredient->stocks()
            ->when($branchId, fn ($query) => $query->whereHas(
                'location', fn ($location) => $location->where('branch_id', $branchId)
            ))
            ->with('location.branch')
            ->orderByDesc('quantity')
            ->get();

        // ── Batches ──────────────────────────────────────────────
        $batches = \App\Models\IngredientBatch::query()
            ->where('ingredient_id', $ingredient->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->with('storageLocation')
            ->orderBy('expiry_date')
            ->limit(50)
            ->get();

        // ── Movements log (paginated) ────────────────────────────
        $movements = \App\Models\InventoryMovement::query()
            ->where('ingredient_id', $ingredient->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->with(['user', 'storageLocation', 'batch'])
            ->latest('occurred_at')
            ->paginate(20, ['*'], 'movements_page')
            ->withQueryString();

        // ── Recipes that use this ingredient ─────────────────────
        // Two paths: directly as a recipe line in a menu item, OR as
        // a raw input inside another composite ingredient's sub-recipe.
        $usedInMenuItems = \App\Models\RecipeItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('menu_item_id')
            ->with(['menuItem.category', 'unit', 'ingredientUnit'])
            ->get()
            ->groupBy('menu_item_id')
            ->map(fn ($lines) => $lines->first());

        $usedInComposites = \App\Models\RecipeItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('parent_ingredient_id')
            ->with(['parentIngredient', 'unit', 'ingredientUnit'])
            ->get();

        // ── Vendor price history ─────────────────────────────────
        $vendorPrices = \App\Models\IngredientSupplierPrice::query()
            ->where('ingredient_id', $ingredient->id)
            ->with('supplier')
            ->latest('observed_at')
            ->limit(30)
            ->get();

        // ── Purchase-order / supplier-invoice history ────────────
        // "Item invoices" tab — every PO line that bought this ingredient,
        // with the matching supplier-invoice qty + receipt status so the
        // user gets a single audit trail per SKU. Limited to the last
        // 100 lines (worst-case ~6 months of daily POs) to keep the
        // page fast; an "older →" link could be added later.
        $purchaseLines = \App\Models\PurchaseOrderItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->with([
                'purchaseOrder.supplier:id,name',
                'unit:id,code,name',
                'ingredientUnit:id,name,factor_to_base',
                'supplierInvoiceItems.supplierInvoice:id,number,invoice_date,status',
            ])
            ->whereHas('purchaseOrder', fn ($query) => $query
                ->when($branchId, fn ($po) => $po->where('branch_id', $branchId)))
            ->latest('id')
            ->limit(100)
            ->get();

        // ── Adjacent-ingredient navigation IDs ────────────────────
        // The index sorts by name ASC; we mirror that so "next" goes to
        // the alphabetically next ingredient the user already expects to
        // see. A tiny ordered ID query keeps navigation deterministic and
        // avoids coupling this screen to a window-function query.
        $allIds = Ingredient::orderBy('name')->orderBy('id')->pluck('id');
        $currentPos = $allIds->search($ingredient->id);
        $nav = [
            'first' => $allIds->first(),
            'prev'  => $currentPos > 0 ? $allIds[$currentPos - 1] : null,
            'next'  => $currentPos !== false && $currentPos < $allIds->count() - 1
                       ? $allIds[$currentPos + 1] : null,
            'last'  => $allIds->last(),
            'index' => $currentPos !== false ? $currentPos + 1 : 0,
            'total' => $allIds->count(),
        ];

        // ── Everything the retired Blade computed in-template ─────
        $baseCode = $ingredient->baseUnit?->code;
        $threshold = $branchId
            ? $ingredient->reorderThresholdAtBranch((int) $branchId)
            : (float) $ingredient->reorder_threshold;
        $isLow = $ingredient->track_stock && $stock <= $threshold && $stock > 0;
        $isOut = $ingredient->track_stock && $stock <= 0;
        $statusLabel = $isOut ? ['نفد', 'danger', 'bi-x-octagon-fill']
                      : ($isLow ? ['منخفض', 'warning', 'bi-exclamation-triangle-fill']
                                : ['آمن', 'success', 'bi-check-circle-fill']);

        // Weekly average — last 30 days ÷ 30 × 7, and days of stock left
        // at the current burn rate.
        $weeklyAvg = $consumed30 / 30 * 7;
        $dailyBurn = $consumed30 / 30;
        $daysLeft  = $dailyBurn > 0 ? floor($stock / $dailyBurn) : null;

        $costPerUnit = $branchId
            ? $ingredient->costAtBranch((int) $branchId)
            : ((float) $stock > 0 ? $value / $stock : (float) $ingredient->cost_per_unit);

        $adjustLocations = StorageLocation::where('active', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when(! $user?->isOwnerLevel(), fn ($query) => $query
                ->whereIn('branch_id', $user?->accessibleBranchIds() ?? []))
            ->with('branch:id,name')
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'is_default']);

        // Manufacturing formula (composite only) — line costs and the two
        // footer totals used to be summed inside the template.
        $formulaLines = [];
        $formulaTotal = 0.0;
        $yieldQty = (float) ($ingredient->composite_yield ?: 1);
        if ($ingredient->is_composite) {
            foreach ($ingredient->subRecipe as $line) {
                $subIng  = $line->ingredient;
                $subBase = $subIng?->baseUnit?->code;
                // Resolve the line quantity into the sub-ingredient's base
                // unit so the cost math is honest even when the recipe is
                // written in tbsp / scoops / etc.
                try {
                    $qtyInBase = $line->quantityInBase();
                } catch (\Throwable $e) {
                    $qtyInBase = (float) $line->quantity;
                }
                $unitCost = $subIng ? $subIng->effectiveCostPerUnit() : 0;
                $lineCost = $qtyInBase * $unitCost;
                $formulaTotal += $lineCost;

                $formulaLines[] = [
                    'name'        => $subIng?->name ?? '—',
                    'sku'         => $subIng?->sku,
                    'url'         => $subIng ? route('admin.ingredients.show', $subIng) : null,
                    'qty'         => static::trimRaw($line->quantity),
                    'unitName'    => $line->ingredientUnit?->name ?? $line->unit?->code ?? $subBase,
                    'qtyInBase'   => QuantityFormatter::smart($qtyInBase, $subBase),
                    'subBaseCode' => $subBase,
                    'unitCost'    => round($unitCost, 4),
                    'lineCost'    => round($lineCost, 4),
                ];
            }
        }

        return AdminShell::render('Admin/Ingredients/Show', [
            'ingredient' => [
                'id'                   => (int) $ingredient->id,
                'name'                 => $ingredient->name,
                'sku'                  => $ingredient->sku,
                'baseCode'             => $baseCode,
                'baseUnitName'         => $ingredient->baseUnit?->name,
                'supplierName'         => $ingredient->supplier?->name,
                'active'               => (bool) $ingredient->active,
                'trackStock'           => (bool) $ingredient->track_stock,
                'tracksExpiry'         => (bool) $ingredient->tracks_expiry,
                'isComposite'          => (bool) $ingredient->is_composite,
                'notes'                => $ingredient->notes,
                'updatedAt'            => $ingredient->updated_at?->format('Y-m-d H:i'),
                'costPerUnit'          => $costPerUnit,
                'effectiveCostPerUnit' => round($branchId ? $costPerUnit : $ingredient->effectiveCostPerUnit(), 4),
                'yieldPctLabel'        => ($ingredient->yield_pct && (float) $ingredient->yield_pct < 100)
                    ? static::trimRaw($ingredient->yield_pct) : null,
                'reorderThresholdLabel' => $threshold > 0
                    ? QuantityFormatter::smart($threshold, $baseCode) : null,
                'compositeYieldLabel'   => ($ingredient->is_composite && $ingredient->composite_yield)
                    ? QuantityFormatter::smart((float) $ingredient->composite_yield, $baseCode) : null,
                // Alternate pack sizes (spec sheet on the "المعلومات العامة" tab)
                'packUnits' => $ingredient->units->map(fn ($u) => [
                    'id'            => (int) $u->id,
                    'name'          => $u->name,
                    'factorLabel'   => static::trimRaw($u->factor_to_base),
                    'barcode'       => $u->barcode,
                    'purchasePrice' => $u->purchase_price !== null ? (float) $u->purchase_price : null,
                    'salePrice'     => $u->sale_price !== null ? (float) $u->sale_price : null,
                    'isDefault'     => (bool) $u->is_default_purchase,
                ])->values()->all(),
            ],
            'status' => ['label' => $statusLabel[0], 'color' => $statusLabel[1], 'icon' => $statusLabel[2]],
            'hero'   => [
                'isLow'      => $isLow,
                'isOut'      => $isOut,
                'stockLabel' => QuantityFormatter::smart($stock, $baseCode),
                'stockExact' => number_format($stock, 4),
                'value'      => round($value, 2),
            ],
            'kpis' => [
                'received30'      => ['label' => QuantityFormatter::smart($received30, $baseCode), 'exact' => number_format($received30, 4)],
                'consumed30'      => ['label' => QuantityFormatter::smart($consumed30, $baseCode), 'exact' => number_format($consumed30, 4)],
                'weeklyAvgLabel'  => QuantityFormatter::smart($weeklyAvg, $baseCode),
                'wasted30'        => ['label' => QuantityFormatter::smart($wasted30, $baseCode), 'exact' => number_format($wasted30, 4), 'positive' => $wasted30 > 0],
                'daysLeft'        => $daysLeft !== null ? (int) $daysLeft : null,
            ],
            'dailySeries' => $dailySeries->values()->all(),
            'lastIn'  => $lastIn ? [
                'ago'      => $lastIn->occurred_at->diffForHumans(),
                'qtyLabel' => QuantityFormatter::smart((float) $lastIn->quantity_in_base, $baseCode),
            ] : null,
            'lastOut' => $lastOut ? [
                'ago'      => $lastOut->occurred_at->diffForHumans(),
                'qtyLabel' => QuantityFormatter::smart((float) $lastOut->quantity_in_base, $baseCode),
            ] : null,

            'locations' => $locations->map(fn ($loc) => [
                'id'             => (int) $loc->id,
                'name'           => $loc->location?->name ?? '—',
                'branchName'     => $loc->location?->branch?->name ?? '—',
                'qtyLabel'       => QuantityFormatter::smart((float) $loc->quantity, $baseCode),
                'qtyExact'       => number_format((float) $loc->quantity, 4),
                'thresholdLabel' => $loc->reorder_threshold > 0
                    ? QuantityFormatter::smart((float) $loc->reorder_threshold, $baseCode) : null,
                'value'          => round((float) $loc->quantity * $costPerUnit, 2),
            ])->values()->all(),

            'batches' => $batches->map(function ($b) use ($baseCode) {
                $isExpired  = $b->isExpired();
                $nearExpiry = $b->isNearExpiry(7);
                $statusKey  = $isExpired ? 'expired'
                    : ($nearExpiry ? 'near'
                        : ((float) $b->remaining_qty <= 0 ? 'drained' : 'active'));

                return [
                    'id'             => (int) $b->id,
                    'label'          => $b->batch_number ?: '#'.$b->id,
                    'locationName'   => $b->storageLocation?->name ?? '—',
                    'remainingLabel' => QuantityFormatter::smart((float) $b->remaining_qty, $baseCode),
                    'initialLabel'   => QuantityFormatter::smart((float) $b->initial_qty, $baseCode),
                    'qtyExact'       => number_format((float) $b->remaining_qty, 4).' / '.number_format((float) $b->initial_qty, 4),
                    'unitCost'       => (float) $b->unit_cost,
                    'expiryDate'     => $b->expiry_date?->format('Y-m-d'),
                    'statusKey'      => $statusKey,
                    'rowTone'        => $isExpired ? 'danger' : ($nearExpiry ? 'warning' : null),
                ];
            })->values()->all(),

            'movements' => $movements->through(function ($m) use ($baseCode) {
                $badge = match ($m->type) {
                    'in'         => ['إدخال', 'success',   '+'],
                    'out'        => ['خصم',   'primary',   '−'],
                    'waste'      => ['هدر',   'danger',    '−'],
                    'return'     => ['إرجاع', 'info',      '+'],
                    'adjustment' => ['تسوية', 'secondary', ''],
                    default      => [$m->type, 'light',    ''],
                };

                return [
                    'id'          => (int) $m->id,
                    'at'          => $m->occurred_at->format('Y-m-d H:i'),
                    'typeLabel'   => $badge[0],
                    'typeColor'   => $badge[1],
                    'sign'        => $badge[2],
                    'qtyLabel'    => QuantityFormatter::smart((float) $m->quantity_in_base, $baseCode),
                    'qtyExact'    => number_format((float) $m->quantity_in_base, 4),
                    'afterLabel'  => QuantityFormatter::smart((float) $m->stock_after, $baseCode),
                    'afterExact'  => number_format((float) $m->stock_after, 4),
                    'reason'      => $m->reason,
                    'userName'    => $m->user?->name,
                ];
            }),

            'usedInMenuItems' => $usedInMenuItems->values()->map(fn ($line) => [
                'name'         => $line->menuItem?->name,
                'sku'          => $line->menuItem?->sku,
                'categoryName' => $line->menuItem?->category?->name,
                'qty'          => static::trimRaw($line->quantity),
                'unitName'     => $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode,
            ])->all(),
            'usedInComposites' => $usedInComposites->map(fn ($line) => [
                'name'     => $line->parentIngredient?->name,
                'sku'      => $line->parentIngredient?->sku,
                'qty'      => static::trimRaw($line->quantity),
                'unitName' => $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode,
            ])->values()->all(),

            'formula' => [
                'lines'          => $formulaLines,
                'yieldLabel'     => QuantityFormatter::smart($yieldQty, $baseCode),
                'total'          => round($formulaTotal, 4),
                'perUnit'        => $yieldQty > 0 ? round($formulaTotal / $yieldQty, 4) : null,
            ],

            'purchaseLines' => $purchaseLines->map(function ($line) use ($baseCode) {
                $po       = $line->purchaseOrder;
                $invoices = $line->supplierInvoiceItems->pluck('supplierInvoice')->filter()->unique('id');

                return [
                    'id'           => (int) $line->id,
                    'date'         => $po?->expected_at?->format('Y-m-d') ?? $po?->created_at?->format('Y-m-d'),
                    'poNumber'     => $po?->number,
                    'poUrl'        => $po ? route('admin.purchase-orders.show', $po) : null,
                    'poStatus'     => $po?->statusLabel(),
                    'poColor'      => $po?->statusColor(),
                    'supplierName' => $po?->supplier?->name,
                    'unitName'     => $line->ingredientUnit?->name ?? $line->unit?->code ?? $baseCode,
                    'ordered'      => static::trimRaw($line->quantity_ordered),
                    'received'     => static::trimRaw($line->quantity_received),
                    'receiptState' => $line->isFullyReceived()
                        ? 'full'
                        : ((float) $line->quantity_received > 0 ? 'partial' : 'none'),
                    'unitPrice'    => (float) $line->unit_price,
                    'subtotal'     => (float) $line->subtotal,
                    'invoices'     => $invoices->map(fn ($inv) => [
                        'id'     => (int) $inv->id,
                        'number' => $inv->number,
                        'label'  => $inv->statusLabel(),
                        'color'  => $inv->status === 'paid'
                            ? 'success'
                            : ($inv->status === 'partially_paid' ? 'warning text-dark' : 'secondary'),
                        'url'    => route('admin.supplier-invoices.show', $inv),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'purchaseTotal' => round((float) $purchaseLines->sum('subtotal'), 2),

            'vendorPrices' => $vendorPrices->map(fn ($p) => [
                'id'           => (int) $p->id,
                'date'         => $p->observed_at?->format('Y-m-d'),
                'supplierName' => $p->supplier?->name,
                'unitPrice'    => (float) $p->unit_price_in_base,
                'changePct'    => $p->change_pct !== null ? round((float) $p->change_pct, 1) : null,
            ])->values()->all(),

            'nav' => [
                'prevUrl' => $nav['prev'] ? route('admin.ingredients.show', $nav['prev']) : null,
                'nextUrl' => $nav['next'] ? route('admin.ingredients.show', $nav['next']) : null,
                'firstUrl' => $nav['first'] ? route('admin.ingredients.show', $nav['first']) : null,
                'lastUrl'  => $nav['last'] ? route('admin.ingredients.show', $nav['last']) : null,
                'index'   => (int) $nav['index'],
                'total'   => (int) $nav['total'],
            ],

            // The adjust sheet needs the same seed the index row carries.
            'adjust' => [
                'currentStockLabel' => QuantityFormatter::smart($stock, $baseCode),
                'currentStockExact' => number_format($stock, 2),
                'baseUnitId'        => (int) $ingredient->base_unit_id,
                'costPerUnit'       => $costPerUnit,
                'locations'         => $adjustLocations->map(function ($location) use ($ingredient, $baseCode, $branchId) {
                    $locationStock = $ingredient->usableStockAtLocation((int) $location->id);

                    return [
                        'id'         => (int) $location->id,
                        'branchId'   => (int) $location->branch_id,
                        'label'      => (! $branchId && $location->branch
                            ? $location->branch->name.' · '
                            : '').$location->name,
                        'isDefault'  => (bool) $location->is_default,
                        'stockLabel' => QuantityFormatter::smart($locationStock, $baseCode),
                        'stockExact' => number_format($locationStock, 4),
                        'unitCost'   => round($ingredient->costAtBranch((int) $location->branch_id), 4),
                    ];
                })->values()->all(),
                'units'             => Unit::where('unit_type', $ingredient->baseUnit?->unit_type)
                    ->orderBy('name')->get()
                    ->map(fn ($u) => ['id' => (int) $u->id, 'label' => $u->name.' ('.$u->code.')'])
                    ->values()->all(),
            ],

            'today'    => now()->toDateString(),
            'currency' => $this->currencyProp(),
            'can'      => ['manage' => Gate::allows('manage', Ingredient::class)],
            'urls'     => [
                'index'  => route('admin.ingredients.index'),
                'edit'   => route('admin.ingredients.edit', $ingredient),
                'adjust' => route('admin.ingredients.adjust', $ingredient),
            ],
        ]);
    }

    public function edit(Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);

        $ingredient->load(['baseUnit', 'units']);

        // Sub-recipe editor payload — the retired Blade ran these three
        // queries inside an @php block. Only a composite shows the editor,
        // so only a composite pays for them.
        $subRecipeIngredients = [];
        $subRecipeUnits       = [];
        $subRecipeLines       = [];
        if ($ingredient->is_composite) {
            $subRecipeIngredients = Ingredient::where('id', '!=', $ingredient->id)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku'])
                ->map(fn ($i) => ['id' => (int) $i->id, 'label' => $i->name.' ('.$i->sku.')'])
                ->all();

            $subRecipeUnits = Unit::orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'label' => $u->name.' ('.$u->code.')'])
                ->all();

            $subRecipeLines = $ingredient->subRecipe()->with('ingredient', 'unit')->get()
                ->map(fn ($l) => [
                    'ingredient_id' => (int) $l->ingredient_id,
                    'quantity'      => (float) $l->quantity,
                    'unit_id'       => (int) $l->unit_id,
                ])->values()->all();
        }

        return AdminShell::render('Admin/Ingredients/Edit', $this->formData() + [
            'ingredient' => [
                'id'                    => (int) $ingredient->id,
                'sku'                   => $ingredient->sku,
                'name'                  => $ingredient->name,
                'base_unit_id'          => (int) $ingredient->base_unit_id,
                'measurement_type'      => $ingredient->measurement_type ?? $ingredient->baseUnit?->unit_type ?? 'weight',
                'supplier_id'           => $ingredient->supplier_id ? (int) $ingredient->supplier_id : '',
                'cost_per_unit'         => (float) $ingredient->cost_per_unit,
                'reorder_threshold'     => (float) $ingredient->reorder_threshold,
                'track_stock'           => (bool) $ingredient->track_stock,
                'tracks_expiry'         => (bool) $ingredient->tracks_expiry,
                'default_shelf_life_days' => $ingredient->default_shelf_life_days,
                'active'                => (bool) $ingredient->active,
                'notes'                 => $ingredient->notes,
                'yield_pct'             => $ingredient->yield_pct !== null ? (float) $ingredient->yield_pct : null,
                'is_composite'          => (bool) $ingredient->is_composite,
                'composite_yield'       => $ingredient->composite_yield !== null ? (float) $ingredient->composite_yield : null,
                'baseUnitName'          => $ingredient->baseUnit?->name,
                'baseUnitCode'          => $ingredient->baseUnit?->code,
                'compositeYieldLabel'   => static::trimRaw($ingredient->composite_yield ?? 0),
                // Read-only "المخزون الحالي" box on the form.
                'trackedStockLabel'     => rtrim(rtrim(number_format((float) $ingredient->trackedStock(), 4, '.', ''), '0'), '.'),
            ],
            'costLocked' => $this->costIsLocked($ingredient),
            'unitLocked' => $this->unitIsLocked($ingredient),
            'packUnits'  => $ingredient->units->map(fn ($u) => [
                'name'                => $u->name,
                'factor_to_base'      => static::trimRaw($u->factor_to_base),
                'barcode'             => $u->barcode ?? '',
                'purchase_price'      => $u->purchase_price !== null ? static::trimRaw($u->purchase_price) : '',
                'sale_price'          => $u->sale_price !== null ? static::trimRaw($u->sale_price) : '',
                'is_default_purchase' => (bool) $u->is_default_purchase,
            ])->values()->all(),
            'subRecipeLines'       => $subRecipeLines,
            'subRecipeIngredients' => $subRecipeIngredients,
            'subRecipeUnits'       => $subRecipeUnits,
            'editUrls' => [
                'update'          => route('admin.ingredients.update', $ingredient),
                'unitsUpdate'     => route('admin.ingredients.units.update', $ingredient),
                'subRecipeUpdate' => route('admin.ingredients.sub_recipe.update', $ingredient),
            ],
        ]);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $data = $this->valid($request);

        if ((int) $data['base_unit_id'] !== (int) $ingredient->base_unit_id) {
            if ($this->unitIsLocked($ingredient)) {
                throw ValidationException::withMessages([
                    'base_unit_id' => 'لا يمكن تغيير الوحدة الأساسية بعد وجود مخزون أو حركات أو وصفات. أنشئ صنفاً جديداً لتجنب إعادة تفسير الكميات السابقة.',
                ]);
            }
        }

        // cost_per_unit locks once any stock history exists (see
        // costIsLocked): movements are posted to the GL at the cost they
        // carried, so a later manual edit would silently revalue stock with
        // no matching journal entry, drifting the inventory account (1200).
        // BEFORE any stock history we allow the edit: it's the only way to
        // repair a day-zero typo (e.g. the 0 default) that would otherwise
        // poison recipe costing forever.
        if ($this->costIsLocked($ingredient)) {
            unset($data['cost_per_unit']);
        }
        $ingredient->update($data);
        return redirect()->route('admin.ingredients.index')->with('success', 'تم التحديث');
    }

    /**
     * The manual cost field locks once ANY stock history exists for this
     * ingredient — a receipt batch OR any inventory movement (opening
     * stock, manual adjustment…). Movements are posted to the GL at the
     * cost they carried, so editing cost_per_unit afterwards would silently
     * revalue on-hand stock with no matching journal entry, drifting the
     * inventory account (1200) from reality. The unlock window is therefore
     * exactly "no stock history yet" — enough to repair a day-zero typo,
     * never enough to rewrite valued stock. Checked in ALL branches
     * (cost_per_unit is a single global average); soft-deleted batches stay
     * excluded — they were rolled back and never fed the average.
     */
    protected function costIsLocked(Ingredient $ingredient): bool
    {
        $hasBatches = \App\Models\IngredientBatch::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('ingredient_id', $ingredient->id)
            ->exists();

        if ($hasBatches) {
            return true;
        }

        return \App\Models\InventoryMovement::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->exists();
    }

    protected function unitIsLocked(Ingredient $ingredient): bool
    {
        return $this->costIsLocked($ingredient)
            || $ingredient->recipeItems()->exists()
            || $ingredient->subRecipe()->exists()
            || $ingredient->units()->exists();
    }

    public function destroy(Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $ingredient->delete();
        return back()->with('success', 'تم الحذف');
    }

    public function adjust(Request $request, Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $data = $request->validate([
            'type' => ['required', 'in:in,out,waste,adjustment_in,adjustment_out'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['required', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:today'],
            'storage_location_id' => ['required', 'integer'],
        ]);

        $location = StorageLocation::withoutGlobalScopes()
            ->whereKey((int) $data['storage_location_id'])
            ->where('active', true)
            ->first();
        if (! $location || ! $request->user()?->belongsToBranch((int) $location->branch_id)) {
            throw ValidationException::withMessages([
                'storage_location_id' => 'اختر موقع تخزين نشطاً من الفروع المسموح لك بإدارتها.',
            ]);
        }

        $unit = Unit::findOrFail((int) $data['unit_id']);
        $ingredient->loadMissing('baseUnit');
        if ($unit->unit_type !== $ingredient->baseUnit?->unit_type) {
            throw ValidationException::withMessages([
                'unit_id' => "الوحدة «{$unit->name}» لا تطابق نوع قياس الصنف «{$ingredient->name}».",
            ]);
        }

        if (in_array($data['type'], ['in', 'adjustment_in'], true)
            && $ingredient->tracks_expiry
            && empty($data['expiry_date'])) {
            throw ValidationException::withMessages([
                'expiry_date' => 'تاريخ الصلاحية مطلوب عند إضافة كمية لهذا الصنف.',
            ]);
        }

        $qtyBase = \App\Helpers\UnitConverter::convert($data['quantity'], $data['unit_id'], $ingredient->base_unit_id);
        $movementType = str_starts_with($data['type'], 'adjustment_') ? 'adjustment' : $data['type'];
        if ($data['type'] === 'adjustment_out') {
            $qtyBase *= -1;
        }
        $unitCost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null
            ? (float) $data['unit_cost']
            : $ingredient->costAtBranch((int) $location->branch_id);

        \DB::transaction(function () use ($ingredient, $data, $qtyBase, $location, $unitCost, $movementType) {
            $batch = null;
            if ($data['type'] === 'in' || $data['type'] === 'adjustment_in') {
                $batch = app(\App\Services\BatchInventoryService::class)->createBatchOnReceipt(
                    ingredient: $ingredient,
                    qtyBase: abs($qtyBase),
                    unitCost: $unitCost,
                    expiryDate: $data['expiry_date'] ?? null,
                    notes: $data['reason'],
                    storageLocationId: (int) $location->id,
                );
            }
            $this->inventory->recordMovement(
                ingredient: $ingredient,
                type: $movementType,
                qtyBase: $qtyBase,
                unitCost: $unitCost,
                reason: $data['reason'],
                batchId: $batch?->id,
                storageLocationId: (int) $location->id,
                syncBatches: ! in_array($data['type'], ['in', 'adjustment_in'], true),
            );
        });
        return back()->with('success', 'تم تسجيل الحركة');
    }

    /**
     * Stream the filtered ingredients as a real .xlsx workbook. We tried CSV
     * first, but Excel on Arabic Windows ignores the UTF-8 BOM next to a
     * `sep=` hint and falls back to Windows-1256 — every Arabic cell turns
     * into mojibake. PhpSpreadsheet writes a native Office Open XML file
     * which is UTF-8 by spec, so the encoding can't be misinterpreted.
     */
    protected function exportXlsx($ingredients, ?int $branchId)
    {
        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->name : 'كل الفروع';
        $stamp      = now()->format('Y-m-d_H-i');
        $filename   = "ingredients_{$stamp}.xlsx";

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('المكونات');
        $sheet->setRightToLeft(true);

        $currency = config('restaurant.currency_symbol', '₪');
        $headers = [
            'SKU',
            'الاسم',
            'الفرع',
            'المخزون',
            'المواقع',                               // ← NEW: per-location breakdown
            'الوحدة',
            'حد إعادة الطلب',
            "تكلفة الوحدة ({$currency})",
            "قيمة المخزون ({$currency})",
            'الحالة',
            'يتتبَّع المخزون',
            'المورّد',
        ];
        $sheet->fromArray($headers, null, 'A1');

        // Tooltip on the new column so the user knows what they're reading.
        $sheet->getComment('E1')->getText()->createTextRun(
            "مواقع التخزين التي تحتوي هذا المكوّن مع كميته في كل موقع.\n"
            . 'تنسيق: «اسم الموقع: الكمية» مفصولة بسطر جديد. عند تصفية فرع، '
            . 'تظهر مواقع هذا الفرع فقط.'
        );

        // Explain the two financial columns inline so the file is self-documenting.
        $sheet->getComment('G1')->getText()->createTextRun(
            "تكلفة شراء الوحدة الواحدة ({$currency} لكل وحدة قاعدية: g / ml / pcs).\n"
            . 'محسوبة كمتوسط مرجَّح من دفعات الاستلام النشطة في الفرع المعروض.'
        );
        $sheet->getComment('H1')->getText()->createTextRun(
            "قيمة المخزون = المخزون × تكلفة الوحدة.\n"
            . "المال المحبوس في هذا المكوّن داخل المخازن — مفيد لجرد التقييم وحساب رأس المال الدوّار."
        );

        // Header styling: green band, white bold text, centered.
        $headerRange = 'A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F4731']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $row = 2;
        foreach ($ingredients as $ing) {
            $bookStock = $branchId ? $ing->stockAtBranch($branchId)            : $ing->trackedStock();
            $stock     = $branchId ? $ing->usableStockAtBranch($branchId)      : $ing->trackedUsableStock();
            $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId) : (float) $ing->reorder_threshold;

            // Cost shown on the row — the per-branch weighted average when a
            // branch is active, or a "blended" rate (total value ÷ total stock)
            // for the all-branches view so cost × stock still equals the value
            // column. Falls back to the global cost when there's no stock.
            $value = $branchId ? $ing->valueAtBranch($branchId) : $ing->trackedValue();
            $cost  = $branchId
                ? $ing->costAtBranch($branchId)
                : ($bookStock > 0 ? $value / $bookStock : (float) $ing->cost_per_unit);

            $status = ! $ing->track_stock
                ? 'غير متتبَّع'
                : ($stock <= 0
                    ? 'نفد'
                    : ($threshold > 0 && $stock <= $threshold ? 'منخفض' : 'صحي'));

            // Build the per-location breakdown string for column E.
            // Format: "اسم الموقع: 12.50\nاسم الموقع 2: 3.00" — line breaks
            // so it reads naturally in Excel cells (with WrapText below).
            $locationsCell = $ing->stocks->isEmpty()
                ? '—'
                : $ing->stocks
                    ->sortByDesc('quantity')
                    ->map(fn ($s) => ($s->location?->name ?? 'موقع محذوف')
                        . ': ' . number_format($ing->usableStockAtLocation((int) $s->storage_location_id), 2))
                    ->implode("\n");

            $sheet->fromArray([
                $ing->sku,
                $ing->name,
                $branchName,
                $stock,
                $locationsCell,                       // ← NEW column E
                $ing->baseUnit?->code ?? '',
                $threshold,
                $cost,
                $value,
                $status,
                $ing->track_stock ? 'نعم' : 'لا',
                $ing->supplier?->name ?? '',
            ], null, 'A' . $row);

            // Wrap the locations cell so multi-line content is readable, and
            // bump row height in proportion to how many locations are shown.
            if (! $ing->stocks->isEmpty()) {
                $sheet->getStyle("E{$row}")->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
                $rowHeight = max(20, 16 + ($ing->stocks->count() * 16));
                $sheet->getRowDimension($row)->setRowHeight($rowHeight);
            }

            // Tint rows by status so issues pop visually.
            if ($status === 'نفد') {
                $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FBE9E9']],
                ]);
            } elseif ($status === 'منخفض') {
                $sheet->getStyle("A{$row}:L{$row}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF6E0']],
                ]);
            }
            $row++;
        }

        // Number formats — column letters shifted right by 1 after the
        // new "المواقع" column (E). Index → letter mapping now:
        //   D=المخزون · E=المواقع · F=الوحدة · G=حد إعادة الطلب
        //   H=تكلفة الوحدة · I=قيمة المخزون · J=الحالة · K=تتبع · L=المورّد
        $lastRow = $row - 1;
        if ($lastRow >= 2) {
            $sheet->getStyle("D2:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');  // المخزون
            $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');  // حد إعادة الطلب
            $sheet->getStyle("H2:H{$lastRow}")->getNumberFormat()->setFormatCode("#,##0.0000 \"{$currency}\""); // تكلفة الوحدة
            $sheet->getStyle("I2:I{$lastRow}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");   // قيمة المخزون

            // Totals row — single SUM of "قيمة المخزون" (now column I).
            $totalRow = $lastRow + 1;
            $sheet->setCellValue("A{$totalRow}", 'الإجمالي');
            $sheet->mergeCells("A{$totalRow}:H{$totalRow}");
            $sheet->setCellValue("I{$totalRow}", "=SUM(I2:I{$lastRow})");
            $sheet->getStyle("A{$totalRow}:L{$totalRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF6F1']],
                'borders' => ['top' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => '0F4731']]],
            ]);
            $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("I{$totalRow}")->getNumberFormat()->setFormatCode("#,##0.00 \"{$currency}\"");
        }

        // Auto-size every column for legible Arabic, except column E
        // (المواقع) — auto-size doesn't account for wrap-text, so we set
        // an explicit width that fits "اسم الموقع: 12.50" comfortably.
        foreach (range('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers))) as $col) {
            if ($col === 'E') {
                $sheet->getColumnDimension($col)->setWidth(28);
            } else {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function formData(): array
    {
        $supQuery = Supplier::where('active', true);
        $user = auth()->user();

        // Branches the current user is allowed to seed stock into. Owner-level
        // sees every active branch; everyone else is locked to their own.
        $branches = $user?->isOwnerLevel()
            ? \App\Models\Branch::where('is_active', true)
                ->orderBy('display_order')->orderBy('name')
                ->get(['id', 'name'])
            : ($user
                ? \App\Models\Branch::whereIn('id', $user->accessibleBranchIds())
                    ->where('is_active', true)
                    ->orderBy('display_order')->orderBy('name')
                    ->get(['id', 'name'])
                : collect());

        // Locations honour the active branch context — same rule as every
        // other admin screen. In a specific branch you only see that
        // branch's locations; in "all branches" mode (owner-level only)
        // BranchScope returns everything. The branch dropdown above is
        // already filtered to user-accessible branches, and this map is
        // keyed by branch_id so the JS dropdown narrows accordingly.
        $locationsByBranch = StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'is_default'])
            ->groupBy('branch_id')
            ->map(fn ($g) => $g->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'is_default' => (bool) $l->is_default,
            ])->values())
            ->toArray();

        if ($user && ! $user->isOwnerLevel()) {
            $branchId = \App\Support\BranchContext::current()
                ?? optional($user->primaryBranch())->id;
            if ($branchId) $supQuery->servingBranch($branchId);
        }

        $suppliers = $supQuery->orderBy('name')->get();

        // Map: branchId => [supplierIds…] so the JS can narrow the supplier
        // dropdown the moment the user picks a branch. Owner-level users
        // start with a flat unfiltered list; non-owner-level users are
        // already scoped above so this map is just a guard rail.
        $supplierIdsByBranch = \DB::table('branch_supplier')
            ->whereIn('supplier_id', $suppliers->pluck('id'))
            ->select('branch_id', 'supplier_id')
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($g) => $g->pluck('supplier_id')->all())
            ->toArray();

        return [
            // Flattened for Inertia — Eloquent models would serialize their
            // entire row (and Unit carries columns the form never renders).
            'units' => Unit::all()->map(fn ($u) => [
                'id'       => (int) $u->id,
                'label'    => $u->name.' ('.$u->code.')',
                'unitType' => $u->unit_type,
            ])->values()->all(),
            'suppliers' => $suppliers->map(fn ($s) => [
                'id'   => (int) $s->id,
                'name' => $s->name,
            ])->values()->all(),
            'supplierIdsByBranch'  => (object) $supplierIdsByBranch,
            'branches'             => $branches->map(fn ($b) => [
                'id'   => (int) $b->id,
                'name' => $b->name,
            ])->values()->all(),
            'locationsByBranch'    => (object) $locationsByBranch,
            'activeBranchId'       => \App\Support\BranchContext::current(),
            // Zero branches means two very different things: for a user who
            // CAN create branches it's a fresh installation (onboarding),
            // for everyone else it's genuinely a permissions problem.
            'canCreateBranch'      => Gate::allows('create', \App\Models\Branch::class),
            'today'                => now()->toDateString(),
            'currency'             => $this->currencyProp(),
            'urls' => [
                'index'          => route('admin.ingredients.index'),
                'store'          => route('admin.ingredients.store'),
                'branchCreate'   => route('admin.branches.create'),
                'locationCreate' => route('admin.storage-locations.create'),
                'suppliersIndex' => route('admin.suppliers.index'),
            ],
        ];
    }

    /**
     * Catalog-level validation (name, unit, supplier, threshold, cost).
     * Stock seeding is handled separately in store() so we can give clearer
     * branch/location errors when the user provides an initial quantity.
     */
    protected function valid(Request $request): array
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'base_unit_id' => ['required', 'exists:units,id'],
            'measurement_type' => ['nullable', 'in:weight,volume,count,length'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'reorder_threshold' => ['required', 'numeric'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'default_shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            // Yield ratio: optional. Null/blank = 100% (no trim loss).
            'yield_pct' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            // Composite-ingredient flags.
            'is_composite' => ['sometimes', 'boolean'],
            'composite_yield' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $baseUnit = Unit::findOrFail((int) $data['base_unit_id']);
        $requestedType = $data['measurement_type'] ?? $baseUnit->unit_type;
        if ($requestedType !== $baseUnit->unit_type) {
            throw ValidationException::withMessages([
                'base_unit_id' => "الوحدة «{$baseUnit->name}» لا تطابق نوع القياس المختار.",
            ]);
        }

        $data['measurement_type'] = $baseUnit->unit_type;
        if (empty($data['tracks_expiry'])) {
            $data['default_shelf_life_days'] = null;
        }

        return $data;
    }

    /**
     * Replace the alternate-unit list (pack sizes) for an ingredient.
     * Posted as `units[] = [name, factor_to_base, barcode?, purchase_price?,
     * sale_price?, is_default_purchase?]`. Just like the sub-recipe
     * editor, existing rows are wiped and re-inserted — simpler than a
     * diff, and pack lists are short.
     *
     * Barcodes are globally unique across all ingredient_units, so a
     * single scanned barcode resolves unambiguously to one (ingredient,
     * unit) pair. We surface a friendly error when the user tries to
     * reuse one.
     */
    public function updateUnits(Request $request, Ingredient $ingredient)
    {
        $this->authorize('update', $ingredient);

        $data = $request->validate([
            'units'                          => ['nullable', 'array'],
            'units.*.name'                   => ['required_with:units', 'string', 'max:100'],
            'units.*.factor_to_base'         => ['required_with:units', 'numeric', 'min:0.0001'],
            'units.*.barcode'                => ['nullable', 'string', 'max:64'],
            'units.*.purchase_price'         => ['nullable', 'numeric', 'min:0'],
            'units.*.sale_price'             => ['nullable', 'numeric', 'min:0'],
            'units.*.is_default_purchase'    => ['sometimes', 'boolean'],
        ]);

        $rows = $data['units'] ?? [];

        // Pre-validate barcode uniqueness against OTHER ingredients,
        // because the DB unique index would throw a less helpful error.
        $barcodes = collect($rows)
            ->pluck('barcode')
            ->filter(fn ($b) => is_string($b) && trim($b) !== '')
            ->map(fn ($b) => trim($b));
        $conflict = \App\Models\IngredientUnit::query()
            ->whereIn('barcode', $barcodes)
            ->where('ingredient_id', '!=', $ingredient->id)
            ->with('ingredient:id,name')
            ->first();
        if ($conflict) {
            return back()->with('error',
                "الباركود «{$conflict->barcode}» مستخدم مسبقاً على الصنف «{$conflict->ingredient?->name}». استخدم باركوداً مختلفاً.");
        }

        // Only ONE row may be flagged as default. The UI's radio
        // group already enforces this, but the controller mirrors it
        // so a hand-crafted POST can't break the invariant.
        $defaultCount = collect($rows)->filter(fn ($r) => ! empty($r['is_default_purchase']))->count();
        if ($defaultCount > 1) {
            return back()->with('error', 'اختر وحدة افتراضية واحدة فقط للشراء.');
        }

        \DB::transaction(function () use ($ingredient, $rows) {
            $ingredient->units()->delete();
            foreach ($rows as $row) {
                \App\Models\IngredientUnit::create([
                    'ingredient_id'       => $ingredient->id,
                    'name'                => trim($row['name']),
                    'factor_to_base'      => (float) $row['factor_to_base'],
                    'barcode'             => trim((string) ($row['barcode'] ?? '')) ?: null,
                    'purchase_price'      => $row['purchase_price'] !== null && $row['purchase_price'] !== ''
                                              ? (float) $row['purchase_price'] : null,
                    'sale_price'          => $row['sale_price'] !== null && $row['sale_price'] !== ''
                                              ? (float) $row['sale_price'] : null,
                    'is_default_purchase' => ! empty($row['is_default_purchase']),
                    'active'              => true,
                ]);
            }
        });

        return back()->with('success', 'تم حفظ وحدات الصنف.');
    }

    /**
     * Replace the sub-recipe of a composite ingredient. Posted as
     * `lines[] = ['ingredient_id', 'quantity', 'unit_id']`. Existing
     * rows for this composite are deleted then re-inserted — simpler
     * than a diff, and recipe lines are short-lived enough that an
     * overwrite is fine.
     */
    public function updateSubRecipe(Request $request, Ingredient $ingredient)
    {
        $this->authorize('update', $ingredient);

        abort_unless($ingredient->is_composite, 422, 'هذا المكون ليس مركّباً — فعّل "مركّب" أولاً.');

        $data = $request->validate([
            'lines'                 => ['nullable', 'array'],
            'lines.*.ingredient_id' => ['required_with:lines', 'exists:ingredients,id'],
            'lines.*.quantity'      => ['required_with:lines', 'numeric', 'min:0.0001'],
            'lines.*.unit_id'       => ['required_with:lines', 'exists:units,id'],
        ]);

        // Cycle prevention: a composite cannot list itself anywhere in
        // its tree. We do the immediate-child check here; the deeper
        // recursion safety lives in InventoryService::expandIngredient.
        foreach ($data['lines'] ?? [] as $line) {
            if ((int) $line['ingredient_id'] === (int) $ingredient->id) {
                return back()->with('error', 'لا يمكن للمكوّن المركّب أن يحوي نفسه كمكوّن فرعي.');
            }
        }

        foreach ($data['lines'] ?? [] as $line) {
            $child = Ingredient::with('baseUnit')->find((int) $line['ingredient_id']);
            $unit = Unit::find((int) $line['unit_id']);
            if ($child && $unit && $child->baseUnit && $unit->unit_type !== $child->baseUnit->unit_type) {
                throw ValidationException::withMessages([
                    'lines' => "الوحدة «{$unit->name}» لا تطابق نوع قياس المكوّن «{$child->name}».",
                ]);
            }
        }

        \DB::transaction(function () use ($ingredient, $data) {
            $ingredient->subRecipe()->delete();
            foreach ($data['lines'] ?? [] as $line) {
                \App\Models\RecipeItem::create([
                    'parent_ingredient_id' => $ingredient->id,
                    'ingredient_id'        => (int) $line['ingredient_id'],
                    'quantity'             => (float) $line['quantity'],
                    'unit_id'              => (int) $line['unit_id'],
                ]);
            }
        });

        return back()->with('success', 'تم حفظ الوصفة الفرعية.');
    }
}
