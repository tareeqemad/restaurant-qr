<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        $q = Supplier::query()->withCount('ingredients');
        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")
                   ->orWhere('phone', 'like', "%$s%")
                   ->orWhere('contact_person', 'like', "%$s%");
            });
        }
        if ($request->filled('inactive')) $q->where('active', false);

        $suppliers = $q->orderBy('name')->paginate(20)->withQueryString();

        $stats = [
            'total'    => Supplier::count(),
            'active'   => Supplier::where('active', true)->count(),
            'inactive' => Supplier::where('active', false)->count(),
            'linked'   => Supplier::has('ingredients')->count(),
        ];

        return view('admin.suppliers.index', compact('suppliers', 'stats'));
    }

    public function create()
    {
        $this->authorize('create', Supplier::class);
        return view('admin.suppliers.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Supplier::class);
        $data = $this->validated($request);
        $supplier = Supplier::create($data);
        return redirect()->route('admin.suppliers.index')->with('success', 'تم إضافة المورد "'.$supplier->name.'"');
    }

    public function edit(Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        $supplier->update($this->validated($request));
        return redirect()->route('admin.suppliers.index')->with('success', 'تم تحديث المورد');
    }

    public function destroy(Supplier $supplier)
    {
        $this->authorize('delete', $supplier);
        if ($supplier->ingredients()->exists()) {
            return back()->with('error', 'لا يمكن حذف المورد — مرتبط بمكونات. اجعله غير فعّال بدلاً.');
        }
        $supplier->delete();
        return redirect()->route('admin.suppliers.index')->with('success', 'تم حذف المورد');
    }

    public function show(Supplier $supplier)
    {
        $this->authorize('view', $supplier);
        $supplier->load(['ingredients.baseUnit']);

        $totals = [
            'ingredient_count' => $supplier->ingredients->count(),
            'low_stock'        => $supplier->ingredients->filter(fn($i) => $i->isLowStock())->count(),
            'stock_value'      => $supplier->ingredients->sum(fn($i) => (float)$i->current_stock * (float)$i->cost_per_unit),
        ];

        return view('admin.suppliers.show', compact('supplier', 'totals'));
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:1000'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'active'         => ['boolean'],
        ]);
    }
}
