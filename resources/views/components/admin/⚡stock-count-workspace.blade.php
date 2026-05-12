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
 *     (variance, cost, badges, totals, search/filter). Typing → instant UI
 *     update with ZERO server round-trip.
 *   - Livewire is only used to PERSIST (saveRow, finalize, cancel) and
 *     to seed initial state.
 *   - `seed` is the JSON payload Alpine reads at boot: a snapshot of
 *     items + cost-per-unit. Subsequent changes never need to re-fetch.
 *   - `saveRow($itemId, $qty, $notes)` is called by Alpine when a row
 *     loses focus. One row → one tiny request → one row updated.
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

    #[Computed(persist: false)]
    public function count(): StockCount
    {
        return StockCount::with('creator', 'finalizer')->findOrFail($this->countId);
    }

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
    $totalRows = count($this->seedRows);
    $currency = config('restaurant.currency_symbol', '₪');
@endphp

<div x-data='stockCountWorkspace({
        editable: @json($editable),
        rows: @json($this->seedRows),
        currency: @json($currency),
    })'
     x-init="init()">

    {{-- ═══ Stat rail (Alpine reactive) ═══ --}}
    <div class="row g-2 mb-2">
        <div class="col-md col-6">
            <div class="sc-stat sc-stat--primary">
                <i class="bi bi-list-check"></i>
                <div>
                    <small>تم عدّها</small>
                    <strong><span x-text="counted"></span> / {{ $totalRows }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="sc-stat sc-stat--neutral">
                <i class="bi bi-hourglass-split"></i>
                <div>
                    <small>لم تُعد بعد</small>
                    <strong x-text="pending"></strong>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="sc-stat sc-stat--ok">
                <i class="bi bi-check-circle-fill"></i>
                <div>
                    <small>مطابقة</small>
                    <strong x-text="matchCount"></strong>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="sc-stat sc-stat--down">
                <i class="bi bi-arrow-down-circle-fill"></i>
                <div>
                    <small>نقص</small>
                    <strong x-text="shortageCount"></strong>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="sc-stat sc-stat--up">
                <i class="bi bi-arrow-up-circle-fill"></i>
                <div>
                    <small>زيادة</small>
                    <strong x-text="overCount"></strong>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="sc-stat" :class="totalCost < 0 ? 'sc-stat--down' : (totalCost > 0 ? 'sc-stat--up' : 'sc-stat--ok')">
                <i class="bi bi-cash-coin"></i>
                <div>
                    <small>صافي قيمة الفروقات</small>
                    <strong x-text="(totalCost >= 0 ? '+' : '') + totalCost.toFixed(2) + ' {{ $currency }}'"></strong>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ Progress bar ═══ --}}
    <div class="sc-progress mb-3">
        <div class="sc-progress__head">
            <span><i class="bi bi-bar-chart-line-fill"></i> تقدّم الجرد</span>
            <span><strong x-text="progressPct"></strong>% <small class="text-muted">(<span x-text="counted"></span>/{{ $totalRows }})</small></span>
        </div>
        <div class="sc-progress__bar">
            <div class="sc-progress__fill" :style="`width: ${progressPct}%`"></div>
        </div>
    </div>

    {{-- ═══ Main panel ═══ --}}
    <div class="card custom-card sc-panel">
        <div class="card-header sc-panel__head">
            <h3 class="card-title mb-0">
                <i class="bi bi-list-check text-accent me-1"></i>
                بنود الجرد
            </h3>
            @if($editable)
                <div class="sc-actions">
                    <button type="button" class="btn btn-sm btn-light"
                            @click="matchAllPending()"
                            title="ضع الكمية الفعلية = كمية النظام لكل المكونات اللي لم تُعد بعد"
                            :disabled="pending === 0">
                        <i class="bi bi-magic me-1"></i>
                        وضع الكل مطابق <span x-show="pending > 0">(<span x-text="pending"></span>)</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary"
                            @click="$wire.saveAll(rows)"
                            :disabled="!hasDirty">
                        <i class="bi bi-save me-1"></i>
                        <span x-text="hasDirty ? 'حفظ ' + dirtyCount + ' تعديل' : 'لا تعديلات معلّقة'"></span>
                    </button>
                    @can('finalize', $count)
                    <button type="button" class="btn btn-sm btn-success"
                            @click="confirmFinalize()">
                        <i class="bi bi-check-circle-fill me-1"></i> اعتماد الجرد
                    </button>
                    @endcan
                    @can('cancel', $count)
                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="showCancel">
                        <i class="bi bi-x-circle me-1"></i> إلغاء الجرد
                    </button>
                    @endcan
                </div>
            @endif
        </div>

        {{-- Filters & search --}}
        <div class="sc-filters">
            <div class="sc-filters__search">
                <i class="bi bi-search"></i>
                <input type="text" x-model="search" placeholder="ابحث باسم المكوّن..." class="form-control form-control-sm">
                <button type="button" x-show="search" @click="search = ''" class="sc-clear" title="مسح البحث"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="sc-filters__chips">
                <button type="button" class="sc-chip" :class="{ 'is-active': filter === 'all' }" @click="filter = 'all'">
                    الكل <span class="sc-chip__num" x-text="rows.length"></span>
                </button>
                <button type="button" class="sc-chip sc-chip--neutral" :class="{ 'is-active': filter === 'pending' }" @click="filter = 'pending'">
                    لم تُعد <span class="sc-chip__num" x-text="pending"></span>
                </button>
                <button type="button" class="sc-chip sc-chip--ok" :class="{ 'is-active': filter === 'match' }" @click="filter = 'match'">
                    مطابقة <span class="sc-chip__num" x-text="matchCount"></span>
                </button>
                <button type="button" class="sc-chip sc-chip--down" :class="{ 'is-active': filter === 'short' }" @click="filter = 'short'">
                    نقص <span class="sc-chip__num" x-text="shortageCount"></span>
                </button>
                <button type="button" class="sc-chip sc-chip--up" :class="{ 'is-active': filter === 'over' }" @click="filter = 'over'">
                    زيادة <span class="sc-chip__num" x-text="overCount"></span>
                </button>
                <button type="button" class="sc-chip sc-chip--variance" :class="{ 'is-active': filter === 'variance' }" @click="filter = 'variance'">
                    <i class="bi bi-funnel-fill"></i> فيها فروقات <span class="sc-chip__num" x-text="shortageCount + overCount"></span>
                </button>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 sc-table">
                    <thead>
                        <tr>
                            <th>المكوّن</th>
                            <th>الوحدة</th>
                            <th class="text-end" title="الكمية المتوقعة حسب سجلات النظام">
                                كمية النظام <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th style="width:160px;" title="الكمية اللي عددتها فعلياً في المخزن">
                                الكمية الفعلية <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="الفعلي − النظام">
                                الفرق <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="متوسط تكلفة شراء الوحدة الواحدة من المكوّن">
                                تكلفة الوحدة <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th class="text-end" title="الفرق × تكلفة الوحدة">
                                قيمة الفرق <i class="bi bi-info-circle text-muted small"></i>
                            </th>
                            <th>ملاحظات</th>
                            <th style="width:110px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, idx) in visibleRows" :key="row.id">
                            <tr :class="rowClass(row)">
                                <td class="fw-semibold" x-text="row.name"></td>
                                <td class="text-muted fs-13" x-text="row.unit"></td>
                                <td class="text-end" x-text="fmtQty(row.system_qty)"></td>
                                <td>
                                    @if($editable)
                                        <input type="number" step="0.0001" min="0"
                                               x-model.number="row.counted_qty"
                                               @blur="onBlur(realIdx(row))"
                                               class="form-control form-control-sm text-end"
                                               placeholder="—">
                                    @else
                                        <span x-text="row.counted_qty === null ? '—' : fmtQty(row.counted_qty)"></span>
                                    @endif
                                </td>
                                <td class="text-end fw-bold">
                                    <template x-if="state(row) === 'pending'"><span class="text-muted">—</span></template>
                                    <template x-if="state(row) === 'match'"><i class="bi bi-check-circle-fill text-success" title="مطابق"></i></template>
                                    <template x-if="state(row) === 'short' || state(row) === 'over'">
                                        <span :class="'text-' + (state(row) === 'short' ? 'danger' : 'warning')"
                                              x-text="(variance(row) > 0 ? '+' : '') + fmtQty(variance(row))"></span>
                                    </template>
                                </td>
                                <td class="text-end text-muted sc-unit-cost">
                                    <span x-text="row.cost_per_unit.toFixed(2) + ' {{ $currency }}'"></span>
                                </td>
                                <td class="text-end fw-bold">
                                    <template x-if="state(row) === 'pending'"><span class="text-muted">—</span></template>
                                    <template x-if="state(row) !== 'pending'">
                                        <span :class="'text-' + (cost(row) < 0 ? 'danger' : (cost(row) > 0 ? 'success' : 'muted'))"
                                              x-text="(cost(row) >= 0 ? '+' : '') + cost(row).toFixed(2) + ' {{ $currency }}'"></span>
                                    </template>
                                </td>
                                <td>
                                    @if($editable)
                                        <input type="text"
                                               x-model="row.notes"
                                               @blur="onBlur(realIdx(row))"
                                               class="form-control form-control-sm"
                                               placeholder="...">
                                    @else
                                        <small class="text-muted" x-text="row.notes"></small>
                                    @endif
                                </td>
                                <td>
                                    <template x-if="status[row.id] === 'dirty'">
                                        <span class="sc-badge sc-badge--dirty" title="غير محفوظ — انقر خارج الحقل لحفظه">
                                            <i class="bi bi-pencil-fill"></i> غير محفوظ
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'saving'">
                                        <span class="sc-badge sc-badge--saving">
                                            <i class="bi bi-cloud-arrow-up"></i> حفظ...
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'saved'">
                                        <span class="sc-badge sc-badge--saved">
                                            <i class="bi bi-check2"></i> محفوظ
                                        </span>
                                    </template>
                                    <template x-if="status[row.id] === 'error'">
                                        <span class="sc-badge sc-badge--error" :title="errors[row.id]">
                                            <i class="bi bi-x-circle"></i> فشل
                                        </span>
                                    </template>
                                    <template x-if="!status[row.id]">
                                        <span class="text-muted fs-11">—</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                        <template x-if="visibleRows.length === 0">
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-22 d-block mb-2"></i>
                                    لا توجد بنود تطابق الفلتر الحالي
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
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger-transparent">
                        <h5 class="text-danger mb-0"><i class="bi bi-x-octagon-fill me-1"></i> إلغاء الجرد</h5>
                        <button type="button" class="btn-close" wire:click="hideCancel"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">سيتم إلغاء هذا الجرد ولن تُطبَّق أي تسوية على المخزون. الإلغاء لا يُحذف الجرد — يُحفظ للأرشيف بسبب الإلغاء.</p>
                        <label class="form-label fw-bold">سبب الإلغاء <span class="text-danger">*</span></label>
                        <textarea wire:model="cancelReason" class="form-control" rows="3" required
                                  placeholder="مثلاً: خطأ بالتاريخ — سيُعاد عمل جرد بتاريخ صحيح"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" wire:click="hideCancel">تراجع</button>
                        <button class="btn btn-danger" wire:click="cancelCount">
                            <i class="bi bi-trash me-1"></i> تأكيد الإلغاء
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('styles')
<style>
    /* ─── Stat cards ───────────────────────────────────────────── */
    .sc-stat {
        display: flex; align-items: center; gap: .85rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-inline-start: 4px solid #94a3b8;
        border-radius: 12px;
        padding: .65rem .85rem;
        height: 100%;
        transition: transform .15s, box-shadow .15s;
    }
    .sc-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 14px -6px rgba(15,23,42,.15); }
    .sc-stat i { font-size: 1.35rem; color: #94a3b8; }
    .sc-stat small { display: block; font-size: .7rem; color: #64748b; font-weight: 600; }
    .sc-stat strong { font-size: 1.05rem; font-weight: 900; color: #0f172a; }
    .sc-stat--primary  { border-inline-start-color: #16a34a; }
    .sc-stat--primary i { color: #16a34a; }
    .sc-stat--ok       { border-inline-start-color: #22c55e; }
    .sc-stat--ok i     { color: #22c55e; }
    .sc-stat--down     { border-inline-start-color: #ef4444; }
    .sc-stat--down i   { color: #ef4444; }
    .sc-stat--down strong { color: #b91c1c; }
    .sc-stat--up       { border-inline-start-color: #f59e0b; }
    .sc-stat--up i     { color: #f59e0b; }
    .sc-stat--up strong { color: #b45309; }
    .sc-stat--neutral  { border-inline-start-color: #64748b; }

    /* ─── Progress ─────────────────────────────────────────────── */
    .sc-progress {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: .65rem .85rem;
    }
    .sc-progress__head {
        display: flex; align-items: center; justify-content: space-between;
        font-size: .82rem; font-weight: 700; color: #475569;
        margin-bottom: .4rem;
    }
    .sc-progress__head i { color: #16a34a; margin-inline-end: .25rem; }
    .sc-progress__bar {
        background: #f1f5f9;
        border-radius: 99px;
        overflow: hidden;
        height: 8px;
    }
    .sc-progress__fill {
        background: linear-gradient(90deg, #16a34a, #22c55e);
        height: 100%;
        transition: width .3s ease-out;
    }

    /* ─── Filters ──────────────────────────────────────────────── */
    .sc-filters {
        display: flex; align-items: center; flex-wrap: wrap; gap: .75rem;
        padding: .65rem 1rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    .sc-filters__search {
        position: relative; flex: 1; min-width: 220px; max-width: 360px;
    }
    .sc-filters__search i { position: absolute; top: 50%; transform: translateY(-50%); inset-inline-end: 10px; color: #94a3b8; }
    .sc-filters__search input { padding-inline-end: 32px; }
    .sc-clear {
        position: absolute; top: 50%; transform: translateY(-50%); inset-inline-start: 6px;
        border: 0; background: transparent; color: #94a3b8; padding: 0 6px; cursor: pointer;
    }
    .sc-clear:hover { color: #475569; }

    .sc-filters__chips { display: flex; flex-wrap: wrap; gap: .35rem; }
    .sc-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 99px;
        padding: .3rem .75rem;
        font-size: .78rem; font-weight: 700; color: #475569;
        cursor: pointer;
        transition: all .15s;
    }
    .sc-chip:hover { background: #f1f5f9; }
    .sc-chip__num {
        background: #f1f5f9;
        color: #475569;
        padding: 0 6px; border-radius: 99px;
        font-size: .7rem; font-weight: 800;
    }
    .sc-chip.is-active { background: #0f4731; color: #fff; border-color: #0f4731; }
    .sc-chip.is-active .sc-chip__num { background: rgba(255,255,255,.2); color: #fff; }
    .sc-chip--ok.is-active   { background: #16a34a; border-color: #16a34a; }
    .sc-chip--down.is-active { background: #dc2626; border-color: #dc2626; }
    .sc-chip--up.is-active   { background: #d97706; border-color: #d97706; }
    .sc-chip--neutral.is-active { background: #475569; border-color: #475569; }
    .sc-chip--variance.is-active { background: #0f4731; border-color: #0f4731; }

    /* ─── Panel & actions ──────────────────────────────────────── */
    .sc-panel { border-radius: 14px; overflow: hidden; }
    .sc-panel__head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; }
    .sc-actions { display: flex; flex-wrap: wrap; gap: .35rem; }

    /* ─── Table ────────────────────────────────────────────────── */
    .sc-table thead th { background: #f8fafc; color: #475569; font-weight: 700; font-size: .82rem; }
    .sc-table .sc-unit-cost { font-size: .82rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .sc-table tbody tr.table-danger  { background: rgba(254, 226, 226, .55); }
    .sc-table tbody tr.table-warning { background: rgba(254, 243, 199, .55); }

    /* ─── Status badges ────────────────────────────────────────── */
    .sc-badge {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: 2px 8px; border-radius: 99px;
        font-size: .7rem; font-weight: 700;
    }
    .sc-badge--dirty  { background: #fef3c7; color: #b45309; }
    .sc-badge--saving { background: #dbeafe; color: #1d4ed8; }
    .sc-badge--saved  { background: #d1fae5; color: #065f46; }
    .sc-badge--error  { background: #fee2e2; color: #b91c1c; }

    @media (max-width: 768px) {
        .sc-filters { flex-direction: column; align-items: stretch; }
        .sc-filters__search { max-width: 100%; }
        .sc-actions .btn { font-size: .75rem; padding: .25rem .55rem; }
    }
</style>
@endpush

@script
<script>
window.stockCountWorkspace = function (config) {
    return {
        editable: config.editable,
        rows: config.rows,
        currency: config.currency || '₪',
        // filter UI state
        search: '',
        filter: 'all', // all | pending | match | short | over | variance
        // Map of row.id → 'dirty' | 'saving' | 'saved' | 'error'
        status: {},
        errors: {},
        original: {},

        init() {
            this.rows.forEach(r => {
                this.original[r.id] = {
                    counted_qty: r.counted_qty,
                    notes: r.notes,
                };
            });
            this.$watch('rows', () => this.recomputeDirty(), { deep: true });
        },

        recomputeDirty() {
            this.rows.forEach(r => {
                const orig = this.original[r.id] || {};
                const isDirty = (
                    String(r.counted_qty ?? '') !== String(orig.counted_qty ?? '')
                    || String(r.notes ?? '') !== String(orig.notes ?? '')
                );
                if (isDirty) {
                    if (this.status[r.id] !== 'saving') this.status[r.id] = 'dirty';
                } else if (this.status[r.id] === 'dirty') {
                    delete this.status[r.id];
                }
            });
        },

        // ─── Display helpers ────────────────────────────────────────────
        fmtQty(v) {
            if (v === null || v === undefined || v === '') return '—';
            const n = Number(v);
            if (Number.isInteger(n)) return n.toString();
            return n.toFixed(4).replace(/\.?0+$/, '');
        },
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

        // ─── Aggregates ────────────────────────────────────────────────
        get counted()       { return this.rows.filter(r => r.counted_qty !== null && r.counted_qty !== '').length; },
        get pending()       { return this.rows.length - this.counted; },
        get matchCount()    { return this.rows.filter(r => this.state(r) === 'match').length; },
        get shortageCount() { return this.rows.filter(r => this.state(r) === 'short').length; },
        get overCount()     { return this.rows.filter(r => this.state(r) === 'over').length; },
        get totalCost()     { return this.rows.reduce((s, r) => s + this.cost(r), 0); },
        get hasDirty()      { return Object.values(this.status).includes('dirty'); },
        get dirtyCount()    { return Object.values(this.status).filter(s => s === 'dirty').length; },
        get progressPct()   {
            if (this.rows.length === 0) return 0;
            return Math.round((this.counted / this.rows.length) * 100);
        },

        // ─── Filtering ─────────────────────────────────────────────────
        get visibleRows() {
            const q = this.search.trim().toLowerCase();
            return this.rows.filter(r => {
                if (q && !r.name.toLowerCase().includes(q)) return false;
                if (this.filter === 'all') return true;
                const s = this.state(r);
                if (this.filter === 'variance') return s === 'short' || s === 'over';
                return s === this.filter;
            });
        },
        // Map a visible row back to its index in this.rows (for onBlur)
        realIdx(row) { return this.rows.findIndex(r => r.id === row.id); },

        // ─── Bulk action: match all pending ────────────────────────────
        matchAllPending() {
            if (! this.editable) return;
            const pending = this.rows.filter(r => r.counted_qty === null || r.counted_qty === '');
            if (pending.length === 0) return;
            if (! window.confirmAction) {
                if (! confirm(`سيتم وضع الكمية الفعلية = كمية النظام لـ ${pending.length} مكوّن. متابعة؟`)) return;
                this._doMatchAll(pending);
                return;
            }
            window.confirmAction({
                title: 'مطابقة المتبقي',
                text: `سيتم وضع الكمية الفعلية = كمية النظام لـ ${pending.length} مكوّن. هذه الطريقة سريعة لو عددت كل شي ولا فيه فروقات.`,
                icon: 'question',
                confirmText: 'نعم، طابِق الكل',
            }).then(ok => {
                if (ok) this._doMatchAll(pending);
            });
        },
        _doMatchAll(pending) {
            pending.forEach(r => { r.counted_qty = r.system_qty; });
            this.$nextTick(() => this.$wire.saveAll(this.rows));
        },

        // ─── Finalize confirmation ─────────────────────────────────────
        confirmFinalize() {
            const pending = this.pending;
            const variances = this.shortageCount + this.overCount;
            const cost = this.totalCost;
            const sign = cost >= 0 ? '+' : '';
            const costFmt = `${sign}${cost.toFixed(2)} ${this.currency}`;

            const summary = `
                <div style="text-align:start; line-height:1.8; font-size:.92rem">
                    <div><b>تم عدّها:</b> ${this.counted} من ${this.rows.length}</div>
                    ${pending > 0 ? `<div style="color:#b45309"><b>لم تُعد بعد:</b> ${pending} (لن تتأثر بالاعتماد)</div>` : ''}
                    <div style="color:#065f46"><b>مطابقة:</b> ${this.matchCount}</div>
                    <div style="color:#b91c1c"><b>نقص:</b> ${this.shortageCount}</div>
                    <div style="color:#b45309"><b>زيادة:</b> ${this.overCount}</div>
                    <hr style="margin:.5rem 0">
                    <div><b>عدد التسويات:</b> ${variances}</div>
                    <div><b>صافي قيمة التسوية:</b> <span style="color:${cost < 0 ? '#b91c1c' : '#065f46'}">${costFmt}</span></div>
                </div>
                <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.6rem .8rem;margin-top:.85rem;font-size:.82rem;color:#78350f">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    سيُطبَّق التعديل على المخزون الفعلي ولا يمكن التراجع. تأكد من الفروقات أعلاه.
                </div>
            `;

            if (! window.Swal) {
                if (confirm('سيتم تطبيق التسويات على المخزون. متابعة؟')) {
                    this.$wire.finalize(this.rows);
                }
                return;
            }

            window.Swal.fire({
                title: 'اعتماد الجرد',
                html: summary,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'نعم، اعتمد الجرد',
                cancelButtonText: 'إلغاء',
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
                focusCancel: true,
                width: 480,
            }).then(res => {
                if (res.isConfirmed) this.$wire.finalize(this.rows);
            });
        },

        // ─── Persistence (one row at a time, on blur) ───────────────────
        async onBlur(idx) {
            if (! this.editable) return;
            if (idx < 0) return;
            const row = this.rows[idx];
            if (this.status[row.id] !== 'dirty') return;

            this.status[row.id] = 'saving';
            try {
                const res = await this.$wire.saveRow(row.id, row.counted_qty, row.notes);
                if (res?.ok) {
                    this.status[row.id] = 'saved';
                    this.original[row.id] = {
                        counted_qty: row.counted_qty,
                        notes: row.notes,
                    };
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
@endscript
