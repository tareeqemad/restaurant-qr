<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Ingredient::class);
        $units = Unit::orderBy('unit_type')->orderBy('factor_to_base')->paginate(30);
        return view('admin.units.index', compact('units'));
    }

    public function create()
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.units.create');
    }

    public function store(Request $request)
    {
        $this->authorize('manage', Ingredient::class);
        Unit::create($this->valid($request));
        return redirect()->route('admin.units.index')->with('success', 'تم');
    }

    public function edit(Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.units.edit', compact('unit'));
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        $unit->update($this->valid($request, $unit->id));
        return redirect()->route('admin.units.index')->with('success', 'تم');
    }

    public function destroy(Unit $unit)
    {
        $this->authorize('manage', Ingredient::class);
        $unit->delete();
        return back()->with('success', 'تم');
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:16', \Illuminate\Validation\Rule::unique('units')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'unit_type' => ['required', 'in:weight,volume,count,length'],
            'factor_to_base' => ['required', 'numeric', 'min:0.0000001'],
            'is_base' => ['sometimes', 'boolean'],
        ]);
    }
}
