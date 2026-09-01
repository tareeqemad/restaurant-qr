<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\User;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MenuWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        return $this->page($request);
    }

    protected function page(Request $request, ?array $editor = null)
    {
        $stations = Station::with('storageLocation')
            ->withCount(['users', 'menuItems', 'orderItems'])
            ->orderBy('display_order')
            ->get();
        $user = auth()->user();
        $canUpdate = (bool) $user?->can('update', User::class);
        $canDelete = (bool) $user?->can('delete', User::class);

        return AdminShell::render('Admin/MenuCatalog/Stations', [
            'navigation' => MenuWorkspace::navigation(),
            'stations' => $stations->map(fn (Station $station) => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->name,
                'color' => $station->color ?: '#166534',
                'icon' => $station->icon,
                'storageLocationId' => $station->storage_location_id,
                'storageLocation' => $station->storageLocation?->name,
                'displayOrder' => (int) $station->display_order,
                'active' => (bool) $station->active,
                'usersCount' => (int) $station->users_count,
                'itemsCount' => (int) $station->menu_items_count,
                'historyCount' => (int) $station->order_items_count,
                'can' => [
                    'update' => $canUpdate,
                    'delete' => $canDelete
                        && $station->users_count === 0
                        && $station->menu_items_count === 0
                        && $station->order_items_count === 0,
                ],
                'urls' => [
                    'update' => route('admin.stations.update', $station),
                    'destroy' => route('admin.stations.destroy', $station),
                ],
            ])->values(),
            'stats' => [
                'total' => $stations->count(),
                'active' => $stations->where('active', true)->count(),
                'items' => $stations->sum('menu_items_count'),
                'staff' => $stations->sum('users_count'),
            ],
            'storageLocations' => $this->formData()['storageLocations']->map(fn (StorageLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'isDefault' => (bool) $location->is_default,
            ])->values(),
            'editor' => $editor,
            'can' => [
                'create' => (bool) $user?->can('create', User::class),
            ],
            'urls' => [
                'index' => route('admin.stations.index'),
                'store' => route('admin.stations.store'),
                'storageLocations' => route('admin.storage-locations.index'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', User::class);

        return $this->page($request, ['mode' => 'create', 'station' => null]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        Station::create($this->valid($request));
        return redirect()->route('admin.stations.index')->with('success', 'تم');
    }

    public function edit(Request $request, Station $station)
    {
        $this->authorize('update', User::class);

        return $this->page($request, [
            'mode' => 'edit',
            'station' => [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->name,
                'color' => $station->color ?: '#166534',
                'icon' => $station->icon,
                'storageLocationId' => $station->storage_location_id,
                'displayOrder' => (int) $station->display_order,
                'active' => (bool) $station->active,
                'updateUrl' => route('admin.stations.update', $station),
            ],
        ]);
    }

    public function update(Request $request, Station $station)
    {
        $this->authorize('update', User::class);
        $station->update($this->valid($request, $station));
        return redirect()->route('admin.stations.index')->with('success', 'تم');
    }

    public function destroy(Station $station)
    {
        $this->authorize('delete', User::class);

        if ($station->users()->exists() || $station->menuItems()->exists() || $station->orderItems()->exists()) {
            return back()->with('error', 'المحطة مستخدمة في أصناف أو موظفين أو طلبات سابقة. عطّلها بدلاً من حذفها.');
        }

        $station->delete();
        return back()->with('success', 'تم');
    }

    protected function valid(Request $request, ?Station $station = null): array
    {
        $branchId = $station?->branch_id
            ?? BranchContext::current()
            ?? auth()->user()?->currentBranch()?->id;

        abort_unless($branchId, 422, 'Select an active branch before managing stations.');

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('stations', 'code')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($station?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:64'],
            'storage_location_id' => [
                'nullable',
                Rule::exists('storage_locations', 'id')
                    ->where(fn ($query) => $query->where('branch_id', $branchId)),
            ],
            'display_order' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $data['branch_id'] = $branchId;

        return $data;
    }

    protected function formData(): array
    {
        return [
            'storageLocations' => StorageLocation::where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ];
    }
}
