@extends('layouts.admin')
@section('title', 'تحويل بين الفروع')

@php
    use App\Models\Branch;
    use App\Models\Ingredient;
    use App\Models\StorageLocation;
    use Illuminate\Support\Facades\DB;

    // Server seeds (used by Alpine for instant UI)
    $branches = Branch::where('is_active', true)
        ->orderBy('display_order')->orderBy('name')
        ->get(['id', 'name'])->toArray();

    $ingredients = Ingredient::with('baseUnit')
        ->orderBy('name')
        ->get()
        ->map(fn ($i) => [
            'id'                 => $i->id,
            'name'               => $i->name,
            'unit_code'          => $i->baseUnit?->code ?? '',
            'global_threshold'   => (float) ($i->reorder_threshold ?? 0),
            'global_cost'        => (float) ($i->cost_per_unit ?? 0),
        ])
        ->keyBy('id')
        ->toArray();

    // Locations grouped by branch — Alpine filters dropdowns based on
    // chosen from/to branches. Default location bubbles to the top so
    // Alpine can auto-pre-select it when the user picks a destination.
    $locationsByBranch = StorageLocation::where('active', true)
        ->orderByDesc('is_default')
        ->orderBy('display_order')->orderBy('name')
        ->get(['id', 'name', 'branch_id', 'is_default'])
        ->groupBy('branch_id')
        ->map(fn ($group) => $group->map(fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'is_default' => (bool) $l->is_default,
        ])->values())
        ->toArray();

    // Per-location stock + thresholds. We aggregate to branch level in JS,
    // but pass per-location too so the available-qty hint can switch when
    // the user picks a specific source location.
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

    $stockByBranch    = []; // branchId => ingId => qty
    $stockByLocation  = []; // locId => ingId => qty
    $thrByBranchSum   = []; // branchId => ingId => sum of per-loc thresholds (>0 only)
    $thrByLocation    = []; // locId => ingId => threshold (>0 only)
    foreach ($stockRows as $r) {
        $bid = (int) $r->branch_id;
        $lid = (int) $r->storage_location_id;
        $iid = (int) $r->ingredient_id;
        $qty = (float) $r->quantity;
        $thr = (float) ($r->reorder_threshold ?? 0);

        $stockByBranch[$bid][$iid]   = ($stockByBranch[$bid][$iid] ?? 0) + $qty;
        $stockByLocation[$lid][$iid] = $qty;

        if ($thr > 0) {
            $thrByBranchSum[$bid][$iid] = ($thrByBranchSum[$bid][$iid] ?? 0) + $thr;
            $thrByLocation[$lid][$iid]  = $thr;
        }
    }

    // Per-branch weighted-average cost from active batches (matches Ingredient::costAtBranch).
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

    $currency = config('app.currency_symbol', 'ر.س');
@endphp

@section('content')
<x-admin.breadcrumb
    title="تحويل بين الفروع"
    icon="bi-arrow-left-right"
    subtitle="انقل المكونات بين فروع مختلفة. سيُحفظ كمسودة أولاً، ثم اضغط «إرسال» لخصم المخزون من المصدر." />

