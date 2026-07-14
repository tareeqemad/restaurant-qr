<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

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
            if ($branchId) {
                $tracked = Ingredient::where('track_stock', true)->get();
                $matchIds = match ($statusFilter) {
                    'low'     => $tracked->filter(fn ($i) =>
                                    $i->isLowStockAtBranch($branchId)
                                    && $i->stockAtBranch($branchId) > 0)->pluck('id'),
                    'out'     => $tracked->filter(fn ($i) => $i->stockAtBranch($branchId) <= 0)->pluck('id'),
                    'healthy' => $tracked->filter(fn ($i) =>
                                    $i->stockAtBranch($branchId) > 0
                                    && ! $i->isLowStockAtBranch($branchId))->pluck('id'),
                    default   => null,
                };
                if ($matchIds !== null) $q->whereIn('id', $matchIds);
            } else {
                match ($statusFilter) {
                    'low'     => $q->whereColumn('current_stock', '<=', 'reorder_threshold')
                                   ->where('current_stock', '>', 0)
                                   ->where('reorder_threshold', '>', 0),
                    'out'     => $q->where('current_stock', '<=', 0),
                    'healthy' => $q->whereColumn('current_stock', '>', 'reorder_threshold'),
                    default   => null,
                };
            }
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
                && $i->stockAtBranch($branchId) > 0
            )->count();
            $outCount = $tracked->filter(fn ($i) => $i->stockAtBranch($branchId) <= 0)->count();
            $healthy  = $tracked->count() - $lowCount - $outCount;
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => $lowCount,
                'out_stock' => $outCount,
                'healthy'   => max(0, $healthy),
            ];
        } else {
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => Ingredient::whereColumn('current_stock', '<=', 'reorder_threshold')
                                         ->where('current_stock', '>', 0)->count(),
                'out_stock' => Ingredient::where('current_stock', '<=', 0)->count(),
                'healthy'   => Ingredient::whereColumn('current_stock', '>', 'reorder_threshold')->count(),
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
                $stock = $branchId ? $i->stockAtBranch($branchId) : $i->trackedStock();
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
            ->get(['id', 'name', 'code', 'branch_id', 'is_default'])
            ->map(function ($loc) use ($branchId) {
                // Add a clone so we don't mutate the original; prefix with
                // branch name only in cross-branch view to disambiguate.
                if (! $branchId && $loc->branch) {
                    $loc->name = $loc->branch->name . ' · ' . $loc->name;
                }
                return $loc;
            });

        return view('admin.ingredients.index', compact(
            'ingredients', 'stats', 'statusFilter', 'totalInventoryValue', 'filterLocations'
        ));
    }

    public function create()
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.ingredients.create', $this->formData());
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
        ], [], [
            'branch_id'           => 'الفرع',
            'storage_location_id' => 'موقع التخزين',
            'initial_quantity'    => 'الكمية الأولية',
        ]);

        $branchId   = (int) $stockData['branch_id'];
        $locationId = (int) $stockData['storage_location_id'];
        $initialQty = (float) ($stockData['initial_quantity'] ?? 0);

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

        $ingredient = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $initialQty, $locationId, $branchId) {
            $ingredient = Ingredient::create($data);

            if ($initialQty > 0) {
                // Seed real stock through the inventory service — creates
                // ingredient_stock + InventoryMovement audit + recomputes
                // current_stock from the per-location truth.
                \App\Support\BranchContext::forBranch($branchId, function () use ($ingredient, $initialQty, $locationId) {
                    $this->inventory->recordMovement(
                        ingredient:        $ingredient,
                        type:              'in',
                        qtyBase:           $initialQty,
                        unitCost:          (float) $ingredient->cost_per_unit,
                        reason:            'كمية افتتاحية عند إنشاء المكوّن',
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

        // ── Overview stats ────────────────────────────────────────
        $now30 = now()->subDays(30);
        $stock = (float) $ingredient->trackedStock();
        $value = $stock * (float) $ingredient->cost_per_unit;

        $movements30 = \App\Models\InventoryMovement::query()
            ->where('ingredient_id', $ingredient->id)
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
            ->with('location.branch')
            ->orderByDesc('quantity')
            ->get();

        // ── Batches ──────────────────────────────────────────────
        $batches = \App\Models\IngredientBatch::query()
            ->where('ingredient_id', $ingredient->id)
            ->with('storageLocation')
            ->orderBy('expiry_date')
            ->limit(50)
            ->get();

        // ── Movements log (paginated) ────────────────────────────
        $movements = \App\Models\InventoryMovement::query()
            ->where('ingredient_id', $ingredient->id)
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
            ->whereHas('purchaseOrder')  // skip orphaned lines from deleted POs
            ->latest('id')
            ->limit(100)
            ->get();

        // ── Adjacent-ingredient navigation IDs ────────────────────
        // The index sorts by name ASC; we mirror that so "next" goes to
        // the alphabetically next ingredient the user already expects to
        // see. lead()/lag() would be ideal but they're not portable across
        // SQLite (used in tests) so we do a tiny per-call query instead.
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

        return view('admin.ingredients.show', compact(
            'ingredient',
            'stock', 'value', 'consumed30', 'wasted30', 'received30',
            'lastIn', 'lastOut',
            'dailySeries',
            'locations', 'batches', 'movements',
            'usedInMenuItems', 'usedInComposites',
            'vendorPrices',
            'purchaseLines',
            'nav',
        ));
    }

    public function edit(Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.ingredients.edit', array_merge($this->formData(), [
            'ingredient' => $ingredient,
            'costLocked' => $this->costIsLocked($ingredient),
        ]));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $data = $this->valid($request);
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
            'type' => ['required', 'in:in,out,waste,adjustment'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['required', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $qtyBase = \App\Helpers\UnitConverter::convert($data['quantity'], $data['unit_id'], $ingredient->base_unit_id);
        $this->inventory->recordMovement(
            ingredient: $ingredient,
            type: $data['type'],
            qtyBase: $qtyBase,
            unitCost: (float) ($data['unit_cost'] ?? $ingredient->cost_per_unit),
            reason: $data['reason'],
            syncBatches: true,   // manual adjustment must keep FIFO batches aligned
        );
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
            $stock     = $branchId ? $ing->stockAtBranch($branchId)            : $ing->trackedStock();
            $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId) : (float) $ing->reorder_threshold;

            // Cost shown on the row — the per-branch weighted average when a
            // branch is active, or a "blended" rate (total value ÷ total stock)
            // for the all-branches view so cost × stock still equals the value
            // column. Falls back to the global cost when there's no stock.
            $value = $branchId ? $ing->valueAtBranch($branchId) : $ing->trackedValue();
            $cost  = $branchId
                ? $ing->costAtBranch($branchId)
                : ($stock > 0 ? $value / $stock : (float) $ing->cost_per_unit);

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
                        . ': ' . number_format((float) $s->quantity, 2))
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
            'units'                => Unit::all(),
            'suppliers'            => $suppliers,
            'supplierIdsByBranch'  => $supplierIdsByBranch,
            'branches'             => $branches,
            'locationsByBranch'    => $locationsByBranch,
            'activeBranchId'       => \App\Support\BranchContext::current(),
        ];
    }

    /**
     * Catalog-level validation (name, unit, supplier, threshold, cost).
     * Stock seeding is handled separately in store() so we can give clearer
     * branch/location errors when the user provides an initial quantity.
     */
    protected function valid(Request $request): array
    {
        return $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'base_unit_id' => ['required', 'exists:units,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'reorder_threshold' => ['required', 'numeric'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            // Yield ratio: optional. Null/blank = 100% (no trim loss).
            'yield_pct' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            // Composite-ingredient flags.
            'is_composite' => ['sometimes', 'boolean'],
            'composite_yield' => ['nullable', 'numeric', 'min:0.0001'],
        ]);
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
