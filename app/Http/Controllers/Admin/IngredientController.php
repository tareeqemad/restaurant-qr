<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Ingredient::class);

        $branchId = \App\Support\BranchContext::current();

        $q = Ingredient::with('baseUnit', 'supplier');
        if ($s = $request->get('search')) $q->where('name', 'like', "%$s%");

        // Branch-aware low-stock filter: when a branch is active, filter by
        // per-branch stock (sum of ingredient_stock at that branch's locations)
        // vs per-branch threshold. Falls back to the global comparison when no
        // branch context (owner-level "all branches" view).
        if ($request->filled('low_stock')) {
            if ($branchId) {
                $lowIds = Ingredient::where('track_stock', true)
                    ->get()
                    ->filter(fn ($i) => $i->isLowStockAtBranch($branchId))
                    ->pluck('id');
                $q->whereIn('id', $lowIds);
            } else {
                $q->whereColumn('current_stock', '<=', 'reorder_threshold');
            }
        }

        $ingredients = $q->orderBy('name')->paginate(20)->withQueryString();

        // Stats are also branch-aware when in branch context.
        if ($branchId) {
            $tracked  = Ingredient::where('track_stock', true)->get();
            $lowCount = $tracked->filter(fn ($i) =>
                $i->isLowStockAtBranch($branchId)
                && $i->stockAtBranch($branchId) > 0
            )->count();
            $outCount = $tracked->filter(fn ($i) => $i->stockAtBranch($branchId) <= 0)->count();
            $healthy  = $tracked->count() - $lowCount - $outCount;
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => $lowCount,
                'out_stock' => $outCount,
                'healthy'   => max(0, $healthy),
            ];
        } else {
            $stats = [
                'total'     => Ingredient::count(),
                'low_stock' => Ingredient::whereColumn('current_stock', '<=', 'reorder_threshold')
                                         ->where('current_stock', '>', 0)->count(),
                'out_stock' => Ingredient::where('current_stock', '<=', 0)->count(),
                'healthy'   => Ingredient::whereColumn('current_stock', '>', 'reorder_threshold')->count(),
            ];
        }

        return view('admin.ingredients.index', compact('ingredients', 'stats'));
    }

    public function create()
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.ingredients.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorize('manage', Ingredient::class);
        Ingredient::create($this->valid($request));
        return redirect()->route('admin.ingredients.index')->with('success', 'تم الإنشاء');
    }

    public function edit(Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        return view('admin.ingredients.edit', array_merge($this->formData(), ['ingredient' => $ingredient]));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $ingredient->update($this->valid($request));
        return redirect()->route('admin.ingredients.index')->with('success', 'تم التحديث');
    }

    public function destroy(Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $ingredient->delete();
        return back()->with('success', 'تم الحذف');
    }

    public function adjust(Request $request, Ingredient $ingredient)
    {
        $this->authorize('manage', Ingredient::class);
        $data = $request->validate([
            'type' => ['required', 'in:in,out,waste,adjustment'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'unit_id' => ['required', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:255'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $qtyBase = \App\Helpers\UnitConverter::convert($data['quantity'], $data['unit_id'], $ingredient->base_unit_id);
        $this->inventory->recordMovement(
            ingredient: $ingredient,
            type: $data['type'],
            qtyBase: $qtyBase,
            unitCost: (float) ($data['unit_cost'] ?? $ingredient->cost_per_unit),
            reason: $data['reason'],
        );
        return back()->with('success', 'تم تسجيل الحركة');
    }

    protected function formData(): array
    {
        $supQuery = Supplier::where('active', true);
        $user = auth()->user();
        if ($user && ! $user->isOwnerLevel()) {
            $branchId = \App\Support\BranchContext::current()
                ?? optional($user->primaryBranch())->id;
            if ($branchId) $supQuery->servingBranch($branchId);
        }
        return [
            'units' => Unit::all(),
            'suppliers' => $supQuery->orderBy('name')->get(),
        ];
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'sku' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'base_unit_id' => ['required', 'exists:units,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'current_stock' => ['required', 'numeric'],
            'reorder_threshold' => ['required', 'numeric'],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
            'track_stock' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
