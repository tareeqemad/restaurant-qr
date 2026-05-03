<?php

use App\Models\StockCount;
use App\Services\StockCountService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Stock Count Workspace (Livewire + Alpine — fast variant)
 *
 * Performance design:
 *   - Alpine.js owns the per-row state (qty/notes) + ALL derived display
 *     (variance, cost, badges, totals). Typing → instant UI update with
 *     ZERO server round-trip.
 *   - Livewire is only used to PERSIST (saveRow, finalize, cancel) and
 *     to seed initial state. No more wire:model.live on inputs.
 *   - `seed` is the JSON payload Alpine reads at boot: a snapshot of
 *     items + cost-per-unit. Subsequent changes never need to re-fetch.
 *   - `saveRow($itemId, $qty, $notes)` is called by Alpine when a row
 *     loses focus. One row → one tiny request → one row updated.
 *   - Polling is removed from this component (was rebuilding 100+ rows).
 *     Multi-user collaboration falls back to "save your work, refresh
 *     the page when you want to see others' changes" — acceptable for
 *     a once-a-week activity.
 */
new class extends Component
{
    #[Locked]
    public int $countId;

    public bool $showingCancel = false;
    public string $cancelReason = '';

    public function mount(StockCount $count): void
    {
        $this->countId = $count->id;
    }

    /** Lightweight metadata for the parent shell — no items eager-loaded
     *  here so Livewire's view stays cheap. */
    #[Computed(persist: false)]
    public function count(): StockCount
    {
        return StockCount::with('creator', 'finalizer')->findOrFail($this->countId);
    }

    /** The seed array Alpine reads at boot. One DB hit, all derived values
     *  recomputed in JS thereafter. */
    #[Computed(persist: false)]
    public function seedRows(): array
    {
        return StockCount::findOrFail($this->countId)
            ->items()
            ->with(['ingredient.baseUnit'])
            ->orderBy('id')
            ->get()
            ->map(fn ($it) => [
                'id'            => $it->id,
                'name'          => $it->ingredient?->name ?? '—',
                'unit'          => $it->ingredient?->baseUnit?->code ?? '',
                'cost_per_unit' => (float) ($it->ingredient?->cost_per_unit ?? 0),
                'system_qty'    => (float) $it->system_qty,
                'counted_qty'   => $it->counted_qty === null ? null : (float) $it->counted_qty,
                'notes'         => (string) $it->notes,
            ])
            ->all();
    }

    /**
     * Persist ONE row. Called by Alpine on input blur with the local values.
     * Returns OK so Alpine knows to flip the badge to "saved".
     */
    public function saveRow(int $itemId, $qty, ?string $notes): array
    {
        $count = $this->count;
        if (! $count->isEditable()) return ['ok' => false, 'reason' => 'closed'];
        $this->authorize('update', $count);

        try {
            app(StockCountService::class)->saveCounts(
                $count,
                [$itemId => ($qty === '' || $qty === null ? null : (float) $qty)],
                [$itemId => $notes ?? ''],
            );
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /** Bulk-save everything at once — Alpine sends the full entries map. */
    public function saveAll(array $entries): void
    {
        $count = $this->count;
        if (! $count->isEditable()) return;
        $this->authorize('update', $count);

        $counts = [];
        $notes  = [];
        foreach ($entries as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (! $id) continue;
            $counts[$id] = ($row['counted_qty'] === '' || $row['counted_qty'] === null) ? null : (float) $row['counted_qty'];
            $notes[$id]  = (string) ($row['notes'] ?? '');
        }

        try {
            app(StockCountService::class)->saveCounts($count, $counts, $notes);
            $this->dispatch('flash', type: 'success', message: 'تم حفظ كل التعديلات.');
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }

    public function finalize(array $entries)
    {
        $count = $this->count;
        $this->authorize('finalize', $count);

        // Persist any unsaved client-side state first
        $this->saveAll($entries);

        try {
            $created = app(StockCountService::class)->finalize($count, auth()->id());
            session()->flash('success', "تم اعتماد الجرد. أُنشئت {$created} حركة تعديل مخزون.");
            return $this->redirect(route('admin.stock-counts.show', $count), navigate: false);
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }

    public function showCancel(): void { $this->showingCancel = true; }
    public function hideCancel(): void { $this->showingCancel = false; $this->cancelReason = ''; }

    public function cancelCount()
    {
        $count = $this->count;
        $this->authorize('cancel', $count);

        $reason = trim($this->cancelReason);
        if ($reason === '') {
            $this->dispatch('flash', type: 'error', message: 'أدخل سبب الإلغاء.');
            return;
        }
        try {
            app(StockCountService::class)->cancel($count, auth()->id(), $reason);
            session()->flash('success', 'تم إلغاء الجرد.');
            return $this->redirect(route('admin.stock-counts.index'), navigate: false);
        } catch (\Throwable $e) {
            $this->dispatch('flash', type: 'error', message: $e->getMessage());
        }
    }
}
?>

@php
    $count = $this->count;
    $editable = $count->isEditable();
@endphp

<div x-data='stockCountWorkspace({
        editable: @json($editable),
        rows: @json($this->seedRows),
    })'
     x-init="init()">

    {{-- ═══ Stat rail (Alpine reactive) ═══ --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card custom-card border-primary">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                    <i class="bi bi-list-check fs-22 text-primary"></i>
                    <div>
                        <small class="text-muted d-block">مكونات معدّة</small>
                        <strong><span x-text="counted"></span> / {{ count($this->seedRows) }}</strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-card border-danger">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                    <i class="bi bi-arrow-down-circle-fill fs-22 text-danger"></i>
                    <div>
                        <small class="text-muted d-block">نقص</small>
                        <strong class="text-danger" x-text="shortageCount"></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-card border-warning">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                    <i class="bi bi-arrow-up-circle-fill fs-22 text-warning"></i>
                    <div>
                        <small class="text-muted d-block">زيادة</small>
                        <strong class="text-warning" x-text="overCount"></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-card" :class="Math.abs(totalCost) > 50 ? 'border-danger' : 'border-success'">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                    <i class="bi bi-cash-coin fs-22" :class="Math.abs(totalCost) > 50 ? 'text-danger' : 'text-success'"></i>
                    <div>
                        <small class="text-muted d-block">قيمة الفروق</small>
                        <strong :class="totalCost < 0 ? 'text-danger' : 'text-success'"
                                x-text="(totalCost >= 0 ? '+' : '') + totalCost.toFixed(2) + ' ₪'"></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ Main panel ═══ --}}
    <div class="card custom-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">
                <i class="bi bi-list-check text-accent me-1"></i>
                بنود الجرد {{ $count->number }}
                <span class="badge bg-{{ $editable ? 'warning' : 'success' }}-transparent ms-2">
                    {{ $editable ? 'مسودة قابلة للتعديل' : 'مُعتمد' }}
                </span>
            </h3>
            @if($editable)
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light"
                            @click="$wire.saveAll(rows)"
                            :disabled="!hasDirty">
                        <i class="bi bi-save me-1"></i>
                        <span x-text="hasDirty ? 'حفظ ' + dirtyCount + ' تعديل' : 'لا تعديلات'"></span>
                    </button>
                    @can('finalize', $count)
                    <button type="button" class="btn btn-sm btn-success"
                            @click="confirm('سيتم تطبيق التسويات على المخزون. متابعة؟') && $wire.finalize(rows)">
                        <i class="bi bi-check-circle-fill me-1"></i> اعتماد الجرد
                    </button>
                    @endcan
                    @can('cancel', $count)
                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="showCancel">
                        <i class="bi bi-x-circle me-1"></i> إلغاء
                    </button>
                    @endcan
                </div>
            @endif
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th>الوحدة</th>
                            <th class="text-end">كمية النظام</th>
                            <th style="width:160px;">الكمية الفعلية</th>
                            <th class="text-end">الفرق</th>
                            <th class="text-end">قيمة الفرق</th>
                            <th>ملاحظات</th>
                            <th style="width:90px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, idx) in rows" :key="row.id">
                            <tr :class="rowClass(row)">
                                <td class="fw-semibold" x-text="row.name"></td>
                                <td class="text-muted fs-13" x-text="row.unit"></td>
                                <td class="text-end" x-text="row.system_qty.toFixed(4)"></td>
                                <td>
                                    @if($editable)
                                        <input type="number" step="0.0001" min="0"
                                               x-model.number="row.counted_qty"
                                               @blur="onBlur(idx)"
                                               class="form-control form-control-sm text-end"
                                               placeholder="—">
                                    @else
                                        <span x-text="row.counted_qty === null ? '—' : row.counted_qty.toFixed(4)"></span>
                                    @endif
                                </td>
                                <td class="text-end fw-bold">
                                    <template x-if="state(row) === 'pending'"><span class="text-muted">—</span></template>
                                    <template x-if="state(row) === 'match'"><i class="bi bi-check-circle-fill text-success" title="مطابق"></i></template>
                                    <template x-if="state(row) === 'short' || state(row) === 'over'">
                                        <span :class="'text-' + (state(row) === 'short' ? 'danger' : 'warning')"
                                              x-text="(variance(row) > 0 ? '+' : '') + variance(row).toFixed(4)"></span>
                                    </template>
                                </td>
                                <td class="text-end fw-bold">
                                    <template x-if="state(row) === 'pending'"><span class="text-muted">—</span></template>
                                    <template x-if="state(row) !== 'pending'">
                                        <span :class="'text-' + (cost(row) < 0 ? 'danger' : (cost(row) > 0 ? 'success' : 'muted'))"
                                              x-text="(cost(row) >= 0 ? '+' : '') + cost(row).toFixed(2)"></span>
                                    </template>
                                </td>
                                <td>
                                    @if($editable)
                                        <input type="text"
                                               x-model="row.notes"
                                               @blur="onBlur(idx)"
                                               class="form-control form-control-sm"
                                               placeholder="...">
                                    @else
                                        <small class="text-muted" x-text="row.notes"></small>
                                    @endif
                                </td>
                                <td>
                                    <template x-if="status[row.id] === 'dirty'">
                                        <span class="badge bg-warning-transparent text-warning fs-11">
                                            <i class="bi bi-pencil-fill"></i> غير محفوظ
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'saving'">
                                        <span class="badge bg-info-transparent text-info fs-11">
                                            <i class="bi bi-cloud-arrow-up"></i> حفظ...
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'saved'">
                                        <span class="badge bg-success-transparent text-success fs-11">
                                            <i class="bi bi-check2"></i> محفوظ
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'error'">
                                        <span class="badge bg-danger-transparent text-danger fs-11" :title="errors[row.id]">
                                            <i class="bi bi-x-circle"></i> فشل
                                        </span>
                                    </template>
                                    <template x-if="!status[row.id]">
                                        <span class="text-muted fs-11">—</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Cancel modal --}}
    @if($showingCancel)
        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5>إلغاء الجرد</h5>
                        <button type="button" class="btn-close" wire:click="hideCancel"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                        <textarea wire:model="cancelReason" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="hideCancel">تراجع</button>
                        <button class="btn btn-danger" wire:click="cancelCount">تأكيد الإلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<script>
