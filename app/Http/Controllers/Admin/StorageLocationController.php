<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StorageLocation;
use App\Services\LocationInventoryService;
use Illuminate\Http\Request;

class StorageLocationController extends Controller
{
    public function __construct(protected LocationInventoryService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $locations = StorageLocation::withCount('ingredientStocks')->orderBy('display_order')->get();

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
        $storageLocation->delete();
        return redirect()->route('admin.storage-locations.index')->with('success', 'تم حذف الموقع');
    }

    /** Transfer stock between locations */
    public function transferForm()
    {
        $this->authorize('viewAny', Ingredient::class);
        return view('admin.storage-locations.transfer', [
            'locations'   => StorageLocation::where('active', true)->orderBy('display_order')->get(),
            'ingredients' => Ingredient::with('baseUnit')->orderBy('name')->get(),
        ]);
    }

    public function transferStore(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

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
