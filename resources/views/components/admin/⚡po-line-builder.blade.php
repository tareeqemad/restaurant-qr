<?php

use App\Models\Ingredient;
use App\Models\IngredientSupplierPrice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseOrderService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * PO Line Builder (Livewire seed + Alpine UI)
 *
 * Performance design:
 *   - All in-form interaction (typing qty/price, add/remove rows, computing
 *     subtotals + grand total) is Alpine. Zero round-trips while editing.
 *   - Server is called ONLY for two things:
 *      1. `lookupLastPrice($supplierId, $ingredientId)` — needs DB to read
 *         vendor price history. Triggered on ingredient pick.
 *      2. `save($payload)` — single round-trip on submit, validates +
 *         persists.
 *
 * The component class is much smaller than before because there's no
 * server-side state to track per-keystroke.
 */
new class extends Component
{
    public ?int $poId = null;

    public function mount(?PurchaseOrder $po = null): void
    {
        if ($po && $po->exists) {
            $this->poId = $po->id;
        }
    }

    /**
     * Suppliers for the dropdown — branch-scoped: a branch only sees the
     * suppliers it serves (linked via the branch_supplier pivot). Owner-
     * level users in "view all branches" mode (no BranchContext bound)
     * see every active supplier, since they can also create POs for any
     * branch.
     */
    #[Computed(persist: false)]
    public function suppliers()
    {
        $query = Supplier::where('active', true)->orderBy('name');

        $branchId = \App\Support\BranchContext::current();
        if ($branchId) {
            $query->servingBranch((int) $branchId);
        }

        return $query->get(['id', 'name'])->all();
    }

    /** Ingredients seed for the row dropdowns — id + name + base_unit_id only. */
    #[Computed(persist: false)]
    public function ingredientOptions(): array
    {
        return Ingredient::with('baseUnit')
            ->orderBy('name')
            ->get(['id', 'name', 'base_unit_id', 'cost_per_unit'])
            ->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->name . ' (' . ($i->baseUnit?->code ?? '') . ')',
                'base_unit_id' => $i->base_unit_id,
                'fallback_cost'=> (float) $i->cost_per_unit,
            ])
            ->all();
    }

    #[Computed(persist: false)]
    public function unitOptions(): array
    {
        return Unit::orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($u) => ['id' => $u->id, 'label' => "{$u->name} ({$u->code})"])
            ->all();
    }

    /** Edit-mode seed: existing lines as a flat array Alpine consumes. */
    #[Computed(persist: false)]
    public function existingLines(): array
    {
        if (! $this->poId) return [];

        return PurchaseOrder::findOrFail($this->poId)
            ->items()
            ->with('ingredient.baseUnit', 'unit')
            ->get()
            ->map(fn ($l) => [
                'ingredient_id'    => $l->ingredient_id,
                'unit_id'          => $l->unit_id,
                'quantity_ordered' => (float) $l->quantity_ordered,
                'unit_price'       => (float) $l->unit_price,
                'notes'            => (string) $l->notes,
            ])
            ->all();
    }

    /**
     * Vendor price lookup — called ONCE per ingredient pick, not per keystroke.
     * Returns the last unit_price_in_base for this supplier+ingredient, or
     * the global cost_per_unit as fallback.
     */
    public function lookupLastPrice(?int $supplierId, ?int $ingredientId): array
    {
        if (! $ingredientId) return ['unit_id' => null, 'price' => 0];

        $ing = Ingredient::with('baseUnit')->find($ingredientId);
        if (! $ing) return ['unit_id' => null, 'price' => 0];

        $price = null;
        if ($supplierId) {
            $price = IngredientSupplierPrice::query()
                ->where('ingredient_id', $ingredientId)
                ->where('supplier_id', $supplierId)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->value('unit_price_in_base');
        }
        return [
            'unit_id' => $ing->base_unit_id,
            'price'   => (float) ($price ?? $ing->cost_per_unit),
        ];
    }

    /** Single round-trip save. Alpine sends the whole payload. */
    public function save(array $payload)
    {
        $headerData = [
            'supplier_id' => (int) ($payload['supplier_id'] ?? 0),
            'expected_at' => $payload['expected_at'] ?? null,
            'notes'       => $payload['notes'] ?? null,
        ];

        $cleanLines = collect($payload['lines'] ?? [])
            ->filter(fn ($l) => ! empty($l['ingredient_id']) && (float) ($l['quantity_ordered'] ?? 0) > 0)
            ->map(fn ($l) => [
                'ingredient_id'    => (int) $l['ingredient_id'],
                'unit_id'          => (int) $l['unit_id'],
                'quantity_ordered' => (float) $l['quantity_ordered'],
                'unit_price'       => (float) $l['unit_price'],
                'notes'            => (string) ($l['notes'] ?? ''),
            ])
            ->values()
            ->toArray();

        if (! $headerData['supplier_id']) {
            $this->dispatch('flash', type: 'error', message: 'اختر المورد.');
            return;
        }
        if (empty($cleanLines)) {
            $this->dispatch('flash', type: 'error', message: 'أضف بنداً واحداً على الأقل.');
            return;
        }

        try {
            $svc = app(PurchaseOrderService::class);
            if ($this->poId) {
                $po = PurchaseOrder::findOrFail($this->poId);
                $this->authorize('update', $po);
                $po->update($headerData);
                $svc->updateLines($po, $cleanLines);
            } else {
                $this->authorize('create', PurchaseOrder::class);
                $po = $svc->create($headerData, $cleanLines, auth()->id());
            }

            session()->flash('success', $this->poId
                ? 'تم تحديث أمر الشراء.'
                : "تم إنشاء أمر الشراء {$po->number}.");
            return $this->redirect(route('admin.purchase-orders.show', $po), navigate: false);
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }
}
?>

