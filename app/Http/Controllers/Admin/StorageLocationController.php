<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Qty;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Services\LocationInventoryService;
use App\Support\AdminShell;
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
            // Mirrors destroy()'s guard exactly (any row with quantity > 0
            // blocks the delete). Shipping it as a flag lets the card say so
            // BEFORE the click instead of bouncing the user with a flash.
            ->withCount(['ingredientStocks as stocked_rows_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('display_order')
            ->get();

        // create/update/delete all resolve to InventoryPolicy::manage.
        $canManage = $request->user()?->can('create', Ingredient::class) ?? false;

        return AdminShell::render('Admin/StorageLocations/Index', [
            'locations' => $locations->map(fn ($loc) => [
                'id' => (int) $loc->id,
                'name' => $loc->name,
                'code' => $loc->code,
                'description' => $loc->description,
                // The card head paints a gradient from this colour; an empty
                // string would emit broken CSS, so fall back like the Blade did.
                'color' => $loc->color ?: '#0F4731',
                'isDefault' => (bool) $loc->is_default,
                'active' => (bool) $loc->active,
                'branchName' => $loc->branch?->name,
                'ingredientsCount' => (int) $loc->ingredient_stocks_count,
                'lowStockCount' => $this->service->lowStockAtLocation($loc)->count(),
                'stockValue' => (float) $loc->stockValue(),
                'blocksDelete' => (int) $loc->stocked_rows_count > 0,
                'urls' => [
                    'show' => route('admin.storage-locations.show', $loc),
                    'edit' => route('admin.storage-locations.edit', $loc),
                    'destroy' => route('admin.storage-locations.destroy', $loc),
                ],
            ])->values()->all(),
            'can' => ['manage' => $canManage],
            'currency' => $this->currencyProp(),
            'urls' => [
                'create' => route('admin.storage-locations.create'),
                'transfer' => route('admin.storage-locations.transfer-form'),
            ],
        ]);
    }

    public function show(StorageLocation $storageLocation)
    {
        $this->authorize('viewAny', Ingredient::class);

        $stocks = IngredientStock::with('ingredient.baseUnit')
            ->where('storage_location_id', $storageLocation->id)
            ->get()
            ->sortBy('ingredient.name');

        return AdminShell::render('Admin/StorageLocations/Show', [
            'location' => [
                'id' => (int) $storageLocation->id,
                'name' => $storageLocation->name,
            ],
            'stocks' => $stocks->map(function (IngredientStock $s) {
                $ing = $s->ingredient;
                $cost = (float) ($ing?->cost_per_unit ?? 0);

                return [
                    'id' => (int) $s->id,
                    'name' => $ing?->name ?? '—',
                    'unitCode' => $ing?->baseUnit?->code ?? '',
                    // Qty::format trims trailing zeros off the 4-decimal
                    // storage precision — same helper the Blade called.
                    'quantityLabel' => Qty::format($s->quantity),
                    'thresholdLabel' => Qty::format($s->reorder_threshold),
                    'costLabel' => Qty::format($cost),
                    'value' => (float) $s->quantity * $cost,
                    'isLow' => $s->isLowStock(),
                ];
            })->values()->all(),
            'currency' => $this->currencyProp(),
            'urls' => [
                'index' => route('admin.storage-locations.index'),
                'purchaseOrder' => route('admin.purchase-orders.create'),
                'supplierInvoice' => route('admin.supplier-invoices.create'),
                'transfer' => route('admin.storage-locations.transfer-form'),
                'branchTransfers' => route('admin.branch-transfers.index'),
                'stockCount' => route('admin.stock-counts.create'),
                'waste' => route('admin.waste.create'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', Ingredient::class);

        return AdminShell::render('Admin/StorageLocations/Form', [
            'location' => null,
            'urls' => [
                'index' => route('admin.storage-locations.index'),
                'submit' => route('admin.storage-locations.store'),
            ],
        ]);
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

        return AdminShell::render('Admin/StorageLocations/Form', [
            'location' => [
                'id' => (int) $storageLocation->id,
                'code' => $storageLocation->code,
                'name' => $storageLocation->name,
                'icon' => $storageLocation->icon,
                'color' => $storageLocation->color,
                'description' => $storageLocation->description,
                'isDefault' => (bool) $storageLocation->is_default,
                'active' => (bool) $storageLocation->active,
                'displayOrder' => (int) $storageLocation->display_order,
            ],
            'urls' => [
                'index' => route('admin.storage-locations.index'),
                'submit' => route('admin.storage-locations.update', $storageLocation),
            ],
        ]);
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
    public function transferForm(Request $request)
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

        // Per-location stock map — lets the client filter the ingredient
        // dropdown to only those that actually have qty > 0 at the chosen
        // source, and surface the available quantity inline.
        //
        // Restricted to the locations this user can actually pick. The map
        // used to be built over the WHOLE table, so the payload carried
        // every other branch's per-location quantities even though the
        // dropdowns are branch-scoped and could never address them.
        $stockByLocation = \DB::table('ingredient_stock')
            ->whereIn('storage_location_id', $locations->pluck('id'))
            ->select('storage_location_id', 'ingredient_id', 'quantity')
            ->get()
            ->groupBy('storage_location_id')
            // Cast both levels to objects so json_encode can never turn a
            // numeric-keyed map into a positional array on the client.
            ->map(fn ($g) => (object) $g->mapWithKeys(fn ($r) => [$r->ingredient_id => (float) $r->quantity])->toArray())
            ->toArray();

        return AdminShell::render('Admin/StorageLocations/Transfer', [
            'locations' => $locations->map(fn ($l) => [
                'id' => (int) $l->id,
                'name' => $l->name,
                'branchId' => (int) $l->branch_id,
                'branchName' => $l->branch?->name ?? '',
                'isDefault' => (bool) $l->is_default,
            ])->values()->all(),
            'ingredients' => $ingredients->map(fn ($i) => [
                'id' => (int) $i->id,
                'name' => $i->name,
                'unitCode' => $i->baseUnit?->code ?? '',
                'globalThreshold' => (float) ($i->reorder_threshold ?? 0),
            ])->values()->all(),
            'stockByLocation' => (object) $stockByLocation,
            // transferStore authorizes `manage`, not `viewAny` — a chef or
            // bartender can OPEN this screen but the write would 403. The
            // Blade offered them a live submit button anyway.
            'can' => ['transfer' => $request->user()?->can('manage', Ingredient::class) ?? false],
            'urls' => [
                'index' => route('admin.storage-locations.index'),
                'store' => route('admin.storage-locations.transfer-store'),
                'branchTransferCreate' => route('admin.branch-transfers.create'),
            ],
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

    /** Same source Money::format reads, so both screens agree on the symbol. */
    protected function currencyProp(): array
    {
        return [
            'symbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'decimals' => 2,
        ];
    }
}
