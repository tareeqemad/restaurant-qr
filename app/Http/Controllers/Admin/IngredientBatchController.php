<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\StorageLocation;
use App\Services\BatchInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngredientBatchController extends Controller
{
    public function __construct(protected BatchInventoryService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $user = $request->user();
        $branchId = \App\Support\BranchContext::current();
        if (! $branchId && $user && ! $user->isOwnerLevel()) {
            $branchId = optional($user->primaryBranch())->id;
        }

        $q = IngredientBatch::with(['ingredient.baseUnit', 'storageLocation'])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->latest('received_date');
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

        $batchScope = fn () => IngredientBatch::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
        $stats = [
            'active'    => $batchScope()->where('remaining_qty', '>', 0)->count(),
            'expiring'  => $batchScope()->where('remaining_qty', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                            ->count(),
            'expired'   => $batchScope()->where('remaining_qty', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '<', now()->toDateString())->count(),
            'total_value' => (float) $batchScope()->where('remaining_qty', '>', 0)
                            ->selectRaw('SUM(remaining_qty * unit_cost) as v')
                            ->value('v'),
        ];

        return \App\Support\AdminShell::render('Admin/Batches/Index', [
            'batches' => $batches->through(function (IngredientBatch $b) {
                $baseCode   = $b->ingredient?->baseUnit?->code;
                $isExpired  = $b->isExpired();
                $nearExpiry = $b->isNearExpiry(7);
                $days       = $b->daysUntilExpiry();

                return [
                    'id'             => (int) $b->id,
                    'locationName'   => $b->storageLocation?->name ?? '—',
                    'ingredientName' => $b->ingredient?->name ?? '—',
                    'batchNumber'    => $b->batch_number ?: '—',
                    'receivedDate'   => $b->received_date?->format('Y-m-d') ?? '—',
                    'expiryDate'     => $b->expiry_date?->format('Y-m-d'),
                    'isExpired'      => $isExpired,
                    'isNearExpiry'   => $nearExpiry,
                    'daysUntilExpiry' => $days === null ? null : (int) $days,
                    'isDepleted'     => $b->isDepleted(),
                    // Row tint: expired wins over near-expiry, exactly as the
                    // Blade's table-danger / table-warning ternary did.
                    'rowTone'        => $isExpired ? 'danger' : ($nearExpiry ? 'warning' : null),
                    'initialQtyDisplay'   => \App\Helpers\QuantityFormatter::smart((float) $b->initial_qty, $baseCode),
                    'initialQtyTitle'     => trim(\App\Helpers\Qty::format($b->initial_qty).' '.($baseCode ?? '')),
                    'remainingQtyDisplay' => \App\Helpers\QuantityFormatter::smart((float) $b->remaining_qty, $baseCode),
                    'remainingQtyTitle'   => trim(\App\Helpers\Qty::format($b->remaining_qty).' '.($baseCode ?? '')),
                    'unitCostDisplay'     => \App\Helpers\Qty::format($b->unit_cost),
                    'unitCostTitle'       => 'تكلفة الوحدة الأساسية ('.($baseCode ?? '').')',
                    // القيمة الحالية = المتبقي × تكلفة الوحدة
                    'value' => (float) $b->remaining_qty * (float) $b->unit_cost,
                ];
            }),
            'stats' => [
                'active'     => (int) $stats['active'],
                'expiring'   => (int) $stats['expiring'],
                'expired'    => (int) $stats['expired'],
                'totalValue' => $stats['total_value'],
            ],
            'filters' => [
                'ingredientId'      => (string) $request->get('ingredient_id', ''),
                'storageLocationId' => (string) $request->get('storage_location_id', ''),
                'active'            => $request->filled('active'),
                'expiring'          => $request->filled('expiring'),
                'expired'           => $request->filled('expired'),
            ],
            'hasFilters' => $request->hasAny(['ingredient_id', 'storage_location_id', 'expired', 'expiring', 'active']),
            'ingredients' => Ingredient::with('baseUnit:id,code')
                ->where('track_stock', true)->orderBy('name')->get(['id', 'name', 'base_unit_id'])
                ->map(fn (Ingredient $i) => [
                    'id'       => (int) $i->id,
                    'name'     => $i->name,
                    'unitCode' => $i->baseUnit?->code ?? '',
                ])->all(),
            'storageLocations' => StorageLocation::where('active', true)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->when(! $user?->isOwnerLevel(), fn ($query) => $query
                    ->whereIn('branch_id', $user?->accessibleBranchIds() ?? []))
                ->with('branch:id,name')
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'branch_id', 'is_default'])
                ->map(fn (StorageLocation $l) => [
                    'id'        => (int) $l->id,
                    'label'     => (! $branchId && $l->branch ? $l->branch->name.' · ' : '')
                        .$l->name.($l->code ? ' - '.$l->code : ''),
                    'isDefault' => (bool) $l->is_default,
                ])->all(),
            'currency' => [
                'symbol'   => \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
                'decimals' => 2,
            ],
            'can' => [
                // Manual batch creation injects stock without a PO trail —
                // `manage` only, same gate store() authorizes against.
                'create' => (bool) $request->user()?->can('manage', Ingredient::class),
            ],
            'urls' => [
                'index' => route('admin.batches.index'),
                'store' => route('admin.batches.store'),
            ],
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
            'storage_location_id' => ['required', 'integer'],
            'qty'           => ['required', 'numeric', 'min:0.0001'],
            'unit_cost'     => ['nullable', 'numeric', 'min:0'],
            'expiry_date'   => ['nullable', 'date', 'after_or_equal:today'],
            'batch_number'  => ['nullable', 'string', 'max:80'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $ing = Ingredient::findOrFail($data['ingredient_id']);
        $location = StorageLocation::withoutGlobalScopes()
            ->whereKey((int) $data['storage_location_id'])
            ->where('active', true)
            ->first();
        if (! $location || ! $request->user()?->belongsToBranch((int) $location->branch_id)) {
            throw ValidationException::withMessages([
                'storage_location_id' => 'اختر موقع تخزين نشطاً من الفروع المسموح لك بإدارتها.',
            ]);
        }

        $storageLocationId = (int) $location->id;
        $unitCost = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null
            ? (float) $data['unit_cost']
            : $ing->costAtBranch((int) $location->branch_id);

        DB::transaction(function () use ($ing, $data, $storageLocationId, $unitCost) {
            $batch = $this->service->createBatchOnReceipt(
                ingredient:  $ing,
                qtyBase:     (float) $data['qty'],
                unitCost:    $unitCost,
                expiryDate:  $data['expiry_date'] ?? null,
                batchNumber: $data['batch_number'] ?? null,
                source:      null,
                notes:       $data['notes'] ?? null,
                storageLocationId: $storageLocationId,
            );

            // Batch + stock movement are one atomic operation: neither may
            // survive without the other.
            app(\App\Services\InventoryService::class)->recordMovement(
                ingredient: $ing,
                type:       'in',
                qtyBase:    (float) $data['qty'],
                unitCost:   $unitCost,
                reference:  $batch,
                reason:     'إضافة دفعة يدوية',
                userId:     auth()->id(),
                batchId:    $batch->id,
                storageLocationId: $storageLocationId,
            );
        });

        return back()->with('success', 'تم إنشاء الدفعة وإضافتها للمخزون.');
    }
}
