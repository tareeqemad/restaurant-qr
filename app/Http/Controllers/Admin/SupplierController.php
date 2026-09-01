<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Helpers\Money;
use App\Helpers\Qty;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Supplier::class);

        // Branch-aware listing. Behavior depends on the active context:
        //   - Active branch picked (any role)     → filter to that branch
        //   - Owner-level + "all branches" picked → no filter (global view)
        //   - Branch user with no context         → fall back to their
        //     primary branch (defensive — should never happen in practice
        //     because SetActiveBranch middleware pins it on every request)
        $user = auth()->user();
        $branchId = \App\Support\BranchContext::current();
        if (! $branchId && $user && ! $user->isOwnerLevel()) {
            $branchId = optional($user->primaryBranch())->id;
        }

        $q = Supplier::query()->with('branches:id,name')->withCount('ingredients');
        if ($branchId) $q->servingBranch($branchId);

        if ($s = $request->get('search')) {
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%$s%")
                   ->orWhere('phone', 'like', "%$s%")
                   ->orWhere('contact_person', 'like', "%$s%");
            });
        }
        if ($request->filled('inactive')) $q->where('active', false);

        $suppliers = $q->orderBy('name')->paginate(20)->withQueryString();

        // Stats must honour the same branch scope as the list — otherwise a
        // branch user sees a global count in the rail that contradicts the
        // filtered table below it.
        $scoped = fn () => $branchId
            ? Supplier::query()->servingBranch($branchId)
            : Supplier::query();

        $stats = [
            'total'    => $scoped()->count(),
            'active'   => $scoped()->where('active', true)->count(),
            'inactive' => $scoped()->where('active', false)->count(),
            'linked'   => $scoped()->has('ingredients')->count(),
        ];

        $user = $request->user();
        $suppliers->through(fn (Supplier $supplier) => [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'contactPerson' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'active' => (bool) $supplier->active,
            'ingredientsCount' => (int) $supplier->ingredients_count,
            'branches' => $supplier->branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->localizedName(),
            ])->values(),
            'can' => [
                'update' => (bool) $user?->can('update', $supplier),
                'delete' => (bool) $user?->can('delete', $supplier),
            ],
            'urls' => [
                'show' => route('admin.suppliers.show', $supplier),
                'edit' => route('admin.suppliers.edit', $supplier),
                'destroy' => route('admin.suppliers.destroy', $supplier),
            ],
        ]);

        return AdminShell::render('Admin/Suppliers/Index', [
            'suppliers' => $suppliers,
            'stats' => $stats,
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'inactive' => $request->boolean('inactive'),
            ],
            'can' => ['create' => (bool) $user?->can('create', Supplier::class)],
            'urls' => [
                'index' => route('admin.suppliers.index'),
                'create' => route('admin.suppliers.create'),
            ],
        ]);
    }

    public function create()
    {
        $this->authorize('create', Supplier::class);
        $user = auth()->user();
        $defaultBranchId = \App\Support\BranchContext::current()
            ?? optional($user?->primaryBranch())->id;

        return AdminShell::render('Admin/Suppliers/Form', $this->formProps(
            null,
            $this->availableBranches(),
            $defaultBranchId ? [(int) $defaultBranchId] : [],
            $user?->isOwnerLevel()
                ? \App\Models\Branch::where('is_active', true)->pluck('id')->all()
                : ($user?->accessibleBranchIds() ?? []),
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Supplier::class);
        $data = $this->validated($request);
        $submitted = $this->validatedBranchIds($request);

        // Filter to branches the current user is actually allowed to assign
        // to. Without this guard, a branch user could POST `branch_ids[]=99`
        // (a branch they don't manage) and link the supplier to it anyway —
        // bypassing the same restriction enforced visually in the form.
        $allowedIds = $this->filterToAccessible($submitted);

        $supplier = Supplier::create($data);
        $supplier->branches()->sync($this->resolveBranchIds($allowedIds));

        return redirect()->route('admin.suppliers.index')->with('success', 'تم إضافة المورد "'.$supplier->name.'"');
    }

    public function edit(Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        $supplier->load('branches:id,name');

        $user = auth()->user();
        $accessibleIds = $user?->isOwnerLevel()
            ? \App\Models\Branch::where('is_active', true)->pluck('id')->all()
            : ($user?->accessibleBranchIds() ?? []);

        return AdminShell::render('Admin/Suppliers/Form', $this->formProps(
            $supplier,
            $this->visibleBranches($supplier),
            $supplier->branches->pluck('id')->all(),
            $accessibleIds,
        ));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->authorize('update', $supplier);
        $supplier->update($this->validated($request));

        $submitted = $this->validatedBranchIds($request);
        $allowedIds = $this->filterToAccessible($submitted);

        $user = auth()->user();
        if ($user?->isOwnerLevel()) {
            // Owner-level can reset the full list — sync replaces everything.
            $supplier->branches()->sync($this->resolveBranchIds($allowedIds));
        } else {
            // Branch-scoped users only manage links inside their own
            // branches. We diff against the existing pivot rows and only
            // attach/detach within their accessible set — preserves links
            // to branches the user can't see, instead of silently wiping
            // them on save.
            $accessibleIds = collect($user?->accessibleBranchIds() ?? []);
            $existingInScope = $supplier->branches()
                ->whereIn('branches.id', $accessibleIds)
                ->pluck('branches.id');
            $desiredInScope = collect($allowedIds)->intersect($accessibleIds);

            $toAttach = $desiredInScope->diff($existingInScope);
            $toDetach = $existingInScope->diff($desiredInScope);

            if ($toAttach->isNotEmpty()) $supplier->branches()->attach($toAttach->all());
            if ($toDetach->isNotEmpty()) $supplier->branches()->detach($toDetach->all());
        }

        return redirect()->route('admin.suppliers.index')->with('success', 'تم تحديث المورد');
    }

    /** Branches the current user is allowed to assign suppliers to. */
    protected function availableBranches()
    {
        $user = auth()->user();
        if (! $user) return collect();

        if ($user->isOwnerLevel()) {
            return \App\Models\Branch::where('is_active', true)
                ->orderBy('display_order')->orderBy('name')
                ->get(['id', 'name']);
        }
        return $user->branches()->where('is_active', true)
            ->orderBy('display_order')->orderBy('name')
            ->get(['branches.id', 'branches.name']);
    }

    /**
     * Branches to display in the edit form: the user's accessible ones,
     * plus any branches the supplier already serves (so a Gaza admin
     * editing a Khanyounis-linked supplier can see — but not change —
     * the existing Khanyounis link). The view marks the latter as
     * disabled so the checkbox is read-only.
     */
    protected function visibleBranches(Supplier $supplier)
    {
        $user = auth()->user();
        if (! $user) return collect();

        $accessibleIds = collect($user->isOwnerLevel()
            ? \App\Models\Branch::where('is_active', true)->pluck('id')->all()
            : $user->accessibleBranchIds());

        $supplierBranchIds = $supplier->branches->pluck('id');
        $unionIds = $accessibleIds->merge($supplierBranchIds)->unique();

        return \App\Models\Branch::whereIn('id', $unionIds)
            ->where('is_active', true)
            ->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function validatedBranchIds(Request $request): array
    {
        return $request->validate([
            'branch_ids'   => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ], [
            'branch_ids.required' => 'يجب ربط المورد بفرع واحد على الأقل.',
            'branch_ids.min'      => 'يجب ربط المورد بفرع واحد على الأقل.',
        ])['branch_ids'];
    }

    /**
     * Drop every submitted branch id the current user doesn't have
     * access to. Defence-in-depth — the form already hides them, but
     * a hand-crafted POST shouldn't be able to bypass that.
     */
    protected function filterToAccessible(array $ids): array
    {
        $user = auth()->user();
        if (! $user) return [];
        if ($user->isOwnerLevel()) return $ids;
        $accessible = collect($user->accessibleBranchIds());
        return collect($ids)->intersect($accessible)->values()->all();
    }

    /**
     * If empty (no branches selected), default to attaching the current
     * branch — keeps the supplier private to where it was created instead
     * of leaking globally. Owner-level edit lets them clear/expand.
     */
    protected function resolveBranchIds(array $ids): array
    {
        if (! empty($ids)) return $ids;
        $current = \App\Support\BranchContext::current();
        return $current ? [$current] : [];
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
        $supplier->load(['ingredients.baseUnit', 'branches:id,name']);

        $user = auth()->user();
        $branchId = \App\Support\BranchContext::current();
        if (! $branchId && $user && ! $user->isOwnerLevel()) {
            $branchId = optional($user->primaryBranch())->id;
        }
        if ($branchId && ! $supplier->branches->contains('id', (int) $branchId)) {
            abort(404);
        }

        // Stock value is the per-location truth (sum of ingredient_stock ×
        // per-branch cost) rather than the legacy current_stock × global
        // cost_per_unit, which can drift on top of older seed data.
        $totals = [
            'ingredient_count' => $supplier->ingredients->count(),
            'low_stock'        => $supplier->ingredients->filter(function ($ingredient) use ($branchId) {
                $stock = $branchId
                    ? $ingredient->usableStockAtBranch((int) $branchId)
                    : $ingredient->trackedUsableStock();
                $threshold = $branchId
                    ? $ingredient->reorderThresholdAtBranch((int) $branchId)
                    : (float) $ingredient->reorder_threshold;

                return $ingredient->track_stock && $threshold > 0 && $stock <= $threshold;
            })->count(),
            'stock_value'      => $supplier->ingredients->sum(fn($ingredient) => $branchId
                ? $ingredient->valueAtBranch((int) $branchId)
                : $ingredient->trackedValue()),
        ];

        $days = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

        return AdminShell::render('Admin/Suppliers/Show', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'contactPerson' => $supplier->contact_person,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'notes' => $supplier->notes,
                'active' => (bool) $supplier->active,
                'leadTimeDays' => $supplier->lead_time_days,
                'paymentTermsDays' => $supplier->payment_terms_days,
                'minimumOrderAmount' => $supplier->minimum_order_amount !== null
                    ? Money::format($supplier->minimum_order_amount) : null,
                'deliveryDays' => collect($supplier->delivery_days ?? [])
                    ->map(fn ($day) => $days[(int) $day] ?? null)->filter()->values(),
                'branches' => $supplier->branches->map(fn ($branch) => [
                    'id' => (int) $branch->id,
                    'name' => $branch->localizedName(),
                ])->values(),
            ],
            'totals' => [
                'ingredientCount' => $totals['ingredient_count'],
                'lowStock' => $totals['low_stock'],
                'stockValue' => Money::format($totals['stock_value']),
            ],
            'ingredients' => $supplier->ingredients->map(function ($ingredient) use ($branchId) {
                $stock = $branchId
                    ? $ingredient->usableStockAtBranch((int) $branchId)
                    : $ingredient->trackedUsableStock();
                $threshold = $branchId
                    ? $ingredient->reorderThresholdAtBranch((int) $branchId)
                    : (float) $ingredient->reorder_threshold;
                $value = $branchId
                    ? $ingredient->valueAtBranch((int) $branchId)
                    : $ingredient->trackedValue();
                $cost = $branchId
                    ? $ingredient->costAtBranch((int) $branchId)
                    : ($stock > 0 ? $value / $stock : (float) $ingredient->cost_per_unit);

                return [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'sku' => $ingredient->sku,
                    'lowStock' => $ingredient->track_stock && $threshold > 0 && $stock <= $threshold,
                    'stock' => Qty::format($stock).' '.($ingredient->baseUnit?->code ?? ''),
                    'reorder' => Qty::format($threshold).' '.($ingredient->baseUnit?->code ?? ''),
                    'unitCost' => Money::format($cost),
                    'stockValue' => Money::format($value),
                    'url' => route('admin.ingredients.edit', $ingredient),
                ];
            })->values(),
            'scopeLabel' => $branchId
                ? \App\Models\Branch::find($branchId)?->localizedName()
                : 'كل الفروع',
            'can' => ['update' => (bool) $user?->can('update', $supplier)],
            'urls' => [
                'index' => route('admin.suppliers.index'),
                'edit' => route('admin.suppliers.edit', $supplier),
                'prices' => route('admin.vendor-prices.supplier', $supplier),
                'compare' => route('admin.vendor-prices.compare'),
                'ingredients' => route('admin.ingredients.index'),
                'purchaseOrders' => route('admin.purchase-orders.index', ['supplier_id' => $supplier->id]),
                'newPurchaseOrder' => route('admin.purchase-orders.create'),
                'invoices' => route('admin.supplier-invoices.index', ['supplier_id' => $supplier->id]),
            ],
        ]);
    }

    protected function formProps(?Supplier $supplier, $branches, array $selected, array $accessible): array
    {
        $editable = collect($accessible)->map(fn ($id) => (int) $id);

        return [
            'supplier' => [
                'id' => $supplier?->id,
                'name' => $supplier?->name ?? '',
                'contactPerson' => $supplier?->contact_person ?? '',
                'phone' => $supplier?->phone ?? '',
                'email' => $supplier?->email ?? '',
                'address' => $supplier?->address ?? '',
                'notes' => $supplier?->notes ?? '',
                'active' => $supplier ? (bool) $supplier->active : true,
                'leadTimeDays' => $supplier?->lead_time_days,
                'paymentTermsDays' => $supplier?->payment_terms_days,
                'minimumOrderAmount' => $supplier?->minimum_order_amount,
                'deliveryDays' => collect($supplier?->delivery_days ?? [])->map(fn ($day) => (int) $day)->values(),
                'branchIds' => collect($selected)->map(fn ($id) => (int) $id)->values(),
            ],
            'branches' => collect($branches)->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->localizedName(),
                'editable' => $editable->contains((int) $branch->id),
            ])->values(),
            'currency' => config('restaurant.currency_symbol', '₪'),
            'urls' => [
                'index' => route('admin.suppliers.index'),
                'submit' => $supplier
                    ? route('admin.suppliers.update', $supplier)
                    : route('admin.suppliers.store'),
            ],
        ];
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
            'lead_time_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms_days'   => ['nullable', 'integer', 'min:0', 'max:365'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_days'        => ['nullable', 'array'],
            'delivery_days.*'      => ['integer', 'between:0,6'],
        ]);
    }
}