@php
    $poNumber = $poId ? \App\Models\PurchaseOrder::find($poId)?->number : null;
    $existingPo = $poId ? \App\Models\PurchaseOrder::find($poId) : null;
@endphp

<div x-data='poLineBuilder({
        suppliers: @json($this->suppliers),
        ingredients: @json($this->ingredientOptions),
        units: @json($this->unitOptions),
        existing: @json($this->existingLines),
        initialSupplier: {{ (int) ($existingPo?->supplier_id ?? 0) }},
        initialExpected: @json(optional($existingPo?->expected_at)->format('Y-m-d')),
        initialNotes: @json($existingPo?->notes),
     })' x-init="init()">

    {{-- Header --}}
    <div class="card custom-card mb-3">
        <div class="card-header">
            <h3 class="card-title mb-0">
                <i class="bi bi-truck text-accent me-1"></i>
                معلومات أمر الشراء
            </h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">المورد <span class="text-danger">*</span></label>
                    <select x-model.number="supplier_id" class="form-select form-select-lg">
                        <option value="0">— اختر —</option>
                        <template x-for="s in suppliers" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">تاريخ التسليم المتوقع</label>
                    <input type="date" x-model="expected_at" class="form-control form-control-lg">
                </div>
                <div class="col-md-3">
                    <label class="form-label">رقم PO</label>
                    <input type="text"
                           value="{{ $poNumber ?? '— يُنشأ تلقائياً —' }}"
                           class="form-control form-control-lg" disabled>
                </div>
                <div class="col-12">
                    <label class="form-label">ملاحظات</label>
                    <textarea x-model="notes" class="form-control" rows="2"
                              placeholder="تعليمات التسليم، مرجع داخلي..."></textarea>
                </div>
            </div>
        </div>
    </div>

    {{-- Lines --}}
    <div class="card custom-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">
                <i class="bi bi-list-ul text-accent me-1"></i> البنود
                <span class="badge bg-primary-transparent ms-2" x-text="lines.length"></span>
            </h3>
            <button type="button" @click="addLine()" class="btn btn-sm btn-light">
                <i class="bi bi-plus-circle"></i> إضافة بند
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive po-lines-table-wrap">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th style="min-width:240px;">المكوّن</th>
                            <th style="width:120px;">الكمية</th>
                            <th style="width:140px;">الوحدة</th>
                            <th style="width:130px;">السعر / وحدة</th>
                            <th class="text-end" style="width:130px;">الإجمالي</th>
                            <th style="width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(line, idx) in lines" :key="idx">
                            <tr>
                                <td style="min-width: 220px;">
                                    {{-- Searchable ingredient combobox: type to filter, click
                                         to pick. Falls back to opening on focus so a click
                                         in the empty cell still shows the full list. --}}
                                    <div class="po-combo position-relative" @click.outside="line._open = false">
                                        <input type="text"
                                               class="form-control form-control-sm"
                                               :value="line._open ? (line._q ?? '') : ingredientLabel(line)"
                                               @focus="line._open = true; line._q = ''"
                                               @input="line._q = $event.target.value; line._open = true"
                                               @keydown.escape="line._open = false"
                                               placeholder="ابحث أو اختر مكوناً…">
                                        <div class="po-combo-list" x-show="line._open" x-cloak>
                                            <template x-for="ing in filteredIngredients(line._q ?? '')" :key="ing.id">
                                                <div class="po-combo-item"
                                                     :class="line.ingredient_id === ing.id ? 'is-active' : ''"
                                                     @click="pickIngredient(idx, ing)">
                                                    <span x-text="ing.name"></span>
                                                </div>
                                            </template>
                                            <div class="po-combo-empty"
                                                 x-show="filteredIngredients(line._q ?? '').length === 0"
                                                 x-cloak>
                                                لا نتائج
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0.0001"
                                           x-model.number="line.quantity_ordered"
                                           class="form-control form-control-sm text-end">
                                </td>
                                <td>
                                    <select x-model.number="line.unit_id" class="form-select form-select-sm">
                                        <option value="0">—</option>
                                        <template x-for="u in units" :key="u.id">
                                            <option :value="u.id" x-text="u.label"></option>
                                        </template>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.0001" min="0"
                                           x-model.number="line.unit_price"
                                           class="form-control form-control-sm text-end">
                                </td>
                                <td class="text-end fw-bold text-primary"
                                    x-text="lineSubtotal(line).toFixed(2) + ' ₪'"></td>
                                <td>
                                    <button type="button" @click="removeLine(idx)"
                                            class="btn btn-sm btn-outline-danger" title="حذف البند">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <td colspan="4" class="text-end fw-bold">الإجمالي الكلي</td>
                            <td class="text-end fw-bold fs-18 text-primary"
                                x-text="grandTotal.toFixed(2) + ' ₪'"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="{{ $poId
            ? route('admin.purchase-orders.show', $poId)
            : route('admin.purchase-orders.index') }}"
           class="btn btn-light">
            <i class="bi bi-arrow-right"></i> إلغاء
        </a>
        <button type="button"
                @click="submit()"
                :disabled="!canSubmit || saving"
                class="btn btn-primary">
            <i class="bi bi-save me-1"></i>
            <span x-text="saving ? 'جارٍ الحفظ...' : ('{{ $poId ? 'تحديث' : 'حفظ كمسودة' }}')"></span>
        </button>
    </div>
