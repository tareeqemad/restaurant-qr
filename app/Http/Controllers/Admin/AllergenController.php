<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AllergenController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', MenuItem::class);
        $allergens = Allergen::orderBy('display_order')->paginate(30);
        return view('admin.allergens.index', compact('allergens'));
    }

    public function create() { $this->authorize('create', MenuItem::class); return view('admin.allergens.create'); }
    public function store(Request $request) { $this->authorize('create', MenuItem::class); Allergen::create($this->valid($request)); return redirect()->route('admin.allergens.index')->with('success', 'تم'); }
    public function edit(Allergen $allergen) { $this->authorize('update', MenuItem::class); return view('admin.allergens.edit', compact('allergen')); }
    public function update(Request $request, Allergen $allergen) { $this->authorize('update', MenuItem::class); $allergen->update($this->valid($request, $allergen->id)); return redirect()->route('admin.allergens.index')->with('success', 'تم'); }
    public function destroy(Allergen $allergen) { $this->authorize('delete', MenuItem::class); $allergen->delete(); return back()->with('success', 'تم'); }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('allergens')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
        ]);
    }
}
