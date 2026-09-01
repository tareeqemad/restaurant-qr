<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Qty;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferItem;
use App\Models\Ingredient;
use App\Models\Setting;
use App\Models\StorageLocation;
use App\Services\BranchTransferService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchTransferController extends Controller
{
    public function __construct(protected BranchTransferService $service) {}

    /**
     * Inter-branch transfers are an HQ-level operation: a single branch
     * shouldn't be able to pull stock from a peer branch on its own —
     * that needs corporate-level visibility into both inventories and
     * the business reason for the move. Branch-level admins still get
     * the read-only list (their incoming/outgoing) and can mark a
     * physical receipt; everything else (create, send, cancel) is
     * gated to owner-level (Super Admin / Partner).
     */
    protected function assertOwnerLevel(): void
    {
        abort_unless(
            auth()->user()?->isOwnerLevel(),
            403,
            'إنشاء وإدارة التحويلات بين الفروع متاحة لمسؤول النظام فقط.'
        );
    }

    /**
     * The `currency` prop the Vue pages consume as `currency.symbol`.
     * Resolved exactly the way App\Helpers\Money::format() resolves it
     * (DB setting first, config fallback) so a restaurant that changed
     * its symbol in Settings sees the same symbol here as everywhere else.
     */
    protected function currencyProp(): array
    {
        return [
            'symbol' => (string) Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
            'decimals' => 2,
        ];
    }

    /**
     * Cast an id-keyed lookup (and its id-keyed children) to stdClass so
     * json_encode always emits a JS object. A PHP array whose integer keys
     * happen to be 0..n-1 would otherwise serialize as a JSON array and the
     * client-side `map[id]` lookups would silently read the wrong slot.
     * The Blade this replaces did the same with an explicit `(object)` cast.
     */
    protected function idMap(array $map): object
    {
        return (object) array_map(
            fn ($value) => is_array($value) ? (object) $value : $value,
            $map
        );
    }

    /**
     * List view — shows transfers visible to the user's current branch
     * context (either as sender or receiver). Owner-level sees all.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);

        $user = $request->user();
        $query = BranchTransfer::with(['fromBranch', 'toBranch', 'creator'])
            ->withCount('items')
            ->latest('id');

        if (! $user->isOwnerLevel()) {
            $branchId = BranchContext::current() ?? optional($user->primaryBranch())->id;
            if ($branchId) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('from_branch_id', $branchId)
                      ->orWhere('to_branch_id', $branchId);
                });
            }
        }

        if ($s = $request->get('status')) {
            $query->where('status', $s);
        }
        if ($search = $request->get('search')) {
            $query->where('number', 'like', "%{$search}%");
        }

        $transfers = $query->paginate(20)->withQueryString();

        $stats = [
            'in_transit' => BranchTransfer::where('status', 'in_transit')->count(),
            'received_today' => BranchTransfer::where('status', 'received')
                ->whereDate('received_at', today())->count(),
            'draft' => BranchTransfer::where('status', 'draft')->count(),
            'this_month' => BranchTransfer::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)->count(),
        ];

        return AdminShell::render('Admin/BranchTransfers/Index', [
            'transfers' => [
                'data' => $transfers->getCollection()->map(fn (BranchTransfer $t) => [
                    'id' => (int) $t->id,
                    'number' => (string) $t->number,
                    'fromBranchName' => $t->fromBranch?->name,
                    'toBranchName' => $t->toBranch?->name,
                    'itemsCount' => (int) $t->items_count,
                    'status' => (string) $t->status,
                    'statusLabel' => $t->statusLabel(),
                    'statusColor' => $t->statusColor(),
                    'createdAt' => $t->created_at?->format('Y-m-d H:i'),
                    'sentAgo' => $t->sent_at?->diffForHumans(),
                    'url' => route('admin.branch-transfers.show', $t),
                ])->all(),
                'links' => $transfers->linkCollection()->toArray(),
                'total' => $transfers->total(),
            ],
            'stats' => $stats,
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'status' => (string) $request->get('status', ''),
            ],
            'can' => [
                'create' => (bool) $user?->isOwnerLevel(),
            ],
            'urls' => [
                'index' => route('admin.branch-transfers.index'),
                'create' => route('admin.branch-transfers.create'),
            ],
        ]);
    }

    public function show(BranchTransfer $branchTransfer)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);

        // Branch ownership: a non-owner can only view a transfer if their
        // branch is one of the two legs (source OR destination). Without
        // this, a manager in branch A could direct-URL-visit any transfer
        // ID and see another branch's stock movements.
        $user = auth()->user();
        if (! $user->isOwnerLevel()) {
            $myBranches = $user->accessibleBranchIds();
            $involved = in_array((int) $branchTransfer->from_branch_id, $myBranches, true)
                     || in_array((int) $branchTransfer->to_branch_id, $myBranches, true);
            abort_unless($involved, 404);
        }

        $branchTransfer->load([
            'items.ingredient.baseUnit',
            'items.fromLocation', 'items.toLocation',
            'fromBranch', 'toBranch',
            'creator', 'sender', 'receiver',
        ]);

        $isOwner = (bool) $user?->isOwnerLevel();
        $isOpen = $branchTransfer->isDraft() || $branchTransfer->isInTransit();

        return AdminShell::render('Admin/BranchTransfers/Show', [
            'transfer' => [
                'id' => (int) $branchTransfer->id,
                'number' => (string) $branchTransfer->number,
                'status' => (string) $branchTransfer->status,
                'statusLabel' => $branchTransfer->statusLabel(),
                'statusColor' => $branchTransfer->statusColor(),
                'fromBranchName' => $branchTransfer->fromBranch?->name,
                'toBranchName' => $branchTransfer->toBranch?->name,
                'creatorName' => $branchTransfer->creator?->name,
                'createdAt' => $branchTransfer->created_at?->format('Y-m-d H:i'),
                'senderName' => $branchTransfer->sender?->name,
                'sentAt' => $branchTransfer->sent_at?->format('Y-m-d H:i'),
                'receiverName' => $branchTransfer->receiver?->name,
                'receivedAt' => $branchTransfer->received_at?->format('Y-m-d H:i'),
                'notes' => $branchTransfer->notes,
                'cancelReason' => $branchTransfer->cancel_reason,
                'isInTransit' => $branchTransfer->isInTransit(),
            ],
            'items' => $branchTransfer->items->map(fn (BranchTransferItem $item) => [
                'id' => (int) $item->id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => Qty::format($item->quantity_base),
                'unitCode' => $item->ingredient?->baseUnit?->code,
                'fromLocationName' => $item->fromLocation?->name,
                'toLocationName' => $item->toLocation?->name,
                'unitCost' => Qty::format($item->unit_cost),
                'lineCost' => number_format($item->lineCost(), 2),
            ])->values()->all(),
            'totalCost' => number_format($branchTransfer->items->sum(fn (BranchTransferItem $i) => $i->lineCost()), 2),
            'currency' => $this->currencyProp(),
            'can' => [
                'send' => $isOwner && $branchTransfer->isDraft(),
                'receive' => $isOwner && $branchTransfer->isInTransit(),
                'cancel' => $isOwner && $isOpen,
            ],
            // A branch-level user looking at a still-open transfer gets told
            // why there are no buttons instead of just seeing an empty rail.
            'readOnlyNotice' => ! $isOwner && $isOpen,
            'urls' => [
                'index' => route('admin.branch-transfers.index'),
                'send' => route('admin.branch-transfers.send', $branchTransfer),
                'receive' => route('admin.branch-transfers.receive', $branchTransfer),
                'cancel' => route('admin.branch-transfers.cancel', $branchTransfer),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();

        $branches = Branch::where('is_active', true)
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b) => [
                'id' => (int) $b->id,
                'name' => $b->name,
            ])->values()->all();

        $ingredients = Ingredient::with('baseUnit')
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $i) => [
                'id' => (int) $i->id,
                'name' => $i->name,
                'unit_code' => $i->baseUnit?->code ?? '',
                'global_threshold' => (float) ($i->reorder_threshold ?? 0),
                'global_cost' => (float) ($i->cost_per_unit ?? 0),
            ])->values()->all();

        // Locations grouped by branch — the picker filters dropdowns based on
        // the chosen from/to branches. Default location bubbles to the top so
        // the destination can be auto-pre-selected.
        $locationsByBranch = StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'branch_id', 'is_default'])
            ->groupBy('branch_id')
            ->map(fn ($group) => $group->map(fn (StorageLocation $l) => [
                'id' => (int) $l->id,
                'name' => $l->name,
                'is_default' => (bool) $l->is_default,
            ])->values()->all())
            ->toArray();

        // Per-location stock + thresholds. Aggregated to branch level for the
        // headline hint, but shipped per-location too so the available-qty
        // figure can narrow when the user picks a specific source location.
        $stockRows = DB::table('ingredient_stock')
            ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
            ->where('storage_locations.active', true)
            ->select(
                'ingredient_stock.ingredient_id',
                'ingredient_stock.storage_location_id',
                'storage_locations.branch_id',
                'ingredient_stock.quantity',
                'ingredient_stock.reorder_threshold',
            )
            ->get();

        $stockByBranch = [];    // branchId => ingId => qty
        $stockByLocation = [];  // locId    => ingId => qty
        $thrByBranchSum = [];   // branchId => ingId => sum of per-loc thresholds (>0 only)
        $thrByLocation = [];    // locId    => ingId => threshold (>0 only)
        foreach ($stockRows as $r) {
            $bid = (int) $r->branch_id;
            $lid = (int) $r->storage_location_id;
            $iid = (int) $r->ingredient_id;
            $qty = (float) $r->quantity;
            $thr = (float) ($r->reorder_threshold ?? 0);

            $stockByBranch[$bid][$iid] = ($stockByBranch[$bid][$iid] ?? 0) + $qty;
            $stockByLocation[$lid][$iid] = $qty;

            if ($thr > 0) {
                $thrByBranchSum[$bid][$iid] = ($thrByBranchSum[$bid][$iid] ?? 0) + $thr;
                $thrByLocation[$lid][$iid] = $thr;
            }
        }

        // Per-branch weighted-average cost from active batches (matches
        // Ingredient::costAtBranch).
        $costRows = DB::table('ingredient_batches')
            ->join('storage_locations', 'ingredient_batches.storage_location_id', '=', 'storage_locations.id')
            ->where('storage_locations.active', true)
            ->where('ingredient_batches.remaining_qty', '>', 0)
            ->groupBy('storage_locations.branch_id', 'ingredient_batches.ingredient_id')
            ->selectRaw('
                storage_locations.branch_id as branch_id,
                ingredient_batches.ingredient_id as ingredient_id,
                SUM(ingredient_batches.remaining_qty * ingredient_batches.unit_cost) as total_value,
                SUM(ingredient_batches.remaining_qty) as total_qty
            ')
            ->get();

        $costByBranch = []; // branchId => ingId => unit_cost
        foreach ($costRows as $r) {
            $tq = (float) $r->total_qty;
            if ($tq > 0) {
                $costByBranch[(int) $r->branch_id][(int) $r->ingredient_id] = (float) $r->total_value / $tq;
            }
        }

        return AdminShell::render('Admin/BranchTransfers/Create', [
            'branches' => $branches,
            'ingredients' => $ingredients,
            'locationsByBranch' => (object) $locationsByBranch,
            'hasAnyLocation' => ! empty($locationsByBranch),
            'stockByBranch' => $this->idMap($stockByBranch),
            'stockByLocation' => $this->idMap($stockByLocation),
            'thresholdByBranchSum' => $this->idMap($thrByBranchSum),
            'thresholdByLocation' => $this->idMap($thrByLocation),
            'costByBranch' => $this->idMap($costByBranch),
            'currency' => $this->currencyProp(),
            // Repopulate after a failed store() — `back()->withInput()` is the
            // error path, and a picker that drops ten typed lines on a 422 is
            // worse than no picker at all.
            'old' => $this->oldInput(),
            'urls' => [
                'index' => route('admin.branch-transfers.index'),
                'store' => route('admin.branch-transfers.store'),
                'storageLocationsIndex' => route('admin.storage-locations.index'),
                'storageLocationsCreate' => route('admin.storage-locations.create'),
            ],
        ]);
    }

    /** Flashed input from a failed store(), flattened to the picker's shape. */
    protected function oldInput(): array
    {
        $lines = old('lines');
        $lines = is_array($lines) ? $lines : [];

        return [
            'from_branch_id' => (int) old('from_branch_id', 0),
            'to_branch_id' => (int) old('to_branch_id', 0),
            'notes' => (string) old('notes', ''),
            'lines' => array_values(array_map(fn ($l) => [
                'ingredient_id' => (int) (is_array($l) ? ($l['ingredient_id'] ?? 0) : 0),
                'quantity_base' => (float) (is_array($l) ? ($l['quantity_base'] ?? 0) : 0),
                'from_location_id' => (int) (is_array($l) ? ($l['from_location_id'] ?? 0) : 0),
                'to_location_id' => (int) (is_array($l) ? ($l['to_location_id'] ?? 0) : 0),
                'notes' => (string) (is_array($l) ? ($l['notes'] ?? '') : ''),
            ], $lines)),
        ];
    }

    public function store(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();

        $data = $request->validate([
            'from_branch_id'        => ['required', 'exists:branches,id'],
            'to_branch_id'          => ['required', 'exists:branches,id', 'different:from_branch_id'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'exists:ingredients,id'],
            'lines.*.quantity_base' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.from_location_id' => ['nullable', 'exists:storage_locations,id'],
            'lines.*.to_location_id'   => ['nullable', 'exists:storage_locations,id'],
            'lines.*.notes'         => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $transfer = $this->service->create(
                header: [
                    'from_branch_id' => (int) $data['from_branch_id'],
                    'to_branch_id'   => (int) $data['to_branch_id'],
                    'notes'          => $data['notes'] ?? null,
                ],
                lines: $data['lines'],
                userId: auth()->id(),
            );
            return redirect()
                ->route('admin.branch-transfers.show', $transfer)
                ->with('success', "تم إنشاء التحويل {$transfer->number} كمسودة.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function send(BranchTransfer $branchTransfer)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();
        try {
            $this->service->send($branchTransfer, auth()->id());
            return back()->with('success', "تم إرسال التحويل — في الطريق إلى {$branchTransfer->toBranch->name}.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function receive(BranchTransfer $branchTransfer)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();
        try {
            $this->service->receive($branchTransfer, auth()->id());
            return back()->with('success', 'تم استلام التحويل وإضافة المخزون لفرعك.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, BranchTransfer $branchTransfer)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($branchTransfer, $data['reason'], auth()->id());
            return back()->with('success', 'تم إلغاء التحويل.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