<style>
    .bt-line-row { transition: background-color .15s ease; }
    .bt-line-row.is-error { background: rgba(220, 53, 69, .04); }
    .bt-line-row.is-warn  { background: rgba(255, 193, 7, .05); }
    .bt-stock-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .12rem .5rem; border-radius: 999px;
        font-size: 11.5px; font-weight: 600; letter-spacing: 0;
        line-height: 1.5;
    }
    .bt-stock-pill.ok    { background: rgba(25, 135, 84, .12);  color: #157347; }
    .bt-stock-pill.warn  { background: rgba(255, 193, 7, .18);  color: #997404; }
    .bt-stock-pill.error { background: rgba(220, 53, 69, .12);  color: #b02a37; }
    .bt-stock-pill.empty { background: rgba(108, 117, 125, .12); color: #6c757d; }
    .bt-line-msg {
        font-size: 12px; line-height: 1.4; margin-top: .25rem;
        display: flex; align-items: center; gap: .35rem;
    }
    .bt-line-msg.error { color: #b02a37; }
    .bt-line-msg.warn  { color: #997404; }
    .bt-summary {
        background: linear-gradient(135deg, rgba(15, 71, 49, .04), rgba(15, 71, 49, .01));
        border: 1px solid rgba(15, 71, 49, .08);
        border-radius: .5rem;
        padding: .85rem 1rem;
    }
    .bt-empty-state {
        padding: 2rem 1rem; text-align: center; color: #6c757d;
    }
    .bt-empty-state i { font-size: 2.25rem; opacity: .35; display: block; margin-bottom: .35rem; }
</style>

<div x-data='branchTransferForm({
        branches: @json($branches),
        ingredients: @json((object) $ingredients),
        locationsByBranch: @json((object) $locationsByBranch),
        stockByBranch: @json((object) $stockByBranch),
        stockByLocation: @json((object) $stockByLocation),
        thresholdByBranchSum: @json((object) $thrByBranchSum),
        thresholdByLocation: @json((object) $thrByLocation),
        costByBranch: @json((object) $costByBranch),
        currency: @json($currency),
     })' x-init="init()">

    <form method="POST" action="{{ route('admin.branch-transfers.store') }}">
        @csrf

        {{-- Hidden inputs that Alpine populates on submit --}}
        <input type="hidden" name="from_branch_id" :value="fromBranchId">
        <input type="hidden" name="to_branch_id" :value="toBranchId">
        <input type="hidden" name="notes" :value="notes">

        <template x-for="(line, i) in lines" :key="line._key">
            <div>
                <input type="hidden" :name="'lines['+i+'][ingredient_id]'" :value="line.ingredient_id">
                <input type="hidden" :name="'lines['+i+'][quantity_base]'" :value="line.quantity_base">
                <input type="hidden" :name="'lines['+i+'][from_location_id]'" :value="line.from_location_id || ''">
                <input type="hidden" :name="'lines['+i+'][to_location_id]'" :value="line.to_location_id || ''">
                <input type="hidden" :name="'lines['+i+'][notes]'" :value="line.notes || ''">
            </div>
        </template>

        {{-- Branches header --}}
        <div class="card custom-card mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0"><i class="bi bi-building text-accent me-1"></i> الفروع</h3>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label">من فرع <span class="text-danger">*</span></label>
                        <select x-model.number="fromBranchId" @change="onFromBranchChange()" class="form-select form-select-lg">
                            <option value="0">— اختر —</option>
                            <template x-for="b in branches" :key="b.id">
                                <option :value="b.id" x-text="b.name + (branchHasStock(b.id) ? '' : ' (لا مخزون)')"></option>
                            </template>
                        </select>
                        <div class="form-text" x-show="fromBranchId">
                            <i class="bi bi-box-seam text-muted me-1"></i>
                            <span x-text="ingredientsAtFromBranch().length"></span> مكوّن متاح في هذا الفرع
                        </div>
                    </div>
                    <div class="col-md-2 text-center">
                        <i class="bi bi-arrow-left-circle-fill" style="font-size: 2rem; color: var(--accent);"></i>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">إلى فرع <span class="text-danger">*</span></label>
                        <select x-model.number="toBranchId" @change="onToBranchChange()" class="form-select form-select-lg">
                            <option value="0">— اختر —</option>
                            <template x-for="b in branches" :key="b.id">
                                <option :value="b.id" :disabled="b.id === fromBranchId" x-text="b.name + (b.id === fromBranchId ? ' (المصدر)' : '')"></option>
                            </template>
                        </select>
                        <div class="form-text text-danger" x-show="toBranchId && locationsForBranch(toBranchId).length === 0">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            هذا الفرع لا يملك أي موقع تخزين. يجب
                            <a href="{{ route('admin.storage-locations.create') }}" class="alert-link" target="_blank">إنشاء موقع تخزين</a>
                            قبل استلام التحويل، وإلا لن يظهر المخزون في الوجهة.
                        </div>
                        <div class="form-text" x-show="toBranchId && locationsForBranch(toBranchId).length > 0">
                            <i class="bi bi-box-seam text-muted me-1"></i>
                            <span x-text="locationsForBranch(toBranchId).length"></span> موقع تخزين متاح
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">ملاحظات <small class="text-muted">(اختياري)</small></label>
                        <textarea x-model="notes" class="form-control" rows="2"
                                  placeholder="سبب التحويل، تعليمات للسائق، إلخ..."></textarea>
                    </div>
                </div>
            </div>
        </div>

        {{-- Lines --}}
        <div class="card custom-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="bi bi-list-ul text-accent me-1"></i> البنود
                    <span class="badge bg-primary-transparent ms-2" x-text="lines.length"></span>
                </h3>
                <button type="button" @click="addLine()" :disabled="!fromBranchId || ingredientsAtFromBranch().length === 0"
                        class="btn btn-sm btn-light">
                    <i class="bi bi-plus-circle"></i> أضف بند
                </button>
            </div>
            <div class="px-3 pt-2 pb-1 small text-muted" style="line-height: 1.55; background: rgba(15, 71, 49, .03); border-bottom: 1px solid rgba(15, 71, 49, .06);">
                <strong>«من مستودع»</strong> اختياري — لو حدّدته يُحسب المخزون المتاح من ذلك الموقع تحديداً.
                <strong>«إلى مستودع»</strong> مطلوب — اخترنا لك الموقع الافتراضي تلقائياً ويمكنك تغييره. بدون موقع وجهة، المخزون لن يظهر في الفرع المستلم.
                @if(empty($locationsByBranch))
                    <span class="d-block mt-1">
                        <i class="bi bi-info-circle"></i>
                        لم يتم إنشاء أي مواقع تخزين بعد. يمكنك إدارتها من
                        <a href="{{ route('admin.storage-locations.index') }}" class="alert-link">شاشة مواقع التخزين</a>.
                    </span>
                @endif
            </div>

            {{-- Empty states --}}
            <div class="card-body p-0">
                <template x-if="!fromBranchId">
                    <div class="bt-empty-state">
                        <i class="bi bi-building-down"></i>
                        اختر فرع المصدر أولاً لعرض المكونات المتاحة.
                    </div>
                </template>

                <template x-if="fromBranchId && ingredientsAtFromBranch().length === 0">
                    <div class="bt-empty-state">
                        <i class="bi bi-inbox"></i>
                        لا يوجد أي مكوّن بكميات قابلة للنقل في هذا الفرع.
                        <div class="small mt-1">يجب أن يكون لدى الفرع مخزون فعلي قبل إنشاء التحويل.</div>
                    </div>
                </template>

                <div class="table-responsive" x-show="fromBranchId && ingredientsAtFromBranch().length > 0">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="min-width: 260px;">المكوّن</th>
                                <th style="width: 140px;">الكمية</th>
                                <th>الوحدة</th>
                                <th style="min-width: 160px;" title="موقع التخزين داخل فرع المصدر. اختياري — لو حدّدته يُحسب المتاح من ذلك الموقع تحديداً.">
                                    من مستودع (اختياري)
                                    <i class="bi bi-info-circle text-muted small" style="cursor: help;"></i>
                                </th>
                                <th style="min-width: 160px;" title="موقع التخزين داخل فرع الوجهة. اختياري.">
                                    إلى مستودع (اختياري)
                                    <i class="bi bi-info-circle text-muted small" style="cursor: help;"></i>
                                </th>
                                <th style="width: 120px;" class="text-end">التكلفة التقديرية</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, i) in lines" :key="line._key">
                                <tr class="bt-line-row" :class="{ 'is-error': lineStatus(line) === 'error', 'is-warn': lineStatus(line) === 'warn' }">
                                    <td>
                                        <select x-model.number="line.ingredient_id" class="form-select form-select-sm">
                                            <option value="0">— اختر —</option>
                                            <template x-for="ing in ingredientsAtFromBranch()" :key="ing.id">
                                                <option :value="ing.id" x-text="ing.name + ' — متاح: ' + fmtQty(stockForIngredientAtSource(ing.id, null)) + ' ' + (ing.unit_code || '')"></option>
                                            </template>
                                        </select>

                                        {{-- per-line status messages --}}
                                        <template x-if="line.ingredient_id">
                                            <div>
                                                <div class="mt-1">
                                                    <span class="bt-stock-pill"
                                                          :class="{
                                                            'ok':    availableForLine(line) > 0 && (Number(line.quantity_base)||0) <= availableForLine(line) && !willGoBelowThreshold(line),
                                                            'warn':  willGoBelowThreshold(line) && (Number(line.quantity_base)||0) <= availableForLine(line),
                                                            'error': (Number(line.quantity_base)||0) > availableForLine(line),
                                                            'empty': availableForLine(line) <= 0
                                                          }">
                                                        <i class="bi bi-box"></i>
                                                        متاح: <span x-text="fmtQty(availableForLine(line))"></span>
                                                        <span x-text="ingredientUnit(line.ingredient_id)"></span>
                                                    </span>
                                                    <template x-if="(Number(line.quantity_base)||0) > 0 && (Number(line.quantity_base)||0) <= availableForLine(line)">
                                                        <span class="bt-stock-pill ms-1"
                                                              :class="willGoBelowThreshold(line) ? 'warn' : 'ok'">
                                                            <i class="bi bi-arrow-down-right"></i>
                                                            المتبقّي: <span x-text="fmtQty(remainingAfter(line))"></span>
                                                        </span>
                                                    </template>
                                                </div>

                                                {{-- error: insufficient --}}
                                                <template x-if="(Number(line.quantity_base)||0) > availableForLine(line) && availableForLine(line) > 0">
                                                    <div class="bt-line-msg error">
                                                        <i class="bi bi-exclamation-octagon-fill"></i>
                                                        <span>الكمية المطلوبة تتجاوز المتاح. الحد الأقصى:
                                                            <strong x-text="fmtQty(availableForLine(line))"></strong>
                                                            <span x-text="ingredientUnit(line.ingredient_id)"></span>.
                                                        </span>
                                                    </div>
                                                </template>
                                                <template x-if="availableForLine(line) <= 0">
                                                    <div class="bt-line-msg error">
                                                        <i class="bi bi-x-octagon-fill"></i>
                                                        <span>لا مخزون متاح من هذا المكوّن في
                                                            <span x-text="line.from_location_id ? 'الموقع المختار' : 'فرع المصدر'"></span>.
                                                        </span>
                                                    </div>
                                                </template>

                                                {{-- warn: will deplete or below threshold --}}
                                                <template x-if="(Number(line.quantity_base)||0) > 0 && (Number(line.quantity_base)||0) <= availableForLine(line) && willDeplete(line)">
                                                    <div class="bt-line-msg warn">
                                                        <i class="bi bi-droplet-half"></i>
                                                        <span>هذا التحويل سيُفرغ المخزون من هذا المكوّن في المصدر.</span>
                                                    </div>
                                                </template>
                                                <template x-if="(Number(line.quantity_base)||0) > 0 && (Number(line.quantity_base)||0) <= availableForLine(line) && willGoBelowThreshold(line) && !willDeplete(line)">
                                                    <div class="bt-line-msg warn">
                                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                                        <span>المتبقّي بعد التحويل سيكون تحت حد إعادة الطلب
                                                            (<span x-text="fmtQty(thresholdForLine(line))"></span>
                                                             <span x-text="ingredientUnit(line.ingredient_id)"></span>).
                                                        </span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </td>
                                    <td>
                                        <input type="number" step="0.0001" min="0.0001"
                                               x-model.number="line.quantity_base"
                                               :max="availableForLine(line) || ''"
                                               class="form-control form-control-sm text-end"
                                               :class="{ 'is-invalid': line.ingredient_id && (Number(line.quantity_base)||0) > availableForLine(line) }">
                                        <template x-if="line.ingredient_id && availableForLine(line) > 0 && (Number(line.quantity_base)||0) !== availableForLine(line)">
                                            <button type="button" class="btn btn-link btn-sm p-0 mt-1 small text-decoration-none"
                                                    @click="line.quantity_base = availableForLine(line)"
                                                    title="انقل كل المتاح">
                                                <i class="bi bi-magic"></i> انقل الكل (<span x-text="fmtQty(availableForLine(line))"></span>)
                                            </button>
                                        </template>
                                    </td>
                                    <td class="text-muted fs-13" x-text="ingredientUnit(line.ingredient_id)"></td>
                                    <td>
                                        <select x-model.number="line.from_location_id" class="form-select form-select-sm">
                                            <option value="">— أي موقع —</option>
                                            <template x-for="loc in locationsForBranch(fromBranchId)" :key="loc.id">
                                                <option :value="loc.id"
                                                        x-text="loc.name + (line.ingredient_id ? ' (' + fmtQty(stockForIngredientAtSource(line.ingredient_id, loc.id)) + ')' : '')"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <select x-model.number="line.to_location_id" class="form-select form-select-sm" :disabled="!toBranchId">
                                            <option value="">— أي موقع —</option>
                                            <template x-for="loc in locationsForBranch(toBranchId)" :key="loc.id">
                                                <option :value="loc.id" x-text="loc.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td class="text-end fs-13">
                                        <template x-if="line.ingredient_id && (Number(line.quantity_base)||0) > 0">
                                            <span>
                                                <span x-text="fmtMoney(lineCost(line))"></span>
                                                <small class="text-muted d-block" style="font-size: 10.5px;" x-text="currency"></small>
                                            </span>
                                        </template>
                                        <template x-if="!(line.ingredient_id && (Number(line.quantity_base)||0) > 0)">
                                            <span class="text-muted">—</span>
                                        </template>
                                    </td>
                                    <td>
                                        <button type="button" @click="removeLine(i)"
                                                class="btn btn-sm btn-outline-danger" title="حذف">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Summary + Actions --}}
        <div class="row g-3 align-items-stretch">
            <div class="col-md-7">
                <div class="bt-summary h-100" x-show="fromBranchId && validLineCount() > 0">
                    <div class="row g-2 small">
                        <div class="col-6 col-md-4">
                            <div class="text-muted">عدد البنود الصالحة</div>
                            <div class="fs-5 fw-semibold" x-text="validLineCount()"></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-muted">إجمالي الكمية</div>
                            <div class="fs-5 fw-semibold">
                                <span x-text="fmtQty(totalQty())"></span>
                                <small class="text-muted" style="font-size: 11px;">(وحدات قاعدية)</small>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-muted">القيمة التقديرية</div>
                            <div class="fs-5 fw-semibold">
                                <span x-text="fmtMoney(totalCost())"></span>
                                <small class="text-muted" style="font-size: 11px;" x-text="currency"></small>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 small text-muted" x-show="warningLineCount() > 0">
                        <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                        <span x-text="warningLineCount()"></span> بند سيُسبّب نقصاً تحت حد إعادة الطلب — راجع التنبيهات بالأعلى.
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="d-flex justify-content-end align-items-center gap-2 h-100">
                    <a href="{{ route('admin.branch-transfers.index') }}" class="btn btn-light">
                        <i class="bi bi-arrow-right"></i> إلغاء
                    </a>
                    <button type="submit" :disabled="!canSubmit()" class="btn btn-primary"
                            :title="submitBlockedReason() || ''">
                        <i class="bi bi-save me-1"></i> حفظ كمسودة
                    </button>
                </div>
            </div>
        </div>

        {{-- Block reason hint when disabled --}}
        <div class="text-end mt-2 small text-muted" x-show="submitBlockedReason()">
            <i class="bi bi-info-circle"></i>
            <span x-text="submitBlockedReason()"></span>
        </div>
    </form>
