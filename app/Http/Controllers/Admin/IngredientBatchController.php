<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\StorageLocation;
use App\Services\BatchInventoryService;
use Illuminate\Http\Request;

class IngredientBatchController extends Controller
{
    public function __construct(protected BatchInventoryService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $q = IngredientBatch::with(['ingredient.baseUnit', 'storageLocation'])->latest('received_date');
        if ($iid = $request->get('ingredient_id')) $q->where('ingredient_id', $iid);
        if ($locationId = $request->get('storage_location_id')) $q->where('storage_location_id', $locationId);
        if ($request->filled('expired')) {
            $q->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now()->toDateString());
        }
        if ($request->filled('expiring')) {
            $q->whereNotNull('expiry_date')
              ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
              ->where('remaining_qty', '>', 0);
        }
        if ($request->filled('active')) {
            $q->where('remaining_qty', '>', 0);
        }

        $batches = $q->paginate(25)->withQueryString();

        $stats = [
            'active'    => IngredientBatch::where('remaining_qty', '>', 0)->count(),
            'expiring'  => IngredientBatch::where('remaining_qty', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                            ->count(),
            'expired'   => IngredientBatch::where('remaining_qty', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '<', now()->toDateString())->count(),
            'total_value' => (float) IngredientBatch::where('remaining_qty', '>', 0)
                            ->selectRaw('SUM(remaining_qty * unit_cost) as v')
                            ->value('v'),
        ];

        return view('admin.batches.index', [
            'batches'     => $batches,
            'stats'       => $stats,
            'ingredients' => Ingredient::where('track_stock', true)->orderBy('name')->get(),
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /** Create a batch manually (e.g., for existing stock that wasn't captured in PO) */
    public function store(Request $request)
    {
        // Hardened (was `viewAny`): manual batch creation injects stock into
        // the warehouse bypassing the PO trail. Only `manage` can do this —
        // not chefs/cashiers.
        $this->authorize('manage', Ingredient::class);

        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'qty'           => ['required', 'numeric', 'min:0.0001'],
            'unit_cost'     => ['nullable', 'numeric', 'min:0'],
            'expiry_date'   => ['nullable', 'date', 'after_or_equal:today'],
            'batch_number'  => ['nullable', 'string', 'max:80'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $ing = Ingredient::findOrFail($data['ingredient_id']);
        $storageLocationId = (int) ($data['storage_location_id'] ?? 0) ?: StorageLocation::default()?->id;
        $batch = $this->service->createBatchOnReceipt(
            ingredient:  $ing,
            qtyBase:     (float) $data['qty'],
            unitCost:    (float) ($data['unit_cost'] ?? $ing->cost_per_unit),
            expiryDate:  $data['expiry_date'] ?? null,
            batchNumber: $data['batch_number'] ?? null,
            source:      null,
            notes:       $data['notes'] ?? null,
            storageLocationId: $storageLocationId,
        );

        // Also record inventory movement so stock stays in sync
        app(\App\Services\InventoryService::class)->recordMovement(
            ingredient: $ing,
            type:       'in',
            qtyBase:    (float) $data['qty'],
            unitCost:   (float) ($data['unit_cost'] ?? $ing->cost_per_unit),
            reference:  $batch,
            reason:     'إضافة دفعة يدوية',
            userId:     auth()->id(),
            batchId:    $batch->id,
            storageLocationId: $storageLocationId,
        );

        return back()->with('success', 'تم إنشاء الدفعة وإضافتها للمخزون.');
    }
}
