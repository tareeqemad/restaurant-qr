<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchaseOrderService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $q = PurchaseOrder::with(['supplier', 'items'])
            ->latest();
        if ($s = $request->get('status'))      $q->where('status', $s);
        if ($sid = $request->get('supplier_id'))$q->where('supplier_id', $sid);
        if ($s = $request->get('search'))      $q->where('number', 'like', "%$s%");
        if ($d = $request->get('from'))        $q->whereDate('created_at', '>=', $d);
        if ($d = $request->get('to'))          $q->whereDate('created_at', '<=', $d);

        $pos = $q->paginate(20)->withQueryString();

        $stats = [
            'draft'     => PurchaseOrder::where('status', 'draft')->count(),
            'sent'      => PurchaseOrder::where('status', 'sent')->count(),
            'partial'   => PurchaseOrder::where('status', 'partially_received')->count(),
            'this_month'=> (float) PurchaseOrder::whereMonth('created_at', now()->month)
                                                ->whereYear('created_at', now()->year)
                                                ->whereNotIn('status', ['cancelled'])
                                                ->sum('total'),
        ];

        return view('admin.purchase-orders.index', [
            'pos'       => $pos,
            'stats'     => $stats,
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', PurchaseOrder::class);
        return view('admin.purchase-orders.create', $this->formData());
    }

    public function store(Request $request)
    {
        $this->authorize('create', PurchaseOrder::class);
        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);

        $po = $this->service->create($data, $lines, auth()->id());
        return redirect()
            ->route('admin.purchase-orders.show', $po)
            ->with('success', "تم إنشاء أمر الشراء {$po->number}");
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);
        $purchaseOrder->load(['supplier', 'items.ingredient.baseUnit', 'items.unit', 'creator', 'receiver']);
        return view('admin.purchase-orders.show', ['po' => $purchaseOrder]);
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);
        $purchaseOrder->load('items.ingredient', 'items.unit');
        return view('admin.purchase-orders.edit', array_merge($this->formData(), ['po' => $purchaseOrder]));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        $data  = $this->validateHeader($request);
        $lines = $this->validateLines($request);

        $purchaseOrder->update([
            'supplier_id' => $data['supplier_id'],
            'expected_at' => $data['expected_at'] ?? null,
            'notes'       => $data['notes'] ?? null,
        ]);
        $this->service->updateLines($purchaseOrder, $lines);

        return redirect()
            ->route('admin.purchase-orders.show', $purchaseOrder)
            ->with('success', 'تم تحديث أمر الشراء');
    }

    /** Transition draft → sent */
    public function send(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('send', $purchaseOrder);
        try {
            $this->service->send($purchaseOrder);
            return back()->with('success', "تم إرسال أمر الشراء {$purchaseOrder->number}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Open the Goods Receipt form */
    public function receiveForm(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('receive', $purchaseOrder);
        $purchaseOrder->load('items.ingredient.baseUnit', 'items.unit');
        return view('admin.purchase-orders.receive', ['po' => $purchaseOrder]);
    }

    /** Process goods receipt */
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('receive', $purchaseOrder);

        $data = $request->validate([
            'receipts'            => ['required', 'array'],
            'receipts.*'          => ['nullable', 'numeric', 'min:0'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'batch_numbers'       => ['nullable', 'array'],
            'batch_numbers.*'     => ['nullable', 'string', 'max:100'],
            'expiry_dates'        => ['nullable', 'array'],
            'expiry_dates.*'      => ['nullable', 'date'],
        ]);

        try {
            $meta = [];
            foreach ($data['receipts'] as $lineId => $qty) {
                if ((float) $qty <= 0) continue;

                $meta[(int) $lineId] = [
                    'storage_location_id' => $data['storage_location_id'] ?? null,
                    'batch_number' => $data['batch_numbers'][$lineId] ?? null,
                    'expiry_date' => $data['expiry_dates'][$lineId] ?? null,
                ];
            }

            $this->service->receive($purchaseOrder, $data['receipts'], auth()->id(), $meta);
            return redirect()
                ->route('admin.purchase-orders.show', $purchaseOrder)
                ->with('success', 'تم استلام البضاعة وتحديث المخزون والأسعار.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorize('cancel', $purchaseOrder);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($purchaseOrder, $data['reason'], auth()->id());
            return back()->with('success', 'تم إلغاء أمر الشراء');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('delete', $purchaseOrder);
        $purchaseOrder->delete();
        return redirect()->route('admin.purchase-orders.index')->with('success', 'تم حذف أمر الشراء');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    protected function formData(): array
    {
        $supQuery = Supplier::where('active', true);

        // Branch-aware: branch users see only suppliers serving their branch.
        // Owner-level users (Super Admin / Partner) see all.
        $user = auth()->user();
        if ($user && ! $user->isOwnerLevel()) {
            $branchId = \App\Support\BranchContext::current()
                ?? optional($user->primaryBranch())->id;
            if ($branchId) $supQuery->servingBranch($branchId);
        }

        return [
            'suppliers'   => $supQuery->orderBy('name')->get(),
            'ingredients' => Ingredient::with('baseUnit', 'supplier')->orderBy('name')->get(),
            'units'       => Unit::orderBy('name')->get(),
        ];
    }

    protected function validateHeader(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'expected_at' => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);
    }

    protected function validateLines(Request $request): array
    {
        $request->validate([
            'lines'                      => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id'      => ['required', 'exists:ingredients,id'],
            'lines.*.unit_id'            => ['required', 'exists:units,id'],
            'lines.*.quantity_ordered'   => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price'         => ['required', 'numeric', 'min:0'],
            'lines.*.notes'              => ['nullable', 'string', 'max:500'],
        ]);

        // Filter out blank lines (user may have deleted some client-side)
        return array_values(array_filter($request->input('lines', []), function ($l) {
            return !empty($l['ingredient_id']) && ((float) ($l['quantity_ordered'] ?? 0) > 0);
        }));
    }
}