</div>

{{-- The function that the x-data above calls. We have to register it
     via Livewire's @script directive (which queues the block with
     Alpine and runs it before x-data evaluation) instead of a plain
     inline <script> — Livewire 4 strips inline-script siblings of the
     root element, leaving poLineBuilder undefined when Alpine first
     evaluated the x-data and the supplier / ingredient / unit
     dropdowns rendered empty. --}}
@script
<script>
window.poLineBuilder = function (config) {
    return {
        suppliers: config.suppliers,
        ingredients: config.ingredients,
        units: config.units,

        supplier_id: config.initialSupplier || 0,
        expected_at: config.initialExpected || '',
        notes: config.initialNotes || '',
        lines: [],
        saving: false,

        init() {
            this.lines = config.existing.length > 0
                ? config.existing.map(l => ({ ...l, _q: '', _open: false }))
                : [];
            if (this.lines.length === 0) this.addLine();

            // Edit mode: Alpine wires x-model on each <select> before its
            // <template x-for> renders the matching <option>, so the
            // browser's <select>.value defaults to its first option (the
            // "— اختر —" placeholder) and never picks up the seeded id.
            // Wait one tick for the templates to expand, then re-assign
            // the bound values — assignment triggers x-model to look up
            // the option that now exists and select it. Same trick is
            // needed for the row's ingredient_id and unit_id selects.
            this.$nextTick(() => {
                const sup = this.supplier_id;
                this.supplier_id = 0;
                this.supplier_id = sup;
                // Re-spread to retrigger select reactivity, but keep the
                // combobox state (_q/_open) defaulted so the input shows
                // the picked ingredient's name on first render.
                this.lines = this.lines.map(l => ({ ...l, _q: '', _open: false }));
            });
        },

        addLine() {
            this.lines.push({
                ingredient_id: 0,
                unit_id: 0,
                quantity_ordered: 1,
                unit_price: 0,
                notes: '',
                _q: '',
                _open: false,
            });
        },

        // ─── Ingredient combobox helpers ─────────────────────────────────
        ingredientLabel(line) {
            const ing = this.ingredients.find(i => i.id === line.ingredient_id);
            return ing ? ing.name : '';
        },
        filteredIngredients(query) {
            const q = (query || '').trim().toLowerCase();
            if (! q) return this.ingredients;
            return this.ingredients.filter(i => (i.name || '').toLowerCase().includes(q));
        },
        pickIngredient(idx, ing) {
            const line = this.lines[idx];
            line.ingredient_id = ing.id;
            line._q = '';
            line._open = false;
            this.onIngredientChange(idx);
        },

        removeLine(idx) {
            this.lines.splice(idx, 1);
            if (this.lines.length === 0) this.addLine();
        },

        // Pure display
        lineSubtotal(line) {
            return (Number(line.quantity_ordered) || 0) * (Number(line.unit_price) || 0);
        },
        get grandTotal() {
            return this.lines.reduce((s, l) => s + this.lineSubtotal(l), 0);
        },
        get canSubmit() {
            if (! this.supplier_id) return false;
            return this.lines.some(l => l.ingredient_id && l.unit_id && (Number(l.quantity_ordered) || 0) > 0);
        },

        // Server-side lookup (last vendor price). Single tiny request,
        // only when ingredient changes.
        async onIngredientChange(idx) {
            const line = this.lines[idx];
            if (! line.ingredient_id) return;

            // Optimistic local fallback first
            const ing = this.ingredients.find(i => i.id === line.ingredient_id);
            if (ing) {
                line.unit_id = ing.base_unit_id;
                if (! line.unit_price) line.unit_price = ing.fallback_cost;
            }

            // Then ask server for the supplier-specific last price
            try {
                const res = await this.$wire.lookupLastPrice(this.supplier_id || null, line.ingredient_id);
                if (res?.unit_id) line.unit_id = res.unit_id;
                if (typeof res?.price === 'number') line.unit_price = res.price;
            } catch (e) { /* keep optimistic value on network failure */ }
        },

        // Single round-trip save
        async submit() {
            if (! this.canSubmit) return;
            this.saving = true;
            try {
                await this.$wire.save({
                    supplier_id: this.supplier_id,
                    expected_at: this.expected_at,
                    notes: this.notes,
                    lines: this.lines,
                });
            } finally {
                this.saving = false;
            }
        },
    };
};
</script>
@endscript

