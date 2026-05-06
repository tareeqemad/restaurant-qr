<?php

use App\Enums\WasteReason;
use App\Helpers\UnitConverter;
use App\Models\ActivityLog;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Waste Logger (Livewire seed + Alpine reactive UI — fast variant)
 *
 * Performance design:
 *   - Server seeds the lists (ingredients, units, reasons) once at mount.
 *   - Alpine owns ALL interactive state: ingredient pick, qty typing,
 *     batch pick, cost preview math, validation flags. Everything except
 *     the per-ingredient batch lookup is instant.
 *   - One round-trip per ingredient pick: `loadBatches(ingId)` returns
 *     the FIFO list. Stays cached in Alpine until the user picks a
 *     different ingredient.
 *   - Submit is the only round-trip on commit.
 */
new class extends Component
{
    public function mount(?int $preselectedIngredientId = null): void
    {
        // The Alpine init reads the preselect from the prop on the wrapper div
    }

    #[Computed(persist: false)]
    public function ingredients(): array
    {
        return Ingredient::with('baseUnit:id,code')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'base_unit_id', 'cost_per_unit', 'current_stock'])
            ->map(fn ($i) => [
                'id'           => $i->id,
                'name'         => $i->name,
                'sku'          => $i->sku,
                'base_unit_id' => $i->base_unit_id,
                'cost_per_unit'=> (float) $i->cost_per_unit,
                'current_stock'=> (float) $i->current_stock,
                'unit_code'    => $i->baseUnit?->code ?? '',
            ])
            ->all();
    }

    #[Computed(persist: false)]
    public function units(): array
    {
        return Unit::orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($u) => ['id' => $u->id, 'label' => "{$u->name} ({$u->code})"])
            ->all();
    }

    #[Computed(persist: false)]
    public function reasonOptions(): array
    {
        return collect(WasteReason::cases())
            ->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])
            ->all();
    }

    #[Computed(persist: false)]
    public function storageLocations(): array
    {
        return StorageLocation::where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_default'])
            ->map(fn ($location) => [
                'id' => $location->id,
                'label' => trim($location->name . ($location->code ? " ({$location->code})" : '')),
                'is_default' => (bool) $location->is_default,
            ])
            ->all();
    }

    /** One round-trip per ingredient pick. Returns FIFO batches as JSON. */
    public function loadBatches(int $ingredientId, ?int $storageLocationId = null): array
    {
        return IngredientBatch::where('ingredient_id', $ingredientId)
            ->when($storageLocationId, fn ($query) => $query->where('storage_location_id', $storageLocationId))
            ->fifo()
            ->limit(20)
            ->get(['id', 'batch_number', 'remaining_qty', 'expiry_date', 'unit_cost', 'storage_location_id'])
            ->map(fn ($b) => [
                'id'           => $b->id,
                'batch_number' => (string) $b->batch_number,
                'remaining_qty'=> (float) $b->remaining_qty,
                'expiry_date'  => $b->expiry_date?->toDateString(),
                'unit_cost'    => (float) $b->unit_cost,
                'storage_location_id' => $b->storage_location_id,
                'is_expired'   => $b->expiry_date && $b->expiry_date->isPast(),
            ])
            ->all();
    }

    /** Single round-trip persist. Alpine sends the full payload. */
    public function submit(array $payload)
    {
        $data = validator($payload, [
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'batch_id'      => ['nullable', 'exists:ingredient_batches,id'],
            'quantity'      => ['required', 'numeric', 'min:0.0001'],
            'unit_id'       => ['required', 'exists:units,id'],
            'storage_location_id' => ['nullable', 'exists:storage_locations,id'],
            'reason'        => ['required', Rule::in(array_column(WasteReason::cases(), 'value'))],
            'notes'         => ['nullable', 'string', 'max:500'],
        ])->validate();

        $this->authorize('viewAny', Ingredient::class);

        $ingredient = Ingredient::with('baseUnit')->findOrFail($data['ingredient_id']);

        try {
            $qtyBase = UnitConverter::convert(
                (float) $data['quantity'],
                (int) $data['unit_id'],
                (int) $ingredient->base_unit_id,
            );
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: 'تعذّر تحويل الوحدة: '.$e->getMessage());
            return;
        }

        try {
            DB::transaction(function () use ($qtyBase, $ingredient, $data) {
                $batch = null;
                if (! empty($data['batch_id'])) {
                    $batch = IngredientBatch::whereKey($data['batch_id'])
                        ->where('ingredient_id', $ingredient->id)
                        ->when(! empty($data['storage_location_id']), fn ($query) => $query->where('storage_location_id', $data['storage_location_id']))
                        ->lockForUpdate()
                        ->first();

                    if (! $batch) throw new \RuntimeException('الدفعة المختارة لم تعد متاحة.');
                    if ((float) $batch->remaining_qty + 0.0001 < $qtyBase) {
                        throw new \RuntimeException(
                            "الدفعة لا تحتوي على الكمية الكافية ("
                            . \App\Helpers\Qty::format($batch->remaining_qty)
                            . ' < ' . \App\Helpers\Qty::format($qtyBase) . ').'
                        );
                    }
                    $batch->update(['remaining_qty' => (float) $batch->remaining_qty - $qtyBase]);
                }

                $unitCost = $batch ? (float) $batch->unit_cost : (float) $ingredient->cost_per_unit;

                app(InventoryService::class)->recordMovement(
                    ingredient:  $ingredient,
                    type:        'waste',
                    qtyBase:     $qtyBase,
                    unitCost:    $unitCost,
                    reference:   $batch,
                    reason:      $data['notes'] ?? null,
                    userId:      auth()->id(),
                    batchId:     $batch?->id,
                    wasteReason: $data['reason'],
                    storageLocationId: $data['storage_location_id'] ?? null,
                );

                ActivityLog::log(
                    'inventory.waste',
                    "هدر {$ingredient->name} ({$qtyBase} {$ingredient->baseUnit?->code}) — "
                    . WasteReason::from($data['reason'])->label(),
                    $ingredient,
                    [
                        'reason'   => $data['reason'],
                        'qty_base' => $qtyBase,
                        'batch_id' => $batch?->id,
                        'cost'     => $qtyBase * $unitCost,
                    ],
                );
            });
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
            return;
        }

        $this->dispatch('flash', type: 'success', message: 'تم تسجيل الهدر وخصم المخزون.');

        // Trigger Alpine to reset its form state via a browser event
        $this->dispatch('waste-logged');
    }
}
?>