window.stockCountWorkspace = function (config) {
    return {
        editable: config.editable,
        rows: config.rows,
        // Map of row.id → 'dirty' | 'saving' | 'saved' | 'error'
        status: {},
        errors: {},

        // Snapshot of original values to detect dirty rows
        original: {},

        init() {
            // Cache original values so onBlur can compare
            this.rows.forEach(r => {
                this.original[r.id] = {
                    counted_qty: r.counted_qty,
                    notes: r.notes,
                };
            });
            // x-model updates rows[].counted_qty as user types — mark as dirty
            this.$watch('rows', () => this.recomputeDirty(), { deep: true });
        },

        recomputeDirty() {
            this.rows.forEach(r => {
                const orig = this.original[r.id] || {};
                const isDirty = (
                    String(r.counted_qty ?? '') !== String(orig.counted_qty ?? '')
                    || String(r.notes ?? '') !== String(orig.notes ?? '')
                );
                // Don't override 'saving'/'saved'/'error' until user types again
                if (isDirty) {
                    if (this.status[r.id] !== 'saving') this.status[r.id] = 'dirty';
                } else if (this.status[r.id] === 'dirty') {
                    delete this.status[r.id];
                }
            });
        },

        // ─── Pure-display helpers (zero server traffic) ──────────────────
        variance(row) {
            if (row.counted_qty === null || row.counted_qty === '') return 0;
            return Math.round((Number(row.counted_qty) - row.system_qty) * 10000) / 10000;
        },
        cost(row) {
            return Math.round(this.variance(row) * row.cost_per_unit * 100) / 100;
        },
        state(row) {
            if (row.counted_qty === null || row.counted_qty === '') return 'pending';
            const v = this.variance(row);
            if (Math.abs(v) < 0.0001) return 'match';
            return v < 0 ? 'short' : 'over';
        },
        rowClass(row) {
            const s = this.state(row);
            return s === 'short' ? 'table-danger' : (s === 'over' ? 'table-warning' : '');
        },

        // ─── Aggregates (for stat rail) ─────────────────────────────────
        get counted() {
            return this.rows.filter(r => r.counted_qty !== null && r.counted_qty !== '').length;
        },
        get shortageCount() { return this.rows.filter(r => this.state(r) === 'short').length; },
        get overCount()     { return this.rows.filter(r => this.state(r) === 'over').length; },
        get totalCost()     {
            return this.rows.reduce((s, r) => s + this.cost(r), 0);
        },
        get hasDirty()      { return Object.values(this.status).includes('dirty'); },
        get dirtyCount()    { return Object.values(this.status).filter(s => s === 'dirty').length; },

        // ─── Persistence (one row at a time, on blur) ───────────────────
        async onBlur(idx) {
            if (! this.editable) return;
            const row = this.rows[idx];
            if (this.status[row.id] !== 'dirty') return; // nothing changed

            this.status[row.id] = 'saving';
            try {
                const res = await this.$wire.saveRow(row.id, row.counted_qty, row.notes);
                if (res?.ok) {
                    this.status[row.id] = 'saved';
                    this.original[row.id] = {
                        counted_qty: row.counted_qty,
                        notes: row.notes,
                    };
                    // Auto-clear "saved" badge after 3s
                    setTimeout(() => {
                        if (this.status[row.id] === 'saved') delete this.status[row.id];
                    }, 3000);
                } else {
                    this.status[row.id] = 'error';
                    this.errors[row.id] = res?.reason || 'فشل غير معروف';
                }
            } catch (e) {
                this.status[row.id] = 'error';
                this.errors[row.id] = e.message || 'تعذّر الاتصال';
            }
        },
    };
};
</script>
