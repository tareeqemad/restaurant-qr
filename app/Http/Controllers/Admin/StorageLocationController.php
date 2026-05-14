<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StorageLocation;
use App\Services\LocationInventoryService;
use App\Support\BranchContext;
use Illuminate\Http\Request;

class StorageLocationController extends Controller
{
    public function __construct(protected LocationInventoryService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $locations = StorageLocation::with('branch:id,name')
            ->withCount('ingredientStocks')
            ->orderBy('display_order')
            ->get();

        foreach ($locations as $loc) {
            $loc->stock_value = $loc->stockValue();
            $loc->low_stock_count = $this->service->lowStockAtLocation($loc)->count();
        }

        return view('admin.storage-locations.index', compact('locations'));
    }

    public function show(StorageLocation $storageLocation)
    {
        $this->authorize('viewAny', Ingredient::class);

        $stocks = IngredientStock::with('ingredient.baseUnit')
            ->where('storage_location_id', $storageLocation->id)
            ->get()
            ->sortBy('ingredient.name');

        return view('admin.storage-locations.show', [
            'location' => $storageLocation,
            'stocks'   => $stocks,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Ingredient::class);
        return view('admin.storage-locations.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Ingredient::class);
        $data = $this->validated($request);

        // BelongsToBranch normally auto-fills branch_id from the active
        // BranchContext, but an owner-level user in "view all branches"
        // mode has no context bound — the insert would fail with a NOT
        // NULL violation. Pin the new location to the user's current
        // branch (context > primary > first member branch) and refuse to
        // proceed if none can be determined, with a clear instruction
        // instead of a stack trace.
        $branchId = BranchContext::current()
            ?? optional($request->user()->primaryBranch())->id
            ?? optional($request->user()->branches()->first())->id;

        // Owner-level (super admin / partner) is never seated in branch_user,
        // so the fallback chain above hits null. Default them to the first
        // active branch so they aren't blocked at the create form.
        if (! $branchId && $request->user()->isOwnerLevel()) {
            $branchId = \App\Models\Branch::active()->orderBy('display_order')->value('id');
        }

        if (! $branchId) {
            return back()->withInput()->with(
                'error',
                'تعذّر تحديد فرع لإضافة الموقع. اختر فرعاً نشطاً من شريط التبديل في الأعلى ثم أعد المحاولة.'
            );
        }

        $data['branch_id'] = $branchId;
        $loc = StorageLocation::create($data);
        return redirect()->route('admin.storage-locations.index')->with('success', "تم إنشاء الموقع {$loc->name}");
    }

    public function edit(StorageLocation $storageLocation)
    {
        $this->authorize('create', Ingredient::class);
        return view('admin.storage-locations.edit', ['location' => $storageLocation]);
    }

    public function update(Request $request, StorageLocation $storageLocation)
    {
        $this->authorize('create', Ingredient::class);
        $storageLocation->update($this->validated($request));
        return redirect()->route('admin.storage-locations.index')->with('success', 'تم تحديث الموقع');
    }

    public function destroy(StorageLocation $storageLocation)
    {
        $this->authorize('create', Ingredient::class);
        if ($storageLocation->ingredientStocks()->where('quantity', '>', 0)->exists()) {
            return back()->with('error', 'لا يمكن حذف موقع فيه مخزون. انقل المخزون لموقع آخر أولاً.');
        }
        // The guard above guarantees every remaining row is zero. Drop them
        // so we don't leave ingredient_stock rows pointing at a deleted
        // location — orphans like that linger in per-ingredient stock lists
        // and (historically) left current_stock out of sync with reality.
        $storageLocation->ingredientStocks()->delete();
        $storageLocation->delete();
        return redirect()->route('admin.storage-locations.index')->with('success', 'تم حذف الموقع');
    }

    /** Transfer stock between locations */
    public function transferForm()
    {
        $this->authorize('viewAny', Ingredient::class);

        // Honour the active branch context. When a user has switched into a
        // branch via the header, only that branch's locations are visible
        // — same rule as every other admin screen, no special bypass.
        // Owner-level admins who need cross-branch transfers can switch to
        // "all branches" (no context) and use the dedicated branch transfer
        // flow at /admin/branch-transfers/create.
        $locations = StorageLocation::with('branch:id,name')
            ->where('active', true)
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'is_default']);

        $ingredients = Ingredient::with('baseUnit')
            ->where('active', true)
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();

        // Per-location stock map — lets the JS filter the ingredient
        // dropdown to only those that actually have qty > 0 at the chosen
        // source, and surface the available quantity inline.
        $stockByLocation = \DB::table('ingredient_stock')
            ->select('storage_location_id', 'ingredient_id', 'quantity')
            ->get()
            ->groupBy('storage_location_id')
            ->map(fn ($g) => $g->mapWithKeys(fn ($r) => [$r->ingredient_id => (float) $r->quantity]))
            ->toArray();

        return view('admin.storage-locations.transfer', [
            'locations'       => $locations,
            'ingredients'     => $ingredients,
            'stockByLocation' => $stockByLocation,
        ]);
    }

    public function transferStore(Request $request)
    {
        // Hardened (was `viewAny`): inter-location transfers move stock
        // between physical locations and shouldn't be available to anyone
        // who can merely view inventory.
        $this->authorize('manage', Ingredient::class);

        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'from_id'       => ['required', 'exists:storage_locations,id', 'different:to_id'],
            'to_id'         => ['required', 'exists:storage_locations,id'],
            'quantity'      => ['required', 'numeric', 'min:0.0001'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);

        $ing = Ingredient::findOrFail($data['ingredient_id']);
        $from = StorageLocation::findOrFail($data['from_id']);
        $to   = StorageLocation::findOrFail($data['to_id']);

        try {
            $this->service->transfer($ing, $from, $to, (float) $data['quantity'], $data['reason'] ?? null, auth()->id());
            return back()->with('success', "تم نقل {$data['quantity']} {$ing->baseUnit?->code} من {$from->name} إلى {$to->name}.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'code'          => ['required', 'alpha_dash', 'max:40'],
            'name'          => ['required', 'string', 'max:255'],
            'icon'          => ['nullable', 'string', 'max:40'],
            'color'         => ['nullable', 'string', 'max:20'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'is_default'    => ['boolean'],
            'active'        => ['boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