@php
    $wasteLoggerConfig = [
        'ingredients' => $this->ingredients,
        'units' => $this->units,
        'reasons' => $this->reasonOptions,
        'locations' => $this->storageLocations,
        'preselectedIngredientId' => (int) ($preselectedIngredientId ?? 0),
    ];
@endphp

<div x-data="wasteLogger(@js($wasteLoggerConfig))"
     x-init="init()"
     @waste-logged.window="resetForm()">

    <div class="card custom-card">
        <div class="card-header">
            <h3 class="card-title mb-0">
                <i class="bi bi-pencil-square text-accent me-1"></i>
                تفاصيل الحدث
            </h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                {{-- Ingredient picker --}}
                <div class="col-md-6">
                    <label class="form-label">المكوّن <span class="text-danger">*</span></label>
                    <select x-model.number="ingredientId"
                            @change="onIngredientChange()"
                            class="form-select">
                        <option value="0">— اختر —</option>
                        <template x-for="i in ingredients" :key="i.id">
                            <option :value="i.id"
                                    x-text="i.name + (i.sku ? ' (' + i.sku + ')' : '') + ' — متوفّر: ' + i.current_stock.toFixed(4) + ' ' + i.unit_code"></option>
                        </template>
                    </select>
                </div>

                {{-- Reason --}}
                <div class="col-md-6">
                    <label class="form-label">السبب <span class="text-danger">*</span></label>
                    <select x-model="reason"
                            @change="onReasonChange()"
                            class="form-select">
                        <option value="">— اختر —</option>
                        <template x-for="r in reasons" :key="r.value">
                            <option :value="r.value" x-text="r.label"></option>
                        </template>
                    </select>
                </div>

                {{-- Quantity --}}
                <div class="col-md-4">
                    <label class="form-label">الكمية <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.0001"
                           x-model.number="quantity"
                           class="form-control text-end"
                           :class="batchShortfall() ? 'is-invalid' : ''">
                </div>

                {{-- Unit --}}
                <div class="col-md-4">
                    <label class="form-label">الوحدة <span class="text-danger">*</span></label>
                    <select x-model.number="unitId" class="form-select">
                        <option value="0">— اختر —</option>
                        <template x-for="u in units" :key="u.id">
                            <option :value="u.id" x-text="u.label"></option>
                        </template>
                    </select>
                </div>

                {{-- Storage location --}}
                <div class="col-md-4">
                    <label class="form-label">موقع الهدر</label>
                    <select x-model.number="locationId" @change="onLocationChange()" class="form-select">
                        <option value="0">المخزون العام</option>
                        <template x-for="location in locations" :key="location.id">
                            <option :value="location.id" x-text="location.label"></option>
                        </template>
                    </select>
                    <small class="text-muted">يفضل اختياره حتى يظهر الهدر على المطبخ/البار الصحيح.</small>
                </div>

                {{-- Batch picker (Alpine — list cached after one-time fetch per ingredient) --}}
                <div class="col-md-4">
                    <label class="form-label">
                        دفعة محددة <small class="text-muted">(اختياري)</small>
                    </label>
                    <select x-model.number="batchId" class="form-select"
                            :disabled="!ingredientId || loadingBatches">
                        <template x-if="!ingredientId">
                            <option value="0">— اختر مكوّناً أولاً —</option>
                        </template>
                        <template x-if="loadingBatches">
                            <option value="0">— تحميل... —</option>
                        </template>
                        <template x-if="ingredientId && !loadingBatches && batches.length === 0">
                            <option value="0">— لا دفعات متاحة —</option>
                        </template>
                        <template x-if="ingredientId && !loadingBatches && batches.length > 0">
                            <option value="0">— بدون دفعة محددة —</option>
                        </template>
                        <template x-for="b in batches" :key="b.id">
                            <option :value="b.id" x-text="batchLabel(b)"></option>
                        </template>
                    </select>
                    <small class="text-danger" x-show="batchShortfall()" x-cloak>
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        الكمية أكبر من المتبقي في الدفعة.
                    </small>
                    <small class="text-muted" x-show="!batchShortfall()">
                        يوصى بالاختيار لأسباب «انتهاء الصلاحية» — يضمن خصم الـ FIFO الصحيح.
                    </small>
                </div>

                {{-- Notes --}}
                <div class="col-12">
                    <label class="form-label">ملاحظات <small class="text-muted">(اختياري)</small></label>
                    <textarea x-model="notes" class="form-control" rows="2" maxlength="500"
                              placeholder="مثلاً: سُرقت من البراد، انكسرت العبوة عند التسليم..."></textarea>
                </div>

                {{-- Live cost preview --}}
                <div class="col-12" x-show="costPreview() > 0" x-cloak>
                    <div class="alert d-flex align-items-center gap-2 mb-0"
                         style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent);">
                        <i class="bi bi-cash-coin fs-18 text-accent"></i>
                        <span>
                            <strong>قيمة الخسارة المقدرة:</strong>
                            <span class="text-danger fw-bold fs-15"
                                  x-text="costPreview().toFixed(2) + ' ₪'"></span>
                            <small class="text-muted ms-2"
                                   x-text="selectedBatch() ? '(سعر دفعة #' + (selectedBatch().batch_number || selectedBatch().id) + ')' : '(متوسط تكلفة المكوّن)'"></small>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('admin.waste.index') }}" class="btn btn-light">إلغاء</a>
            <button type="button" @click="submitNow()"
                    :disabled="!canSubmit() || saving"
                    class="btn btn-danger">
                <i class="bi bi-trash3 me-1"></i>
                <span x-text="saving ? 'جارٍ التسجيل...' : 'سجّل الهدر واخصم'"></span>
            </button>
        </div>
    </div>