</div>
@endsection

@push('scripts')
{{-- The form is Alpine-driven (x-data/x-for/x-model). The admin layout
     loads @livewireScripts only when a page asks for it, and Livewire
     bundles Alpine — without this directive Alpine never initialises
     and the branch / location <select> templates render empty. --}}
@livewireScripts
<script>
window.branchTransferForm = function (config) {
    let _k = 0;
    const newKey = () => ++_k;

    return {
        branches: config.branches,
        // ingredients keyed by id object → keep both as map and as array
        ingredientsMap: config.ingredients,
        get ingredientsAll() {
            return Object.values(this.ingredientsMap).sort((a, b) => a.name.localeCompare(b.name, 'ar'));
        },
        locationsByBranch: config.locationsByBranch,
        stockByBranch: config.stockByBranch,
        stockByLocation: config.stockByLocation,
        thresholdByBranchSum: config.thresholdByBranchSum,
        thresholdByLocation: config.thresholdByLocation,
        costByBranch: config.costByBranch,
        currency: config.currency,

        fromBranchId: 0,
        toBranchId: 0,
        notes: '',
        lines: [],

        init() {
            this.addLine();
        },

        // ---- Branch / location helpers ----
        branchHasStock(branchId) {
            const map = this.stockByBranch[branchId] || {};
            return Object.values(map).some(q => Number(q) > 0);
        },

        locationsForBranch(branchId) {
            if (! branchId) return [];
            return this.locationsByBranch[branchId] || [];
        },

        // List of ingredients with stock > 0 at the from-branch.
        ingredientsAtFromBranch() {
            if (! this.fromBranchId) return [];
            const stockMap = this.stockByBranch[this.fromBranchId] || {};
            return this.ingredientsAll.filter(i => Number(stockMap[i.id] || 0) > 0);
        },

        ingredientUnit(id) {
            return this.ingredientsMap[id]?.unit_code || '';
        },

        // ---- Stock + threshold lookups ----
        stockForIngredientAtSource(ingredientId, locationId) {
            if (! ingredientId || ! this.fromBranchId) return 0;
            if (locationId) {
                return Number((this.stockByLocation[locationId] || {})[ingredientId] || 0);
            }
            return Number((this.stockByBranch[this.fromBranchId] || {})[ingredientId] || 0);
        },

        // Available stock for a specific line, narrowed by from_location_id when set.
        availableForLine(line) {
            return this.stockForIngredientAtSource(line.ingredient_id, line.from_location_id || null);
        },

        // Threshold to compare against the post-transfer remaining qty.
        thresholdForLine(line) {
            if (! line.ingredient_id || ! this.fromBranchId) return 0;
            // Per-location threshold takes precedence when location is set
            if (line.from_location_id) {
                const locThr = (this.thresholdByLocation[line.from_location_id] || {})[line.ingredient_id];
                if (locThr) return Number(locThr);
                // fallback to ingredient's global threshold
                return Number(this.ingredientsMap[line.ingredient_id]?.global_threshold || 0);
            }
            // Branch-level: sum of per-location thresholds, or global ingredient threshold if none set
            const branchSum = (this.thresholdByBranchSum[this.fromBranchId] || {})[line.ingredient_id];
            if (branchSum && Number(branchSum) > 0) return Number(branchSum);
            return Number(this.ingredientsMap[line.ingredient_id]?.global_threshold || 0);
        },

        remainingAfter(line) {
            return this.availableForLine(line) - (Number(line.quantity_base) || 0);
        },

        willDeplete(line) {
            return this.remainingAfter(line) <= 0.0001;
        },

        willGoBelowThreshold(line) {
            const thr = this.thresholdForLine(line);
            if (thr <= 0) return false;
            return this.remainingAfter(line) < thr;
        },

        // ---- Cost ----
        unitCostForIngredient(ingredientId) {
            if (! this.fromBranchId || ! ingredientId) return 0;
            const branchCost = (this.costByBranch[this.fromBranchId] || {})[ingredientId];
            if (branchCost && Number(branchCost) > 0) return Number(branchCost);
            return Number(this.ingredientsMap[ingredientId]?.global_cost || 0);
        },

        lineCost(line) {
            return this.unitCostForIngredient(line.ingredient_id) * (Number(line.quantity_base) || 0);
        },

        // ---- Line statuses (for row tinting) ----
        lineStatus(line) {
            if (! line.ingredient_id) return 'pending';
            const qty = Number(line.quantity_base) || 0;
            const avail = this.availableForLine(line);
            if (qty <= 0) return 'pending';
            if (qty > avail || avail <= 0) return 'error';
            if (this.willGoBelowThreshold(line)) return 'warn';
            return 'ok';
        },

        // ---- Line CRUD ----
        addLine() {
            this.lines.push({
                _key: newKey(),
                ingredient_id: 0,
                quantity_base: 1,
                from_location_id: '',
                to_location_id: '',
                notes: '',
            });
        },

        removeLine(idx) {
            this.lines.splice(idx, 1);
            if (this.lines.length === 0) this.addLine();
        },

        // ---- Branch change side effects ----
        onFromBranchChange() {
            // Drop ingredients that no longer have stock at the new source,
            // and reset locations (they're branch-bound).
            const validIds = new Set(this.ingredientsAtFromBranch().map(i => i.id));
            this.lines = this.lines.map(l => {
                const ingOk = l.ingredient_id && validIds.has(l.ingredient_id);
                return {
                    ...l,
                    ingredient_id:    ingOk ? l.ingredient_id : 0,
                    from_location_id: '',
                };
            });
            if (this.lines.length === 0) this.addLine();
        },

        onToBranchChange() {
            // Auto-pick the default destination location so stock has a place
            // to land. The list is server-sorted with is_default first.
            const defaultLoc = (this.locationsForBranch(this.toBranchId)[0] || {}).id || '';
            this.lines = this.lines.map(l => ({ ...l, to_location_id: defaultLoc }));
        },

        // ---- Aggregates ----
        validLineCount() {
            return this.lines.filter(l => this.lineStatus(l) === 'ok' || this.lineStatus(l) === 'warn').length;
        },
        warningLineCount() {
            return this.lines.filter(l => this.lineStatus(l) === 'warn').length;
        },
        errorLineCount() {
            return this.lines.filter(l => this.lineStatus(l) === 'error').length;
        },
        totalQty() {
            return this.lines.reduce((s, l) => s + (this.lineStatus(l) === 'ok' || this.lineStatus(l) === 'warn' ? (Number(l.quantity_base) || 0) : 0), 0);
        },
        totalCost() {
            return this.lines.reduce((s, l) => s + (this.lineStatus(l) === 'ok' || this.lineStatus(l) === 'warn' ? this.lineCost(l) : 0), 0);
        },

        // ---- Submit gating ----
        canSubmit() {
            if (! this.fromBranchId || ! this.toBranchId) return false;
            if (this.fromBranchId === this.toBranchId) return false;
            if (this.locationsForBranch(this.toBranchId).length === 0) return false;
            if (this.errorLineCount() > 0) return false;
            return this.validLineCount() > 0;
        },

        submitBlockedReason() {
            if (! this.fromBranchId)                       return 'اختر فرع المصدر.';
            if (! this.toBranchId)                         return 'اختر فرع الوجهة.';
            if (this.fromBranchId === this.toBranchId)     return 'فرع المصدر والوجهة لا يمكن أن يكونا نفس الفرع.';
            if (this.locationsForBranch(this.toBranchId).length === 0)
                return 'فرع الوجهة لا يحتوي على أي موقع تخزين — أنشئ موقعاً واحداً على الأقل أولاً.';
            if (this.ingredientsAtFromBranch().length === 0) return 'لا يوجد مخزون قابل للنقل في فرع المصدر.';
            if (this.errorLineCount() > 0)                 return 'هناك بنود تتجاوز المخزون المتاح — صحّح الكميات.';
            if (this.validLineCount() === 0)               return 'أضف بنداً واحداً صالحاً على الأقل.';
            return '';
        },

        // ---- Formatting ----
        fmtQty(v) {
            const n = Number(v) || 0;
            if (Math.abs(n - Math.round(n)) < 0.0001) return String(Math.round(n));
            return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        },
        fmtMoney(v) {
            const n = Number(v) || 0;
            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
};
</script>
@endpush
