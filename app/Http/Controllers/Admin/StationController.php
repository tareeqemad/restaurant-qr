<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', User::class);
        $stations = Station::with('storageLocation')->orderBy('display_order')->get();
        return view('admin.stations.index', compact('stations'));
    }

    public function create()
    {
        $this->authorize('create', User::class);
        return view('admin.stations.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        Station::create($this->valid($request));
        return redirect()->route('admin.stations.index')->with('success', 'تم');
    }

    public function edit(Station $station)
    {
        $this->authorize('update', User::class);
        return view('admin.stations.edit', array_merge($this->formData(), compact('station')));
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
            'name_en' => ['nullable', 'string', 'max:255'],
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
