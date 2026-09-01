<?php

namespace App\Http\Controllers\Admin;

use App\Exports\StockCountXlsx;
use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StorageLocation;
use App\Services\StockCountService;
use App\Helpers\Money;
use App\Support\AdminShell;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function __construct(protected StockCountService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockCount::class);

        $q = StockCount::query()->with(['creator', 'finalizer', 'storageLocation'])->withCount('items')->latest('count_date');
        if ($s = $request->get('status')) $q->where('status', $s);

        $counts = $q->paginate(20)->withQueryString();

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

        $user = $request->user();
        $counts->through(fn (StockCount $count) => [
            'id' => $count->id,
            'number' => $count->number,
            'date' => $count->count_date?->toDateString(),
            'location' => $count->storageLocation?->name ?? 'إجمالي الفرع',
            'itemsCount' => (int) $count->items_count,
            'status' => $count->status,
            'statusLabel' => $count->statusLabel(),
            'statusColor' => $count->statusColor(),
            'creator' => $count->creator?->name ?? '—',
            'finalizer' => $count->finalizer?->name,
            'finalizedAgo' => $count->finalized_at?->diffForHumans(),
            'editable' => $count->isEditable(),
            'url' => route('admin.stock-counts.show', $count),
        ]);

        return AdminShell::render('Admin/StockCounts/Index', [
            'counts' => $counts,
            'stats' => [
                'draftCount' => $stats['draft_count'],
                'monthCount' => $stats['month_count'],
                'lastFinalized' => $stats['last_finalized']
                    ? \Illuminate\Support\Carbon::parse($stats['last_finalized'])->diffForHumans() : '—',
                'monthVariance' => Money::format($stats['month_variance']),
                'monthVarianceRaw' => $stats['month_variance'],
            ],
            'filters' => ['status' => (string) $request->get('status', '')],
            'can' => ['create' => (bool) $user?->can('create', StockCount::class)],
            'urls' => [
                'index' => route('admin.stock-counts.index'),
                'create' => route('admin.stock-counts.create'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', StockCount::class);
        $ingredientCount = Ingredient::where('track_stock', true)->count();
        $storageLocations = StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return AdminShell::render('Admin/StockCounts/Create', [
            'ingredientCount' => $ingredientCount,
            'locations' => $storageLocations->map(fn (StorageLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'default' => (bool) $location->is_default,
            ])->values(),
            'defaults' => ['date' => today()->toDateString()],
            'urls' => [
                'index' => route('admin.stock-counts.index'),
                'store' => route('admin.stock-counts.store'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockCount::class);
        $data = $request->validate([
            'count_date'      => ['nullable', 'date'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'ingredient_ids'  => ['nullable', 'array'],
            'ingredient_ids.*'=> ['exists:ingredients,id'],
        ]);

        $count = $this->service->create($data, auth()->id());
        return redirect()->route('admin.stock-counts.show', $count)
            ->with('success', "تم إنشاء جرد {$count->number} — ادخل الكميات الفعلية للمكونات.");
    }

    /**
     * The counting workspace — Inertia/Vue since Wave 4.
     *
     * Rows ship in stock_count_items.id order (the order create() snapshot
     * them in, which is the order of the operator's printed list — NOT
     * alphabetical). `counted_qty: null` means "not counted yet" and is
     * emphatically different from 0 ("we have none left"); the whole
     * screen preserves that distinction end to end.
     */
    public function show(StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);
        $stockCount->load(['items.ingredient.baseUnit', 'creator', 'finalizer', 'storageLocation']);

        $items = $stockCount->items;
        $counted = $items->whereNotNull('counted_qty');

        return \App\Support\AdminShell::render('Admin/StockCounts/Show', [
            'count' => [
                'id' => $stockCount->id,
                'number' => $stockCount->number,
                'countDate' => $stockCount->count_date?->toDateString(),
                'locationName' => $stockCount->storageLocation?->name ?? 'إجمالي الفرع',
                'status' => $stockCount->status,
                'statusLabel' => match ($stockCount->status) {
                    'draft' => 'مسودة — قيد العدّ',
                    'finalized' => 'مُعتمد — تم تطبيق التسويات',
                    default => 'ملغي',
                },
                'notes' => $stockCount->notes,
                'creatorName' => $stockCount->creator?->name,
                'createdAgo' => $stockCount->created_at?->diffForHumans(),
                'finalizerName' => $stockCount->finalizer?->name,
                'finalizedAt' => $stockCount->finalized_at?->format('Y-m-d H:i'),
                'editable' => $stockCount->isEditable(),
            ],
            'rows' => $items->map(fn ($it) => [
                // The save key is the LINE id — saveCounts only touches ids
                // belonging to this count, which is what stops a crafted
                // payload from writing another count's lines.
                'id' => $it->id,
                'name' => $it->ingredient?->name ?? '—',
                'unit' => $it->ingredient?->baseUnit?->code ?? '',
                'costPerUnit' => (float) ($it->ingredient?->cost_per_unit ?? 0),
                'systemQty' => (float) $it->system_qty,
                'countedQty' => $it->counted_qty === null ? null : (float) $it->counted_qty,
                'notes' => (string) ($it->notes ?? ''),
            ])->values()->all(),
            'summary' => [
                'total' => $items->count(),
                'counted' => $counted->count(),
                'matches' => $counted->filter(fn ($i) => abs((float) $i->variance) < 0.0001)->count(),
                'shortages' => $counted->filter(fn ($i) => (float) $i->variance < -0.0001)->count(),
                'overages' => $counted->filter(fn ($i) => (float) $i->variance > 0.0001)->count(),
                'netCost' => (float) $items->sum('variance_cost'),
            ],
            'can' => [
                'update' => auth()->user()->can('update', $stockCount),
                'finalize' => auth()->user()->can('finalize', $stockCount),
                'cancel' => auth()->user()->can('cancel', $stockCount),
            ],
            'currency' => config('restaurant.currency_symbol', '₪'),
            'urls' => [
                'save' => route('admin.stock-counts.save-counts', $stockCount),
                'finalize' => route('admin.stock-counts.finalize', $stockCount),
                'cancel' => route('admin.stock-counts.cancel', $stockCount),
                'export' => route('admin.stock-counts.export.xlsx', $stockCount),
                'index' => route('admin.stock-counts.index'),
            ],
        ]);
    }

    /**
     * Stream a multi-sheet xlsx of this count: ملخص + كل البنود + الفروقات فقط.
     * Available in any state — draft (work-in-progress snapshot), finalized
     * (permanent record of adjustments), or cancelled (audit of abandoned work).
     */
    public function export(StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);
        return (new StockCountXlsx())->download($stockCount);
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

            // The workspace autosaves row-by-row: answer JSON so the table
            // keeps its in-progress state instead of being blown away by a
            // redirect. Every caller uses this validated write path, so a
            // negative counted_qty cannot reach the ledger.
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => true]);
            }

            return back()->with('success', 'تم حفظ الكميات — لم يتم تطبيق التعديلات بعد.');
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

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
