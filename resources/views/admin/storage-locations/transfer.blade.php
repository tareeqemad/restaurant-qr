@extends('layouts.admin')
@section('title', 'نقل مخزون بين المواقع')

@section('content')
<x-admin.breadcrumb title="نقل بين المواقع" icon="bi-arrow-left-right"
    subtitle="انقل كمية من مكوّن من موقع تخزين إلى آخر — تُسجَّل حركتا «خصم» و«إدخال» في السجل"
    :crumbs="[['label' => 'مواقع التخزين', 'url' => route('admin.storage-locations.index')]]" />

<style>
    .lt-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .15rem .55rem; border-radius: 999px;
        font-size: 11.5px; font-weight: 600; line-height: 1.5;
    }
    .lt-pill.ok    { background: rgba(25, 135, 84, .12);  color: #157347; }
    .lt-pill.warn  { background: rgba(255, 193, 7, .18);  color: #997404; }
    .lt-pill.error { background: rgba(220, 53, 69, .12);  color: #b02a37; }
    .lt-pill.empty { background: rgba(108, 117, 125, .12); color: #6c757d; }

    .lt-arrow {
        font-size: 2.2rem;
        color: var(--accent);
    }

    .lt-loc-card {
        background: rgba(15, 71, 49, .03);
        border: 1px solid rgba(15, 71, 49, .08);
        border-radius: 10px;
        padding: 1rem;
    }
    .lt-loc-card .label {
        font-size: 12px; color: #6c757d; font-weight: 600; margin-bottom: .35rem;
    }

    .lt-msg {
        font-size: 12.5px; line-height: 1.45;
        margin-top: .35rem;
        display: flex; align-items: flex-start; gap: .35rem;
    }
    .lt-msg.error { color: #b02a37; }
    .lt-msg.warn  { color: #997404; }
</style>

@php
    // Pre-compute the JSON payloads up here — Blade chokes on PHP short-fn
    // arrows inside `@json()` interpolated into an x-data attribute string.
    $locationsJson = $locations->map(fn ($l) => [
        'id' => (int) $l->id,
        'name' => $l->name,
        'branch_id' => (int) $l->branch_id,
        'is_default' => (bool) $l->is_default,
        'branch' => $l->branch ? ['id' => (int) $l->branch->id, 'name' => $l->branch->name] : null,
    ])->values();

    $ingredientsJson = $ingredients->map(fn ($i) => [
        'id' => (int) $i->id,
        'name' => $i->name,
        'unit_code' => $i->baseUnit?->code ?? '',
        'global_threshold' => (float) ($i->reorder_threshold ?? 0),
    ])->values();
@endphp

<div x-data='locationTransferForm({
        locations: @json($locationsJson),
        ingredients: @json($ingredientsJson),
        stockByLocation: @json((object) $stockByLocation),
     })' x-init="init()">

    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="border-radius: .5rem;">
            <i class="bi bi-check-circle-fill"></i>
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius: .5rem;">
            <i class="bi bi-exclamation-octagon-fill"></i>
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger mb-3" style="border-radius: .5rem;">
            <ul class="mb-0">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.storage-locations.transfer-store') }}">
        @csrf

        {{-- Source / Destination --}}
        <div class="card custom-card mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    <i class="bi bi-geo-alt-fill text-accent me-1"></i> المواقع
                </h3>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-stretch">
                    <div class="col-md-5">
                        <div class="lt-loc-card h-100">
                            <div class="label"><i class="bi bi-box-arrow-up"></i> من موقع <span class="text-danger">*</span></div>
                            <select name="from_id" x-model.number="fromId" @change="onFromChange()"
                                    class="form-select form-select-lg" required>
                                <option value="">— اختر —</option>
                                <template x-for="loc in locations" :key="loc.id">
                                    <option :value="loc.id"
                                            x-text="loc.name + ' — ' + (loc.branch?.name || '')"></option>
                                </template>
                            </select>
                            <template x-if="fromId">
                                <div class="mt-2 small text-muted">
                                    <i class="bi bi-box-seam"></i>
                                    <span x-text="ingredientsAtSource().length"></span> مكوّن متاح في هذا الموقع
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                        <i class="bi bi-arrow-left-circle-fill lt-arrow"></i>
                    </div>

                    <div class="col-md-5">
                        <div class="lt-loc-card h-100">
                            <div class="label"><i class="bi bi-box-arrow-in-down"></i> إلى موقع <span class="text-danger">*</span></div>
                            <select name="to_id" x-model.number="toId"
                                    class="form-select form-select-lg" required>
                                <option value="">— اختر —</option>
                                <template x-for="loc in locations" :key="loc.id">
                                    <option :value="loc.id" :disabled="loc.id === fromId"
                                            x-text="loc.name + ' — ' + (loc.branch?.name || '') + (loc.id === fromId ? ' (المصدر)' : '')"></option>
                                </template>
                            </select>
                            <template x-if="toId && fromId && fromBranchId() !== toBranchId()">
                                <div class="mt-2 small text-warning">
                                    <i class="bi bi-info-circle-fill"></i>
                                    هذا نقل عبر فرعين. الأنسب استخدام
                                    <a href="{{ route('admin.branch-transfers.create') }}" class="alert-link">«تحويل بين الفروع»</a>
                                    للحصول على دورة كاملة (إرسال → استلام).
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Ingredient + Quantity --}}
        <div class="card custom-card mb-3">
            <div class="card-header">
                <h3 class="card-title mb-0">
                    <i class="bi bi-basket2-fill text-accent me-1"></i> المكوّن والكمية
                </h3>
            </div>

            {{-- Empty state when no source picked --}}
            <template x-if="!fromId">
                <div class="card-body text-center py-4 text-muted">
                    <i class="bi bi-arrow-up-circle" style="font-size: 2rem; opacity: .35;"></i>
                    <div class="mt-2">اختر موقع المصدر أولاً لعرض المكونات المتاحة.</div>
                </div>
            </template>

            <template x-if="fromId && ingredientsAtSource().length === 0">
                <div class="card-body text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 2rem; opacity: .35;"></i>
                    <div class="mt-2">لا توجد مكونات بكميات قابلة للنقل في هذا الموقع.</div>
                </div>
            </template>

            <div class="card-body" x-show="fromId && ingredientsAtSource().length > 0">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">المكوّن <span class="text-danger">*</span></label>
                        <select name="ingredient_id" x-model.number="ingredientId" class="form-select" required>
                            <option value="">— اختر —</option>
                            <template x-for="ing in ingredientsAtSource()" :key="ing.id">
                                <option :value="ing.id"
                                        x-text="ing.name + ' — متاح: ' + fmtQty(stockAt(fromId, ing.id)) + ' ' + ing.unit_code"></option>
                            </template>
                        </select>
                        <template x-if="ingredientId">
                            <div class="mt-2">
                                <span class="lt-pill ok">
                                    <i class="bi bi-box"></i>
                                    متاح في المصدر:
                                    <strong x-text="fmtQty(availableAtSource())"></strong>
                                    <span x-text="selectedIngredient()?.unit_code"></span>
                                </span>
                                <template x-if="toId && stockAt(toId, ingredientId) > 0">
                                    <span class="lt-pill ok ms-1">
                                        <i class="bi bi-arrow-down-right"></i>
                                        موجود في الوجهة:
                                        <strong x-text="fmtQty(stockAt(toId, ingredientId))"></strong>
                                        <span x-text="selectedIngredient()?.unit_code"></span>
                                    </span>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">الكمية <span class="text-danger">*</span></label>
                        <input type="number" step="0.0001" min="0.0001"
                               name="quantity" x-model.number="quantity"
                               :max="availableAtSource() || ''"
                               class="form-control text-end"
                               :class="{ 'is-invalid': ingredientId && (Number(quantity)||0) > availableAtSource() }"
                               required>
                        <template x-if="ingredientId && availableAtSource() > 0">
                            <button type="button" class="btn btn-link btn-sm p-0 mt-1 small text-decoration-none"
                                    @click="quantity = availableAtSource()">
                                <i class="bi bi-magic"></i> نقل الكل (<span x-text="fmtQty(availableAtSource())"></span>)
                            </button>
                        </template>
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="w-100">
                            <div class="small text-muted">الوحدة</div>
                            <div class="fs-5 fw-semibold" x-text="selectedIngredient()?.unit_code || '—'"></div>
                        </div>
                    </div>

                    <div class="col-12">
                        {{-- Inline status messages --}}
                        <template x-if="ingredientId && (Number(quantity)||0) > availableAtSource()">
                            <div class="lt-msg error">
                                <i class="bi bi-exclamation-octagon-fill mt-1"></i>
                                <span>الكمية المطلوبة تتجاوز المتاح. الحد الأقصى:
                                    <strong x-text="fmtQty(availableAtSource())"></strong>
                                    <span x-text="selectedIngredient()?.unit_code"></span>.</span>
                            </div>
                        </template>
                        <template x-if="ingredientId && (Number(quantity)||0) > 0 && (Number(quantity)||0) <= availableAtSource() && willGoBelowThreshold()">
                            <div class="lt-msg warn">
                                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                                <span>المتبقّي بعد النقل سيكون تحت حد إعادة الطلب
                                    (<span x-text="fmtQty(selectedIngredient()?.global_threshold || 0)"></span>
                                     <span x-text="selectedIngredient()?.unit_code"></span>).</span>
                            </div>
                        </template>
                    </div>

                    <div class="col-12">
                        <label class="form-label">السبب <small class="text-muted">(اختياري)</small></label>
                        <input type="text" name="reason" x-model="reason" maxlength="500"
                               class="form-control"
                               placeholder="مثلاً: نفاد البار من الزيت، إعادة توزيع، إلخ...">
                    </div>
                </div>
            </div>
        </div>

        {{-- Live preview + Submit --}}
        <div class="row g-3 align-items-stretch">
            <div class="col-md-7">
                <template x-if="fromId && toId && ingredientId && (Number(quantity)||0) > 0">
                    <div class="lt-loc-card h-100">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="text-muted small">معاينة الحركة</div>
                                <div class="fs-6">
                                    <strong x-text="fmtQty(quantity)"></strong>
                                    <span x-text="selectedIngredient()?.unit_code"></span>
                                    من
                                    <strong x-text="locationName(fromId)"></strong>
                                    →
                                    <strong x-text="locationName(toId)"></strong>
                                </div>
                                <div class="small text-muted mt-1">
                                    الرصيد بعد النقل في المصدر:
                                    <strong x-text="fmtQty(availableAtSource() - (Number(quantity)||0))"></strong>
                                    /
                                    في الوجهة:
                                    <strong x-text="fmtQty(stockAt(toId, ingredientId) + (Number(quantity)||0))"></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div class="col-md-5">
                <div class="d-flex justify-content-end gap-2 h-100 align-items-end">
                    <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light">
                        <i class="bi bi-arrow-right"></i> إلغاء
                    </a>
                    <button type="submit" :disabled="!canSubmit()" class="btn btn-primary"
                            :title="submitBlockedReason()">
                        <i class="bi bi-arrow-left-right me-1"></i> نقل المخزون
                    </button>
                </div>
            </div>
        </div>

        <div class="text-end mt-2 small text-muted" x-show="submitBlockedReason()">
            <i class="bi bi-info-circle"></i>
            <span x-text="submitBlockedReason()"></span>
        </div>
    </form>
</div>
@endsection

@push('scripts')
{{-- Alpine ships with Livewire; this directive boots both. --}}
@livewireScripts
<script>
window.locationTransferForm = function (config) {
    return {
        locations: config.locations,
        ingredients: config.ingredients,
        stockByLocation: config.stockByLocation,

        fromId: 0,
        toId: 0,
        ingredientId: 0,
        quantity: 0,
        reason: '',

        init() {},

        // ---- Lookups ----
        stockAt(locId, ingId) {
            if (! locId || ! ingId) return 0;
            return Number((this.stockByLocation[locId] || {})[ingId] || 0);
        },
        availableAtSource() {
            return this.stockAt(this.fromId, this.ingredientId);
        },
        ingredientsAtSource() {
            if (! this.fromId) return [];
            const stockMap = this.stockByLocation[this.fromId] || {};
            return this.ingredients.filter(i => Number(stockMap[i.id] || 0) > 0);
        },
        selectedIngredient() {
            return this.ingredients.find(i => i.id === this.ingredientId) || null;
        },
        locationName(id) {
            return this.locations.find(l => l.id === id)?.name || '';
        },
        fromBranchId() {
            return this.locations.find(l => l.id === this.fromId)?.branch_id || 0;
        },
        toBranchId() {
            return this.locations.find(l => l.id === this.toId)?.branch_id || 0;
        },

        willGoBelowThreshold() {
            const ing = this.selectedIngredient();
            if (! ing || ! ing.global_threshold) return false;
            const remaining = this.availableAtSource() - (Number(this.quantity) || 0);
            return remaining < ing.global_threshold;
        },

        // ---- Source change side effects ----
        onFromChange() {
            // Drop the chosen ingredient if it's no longer available at the new source.
            if (this.ingredientId) {
                const validIds = new Set(this.ingredientsAtSource().map(i => i.id));
                if (! validIds.has(this.ingredientId)) {
                    this.ingredientId = 0;
                    this.quantity = 0;
                }
            }
            // Clear destination if it's the same as the new source.
            if (this.toId && this.toId === this.fromId) {
                this.toId = 0;
            }
        },

        // ---- Submit gating ----
        canSubmit() {
            if (! this.fromId || ! this.toId) return false;
            if (this.fromId === this.toId) return false;
            if (! this.ingredientId) return false;
            const qty = Number(this.quantity) || 0;
            if (qty <= 0) return false;
            if (qty > this.availableAtSource()) return false;
            return true;
        },
        submitBlockedReason() {
            if (! this.fromId)        return 'اختر موقع المصدر.';
            if (! this.toId)          return 'اختر موقع الوجهة.';
            if (this.fromId === this.toId) return 'المصدر والوجهة لا يمكن أن يكونا نفس الموقع.';
            if (! this.ingredientId)  return 'اختر المكوّن.';
            const qty = Number(this.quantity) || 0;
            if (qty <= 0)             return 'أدخل كمية أكبر من صفر.';
            if (qty > this.availableAtSource()) return 'الكمية تتجاوز المتاح في المصدر.';
            return '';
        },

        // ---- Formatting ----
        fmtQty(v) {
            const n = Number(v) || 0;
            if (Math.abs(n - Math.round(n)) < 0.0001) return String(Math.round(n));
            return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        },
    };
};
</script>
@endpush