@once
<style>
.po-combo-list {
    position: absolute;
    inset-inline-start: 0;
    inset-inline-end: 0;
    top: auto;
    bottom: calc(100% + 4px);
    z-index: 1100;
    max-height: 180px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid var(--border, #dfe7df);
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 71, 49, .12);
}
@media (min-width: 992px) {
    .po-lines-table-wrap {
        overflow: visible;
    }
    .po-lines-table-wrap table,
    .po-lines-table-wrap tbody,
    .po-lines-table-wrap tr,
    .po-lines-table-wrap td {
        overflow: visible;
    }
}
@media (max-width: 991.98px) {
    .po-lines-table-wrap {
        padding-bottom: 9rem;
    }
    .po-combo-list {
        top: calc(100% + 4px);
        bottom: auto;
        max-height: 190px;
    }
}
.po-combo-item {
    padding: .45rem .75rem;
    cursor: pointer;
    font-size: .88rem;
    line-height: 1.4;
    border-bottom: 1px solid rgba(15, 71, 49, .04);
}
.po-combo-item:last-child { border-bottom: 0; }
.po-combo-item:hover { background: rgba(15, 71, 49, .06); color: var(--primary); }
.po-combo-item.is-active { background: rgba(15, 71, 49, .1); color: var(--primary); font-weight: 700; }
.po-combo-empty { padding: .85rem .75rem; color: #888; font-size: .85rem; text-align: center; }
</style>
@endonce
