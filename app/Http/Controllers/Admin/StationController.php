<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', User::class);
        $stations = Station::orderBy('display_order')->get();
        return view('admin.stations.index', compact('stations'));
    }

    public function create()
    {
        $this->authorize('create', User::class);
        return view('admin.stations.create');
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
        return view('admin.stations.edit', compact('station'));
    }

    public function update(Request $request, Station $station)
    {
        $this->authorize('update', User::class);
        $station->update($this->valid($request, $station->id));
        return redirect()->route('admin.stations.index')->with('success', 'تم');
    }

    public function destroy(Station $station)
    {
        $this->authorize('delete', User::class);
        $station->delete();
        return back()->with('success', 'تم');
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('stations')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:64'],
            'display_order' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
