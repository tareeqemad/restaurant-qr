<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Ingredient;
use App\Models\StorageLocation;
use App\Services\BranchTransferService;
use App\Support\BranchContext;
use Illuminate\Http\Request;

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
     * List view — shows transfers visible to the user's current branch
     * context (either as sender or receiver). Owner-level sees all.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);

        $user = $request->user();
        $query = BranchTransfer::with(['fromBranch', 'toBranch', 'creator'])
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

        return view('admin.branch-transfers.index', compact('transfers', 'stats'));
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
        return view('admin.branch-transfers.show', ['transfer' => $branchTransfer]);
    }

    public function create()
    {
        $this->authorize('viewAny', \App\Models\Ingredient::class);
        $this->assertOwnerLevel();
        return view('admin.branch-transfers.create');
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
