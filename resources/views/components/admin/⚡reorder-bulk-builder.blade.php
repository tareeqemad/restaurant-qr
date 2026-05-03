<?php

use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reorder Bulk Builder (Livewire seed + Alpine UI — fast variant)
 *
 * Performance design:
 *   - The expensive 30-day usage aggregation is cached for 60 seconds and
 *     the candidate list is computed ONCE at mount, then handed to Alpine
 *     as a flat array. ZERO re-query on toggle/qty change.
 *   - Alpine owns: selection state, custom qty per row, all derived
 *     totals (per supplier + grand). Toggling a checkbox re-renders only
 *     in the browser — no Livewire round-trip.
 *   - Submit is the only round-trip — sends the final selection map.
 *
 * Cache key includes the active branch id so each branch gets its own
 * snapshot. TTL is short (60s) so reorder data stays fresh enough.
 */
new class extends Component
{
    public function submit(array $payload)
    {
        $this->authorize('create', PurchaseOrder::class);

        $picks = $payload['picks'] ?? []; // [{ingredient_id, supplier_id, unit_id, qty, price}, ...]
        if (empty($picks)) {
            $this->dispatch('flash', type: 'error', message: 'لم تختر أي بند.');
            return;
        }

        // Group by supplier and create one DRAFT PO per supplier.
        $bySup = [];
        foreach ($picks as $row) {
            $supplierId = (int) ($row['supplier_id'] ?? 0);
            if (! $supplierId) continue;
            $bySup[$supplierId][] = [
                'ingredient_id'    => (int) $row['ingredient_id'],
                'unit_id'          => (int) $row['unit_id'],
                'quantity_ordered' => (float) $row['qty'],
                'unit_price'       => (float) $row['price'],
                'notes'            => 'مولّد آلياً من اقتراحات إعادة الطلب',
            ];
        }

        $svc = app(PurchaseOrderService::class);
        $created = 0;
        foreach ($bySup as $supplierId => $lines) {
            try {
                $svc->create(
                    data: [
                        'supplier_id' => $supplierId,
                        'expected_at' => null,
                        'notes'       => 'تم توليد هذا الـ PO آلياً من اقتراحات إعادة الطلب. راجع قبل الإرسال.',
                    ],
                    lines: $lines,
                    userId: auth()->id(),
                );
                $created++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Invalidate cache so the page reflects the new draft
        Cache::forget($this->cacheKey());

        session()->flash('success', "تم إنشاء {$created} مسودة أمر شراء. راجعها قبل الإرسال.");
        return $this->redirect(route('admin.purchase-orders.index', ['status' => 'draft']), navigate: false);
    }

    /** Cache key per branch — keeps branches' snapshots isolated. */
    protected function cacheKey(): string
    {
        $branchId = BranchContext::current() ?? 'global';
        return "reorder.candidates.{$branchId}";
    }

    /**
     * Compute candidates (cached). Returns a flat array Alpine can iterate
     * with no further server calls.
     */
    #[Computed(persist: false)]
    public function candidatesSeed(): array
    {
        return Cache::remember($this->cacheKey(), 60, function () {
            $branchId = BranchContext::current();
            $branchCol = 'inventory_movements.branch_id';

            $usageQuery = DB::table('inventory_movements')
                ->whereIn('type', ['out', 'waste'])
                ->where('occurred_at', '>=', now()->subDays(30));
            if ($branchId) {
                $usageQuery->where($branchCol, $branchId);
            }

            $usage = $usageQuery
                ->selectRaw('ingredient_id, SUM(quantity_in_base) as used')
                ->groupBy('ingredient_id')
                ->pluck('used', 'ingredient_id');

            // Branch-aware candidate filter using the helpers — uses per-branch
            // stock + threshold instead of the global one. Owner-level (no branch)
            // falls back to the global comparison.
            $tracked = Ingredient::with(['baseUnit:id,code', 'supplier:id,name'])
                ->where('track_stock', true)
                ->orderBy('supplier_id')
                ->orderBy('name')
                ->get();

            $list = $branchId
                ? $tracked->filter(fn ($i) => $i->isLowStockAtBranch($branchId))->values()
                : $tracked->filter(fn ($i) => (float) $i->current_stock <= (float) $i->reorder_threshold)->values();

            return $list->map(function ($ing) use ($usage, $branchId) {
                $used30    = (float) ($usage[$ing->id] ?? 0);
                $current   = $branchId ? $ing->stockAtBranch($branchId)            : (float) $ing->current_stock;
                $threshold = $branchId ? $ing->reorderThresholdAtBranch($branchId) : (float) $ing->reorder_threshold;
                $cpu       = $branchId ? $ing->costAtBranch($branchId)             : (float) $ing->cost_per_unit;

                $suggested = max($used30, 2 * $threshold, 1);
                $dailyBurn = $used30 / 30;
                $days      = $dailyBurn > 0 ? $current / $dailyBurn : INF;

                return [
                    'id'              => $ing->id,
                    'name'            => $ing->name,
                    'unit'            => $ing->baseUnit?->code ?? '',
                    'supplier_id'     => $ing->supplier_id,
                    'supplier_name'   => $ing->supplier?->name ?? 'بدون مورّد محدد',
                    'current_stock'   => $current,
                    'reorder_threshold' => $threshold,
                    'used_30d'        => $used30,
                    'suggested_qty'   => $suggested,
                    'cost_per_unit'   => $cpu,
                    'unit_id'         => $ing->base_unit_id,
                    'days_to_stockout'=> is_infinite($days) ? null : $days,
                    'urgency'         => $this->urgencyOf($days, $current),
                ];
            })->all();
        });
    }

    protected function urgencyOf(float $days, float $current): string
    {
        if ($current <= 0) return 'critical';
        if ($days <= 2)    return 'critical';
        if ($days <= 7)    return 'high';
        if ($days <= 14)   return 'medium';
        return 'low';
    }
}
?>

<div x-data='reorderBulkBuilder({ candidates: @json($this->candidatesSeed) })'
     x-init="init()">

    <template x-if="candidates.length === 0">
        <div class="text-center py-5">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
            <h4 class="mt-3 mb-2">المخزون في حالة جيدة</h4>
            <p class="text-muted">لا يوجد مكونات وصلت حد إعادة الطلب.</p>
        </div>
    </template>

    <template x-if="candidates.length > 0">
        <div>
            {{-- Stat rail (Alpine reactive) --}}
            <div class="row g-2 mb-3">
                <div class="col-md-2">
                    <div class="card custom-card">
                        <div class="card-body py-2 px-3 text-center">
                            <small class="text-muted d-block">الإجمالي</small>
                            <strong class="fs-18" x-text="candidates.length"></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card custom-card border-danger">
                        <div class="card-body py-2 px-3 text-center">
                            <small class="text-muted d-block">حرج</small>
                            <strong class="fs-18 text-danger" x-text="urgencyCounts.critical"></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card custom-card border-warning">
                        <div class="card-body py-2 px-3 text-center">
                            <small class="text-muted d-block">مرتفع</small>
                            <strong class="fs-18 text-warning" x-text="urgencyCounts.high"></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card custom-card border-primary">
                        <div class="card-body py-2 px-3 text-center">
                            <small class="text-muted d-block">مختار الآن</small>
                            <strong class="fs-18 text-primary"
                                    x-text="totals.count + ' بند · ' + totals.supplier_count + ' مورّد'"></strong>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card custom-card border-success">
                        <div class="card-body py-2 px-3 text-center">
                            <small class="text-muted d-block">تكلفة المختار</small>
                            <strong class="fs-18 text-success"
                                    x-text="totals.cost.toFixed(2) + ' ₪'"></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert d-flex align-items-start gap-2"
                 style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
                <i class="bi bi-info-circle-fill fs-18"></i>
                <div>
                    <strong>المنطق:</strong> الكمية المقترحة = max(استهلاك 30 يوم، 2× حد الطلب).
                    «أيام للنفاد» = المخزون ÷ متوسط الاستهلاك اليومي.
                    <br>
                    <small>اختر الصفوف، عدّل الكمية، ثم اضغط «أنشئ مسودات الـ POs». سيُولَّد PO منفصل لكل مورّد.</small>
                </div>
            </div>

            {{-- Per-supplier groups --}}
            <template x-for="(items, supplierId) in groups" :key="supplierId">
                <div class="card custom-card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0 d-inline-flex align-items-center gap-2">
                            <input type="checkbox"
                                   :disabled="items[0].supplier_id === null || items[0].supplier_id === 0"
                                   :checked="allSelectedInGroup(supplierId)"
                                   @click="toggleGroup(supplierId, $event.target.checked)"
                                   class="form-check-input">
                            <i class="bi bi-truck text-accent"></i>
                            <span x-text="items[0].supplier_name"></span>
                            <span class="badge bg-primary-transparent ms-1" x-text="items.length"></span>
                        </h3>
                        <span class="fw-bold text-primary"
                              x-text="groupCost(supplierId).toFixed(2) + ' ₪'"></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th style="width:36px;"></th>
                                        <th>المكوّن</th>
                                        <th class="text-end">المخزون / حد</th>
                                        <th class="text-end">استهلاك 30ي</th>
                                        <th class="text-end">أيام للنفاد</th>
                                        <th class="text-end">إلحاح</th>
                                        <th style="width:140px;">كمية الطلب</th>
                                        <th class="text-end">تكلفة تقديرية</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="ing in items" :key="ing.id">
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       x-model="selected[ing.id]"
                                                       :disabled="!ing.supplier_id"
                                                       class="form-check-input">
                                            </td>
                                            <td>
                                                <div class="fw-semibold" x-text="ing.name"></div>
                                                <small class="text-muted" x-text="'/ ' + ing.unit"></small>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-semibold"
                                                     :class="ing.current_stock <= 0 ? 'text-danger' : ''"
                                                     x-text="ing.current_stock.toFixed(2)"></div>
                                                <small class="text-muted"
                                                       x-text="'حد: ' + ing.reorder_threshold.toFixed(2)"></small>
                                            </td>
                                            <td class="text-end" x-text="ing.used_30d.toFixed(2)"></td>
                                            <td class="text-end fs-13" x-text="formatDays(ing.days_to_stockout)"></td>
                                            <td class="text-end">
                                                <span class="badge"
                                                      :class="urgencyBadgeClass(ing.urgency)"
                                                      x-text="urgencyLabel(ing.urgency)"></span>
                                            </td>
                                            <td>
                                                <input type="number" step="0.0001" min="0"
                                                       x-model.number="customQty[ing.id]"
                                                       class="form-control form-control-sm text-end">
                                            </td>
                                            <td class="text-end fw-bold text-primary"
                                                x-text="lineCost(ing).toFixed(2) + ' ₪'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <template x-if="!items[0].supplier_id">
                            <div class="alert alert-warning m-2 mb-2">
                                <i class="bi bi-exclamation-triangle"></i>
                                هذه المكونات بلا مورّد محدد — لا يمكن إنشاء PO تلقائياً.
                                حدّد مورّداً لكل واحد من
                                <a href="{{ route('admin.ingredients.index') }}">شاشة المكونات</a>.
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Sticky bottom bar --}}
            <div class="position-sticky bottom-0 bg-white border-top shadow-lg p-3"
                 style="z-index: 100; border-radius: 8px;">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <span class="fw-semibold">
                        <i class="bi bi-check2-square me-1"></i>
                        <strong x-text="totals.count"></strong> بند مختار ·
                        <strong x-text="totals.supplier_count"></strong> مورّد ·
                        إجمالي <strong class="text-primary" x-text="totals.cost.toFixed(2) + ' ₪'"></strong>
                    </span>
                    <button type="button" @click="toggleAll(true)" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-check2-all"></i> حدّد الكل
                    </button>
                    <button type="button" @click="toggleAll(false)" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x"></i> إلغاء التحديد
                    </button>
                    <button type="button"
                            @click="submit()"
                            :disabled="totals.count === 0 || saving"
                            class="btn btn-primary ms-auto">
                        <i class="bi bi-bag-plus-fill me-1"></i>
                        <span x-text="saving ? 'جارٍ الإنشاء...' : 'أنشئ مسودات الـ POs'"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
