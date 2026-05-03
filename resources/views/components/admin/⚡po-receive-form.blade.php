<?php

use App\Models\PurchaseOrder;
use App\Models\StorageLocation;
use App\Services\PurchaseOrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * PO Receive Form (Livewire seed + Alpine reactive UI)
 *
 * Performance design:
 *   - Server seeds the lines (Computed once at render).
 *   - Alpine owns ALL interactive state: per-line qty, totals, validation
 *     flags. Typing → instant feedback, no network.
 *   - The submit button calls `$wire.submitReceipt(qtyMap)` once with the
 *     whole map — single round-trip on commit.
 *   - Inputs intentionally have no wire:model binding. The submit handler
 *     accepts the qty map as a parameter, so the server only sees the
 *     final values when the user actually commits.
 */
new class extends Component
{
    #[Locked]
    public int $poId;

    public function mount(PurchaseOrder $po): void
    {
        $this->poId = $po->id;
    }

    #[Computed(persist: false)]
    public function po(): PurchaseOrder
    {
        return PurchaseOrder::with(['supplier', 'items.ingredient.baseUnit', 'items.unit'])
            ->findOrFail($this->poId);
    }

    /** Lightweight seed for Alpine — only what the JS needs to render + validate. */
    #[Computed(persist: false)]
    public function seedLines(): array
    {
        return $this->po->items->map(fn ($l) => [
            'id'                 => $l->id,
            'ingredient_name'    => $l->ingredient?->name ?? '—',
            'unit_name'          => $l->unit?->name ?? '',
            'unit_code'          => $l->unit?->code ?? '',
            'quantity_ordered'   => (float) $l->quantity_ordered,
            'quantity_received'  => (float) $l->quantity_received,
            'outstanding'        => (float) $l->outstandingQty(),
            'unit_price'         => (float) $l->unit_price,
            'is_full'            => $l->isFullyReceived(),
        ])->all();
    }

    #[Computed(persist: false)]
    public function storageLocations(): array
    {
        return StorageLocation::query()
            ->where('active', true)
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

    /**
     * Persist the receipt. Alpine sends qtyMap = { lineId: qty, ... }.
     * Single round-trip on commit only.
     */
    public function submitReceipt(array $qtyMap, $storageLocationId = null, array $batchMap = [], array $expiryMap = [])
    {
        $po = $this->po;
        $this->authorize('receive', $po);

        $storageLocationId = $storageLocationId ? (int) $storageLocationId : null;
        if (! $storageLocationId && ! empty($this->storageLocations)) {
            $this->dispatch('flash', type: 'error', message: 'اختر وجهة التخزين قبل تسجيل الاستلام.');
            return;
        }

        if ($storageLocationId && ! StorageLocation::whereKey($storageLocationId)->where('active', true)->exists()) {
            $this->dispatch('flash', type: 'error', message: 'وجهة التخزين المختارة غير متاحة.');
            return;
        }

        // Cast strings to floats + drop zeros
        $receipts = [];
        $meta = [];
        foreach ($qtyMap as $lineId => $val) {
            $val = (float) $val;
            if ($val > 0) {
                $lineId = (int) $lineId;
                $receipts[$lineId] = $val;
                $meta[$lineId] = [
                    'storage_location_id' => $storageLocationId,
                    'batch_number' => trim((string) ($batchMap[$lineId] ?? '')) ?: null,
                    'expiry_date' => $expiryMap[$lineId] ?? null,
                ];
            }
        }
        if (empty($receipts)) {
            $this->dispatch('flash', type: 'error', message: 'لم تُدخل أي كمية.');
            return;
        }

        try {
            app(PurchaseOrderService::class)->receive($po, $receipts, auth()->id(), $meta);
            session()->flash('success', 'تم تسجيل الاستلام وتحديث المخزون والأسعار.');
            return $this->redirect(route('admin.purchase-orders.show', $po), navigate: false);
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }
}
?>

@php
    $po = $this->po;
@endphp

<div x-data='poReceiveForm({ lines: @json($this->seedLines), locations: @json($this->storageLocations) })' x-init="init()">
    <div class="alert d-flex align-items-start gap-2"
         style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
        <i class="bi bi-info-circle-fill fs-18"></i>
        <div>
            <strong>تذكير:</strong> الاستلام يضيف للمخزون كحركة "إدخال"، يُحدّث السعر بالـ Weighted Average،
            ويعيد حساب تكلفة أصناف المنيو. كل كمية تكتبها تُعرض حية في الإجمالي بالأسفل.
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label fw-semibold">وجهة التخزين</label>
                    <select class="form-select" x-model.number="locationId" :class="needsLocation ? 'is-invalid' : ''">
                        <option value="">اختر المخزن أو المحطة</option>
                        <template x-for="location in locations" :key="location.id">
                            <option :value="location.id" x-text="location.label"></option>
                        </template>
                    </select>
                    <small class="text-muted">كل كمية مستلمة ستظهر في حركة المخزون على هذا الموقع.</small>
                </div>
                <div class="col-lg-7">
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-success-transparent text-success px-3 py-2">
                            <i class="bi bi-box-arrow-in-down me-1"></i>
                            وارد مشتريات
                        </span>
                        <span class="badge bg-info-transparent text-info px-3 py-2">
                            <i class="bi bi-box2-heart me-1"></i>
                            دفعات FIFO
                        </span>
                        <span class="badge bg-warning-transparent text-warning px-3 py-2">
                            <i class="bi bi-calendar2-week me-1"></i>
                            تاريخ انتهاء اختياري
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card custom-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">
                <i class="bi bi-list-ul text-accent me-1"></i>
                بنود أمر الشراء
                <span class="badge bg-primary-transparent ms-2">{{ count($this->seedLines) }}</span>
            </h3>
            <div class="d-flex gap-2">
                <button type="button" @click="fillAll()" class="btn btn-sm btn-light">
                    <i class="bi bi-magic me-1"></i> املأ الكل بالمتبقي
                </button>
                <button type="button" @click="clearAll()" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-eraser me-1"></i> امسح الكل
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th class="text-end">مطلوب</th>
                            <th class="text-end">مستلم سابقاً</th>
                            <th class="text-end">المتبقي</th>
                            <th style="width:200px;">استلام الآن</th>
                            <th style="width:180px;">رقم الدفعة</th>
                            <th style="width:160px;">انتهاء</th>
                            <th class="text-end">سعر/وحدة</th>
                            <th class="text-end">قيمة السطر</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="line in lines" :key="line.id">
                            <tr :class="rowClass(line)">
                                <td>
                                    <div class="fw-semibold" x-text="line.ingredient_name"></div>
                                    <small class="text-muted">
                                        وحدة: <span x-text="line.unit_name"></span> (<span x-text="line.unit_code"></span>)
                                    </small>
                                </td>
                                <td class="text-end" x-text="line.quantity_ordered.toFixed(4)"></td>
                                <td class="text-end" x-text="line.quantity_received.toFixed(4)"></td>
                                <td class="text-end fw-bold"
                                    :class="line.outstanding > 0 ? 'text-warning' : 'text-success'"
                                    x-text="line.outstanding.toFixed(4)"></td>
                                <td>
                                    <template x-if="line.is_full">
                                        <span class="badge bg-success w-100 py-2">
                                            <i class="bi bi-check-circle"></i> مستلم بالكامل
                                        </span>
                                    </template>
                                    <template x-if="!line.is_full">
                                        <div>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.0001" min="0"
                                                       :max="line.outstanding"
                                                       x-model.number="qty[line.id]"
                                                       :class="isOver(line) ? 'is-invalid' : ''"
                                                       class="form-control text-end">
                                                <span class="input-group-text" x-text="line.unit_code"></span>
                                            </div>
                                            <small class="text-danger" x-show="isOver(line)" x-cloak>
                                                <i class="bi bi-exclamation-triangle-fill"></i>
                                                أكبر من المتبقي (<span x-text="line.outstanding.toFixed(4)"></span>)
                                            </small>
                                        </div>
                                    </template>
                                </td>
                                <td>
                                    <template x-if="line.is_full">
                                        <span class="text-muted">—</span>
                                    </template>
                                    <template x-if="!line.is_full">
                                        <input type="text"
                                               x-model="batch[line.id]"
                                               class="form-control form-control-sm"
                                               placeholder="اختياري">
                                    </template>
                                </td>
                                <td>
                                    <template x-if="line.is_full">
                                        <span class="text-muted">—</span>
                                    </template>
                                    <template x-if="!line.is_full">
                                        <input type="date"
                                               x-model="expiry[line.id]"
                                               class="form-control form-control-sm">
                                    </template>
                                </td>
                                <td class="text-end text-muted" x-text="line.unit_price.toFixed(4) + ' ₪'"></td>
                                <td class="text-end fw-bold text-primary"
                                    x-text="lineCost(line).toFixed(2) + ' ₪'"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <td colspan="8" class="text-end fw-bold">إجمالي قيمة الاستلام</td>
                            <td class="text-end fw-bold fs-18 text-primary"
                                x-text="totalCost.toFixed(2) + ' ₪'"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Sticky bottom bar --}}
    <div class="position-sticky bottom-0 bg-white border-top shadow-lg p-3 mt-3"
         style="z-index: 100; border-radius: 8px;">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="fs-14">
                <strong x-text="linesEntered"></strong> / {{ count($this->seedLines) }}
                بنود مدخلة · إجمالي
                <strong class="text-primary" x-text="totalCost.toFixed(2) + ' ₪'"></strong>
            </span>

            <span class="badge bg-danger fs-12" x-show="hasOver" x-cloak>
                <i class="bi bi-exclamation-octagon-fill"></i>
                يوجد بنود تتجاوز المتبقي — صحّح أولاً
            </span>

            <span class="badge bg-warning fs-12" x-show="needsLocation" x-cloak>
                <i class="bi bi-geo-alt-fill"></i>
                اختر وجهة التخزين
            </span>

            <a href="{{ route('admin.purchase-orders.show', $po) }}" class="btn btn-light ms-auto">
                <i class="bi bi-arrow-right"></i> إلغاء
            </a>
            <button type="button"
                    @click="submitNow()"
                    :disabled="!canSubmit || submitting"
                    class="btn btn-success">
                <i class="bi bi-check-circle-fill me-1"></i>
                <span x-text="submitting ? 'جارٍ التسجيل...' : 'تسجيل الاستلام'"></span>
            </button>
        </div>
    </div>
