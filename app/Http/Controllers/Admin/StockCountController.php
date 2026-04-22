<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Services\StockCountService;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function __construct(protected StockCountService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockCount::class);

        $q = StockCount::query()->with(['creator', 'finalizer'])->withCount('items')->latest('count_date');
        if ($s = $request->get('status')) $q->where('status', $s);

        $counts = $q->paginate(20);

        $stats = [
            'draft_count' => StockCount::where('status', 'draft')->count(),
            'month_count' => StockCount::whereMonth('count_date', now()->month)->whereYear('count_date', now()->year)->count(),
            'last_finalized' => StockCount::where('status', 'finalized')->latest('finalized_at')->value('finalized_at'),
            'month_variance' => (float) StockCount::where('status', 'finalized')
                ->whereMonth('count_date', now()->month)
                ->whereYear('count_date', now()->year)
                ->withSum('items', 'variance_cost')
                ->get()
                ->sum('items_sum_variance_cost'),
        ];

        return view('admin.stock-counts.index', compact('counts', 'stats'));
    }

    public function create()
    {
        $this->authorize('create', StockCount::class);
        $ingredientCount = Ingredient::where('track_stock', true)->count();
        return view('admin.stock-counts.create', compact('ingredientCount'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockCount::class);
        $data = $request->validate([
            'count_date'      => ['nullable', 'date'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'ingredient_ids'  => ['nullable', 'array'],
            'ingredient_ids.*'=> ['exists:ingredients,id'],
        ]);

        $count = $this->service->create($data, auth()->id());
        return redirect()->route('admin.stock-counts.show', $count)
            ->with('success', "تم إنشاء جرد {$count->number} — ادخل الكميات الفعلية للمكونات.");
    }

    public function show(StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);
        $stockCount->load(['items.ingredient.baseUnit', 'creator', 'finalizer']);
        return view('admin.stock-counts.show', ['count' => $stockCount]);
    }

    /** Save the entered quantities (without finalizing) */
    public function saveCounts(Request $request, StockCount $stockCount)
    {
        $this->authorize('update', $stockCount);
        $data = $request->validate([
            'counts'   => ['nullable', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0'],
            'notes'    => ['nullable', 'array'],
            'notes.*'  => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->saveCounts($stockCount, $data['counts'] ?? [], $data['notes'] ?? []);
            return back()->with('success', 'تم حفظ الكميات — لم يتم تطبيق التعديلات بعد.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Finalize — apply variance adjustments to stock */
    public function finalize(Request $request, StockCount $stockCount)
    {
        $this->authorize('finalize', $stockCount);
        try {
            $created = $this->service->finalize($stockCount, auth()->id());
            return redirect()->route('admin.stock-counts.show', $stockCount)
                ->with('success', "تم اعتماد الجرد. تم إنشاء {$created} حركة تعديل مخزون.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, StockCount $stockCount)
    {
        $this->authorize('cancel', $stockCount);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($stockCount, auth()->id(), $data['reason']);
            return back()->with('success', 'تم إلغاء الجرد.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(StockCount $stockCount)
    {
        $this->authorize('delete', $stockCount);
        $stockCount->delete();
        return redirect()->route('admin.stock-counts.index')->with('success', 'تم حذف الجرد.');
    }
}