</div>

<script>
window.wasteLogger = function (config) {
    return {
        ingredients: config.ingredients,
        units:       config.units,
        reasons:     config.reasons,
        locations:   config.locations || [],

        ingredientId: 0,
        reason: '',
        quantity: 0,
        unitId: 0,
        locationId: 0,
        batchId: 0,
        notes: '',

        batches: [],            // [{id, batch_number, remaining_qty, expiry_date, unit_cost, is_expired}]
        loadingBatches: false,
        saving: false,

        async init() {
            const defaultLocation = this.locations.find(l => l.is_default) || this.locations[0];
            this.locationId = defaultLocation ? defaultLocation.id : 0;

            if (config.preselectedIngredientId) {
                this.ingredientId = config.preselectedIngredientId;
                await this.onIngredientChange();
            }
        },

        // ─── Pure computed (instant) ────────────────────────────────────
        ingredient() {
            return this.ingredients.find(i => i.id === this.ingredientId);
        },
        selectedBatch() {
            return this.batchId ? this.batches.find(b => b.id === this.batchId) : null;
        },
        batchLabel(b) {
            let s = b.batch_number ? ('#' + b.batch_number + ' · ') : '';
            s += 'متبقي ' + b.remaining_qty.toFixed(4);
            if (b.expiry_date) s += ' · ينتهي ' + b.expiry_date;
            if (b.is_expired)  s += ' ⚠️ منتهية';
            return s;
        },
        // qty in BASE unit (using a simple ratio — accurate when same dimension).
        // Server validates the conversion, so an edge-case unit mismatch fails
        // there. This client-side preview is for instant feedback only.
        qtyBase() {
            const ing = this.ingredient();
            if (! ing || ! this.unitId) return 0;
            // Same unit → no conversion. Different unit → assume preview-only
            // (server is the source of truth for cross-unit math).
            if (this.unitId === ing.base_unit_id) return Number(this.quantity) || 0;
            return Number(this.quantity) || 0;
        },
        costPreview() {
            const ing = this.ingredient();
            if (! ing || this.qtyBase() <= 0) return 0;
            const unitCost = this.selectedBatch()?.unit_cost ?? ing.cost_per_unit;
            return this.qtyBase() * unitCost;
        },
        batchShortfall() {
            const b = this.selectedBatch();
            if (! b) return false;
            return this.qtyBase() > b.remaining_qty + 0.0001;
        },
        canSubmit() {
            return this.ingredientId
                && this.reason
                && this.quantity > 0
                && this.unitId
                && ! this.batchShortfall();
        },

        // ─── Pickers (each = one tiny round-trip) ───────────────────────
        async onIngredientChange() {
            this.batchId = 0;
            this.batches = [];
            const ing = this.ingredient();
            if (ing && ! this.unitId) this.unitId = ing.base_unit_id;
            if (! this.ingredientId) return;

            this.loadingBatches = true;
            try {
                const res = await this.$wire.loadBatches(this.ingredientId, this.locationId || null);
                this.batches = Array.isArray(res) ? res : [];
            } catch (e) {
                this.batches = [];
            } finally {
                this.loadingBatches = false;
            }
        },

        async onLocationChange() {
            if (this.ingredientId) {
                await this.onIngredientChange();
            }
        },

        // When reason changes to "expired", auto-pick the soonest-expiring batch
        onReasonChange() {
            if (this.reason !== 'expired' || this.batchId) return;
            const expired = this.batches
                .filter(b => b.is_expired)
                .sort((a, b) => (a.expiry_date || '').localeCompare(b.expiry_date || ''))[0];
            if (expired) this.batchId = expired.id;
        },

        resetForm() {
            this.quantity = 0;
            this.batchId = 0;
            this.reason = '';
            this.notes = '';
            // Keep ingredient + unit selected so warehouse staff can quickly log multiple losses
        },

        // ─── Submit (single round-trip) ────────────────────────────────
        async submitNow() {
            if (! this.canSubmit()) return;
            this.saving = true;
            try {
                await this.$wire.submit({
                    ingredient_id: this.ingredientId,
                    batch_id:      this.batchId || null,
                    quantity:      this.quantity,
                    unit_id:       this.unitId,
                    storage_location_id: this.locationId || null,
                    reason:        this.reason,
                    notes:         this.notes,
                });
            } finally {
                this.saving = false;
            }
        },
    };
};
</script>