</div>

<script>
window.poReceiveForm = function (config) {
    return {
        lines: config.lines,
        locations: config.locations || [],
        locationId: null,
        qty: {},                  // {lineId: number}
        batch: {},                // {lineId: string}
        expiry: {},               // {lineId: yyyy-mm-dd}
        submitting: false,

        init() {
            const defaultLocation = this.locations.find(l => l.is_default) || this.locations[0];
            this.locationId = defaultLocation ? defaultLocation.id : null;

            // Pre-fill each line's qty with its outstanding amount
            this.lines.forEach(l => {
                this.qty[l.id] = l.is_full ? 0 : l.outstanding;
                this.batch[l.id] = '';
                this.expiry[l.id] = '';
            });
        },

        // ─── Per-line derived (instant) ──────────────────────────────────
        enteredQty(line) {
            return Number(this.qty[line.id] ?? 0) || 0;
        },
        isOver(line) {
            return this.enteredQty(line) > line.outstanding + 0.0001;
        },
        lineCost(line) {
            return Math.max(0, this.enteredQty(line)) * line.unit_price;
        },
        rowClass(line) {
            if (line.is_full) return 'table-light text-muted';
            if (this.isOver(line)) return 'table-danger';
            const entered = this.enteredQty(line);
            if (entered > 0 && Math.abs(entered - line.outstanding) < 0.0001) return 'table-success';
            return '';
        },

        // ─── Aggregates ──────────────────────────────────────────────────
        get totalCost()     { return this.lines.reduce((s, l) => s + this.lineCost(l), 0); },
        get linesEntered()  { return this.lines.filter(l => this.enteredQty(l) > 0).length; },
        get hasOver()       { return this.lines.some(l => this.isOver(l)); },
        get hasAny()        { return this.linesEntered > 0; },
        get needsLocation() { return this.locations.length > 0 && !this.locationId; },
        get canSubmit()     { return this.hasAny && !this.hasOver && !this.needsLocation; },

        // ─── Bulk helpers ────────────────────────────────────────────────
        fillAll() {
            this.lines.forEach(l => { if (! l.is_full) this.qty[l.id] = l.outstanding; });
        },
        clearAll() {
            this.lines.forEach(l => { this.qty[l.id] = 0; });
        },

        // ─── Submit (single round-trip) ──────────────────────────────────
        async submitNow() {
            if (! this.canSubmit) return;
            if (! confirm('تأكيد استلام البضاعة؟ سيتم تحديث المخزون والأسعار.')) return;
            this.submitting = true;
            try {
                await this.$wire.submitReceipt(this.qty, this.locationId, this.batch, this.expiry);
            } finally {
                this.submitting = false;
            }
        },
    };
};
</script>
