<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WasteReason;
use App\Helpers\UnitConverter;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\InventoryMovement;
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

        $query = InventoryMovement::with(['ingredient.baseUnit', 'batch', 'storageLocation', 'user', 'unit'])
            ->where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end])
            ->latest('occurred_at');

        if ($r = $request->get('reason'))         $query->where('waste_reason', $r);
        if ($i = $request->get('ingredient_id'))  $query->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $query->where('storage_location_id', $l);

        $movements = $query->paginate(25)->withQueryString();

        // Aggregates over the SAME filtered window
        $base = InventoryMovement::where('type', 'waste')
            ->whereBetween('occurred_at', [$start, $end]);
        if ($r = $request->get('reason'))        $base->where('waste_reason', $r);
        if ($i = $request->get('ingredient_id')) $base->where('ingredient_id', $i);
        if ($l = $request->get('storage_location_id')) $base->where('storage_location_id', $l);

        $stats = [
            'count'      => (clone $base)->count(),
            'total_cost' => (float) (clone $base)->sum('total_cost'),
            'today_count'=> (clone $base)->whereDate('occurred_at', today())->count(),
            'today_cost' => (float) (clone $base)->whereDate('occurred_at', today())->sum('total_cost'),
        ];

        // Group by reason for the breakdown card
        $byReason = (clone $base)
            ->selectRaw('waste_reason, COUNT(*) as count, SUM(total_cost) as total_cost')
            ->groupBy('waste_reason')
            ->orderByDesc('total_cost')
            ->get();

        // Top 10 wasted ingredients in window — actionable: drives recipe/storage fixes
        $topIngredients = DB::table('inventory_movements')
            ->join('ingredients', 'inventory_movements.ingredient_id', '=', 'ingredients.id')
            ->where('inventory_movements.type', 'waste')
            ->whereBetween('inventory_movements.occurred_at', [$start, $end])
            ->when($request->get('storage_location_id'), fn ($q, $locationId) => $q->where('inventory_movements.storage_location_id', $locationId))
            ->when($request->get('reason'), fn ($q, $reason) => $q->where('inventory_movements.waste_reason', $reason))
            ->when($request->get('ingredient_id'), fn ($q, $ingredientId) => $q->where('inventory_movements.ingredient_id', $ingredientId))
            ->selectRaw('
                ingredients.id,
                ingredients.name,
                COUNT(*) as event_count,
                SUM(inventory_movements.quantity_in_base) as qty,
                SUM(inventory_movements.total_cost) as total_cost
            ')
            ->groupBy('ingredients.id', 'ingredients.name')
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
            'reasons'       => WasteReason::cases(),
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
        $this->authorize('viewAny', Ingredient::class);
        return view('admin.waste.create');
    }

    /** Persist a waste event. */
    public function store(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'batch_id'      => ['nullable', 'exists:ingredient_batches,id'],
            'quantity'      => ['required', 'numeric', 'min:0.0001'],
            'unit_id'       => ['required', 'exists:units,id'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'reason'        => ['required', Rule::in(array_column(WasteReason::cases(), 'value'))],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

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
            DB::transaction(function () use (&$batch, $data, $qtyBase, $ingredient, $request) {
                if (! empty($data['batch_id'])) {
                    $batch = IngredientBatch::whereKey($data['batch_id'])
                        ->where('ingredient_id', $ingredient->id)
                        ->when(! empty($data['storage_location_id']), fn ($query) => $query->where('storage_location_id', $data['storage_location_id']))
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((float) $batch->remaining_qty + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            "الدفعة المختارة لا تحتوي على الكمية الكافية ("
                            . number_format((float) $batch->remaining_qty, 4)
                            . ' < ' . number_format($qtyBase, 4) . ').'
                        );
                    }
                    $batch->update(['remaining_qty' => (float) $batch->remaining_qty - $qtyBase]);
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