window.reorderBulkBuilder = function (config) {
    return {
        candidates: config.candidates,
        selected: {},     // ingredient_id => bool
        customQty: {},    // ingredient_id => number
        saving: false,

        init() {
            this.candidates.forEach(c => {
                this.selected[c.id] = !!c.supplier_id;        // pre-check items with a supplier
                this.customQty[c.id] = c.suggested_qty;       // default to suggested
            });
        },

        // ─── Display helpers (instant) ───────────────────────────────────
        effectiveQty(ing) {
            const v = this.customQty[ing.id];
            if (v === null || v === undefined || v === '' || Number(v) <= 0) {
                return ing.suggested_qty;
            }
            return Number(v);
        },
        lineCost(ing) {
            return this.effectiveQty(ing) * ing.cost_per_unit;
        },
        formatDays(days) {
            if (days === null || days === undefined) return '∞';
            if (days <= 0) return 'الآن';
            if (days < 1)  return '< يوم';
            return days.toFixed(1) + ' يوم';
        },
        urgencyLabel(u) {
            return { critical: 'حرج', high: 'مرتفع', medium: 'متوسط', low: 'منخفض' }[u] || u;
        },
        urgencyBadgeClass(u) {
            const color = { critical: 'danger', high: 'warning', medium: 'info', low: 'success' }[u] || 'secondary';
            return `bg-${color}-transparent text-${color}`;
        },

        // ─── Groups (memoised by supplier_id) ────────────────────────────
        get groups() {
            const out = {};
            this.candidates.forEach(c => {
                const key = c.supplier_id || 0;
                (out[key] ||= []).push(c);
            });
            return out;
        },

        // ─── Aggregates ──────────────────────────────────────────────────
        get totals() {
            let count = 0;
            let cost  = 0;
            const sup = new Set();
            this.candidates.forEach(c => {
                if (! this.selected[c.id]) return;
                if (! c.supplier_id) return;
                count++;
                cost += this.lineCost(c);
                sup.add(c.supplier_id);
            });
            return { count, cost, supplier_count: sup.size };
        },
        get urgencyCounts() {
            const out = { critical: 0, high: 0, medium: 0, low: 0 };
            this.candidates.forEach(c => { out[c.urgency] = (out[c.urgency] || 0) + 1; });
            return out;
        },
        groupCost(supplierId) {
            return (this.groups[supplierId] || []).reduce((s, c) => s + this.lineCost(c), 0);
        },
        allSelectedInGroup(supplierId) {
            const items = this.groups[supplierId] || [];
            return items.length > 0 && items.every(c => !c.supplier_id || this.selected[c.id]);
        },

        // ─── Selection actions ───────────────────────────────────────────
        toggleAll(on) {
            this.candidates.forEach(c => { if (c.supplier_id) this.selected[c.id] = on; });
        },
        toggleGroup(supplierId, on) {
            (this.groups[supplierId] || []).forEach(c => { if (c.supplier_id) this.selected[c.id] = on; });
        },

        // ─── Submit (single round-trip) ──────────────────────────────────
        async submit() {
            if (this.totals.count === 0) return;
            if (! confirm(`سيتم إنشاء ${this.totals.supplier_count} مسودة PO. متابعة؟`)) return;
            this.saving = true;
            try {
                const picks = [];
                this.candidates.forEach(c => {
                    if (! this.selected[c.id]) return;
                    if (! c.supplier_id) return;
                    picks.push({
                        ingredient_id: c.id,
                        supplier_id:   c.supplier_id,
                        unit_id:       c.unit_id,
                        qty:           this.effectiveQty(c),
                        price:         c.cost_per_unit,
                    });
                });
                await this.$wire.submit({ picks });
            } finally {
                this.saving = false;
            }
        },
    };
};
</script>
