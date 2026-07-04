<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WasteReason;
use App\Helpers\UnitConverter;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
use App\Models\Lookup;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $end   = $to  .' 23:59:59';

        $query = InventoryMovement::with(['ingredient.baseUnit', 'batch', 'storageLocation', 'user', 'unit', 'wasteReasonLookup'])
            ->where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end])
            ->latest('occurred_at');

        // The reason filter is the FK id (preferred) — falls back to the legacy
        // string column for any old rows that pre-date the FK migration.
        if ($r = $request->get('reason')) {
            $query->where(function ($q) use ($r) {
                $q->where('waste_reason_lookup_id', $r)
                  ->orWhere(function ($q2) use ($r) {
                      $code = \App\Models\Lookup::whereKey($r)->value('code');
                      if ($code) {
                          $q2->whereNull('waste_reason_lookup_id')->where('waste_reason', $code);
                      }
                  });
            });
        }
        if ($i = $request->get('ingredient_id'))  $query->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $query->where('storage_location_id', $l);

        $movements = $query->paginate(25)->withQueryString();

        // Aggregates over the SAME filtered window
        $base = InventoryMovement::where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end]);
        if ($r = $request->get('reason')) {
            $base->where(function ($q) use ($r) {
                $q->where('waste_reason_lookup_id', $r)
                  ->orWhere(function ($q2) use ($r) {
                      $code = \App\Models\Lookup::whereKey($r)->value('code');
                      if ($code) {
                          $q2->whereNull('waste_reason_lookup_id')->where('waste_reason', $code);
                      }
                  });
            });
        }
        if ($i = $request->get('ingredient_id')) $base->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $base->where('storage_location_id', $l);

        $stats = [
            'count'      => (clone $base)->count(),
            'total_cost' => (float) (clone $base)->sum('total_cost'),
            'today_count'=> (clone $base)->whereDate('occurred_at', today())->count(),
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
        if ($branchId = \App\Support\BranchContext::current()) {
            $topIngQuery->where('inventory_movements.branch_id', $branchId);
        }

        // Reason filter — same FK-or-legacy logic as the other two queries.
        if ($r = $request->get('reason')) {
            $topIngQuery->where(function ($q) use ($r) {
                $q->where('inventory_movements.waste_reason_lookup_id', $r)
                  ->orWhere(function ($q2) use ($r) {
                      $code = \App\Models\Lookup::whereKey($r)->value('code');
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

        return view('admin.waste.index', [
            'movements'     => $movements,
            'stats'         => $stats,
            'byReason'      => $byReason,
            'topIngredients'=> $topIngredients,
            'from'          => $from,
            'to'            => $to,
            // Reasons come from the lookups admin (group=waste_reasons) so renames /
            // new entries appear here automatically. Keyed by `code` for fast lookup
            // against the legacy `waste_reason` string column on the movements table.
            // Keyed by `id` (the FK value) — the dropdown sends id, the
            // controller filters by id, the breakdown looks up by id.
            // The view derives a code-keyed copy for legacy-row lookups.
            'reasons'       => Lookup::for('waste_reasons')->keyBy('id'),
            'ingredients'   => Ingredient::orderBy('name')->get(['id', 'name']),
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    /** Form to log a new waste event. The Livewire component fetches its
     *  own ingredients/units/reasons; we just pass the preselected id from
     *  the query string (used when staff click "log waste" from the
     *  top-wasted-ingredients table on the index page). */
    public function create(Request $request)
    {
        // Anyone who can read inventory can OPEN the form, but the actual
        // store() requires `manage` (see below) so a chef/cashier can't
        // submit even if they reach the URL.
        $this->authorize('viewAny', Ingredient::class);
        return view('admin.waste.create');
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
            'ingredient_id'    => ['required', 'exists:ingredients,id'],
            'batch_id'         => ['nullable', 'exists:ingredient_batches,id'],
            'quantity'         => ['required', 'numeric', 'min:0.0001'],
            'unit_id'          => ['required', 'exists:units,id'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'reason_lookup_id' => ['nullable', 'integer', Rule::exists('lookups', 'id')->where('group', 'waste_reasons')],
            'reason'           => ['nullable', Rule::in(array_column(WasteReason::cases(), 'value'))],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        // Resolve the reason — prefer FK, derive string from its code.
        [$reasonString, $reasonLookupId] = $this->resolveReason($data);
        if (! $reasonString) {
            return back()->withInput()->with('error', 'اختر سبب الهدر.');
        }
        $data['reason'] = $reasonString;

        $ingredient = Ingredient::findOrFail($data['ingredient_id']);

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
            DB::transaction(function () use (&$batch, $data, $qtyBase, $ingredient, $request, $reasonLookupId) {
                $unitCode = $ingredient->baseUnit?->code ?? '';

                if (! empty($data['batch_id'])) {
                    // Batch path — decrement THIS batch and validate against its remaining qty.
                    $batch = IngredientBatch::whereKey($data['batch_id'])
                        ->where('ingredient_id', $ingredient->id)
                        ->when(! empty($data['storage_location_id']), fn ($query) => $query->where('storage_location_id', $data['storage_location_id']))
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((float) $batch->remaining_qty + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . \App\Helpers\Qty::format($qtyBase) . " {$unitCode}) "
                            . 'أكبر من المتبقّي في الدفعة (' . \App\Helpers\Qty::format($batch->remaining_qty) . " {$unitCode}). "
                            . 'اختر دفعة أخرى أو قلّل الكمية.'
                        );
                    }
                    $batch->update(['remaining_qty' => (float) $batch->remaining_qty - $qtyBase]);
                } elseif (! empty($data['storage_location_id'])) {
                    // Location path — validate against per-location stock row.
                    $available = (float) (\App\Models\IngredientStock::where('ingredient_id', $ingredient->id)
                        ->where('storage_location_id', $data['storage_location_id'])
                        ->lockForUpdate()
                        ->value('quantity') ?? 0);

                    if ($available + 0.0001 < $qtyBase) {
                        $locName = StorageLocation::whereKey($data['storage_location_id'])->value('name') ?? 'الموقع المختار';
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . \App\Helpers\Qty::format($qtyBase) . " {$unitCode}) "
                            . "أكبر من المتوفّر في «{$locName}» (" . \App\Helpers\Qty::format($available) . " {$unitCode}). "
                            . 'قلّل الكمية أو اختر موقعاً آخر.'
                        );
                    }
                } else {
                    // Global path — validate against the ingredient's total current_stock.
                    $current = (float) Ingredient::whereKey($ingredient->id)
                        ->lockForUpdate()
                        ->value('current_stock');

                    if ($current + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            'الكمية المطلوبة (' . \App\Helpers\Qty::format($qtyBase) . " {$unitCode}) "
                            . 'أكبر من المتوفّر إجمالياً (' . \App\Helpers\Qty::format($current) . " {$unitCode}). "
                            . 'قلّل الكمية.'
                        );
                    }
                }

                $unitCost = $batch
                    ? (float) $batch->unit_cost
                    : (float) $ingredient->cost_per_unit;

                $this->inventory->recordMovement(
                    ingredient:  $ingredient,
                    type:        'waste',
                    qtyBase:     $qtyBase,
                    unitCost:    $unitCost,
                    reference:   $batch,                 // movement.reference → batch when we have one
                    reason:      $data['notes'] ?? null,
                    userId:      $request->user()->id,
                    batchId:     $batch?->id,
                    wasteReason: $data['reason'],
                    storageLocationId: $data['storage_location_id'] ?? null,
                    wasteReasonLookupId: $reasonLookupId,
                    // Explicit-batch waste already decremented the batch above;
                    // location/global waste (no $batch) must reconcile FIFO here.
                    syncBatches: $batch === null,
                );

                ActivityLog::log(
                    'inventory.waste',
                    "هدر {$ingredient->name} ({$qtyBase} {$ingredient->baseUnit?->code}) — "
                    . WasteReason::from($data['reason'])->label(),
                    $ingredient,
                    [
                        'reason'   => $data['reason'],
                        'qty_base' => $qtyBase,
                        'batch_id' => $batch?->id,
                        'cost'     => $qtyBase * (float) ($batch?->unit_cost ?? $ingredient->cost_per_unit),
                    ],
                );
            });
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
        $batches = IngredientBatch::where('ingredient_id', $ingredient->id)
            ->when($request->get('storage_location_id'), fn ($query, $locationId) => $query->where('storage_location_id', $locationId))
            ->fifo()
            ->limit(20)
            ->get(['id', 'batch_number', 'remaining_qty', 'expiry_date', 'received_date', 'unit_cost'])
            ->map(fn ($b) => [
                'id'             => $b->id,
                'label'          => trim(
                    ($b->batch_number ? "#{$b->batch_number} · " : '')
                    . 'متبقي ' . number_format((float) $b->remaining_qty, 4)
                    . ($b->expiry_date ? ' · ينتهي ' . $b->expiry_date->format('Y-m-d') : '')
                ),
                'remaining_qty'  => (float) $b->remaining_qty,
                'unit_cost'      => (float) $b->unit_cost,
                'expiry_date'    => $b->expiry_date?->toDateString(),
                'is_expired'     => $b->isExpired(),
            ]);

        return response()->json($batches);
    }

    protected function dateRange(Request $request): array
    {
        return [
            $request->get('from', now()->startOfMonth()->toDateString()),
            $request->get('to',   now()->toDateString()),
        ];
    }
}
