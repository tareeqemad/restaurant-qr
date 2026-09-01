<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WasteReason;
use App\Helpers\Qty;
use App\Helpers\QuantityFormatter;
use App\Helpers\UnitConverter;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\Lookup;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dedicated waste-logging surface — separate from the generic
 * `IngredientController::adjust` so:
 *
 *   - The form forces a waste reason (one of WasteReason cases).
 *   - The form lets the user pick a SPECIFIC batch (so the right FIFO
 *     row is decremented, not just "current_stock").
 *   - The list view shows waste-only movements with reason groupings
 *     and per-period cost totals.
 *
 * The actual stock movement still goes through `InventoryService::recordMovement`
 * with `type = 'waste'`, so the ledger + low-stock alerts work uniformly.
 */
class WasteController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Waste log + analytics. Filters: from/to/ingredient/reason.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        [$from, $to] = $this->dateRange($request);
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';

        $query = InventoryMovement::with(['ingredient.baseUnit', 'batch', 'storageLocation', 'user', 'unit', 'wasteReasonLookup'])
            ->where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end])
            ->latest('occurred_at')
            ->latest('id');

        // The reason filter is the FK id (preferred) — falls back to the legacy
        // string column for any old rows that pre-date the FK migration.
        if ($r = $request->get('reason')) {
            $query->where(function ($q) use ($r) {
                $q->where('waste_reason_lookup_id', $r)
                    ->orWhere(function ($q2) use ($r) {
                        $code = Lookup::whereKey($r)->value('code');
                        if ($code) {
                            $q2->whereNull('waste_reason_lookup_id')->where('waste_reason', $code);
                        }
                    });
            });
        }
        if ($i = $request->get('ingredient_id')) {
            $query->where('ingredient_id', $i);
        }
        if ($l = $request->get('storage_location_id')) {
            $query->where('storage_location_id', $l);
        }

        $movements = $query->paginate(25)->withQueryString();

        // Aggregates over the SAME filtered window
        $base = InventoryMovement::where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end]);
        if ($r = $request->get('reason')) {
            $base->where(function ($q) use ($r) {
                $q->where('waste_reason_lookup_id', $r)
                    ->orWhere(function ($q2) use ($r) {
                        $code = Lookup::whereKey($r)->value('code');
                        if ($code) {
                            $q2->whereNull('waste_reason_lookup_id')->where('waste_reason', $code);
                        }
                    });
            });
        }
        if ($i = $request->get('ingredient_id')) {
            $base->where('ingredient_id', $i);
        }
        if ($l = $request->get('storage_location_id')) {
            $base->where('storage_location_id', $l);
        }

        $stats = [
            'count' => (clone $base)->count(),
            'total_cost' => (float) (clone $base)->sum('total_cost'),
            'today_count' => (clone $base)->whereDate('occurred_at', today())->count(),
            'today_cost' => (float) (clone $base)->whereDate('occurred_at', today())->sum('total_cost'),
        ];

        // Breakdown — group by FK id when present, fall back to the legacy
        // string code via COALESCE so old + new rows show up under one row.
        $byReason = (clone $base)
            ->selectRaw('
                COALESCE(waste_reason_lookup_id, 0) as lookup_id,
                waste_reason as legacy_code,
                COUNT(*) as count,
                SUM(total_cost) as total_cost
            ')
            ->groupBy('lookup_id', 'legacy_code')
            ->orderByDesc('total_cost')
            ->get();

        // Top 10 wasted ingredients in window — actionable: drives recipe/storage fixes.
        //
        // Branch-aware: `DB::table()` bypasses the global BranchScope (only
        // Eloquent models inherit it), so we MUST apply the active branch
        // filter explicitly. Without this, every branch saw identical "top
        // wasted" numbers — the cross-company sum.
        //
        // Reason filter: also supports the FK column (`waste_reason_lookup_id`)
        // with a fallback to the legacy string `waste_reason`, matching the
        // behaviour of `$query` and `$base` above.
        $topIngQuery = DB::table('inventory_movements')
            ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
            ->leftJoin('units', 'ingredients.base_unit_id', '=', 'units.id')
            ->where('inventory_movements.type', 'waste')
            ->whereBetween('inventory_movements.occurred_at', [$start, $end])
            ->when($request->get('storage_location_id'), fn ($q, $locationId) => $q->where('inventory_movements.storage_location_id', $locationId))
            ->when($request->get('ingredient_id'), fn ($q, $ingredientId) => $q->where('inventory_movements.ingredient_id', $ingredientId));

        // Branch scope — explicit because raw query builder doesn't get it.
        if ($branchId = BranchContext::current()) {
            $topIngQuery->where('inventory_movements.branch_id', $branchId);
        }

        // Reason filter — same FK-or-legacy logic as the other two queries.
        if ($r = $request->get('reason')) {
            $topIngQuery->where(function ($q) use ($r) {
                $q->where('inventory_movements.waste_reason_lookup_id', $r)
                    ->orWhere(function ($q2) use ($r) {
                        $code = Lookup::whereKey($r)->value('code');
                        if ($code) {
                            $q2->whereNull('inventory_movements.waste_reason_lookup_id')
                                ->where('inventory_movements.waste_reason', $code);
                        }
                    });
            });
        }

        $topIngredients = $topIngQuery
            ->selectRaw('
                ingredients.id,
                ingredients.name,
                units.code as base_unit_code,
                COUNT(*) as event_count,
                SUM(inventory_movements.quantity_in_base) as qty,
                SUM(inventory_movements.total_cost) as total_cost
            ')
            ->groupBy('ingredients.id', 'ingredients.name', 'units.code')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get();

        // Reasons come from the lookups admin (group=waste_reasons) so renames /
        // new entries appear here automatically.
        //   - keyed by `id`   → the FK value the dropdown sends and the
        //                        breakdown groups by.
        //   - keyed by `code` → the legacy `waste_reason` string column, for
        //                        rows written before the FK migration.
        $reasons = Lookup::for('waste_reasons');
        $reasonsById = $reasons->keyBy('id');
        $reasonsByCode = $reasons->keyBy('code');

        return AdminShell::render('Admin/Waste/Index', [
            'movements' => $movements->through(function (InventoryMovement $m) use ($reasonsByCode) {
                // Prefer the relation (FK id → Lookup row). Fall back to the
                // legacy string column matched against the code-keyed map.
                $lookup = $m->wasteReasonLookup
                    ?? ($m->waste_reason ? ($reasonsByCode[$m->waste_reason] ?? null) : null);

                $baseCode = $m->ingredient?->baseUnit?->code;

                return [
                    'id' => (int) $m->id,
                    'at' => $m->occurred_at?->format('Y-m-d H:i'),
                    'ago' => $m->occurred_at?->diffForHumans(),
                    'ingredientName' => $m->ingredient?->name ?? '—',
                    // null → the view prints the raw legacy string in a plain badge.
                    'reason' => $lookup ? [
                        'label' => (string) $lookup->label,
                        'icon' => (string) ($lookup->icon ?: 'bi-tag'),
                        'color' => (string) ($lookup->color ?: '#64748b'),
                    ] : null,
                    'reasonFallback' => $m->waste_reason ?: '—',
                    'locationName' => $m->storageLocation?->name,
                    'batch' => $m->batch ? [
                        'label' => '#'.($m->batch->batch_number ?: $m->batch->id),
                        'expiry' => $m->batch->expiry_date?->format('Y-m-d'),
                    ] : null,
                    'qtyDisplay' => QuantityFormatter::smart((float) $m->quantity_in_base, $baseCode),
                    'qtyTitle' => trim(Qty::format($m->quantity_in_base).' '.($baseCode ?? '')),
                    'cost' => (float) $m->total_cost,
                    'userName' => $m->user?->name ?? '—',
                    'note' => $m->reason ?? '—',
                ];
            }),
            'stats' => [
                'count' => (int) $stats['count'],
                'totalCost' => $stats['total_cost'],
                'todayCount' => (int) $stats['today_count'],
                'todayCost' => $stats['today_cost'],
            ],
            'byReason' => $byReason->map(function ($r) use ($reasonsById, $reasonsByCode, $stats) {
                $lookup = $r->lookup_id ? ($reasonsById[(int) $r->lookup_id] ?? null) : null;
                if (! $lookup && $r->legacy_code) {
                    $lookup = $reasonsByCode[$r->legacy_code] ?? null;
                }

                $total = (float) $r->total_cost;
                $pct = $stats['total_cost'] > 0 ? ($total / $stats['total_cost']) * 100 : 0;

                return [
                    'label' => $lookup?->label ?: ($r->legacy_code ?: 'غير محدد'),
                    'icon' => $lookup?->icon ?: 'bi-question-circle',
                    'color' => $lookup?->color ?: '#64748b',
                    'count' => (int) $r->count,
                    'totalCost' => $total,
                    // Raw for the bar width, pre-rounded for the caption —
                    // number_format($pct, 1) in the Blade.
                    'pct' => $pct,
                    'pctLabel' => number_format($pct, 1),
                ];
            })->values()->all(),
            'topIngredients' => $topIngredients->map(fn ($ing) => [
                'id' => (int) $ing->id,
                'name' => $ing->name,
                'eventCount' => (int) $ing->event_count,
                'qtyDisplay' => QuantityFormatter::smart((float) $ing->qty, $ing->base_unit_code),
                'qtyTitle' => trim(Qty::format($ing->qty).' '.($ing->base_unit_code ?? '')),
                'totalCost' => (float) $ing->total_cost,
                'createUrl' => route('admin.waste.create', ['ingredient_id' => $ing->id]),
            ])->values()->all(),
            'filters' => [
                'from' => $from,
                'to' => $to,
                'reason' => (string) $request->get('reason', ''),
                'ingredientId' => (string) $request->get('ingredient_id', ''),
                'storageLocationId' => (string) $request->get('storage_location_id', ''),
            ],
            'reasons' => $reasons->map(fn ($r) => [
                'id' => (int) $r->id,
                'label' => (string) $r->label,
            ])->values()->all(),
            'ingredients' => Ingredient::orderBy('name')->get(['id', 'name'])
                ->map(fn ($i) => ['id' => (int) $i->id, 'name' => $i->name])->all(),
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->all(),
            'currency' => $this->currencyProp(),
            'can' => [
                // Gear link to the lookups admin — same @can the Blade had.
                'manageLookups' => (bool) $request->user()?->can('viewAny', Lookup::class),
            ],
            'urls' => [
                'index' => route('admin.waste.index'),
                'create' => route('admin.waste.create'),
                'lookups' => route('admin.lookups.index', ['group' => 'waste_reasons']),
            ],
        ]);
    }

    /**
     * The `currency` prop every migrated page consumes as `currency.symbol`.
     * Resolved exactly the way App\Helpers\Money::format() resolves it (DB
     * setting first, config fallback) so a restaurant that changed its symbol
     * in Settings sees it here too. Never let a Vue file hardcode a symbol.
     */
    protected function currencyProp(): array
    {
        return [
            'symbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'decimals' => 2,
        ];
    }

    /** Form to log a new waste event. We pass the preselected id from
     *  the query string (used when staff click "log waste" from the
     *  top-wasted-ingredients table on the index page). */
    /**
     * The waste form — Inertia/Vue since Wave 4.
     *
     * The server seeds every list ONCE (ingredients with their per-location
     * stocks, units carrying unit_type so the client can filter to matching
     * units, reasons, locations); the client owns all interactive state and
     * only calls back for the FIFO batch list of the picked ingredient.
     * The controller remains the single validated write path.
     */
    public function create(Request $request)
    {
        // Anyone who can read inventory can OPEN the form, but the actual
        // store() requires `manage` (see below) so a chef/cashier can't
        // submit even if they reach the URL.
        $this->authorize('viewAny', Ingredient::class);

        $ingredients = Ingredient::with('baseUnit:id,code,unit_type')
            ->where('track_stock', true)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'base_unit_id', 'cost_per_unit', 'current_stock']);

        $user = $request->user();
        $branchId = BranchContext::current();
        if (! $branchId && $user && ! $user->isOwnerLevel()) {
            $branchId = optional($user->primaryBranch())->id;
        }
        $locations = StorageLocation::where('active', true)
            ->whereIn('branch_id', $user?->accessibleBranchIds() ?? [])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->with('branch:id,name')
            ->orderByDesc('is_default')->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'code', 'branch_id', 'is_default']);

        // All per-location stocks in ONE query — the client filters the
        // ingredient dropdown by location with zero round-trips.
        $stocks = DB::table('ingredient_stock')
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->whereIn('storage_location_id', $locations->pluck('id'))
            ->where('quantity', '>', 0)
            ->select('ingredient_id', 'storage_location_id', 'quantity')
            ->get()
            ->groupBy('ingredient_id');

        return AdminShell::render('Admin/Waste/Create', [
            'ingredients' => $ingredients->map(function ($i) use ($stocks, $locations) {
                $perLocation = [];
                foreach ($stocks->get($i->id, collect()) as $row) {
                    $perLocation[(int) $row->storage_location_id] = (float) $row->quantity;
                }
                $costs = [];
                $branchCosts = [];
                foreach ($locations as $location) {
                    $bid = (int) $location->branch_id;
                    $branchCosts[$bid] ??= $i->costAtBranch($bid);
                    $costs[(int) $location->id] = round($branchCosts[$bid], 4);
                }

                return [
                    'id' => (int) $i->id,
                    'name' => $i->name,
                    'sku' => $i->sku,
                    'baseUnitId' => (int) $i->base_unit_id,
                    'costPerUnit' => (float) $i->cost_per_unit,
                    'currentStock' => (float) array_sum($perLocation),
                    'unitCode' => $i->baseUnit?->code ?? '',
                    // Drives the unit-dropdown filter: picking ml for a
                    // gram-based ingredient would explode the conversion.
                    'baseUnitType' => $i->baseUnit?->unit_type ?? '',
                    'stocks' => (object) $perLocation,
                    'costs' => (object) $costs,
                ];
            })->values()->all(),
            'units' => Unit::orderBy('unit_type')->orderBy('name')
                ->get(['id', 'name', 'code', 'unit_type', 'factor_to_base'])
                ->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'label' => "{$u->name} ({$u->code})",
                    'unitType' => (string) $u->unit_type,
                    // Ships so the client can mirror UnitConverter exactly.
                    // Without it the retired screen compared a kg number
                    // against a gram cap: the cost preview was off by 1000×
                    // and the availability check waved through submits the
                    // server then rejected.
                    'factorToBase' => (float) $u->factor_to_base,
                ])->all(),
            // Reasons come from the lookups admin, so staff can rename or
            // add them without a code change. `code` rides along so the
            // client can auto-pick an expired batch when reason=expired.
            'reasons' => Lookup::for('waste_reasons')->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => (string) $r->code,
                'label' => (string) $r->label,
                'icon' => (string) ($r->icon ?: ''),
                'color' => (string) ($r->color ?: ''),
            ])->values()->all(),
            'locations' => $locations->map(fn ($l) => [
                'id' => (int) $l->id,
                'label' => trim((! $branchId && $l->branch ? $l->branch->name.' · ' : '')
                    .$l->name.($l->code ? " ({$l->code})" : '')),
                'isDefault' => (bool) $l->is_default,
            ])->all(),
            'preselectedIngredientId' => (int) $request->query('ingredient_id', 0),
            'submitToken' => (string) Str::ulid(),
            'canManage' => $request->user()->can('manage', Ingredient::class),
            'urls' => [
                'store' => route('admin.waste.store'),
                'index' => route('admin.waste.index'),
                // {ingredient} is replaced client-side — one round-trip per pick.
                'batches' => route('admin.waste.batches', ['ingredient' => 0]),
            ],
        ]);
    }

    /**
     * Persist a waste event.
     *
     * The form sends `reason_lookup_id` (FK → lookups, group=waste_reasons).
     * We resolve it to its `code` and write BOTH columns:
     *   - waste_reason_lookup_id (source of truth going forward)
     *   - waste_reason (legacy string — kept in sync so old reports work)
     *
     * Legacy callers that still send a string `reason` are accepted too.
     */
    public function store(Request $request)
    {
        // Hardened: was `viewAny` — that let any chef/cashier write off stock,
        // hiding theft. Use `manage` so only admin/manager (or anyone with
        // explicit `inventory.manage` permission) can persist a waste event.
        $this->authorize('manage', Ingredient::class);

        $data = $request->validate([
            'request_token' => ['required', 'ulid'],
            'ingredient_id' => [
                'required',
                Rule::exists('ingredients', 'id')
                    ->where(fn ($query) => $query->where('active', true)->where('track_stock', true)),
            ],
            'batch_id' => ['nullable', 'exists:ingredient_batches,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['required', 'exists:units,id'],
            'storage_location_id' => ['required', 'exists:storage_locations,id'],
            'reason_lookup_id' => ['nullable', 'integer', Rule::exists('lookups', 'id')->where('group', 'waste_reasons')],
            'reason' => ['nullable', Rule::in(array_column(WasteReason::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $existingMovement = InventoryMovement::withoutGlobalScopes()
            ->where('uuid', $data['request_token'])
            ->first();
        if ($existingMovement) {
            if ($existingMovement->type === 'waste') {
                return redirect()->route('admin.waste.index')
                    ->with('info', 'تم تسجيل هذا الهدر مسبقاً، ولم يُخصم المخزون مرة ثانية.');
            }

            return back()->withInput()->withErrors([
                'request_token' => 'تعذر اعتماد العملية. أعد فتح نموذج الهدر وحاول مجدداً.',
            ]);
        }

        // Resolve the reason — prefer FK, derive string from its code.
        [$reasonString, $reasonLookupId] = $this->resolveReason($data);
        if (! $reasonString) {
            return back()->withInput()->with('error', 'اختر سبب الهدر.');
        }
        $data['reason'] = $reasonString;

        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $location = StorageLocation::withoutGlobalScopes()
            ->whereKey((int) $data['storage_location_id'])
            ->where('active', true)
            ->first();
        if (! $location || ! $request->user()->belongsToBranch((int) $location->branch_id)) {
            return back()->withInput()->with('error', 'موقع التخزين لا يتبع فرعاً مسموحاً لك أو أنه غير نشط.');
        }

        // Convert input qty → base unit. The form may show kg while stock is in g.
        $qtyBase = UnitConverter::convert(
            (float) $data['quantity'],
            (int) $data['unit_id'],
            (int) $ingredient->base_unit_id,
        );

        // If a batch is specified, decrement IT first (so FIFO ordering on
        // remaining batches stays honest). Done inside a transaction so a
        // failure in either side rolls both back.
        $batch = null;
        try {
            DB::transaction(function () use (&$batch, $data, $qtyBase, $ingredient, $request, $reasonLookupId, $location) {
                $unitCode = $ingredient->baseUnit?->code ?? '';

                if (! empty($data['batch_id'])) {
                    // Batch path — decrement THIS batch and validate against its remaining qty.
                    $batch = IngredientBatch::withoutGlobalScopes()->whereKey($data['batch_id'])
                        ->where('ingredient_id', $ingredient->id)
                        ->where('branch_id', $location->branch_id)
                        ->when(! empty($data['storage_location_id']), fn ($query) => $query->where('storage_location_id', $data['storage_location_id']))
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((float) $batch->remaining_qty + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة ('.Qty::format($qtyBase)." {$unitCode}) "
                            .'أكبر من المتبقّي في الدفعة ('.Qty::format($batch->remaining_qty)." {$unitCode}). "
                            .'اختر دفعة أخرى أو قلّل الكمية.'
                        );
                    }
                    $batch->update(['remaining_qty' => (float) $batch->remaining_qty - $qtyBase]);

                    // Pin the movement to the BATCH's own location. Without
                    // this, wasting a batch while "all locations" is selected
                    // sent storage_location_id=null, and recordMovement fell
                    // back to the branch's DEFAULT location — so the batch
                    // shrank at location A while ingredient_stock shrank at B.
                    $data['storage_location_id'] = $batch->storage_location_id;
                } else {
                    // Location path — storage_location_id is mandatory.
                    $available = (float) (IngredientStock::where('ingredient_id', $ingredient->id)
                        ->where('storage_location_id', $location->id)
                        ->lockForUpdate()
                        ->value('quantity') ?? 0);

                    if ($available + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة ('.Qty::format($qtyBase)." {$unitCode}) "
                            ."أكبر من المتوفّر في «{$location->name}» (".Qty::format($available)." {$unitCode}). "
                            .'قلّل الكمية أو اختر موقعاً آخر.'
                        );
                    }
                }

                $unitCost = $batch
                    ? (float) $batch->unit_cost
                    : (float) $ingredient->costAtBranch((int) $location->branch_id);

                $this->inventory->recordMovement(
                    ingredient: $ingredient,
                    type: 'waste',
                    qtyBase: $qtyBase,
                    unitCost: $unitCost,
                    reference: $batch,                 // movement.reference → batch when we have one
                    reason: $data['notes'] ?? null,
                    userId: $request->user()->id,
                    batchId: $batch?->id,
                    wasteReason: $data['reason'],
                    storageLocationId: $data['storage_location_id'] ?? null,
                    wasteReasonLookupId: $reasonLookupId,
                    // Explicit-batch waste already decremented the batch above;
                    // location/global waste (no $batch) must reconcile FIFO here.
                    syncBatches: $batch === null,
                    movementUuid: $data['request_token'],
                );

                ActivityLog::log(
                    'inventory.waste',
                    "هدر {$ingredient->name} ({$qtyBase} {$ingredient->baseUnit?->code}) — "
                    .WasteReason::from($data['reason'])->label(),
                    $ingredient,
                    [
                        'reason' => $data['reason'],
                        'qty_base' => $qtyBase,
                        'batch_id' => $batch?->id,
                        'cost' => $qtyBase * $unitCost,
                    ],
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            $existingMovement = InventoryMovement::withoutGlobalScopes()
                ->where('uuid', $data['request_token'])
                ->first();

            if ($existingMovement?->type === 'waste') {
                return redirect()->route('admin.waste.index')
                    ->with('info', 'تم تسجيل هذا الهدر مسبقاً، ولم يُخصم المخزون مرة ثانية.');
            }

            return back()->withInput()->with('error', 'تعذر تسجيل الهدر. أعد فتح النموذج وحاول مجدداً.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.waste.index')
            ->with('success', 'تم تسجيل الهدر وخصم المخزون.');
    }

    /**
     * Pull the waste reason from either the FK (preferred, dropdown sends it)
     * or the legacy string (still accepted for direct API callers / tests).
     *
     * Returns [reasonString, lookupId|null]. The string is what gets written
     * to the legacy `waste_reason` column; the FK to `waste_reason_lookup_id`.
     */
    protected function resolveReason(array $data): array
    {
        // Preferred path: dropdown sent a lookup id.
        if (! empty($data['reason_lookup_id'])) {
            $lookup = Lookup::query()
                ->where('group', 'waste_reasons')
                ->where('id', $data['reason_lookup_id'])
                ->first();
            if ($lookup) {
                // Use the lookup's `code` as the legacy string when it matches an
                // enum value; otherwise fall through and use 'other'.
                $code = WasteReason::tryFrom($lookup->code) ? $lookup->code : 'other';

                return [$code, (int) $lookup->id];
            }
        }

        // Legacy path: caller sent the enum string directly.
        if (! empty($data['reason'])) {
            $lookupId = Lookup::query()
                ->where('group', 'waste_reasons')
                ->where('code', $data['reason'])
                ->whereNull('branch_id')
                ->value('id');

            return [$data['reason'], $lookupId ? (int) $lookupId : null];
        }

        return [null, null];
    }

    /**
     * AJAX endpoint — list available batches for an ingredient (for the
     * "specific batch" picker on the waste form). Sorted FIFO so the most
     * urgent (earliest expiry) appears first.
     */
    public function batchesForIngredient(Request $request, Ingredient $ingredient)
    {
        // Batch numbers, remaining quantities and per-lot unit costs are
        // inventory data — gate the endpoint instead of leaving it open to
        // anyone who merely clears the admin middleware.
        $this->authorize('viewAny', Ingredient::class);

        abort_unless($ingredient->active && $ingredient->track_stock, 404);

        $data = $request->validate([
            'storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
        ]);
        $location = StorageLocation::withoutGlobalScopes()
            ->whereKey((int) $data['storage_location_id'])
            ->where('active', true)
            ->first();
        if (! $location || ! $request->user()->belongsToBranch((int) $location->branch_id)) {
            abort(403);
        }

        $batches = IngredientBatch::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->where('branch_id', $location->branch_id)
            ->where('storage_location_id', $location->id)
            // Waste must show expired/quarantined lots so staff can write
            // them off and trigger the matching accounting entry.
            ->fifo(true)
            ->limit(20)
            ->get(['id', 'batch_number', 'remaining_qty', 'expiry_date', 'received_date', 'unit_cost'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'label' => trim(
                    ($b->batch_number ? "#{$b->batch_number} · " : '')
                    .'متبقي '.number_format((float) $b->remaining_qty, 4)
                    .($b->expiry_date ? ' · ينتهي '.$b->expiry_date->format('Y-m-d') : '')
                ),
                'remaining_qty' => (float) $b->remaining_qty,
                'unit_cost' => (float) $b->unit_cost,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'is_expired' => $b->isExpired(),
            ]);

        return response()->json($batches);
    }

    protected function dateRange(Request $request): array
    {
        return [
            $request->get('from', now()->startOfMonth()->toDateString()),
            $request->get('to', now()->toDateString()),
        ];
    }
}
