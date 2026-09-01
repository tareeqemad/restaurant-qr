<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use App\Models\MenuItem;
use App\Support\AdminShell;
use App\Support\MenuWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AllergenController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);

        return $this->page($request);
    }

    protected function page(Request $request, ?array $editor = null)
    {
        $allergens = Allergen::withCount('menuItems')
            ->orderBy('display_order')
            ->paginate(30)
            ->withQueryString();
        $user = auth()->user();
        $canUpdate = (bool) $user?->can('update', MenuItem::class);
        $canDelete = (bool) $user?->can('delete', MenuItem::class);

        $allergens->through(fn (Allergen $allergen) => [
            'id' => $allergen->id,
            'code' => $allergen->code,
            'name' => $allergen->name,
            'icon' => $allergen->icon,
            'description' => $allergen->description,
            'displayOrder' => (int) $allergen->display_order,
            'itemsCount' => (int) $allergen->menu_items_count,
            'can' => [
                'update' => $canUpdate,
                'delete' => $canDelete && $allergen->menu_items_count === 0,
            ],
            'urls' => [
                'update' => route('admin.allergens.update', $allergen),
                'destroy' => route('admin.allergens.destroy', $allergen),
            ],
        ]);

        return AdminShell::render('Admin/MenuCatalog/Allergens', [
            'navigation' => MenuWorkspace::navigation(),
            'allergens' => $allergens,
            'stats' => [
                'total' => Allergen::count(),
                'used' => Allergen::has('menuItems')->count(),
                'unused' => Allergen::doesntHave('menuItems')->count(),
            ],
            'editor' => $editor,
            'can' => [
                'create' => (bool) $user?->can('create', MenuItem::class),
            ],
            'urls' => [
                'index' => route('admin.allergens.index'),
                'store' => route('admin.allergens.store'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', MenuItem::class);

        return $this->page($request, ['mode' => 'create', 'allergen' => null]);
    }
    public function store(Request $request) { $this->authorize('create', MenuItem::class); Allergen::create($this->valid($request)); return redirect()->route('admin.allergens.index')->with('success', 'تم'); }
    public function edit(Request $request, Allergen $allergen)
    {
        $this->authorize('update', MenuItem::class);

        return $this->page($request, [
            'mode' => 'edit',
            'allergen' => [
                'id' => $allergen->id,
                'code' => $allergen->code,
                'name' => $allergen->name,
                'icon' => $allergen->icon,
                'description' => $allergen->description,
                'displayOrder' => (int) $allergen->display_order,
                'updateUrl' => route('admin.allergens.update', $allergen),
            ],
        ]);
    }
    public function update(Request $request, Allergen $allergen) { $this->authorize('update', MenuItem::class); $allergen->update($this->valid($request, $allergen->id)); return redirect()->route('admin.allergens.index')->with('success', 'تم'); }
    public function destroy(Allergen $allergen)
    {
        $this->authorize('delete', MenuItem::class);
        if ($allergen->menuItems()->exists()) {
            return back()->with('error', 'مسبب الحساسية مستخدم على أصناف. أزله من الأصناف أولاً.');
        }
        $allergen->delete();

        return back()->with('success', 'تم');
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('allergens')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
        ]);
    }
}
