@extends('layouts.admin')
@section('title', 'تحويل بين الفروع')

@php
    use App\Models\Branch;
    use App\Models\Ingredient;
    use App\Models\StorageLocation;

    // Server seeds (used by Alpine for instant UI)
    $branches = Branch::where('is_active', true)
        ->orderBy('display_order')->orderBy('name')
        ->get(['id', 'name'])->toArray();

    $ingredients = Ingredient::with('baseUnit')
        ->orderBy('name')
        ->get()
        ->map(fn ($i) => [
            'id'        => $i->id,
            'name'      => $i->name,
            'unit_code' => $i->baseUnit?->code ?? '',
        ])
        ->toArray();

    // Locations grouped by branch — Alpine filters dropdowns based on
    // chosen from/to branches.
    $locationsByBranch = StorageLocation::where('active', true)
        ->orderBy('display_order')->orderBy('name')
        ->get(['id', 'name', 'branch_id'])
        ->groupBy('branch_id')
        ->map(fn ($group) => $group->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values())
        ->toArray();
@endphp

@section('content')
<x-admin.breadcrumb
    title="تحويل بين الفروع"
    icon="bi-arrow-left-right"
    subtitle="انقل المكونات بين فروع مختلفة. سيُحفظ كمسودة أولاً، ثم اضغط «إرسال» لخصم المخزون من المصدر." />

<div x-data='branchTransferForm({
        branches: @json($branches),
        ingredients: @json($ingredients),
        locationsByBranch: @json($locationsByBranch),
     })' x-init="init()">

    <form method="POST" action="{{ route('admin.branch-transfers.store') }}">
        @csrf

        {{-- Hidden inputs that Alpine populates on submit --}}
        <input type="hidden" name="from_branch_id" :value="fromBranchId">
        <input type="hidden" name="to_branch_id" :value="toBranchId">
        <input type="hidden" name="notes" :value="notes">

        <template x-for="(line, i) in lines" :key="i">
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
                        <select x-model.number="fromBranchId" class="form-select form-select-lg">
                            <option value="0">— اختر —</option>
                            <template x-for="b in branches" :key="b.id">
                                <option :value="b.id" x-text="b.name"></option>
                            </template>
                        </select>
                    </div>
                    <div class="col-md-2 text-center">
                        <i class="bi bi-arrow-left-circle-fill" style="font-size: 2rem; color: var(--accent);"></i>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">إلى فرع <span class="text-danger">*</span></label>
                        <select x-model.number="toBranchId" class="form-select form-select-lg">
                            <option value="0">— اختر —</option>
                            <template x-for="b in branches" :key="b.id">
                                <option :value="b.id" :disabled="b.id === fromBranchId" x-text="b.name + (b.id === fromBranchId ? ' (المصدر)' : '')"></option>
                            </template>
                        </select>
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
                <button type="button" @click="addLine()" class="btn btn-sm btn-light">
                    <i class="bi bi-plus-circle"></i> أضف بند
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th style="min-width: 220px;">المكوّن</th>
                                <th style="width: 130px;">الكمية</th>
                                <th>الوحدة</th>
                                <th style="min-width: 150px;">من موقع (اختياري)</th>
                                <th style="min-width: 150px;">إلى موقع (اختياري)</th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(line, i) in lines" :key="i">
                                <tr>
                                    <td>
                                        <select x-model.number="line.ingredient_id" class="form-select form-select-sm">
                                            <option value="0">— اختر —</option>
                                            <template x-for="ing in ingredients" :key="ing.id">
                                                <option :value="ing.id" x-text="ing.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.0001" min="0.0001"
                                               x-model.number="line.quantity_base"
                                               class="form-control form-control-sm text-end">
                                    </td>
                                    <td class="text-muted fs-13" x-text="ingredientUnit(line.ingredient_id)"></td>
                                    <td>
                                        <select x-model.number="line.from_location_id" class="form-select form-select-sm">
                                            <option value="">— غير محدد —</option>
                                            <template x-for="loc in locationsForBranch(fromBranchId)" :key="loc.id">
                                                <option :value="loc.id" x-text="loc.name"></option>
                                            </template>
                                        </select>
                                    </td>
                                    <td>
                                        <select x-model.number="line.to_location_id" class="form-select form-select-sm">
                                            <option value="">— غير محدد —</option>
                                            <template x-for="loc in locationsForBranch(toBranchId)" :key="loc.id">
                                                <option :value="loc.id" x-text="loc.name"></option>
                                            </template>
                                        </select>
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

        {{-- Actions --}}
        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('admin.branch-transfers.index') }}" class="btn btn-light">
                <i class="bi bi-arrow-right"></i> إلغاء
            </a>
            <button type="submit" :disabled="!canSubmit()" class="btn btn-primary">
                <i class="bi bi-save me-1"></i> حفظ كمسودة
            </button>
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
    return {
        branches: config.branches,
        ingredients: config.ingredients,
        locationsByBranch: config.locationsByBranch,

        fromBranchId: 0,
        toBranchId: 0,
        notes: '',
        lines: [],

        init() {
            this.addLine();
        },

        addLine() {
            this.lines.push({
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

        ingredientUnit(id) {
            return this.ingredients.find(i => i.id === id)?.unit_code || '';
        },

        locationsForBranch(branchId) {
            if (! branchId) return [];
            return this.locationsByBranch[branchId] || [];
        },

        canSubmit() {
            if (! this.fromBranchId || ! this.toBranchId) return false;
            if (this.fromBranchId === this.toBranchId) return false;
            return this.lines.some(l => l.ingredient_id && (Number(l.quantity_base) || 0) > 0);
        },
    };
};
</script>
@endpush
