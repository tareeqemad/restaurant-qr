@csrf
@php
    $ing = $ingredient ?? null;
    $isCreate = ! $ing;
    $branches = $branches ?? collect();
    $locationsByBranch = $locationsByBranch ?? [];
    $supplierIdsByBranch = $supplierIdsByBranch ?? [];
    $activeBranchId = $activeBranchId ?? null;
@endphp

@if($isCreate)
<div x-data='ingredientForm({
        branches: @json($branches->values()),
        locationsByBranch: @json((object) $locationsByBranch),
        supplierIdsByBranch: @json((object) $supplierIdsByBranch),
        allSuppliers: @json($suppliers->map(fn($s) => ["id" => (int) $s->id, "name" => $s->name])->values()),
        activeBranchId: @json($activeBranchId),
        initialBranchId: @json(old("branch_id")),
        initialLocationId: @json(old("storage_location_id")),
        initialSupplierId: @json(old("supplier_id", $ing?->supplier_id)),
        initialQty: @json(old("initial_quantity", 0)),
     })' x-init="init()">
@endif

<div class="row g-3">
    {{-- Identity ─────────────────────────────────────────────── --}}
    @if($ing)
        <div class="col-md-4">
            <label class="form-label">SKU</label>
            <input value="{{ $ing->sku }}" class="form-control bg-light"
                   readonly tabindex="-1"
                   title="رمز داخلي يُولَّد تلقائياً عند الإنشاء — للعرض فقط">
        </div>
        <div class="col-md-4"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name', $ing->name) }}" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $ing->name_en) }}" class="form-control"></div>
    @else
        <div class="col-md-6"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name') }}" class="form-control" required autofocus></div>
        <div class="col-md-6"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en') }}" class="form-control"></div>
    @endif

    {{-- Branch context — only on CREATE, drives BOTH supplier list and stock seed ── --}}
    @if($isCreate)
        <div class="col-12">
            <div style="background: linear-gradient(135deg, rgba(15,71,49,.04), rgba(15,71,49,.01)); border: 1px solid rgba(15,71,49,.12); border-radius: .6rem; padding: 1rem 1.1rem;">

                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                    <h5 class="mb-0">
                        <i class="bi bi-building text-accent me-1"></i>
                        فرع المكوّن وموقع تخزينه
                    </h5>
                    <small class="text-muted">يُحدِّد البيت الافتراضي للمكوّن في هذا الفرع. الكمية الأولية اختيارية.</small>
                </div>

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">
                            الفرع <span class="text-danger">*</span>
                        </label>
                        <select name="branch_id" x-model.number="branchId" @change="onBranchChange()"
                                :disabled="branches.length === 0"
                                class="form-select" required>
                            <option value="">— اختر —</option>
                            <template x-for="b in branches" :key="b.id">
                                <option :value="b.id" x-text="b.name"></option>
                            </template>
                        </select>
                        <template x-if="branches.length === 0">
                            <small class="text-danger d-block mt-1">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                لا تملك صلاحية على أي فرع.
                            </small>
                        </template>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            موقع التخزين <span class="text-danger">*</span>
                        </label>
                        <select name="storage_location_id" x-model.number="locationId"
                                :disabled="!branchId || locationsForBranch().length === 0"
                                class="form-select" required>
                            <option value="">— اختر —</option>
                            <template x-for="l in locationsForBranch()" :key="l.id">
                                <option :value="l.id" x-text="l.name + (l.is_default ? ' (افتراضي)' : '')"></option>
                            </template>
                        </select>
                        <template x-if="!branchId">
                            <small class="text-muted d-block mt-1">اختر الفرع أولاً لعرض المواقع.</small>
                        </template>
                        <template x-if="branchId && locationsForBranch().length === 0">
                            <small class="text-danger d-block mt-1">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                هذا الفرع بلا مواقع تخزين.
                                <a href="{{ route('admin.storage-locations.create') }}" target="_blank" class="alert-link">أنشئ موقعاً</a>.
                            </small>
                        </template>
                        <template x-if="branchId && locationsForBranch().length > 0">
                            <small class="text-muted d-block mt-1">
                                <i class="bi bi-cursor"></i>
                                <span x-text="locationsForBranch().length"></span> موقع متاح — يمكنك التغيير.
                            </small>
                        </template>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            الكمية الأولية
                            <small class="text-muted">(اختياري)</small>
                        </label>
                        <input type="number" step="0.0001" min="0" name="initial_quantity"
                               x-model.number="qty"
                               class="form-control text-end"
                               placeholder="0">
                        <small class="text-muted d-block mt-1">
                            اتركها 0 لو ستُضاف الكمية لاحقاً عبر أمر شراء.
                        </small>
                    </div>
                </div>

                {{-- Live summary — different message based on whether stock is being seeded --}}
                <template x-if="branchId && locationId && qty > 0">
                    <div class="mt-2 small text-muted">
                        <i class="bi bi-check-circle-fill text-success"></i>
                        ستُضاف <strong x-text="qty"></strong> وحدة إلى
                        <strong x-text="(branches.find(b => b.id === branchId) || {}).name"></strong>
                        — موقع
                        <strong x-text="(locationsForBranch().find(l => l.id === locationId) || {}).name"></strong>،
                        وتُسجَّل كحركة «إدخال» في سجل المخزون.
                    </div>
                </template>
                <template x-if="branchId && locationId && qty <= 0">
                    <div class="mt-2 small text-muted">
                        <i class="bi bi-bookmark-check-fill text-accent"></i>
                        سيُسجَّل المكوّن في
                        <strong x-text="(branches.find(b => b.id === branchId) || {}).name"></strong>
                        — موقع
                        <strong x-text="(locationsForBranch().find(l => l.id === locationId) || {}).name"></strong>
                        كمكان افتراضي. عند استلام أمر شراء، يُقترح هذا الموقع تلقائياً.
                    </div>
                </template>
            </div>
        </div>
    @endif

    {{-- Catalog details ────────────────────────────────────────── --}}
    <div class="col-md-4"><label class="form-label">الوحدة الأساسية *</label>
        <select name="base_unit_id" class="form-select" required>
            @foreach($units as $u)<option value="{{ $u->id }}" @selected(old('base_unit_id', $ing?->base_unit_id)==$u->id)>{{ $u->name }} ({{ $u->code }})</option>@endforeach
        </select></div>

    <div class="col-md-4"><label class="form-label">المورد</label>
        @if($isCreate)
            <select name="supplier_id" x-model.number="supplierId" class="form-select">
                <option value="">—</option>
                <template x-for="s in suppliersForBranch()" :key="s.id">
                    <option :value="s.id" x-text="s.name"></option>
                </template>
            </select>
            {{-- Hidden static options ensure form-validation can find the value
                 even before Alpine boots; suppliersForBranch handles the live UI. --}}
            <template x-if="branchId && suppliersForBranch().length === 0">
                <small class="text-warning d-block mt-1">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    لا يوجد موردون مرتبطون بهذا الفرع. اربط أحد الموردين به من
                    <a href="{{ route('admin.suppliers.index') }}" target="_blank" class="alert-link">شاشة الموردين</a>.
                </small>
            </template>
            <template x-if="!branchId">
                <small class="text-muted d-block mt-1">اختر فرعاً لتضييق قائمة الموردين.</small>
            </template>
        @else
            <select name="supplier_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مورد..."><option value="">—</option>
                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $ing?->supplier_id)==$s->id)>{{ $s->name }}</option>@endforeach
            </select>
        @endif
    </div>

    <div class="col-md-4">
        <label class="form-label">التكلفة/وحدة *</label>
        <input type="number" step="0.0001" name="cost_per_unit"
               value="{{ old('cost_per_unit', $ing?->cost_per_unit ?? 0) }}"
               class="form-control{{ $ing ? ' bg-light' : '' }}" required
               @if($ing) readonly tabindex="-1" title="تُدار آلياً كمتوسط مرجَّح من الاستلامات — للعرض فقط" @endif>
        @if($ing)
            <small class="text-muted d-block mt-1">
                متوسط مرجَّح يُحدَّث تلقائياً عند استلام المشتريات — لا يُعدَّل يدوياً.
            </small>
        @endif
    </div>

    <div class="col-md-4"><label class="form-label">حد الطلب *</label><input type="number" step="0.0001" name="reorder_threshold" value="{{ old('reorder_threshold', $ing?->reorder_threshold ?? 0) }}" class="form-control" required></div>

    <div class="col-md-4">
        <label class="form-label">
            نسبة الاستفادة %
            <small class="text-muted">(اختياري)</small>
        </label>
        <div class="input-group">
            <input type="number" step="0.01" min="0.01" max="100" name="yield_pct"
                   value="{{ old('yield_pct', $ing?->yield_pct) }}"
                   placeholder="100"
                   class="form-control">
            <span class="input-group-text">%</span>
        </div>
        <small class="text-muted d-block mt-1">
            لو من كل 1kg تجلب 700g صالح بعد التنظيف، اكتب 70. اتركه فاضي = استفادة 100%.
        </small>
    </div>

    <div class="col-md-8 d-flex align-items-end gap-3 flex-wrap">
        <div class="form-check"><input type="hidden" name="track_stock" value="0"><input type="checkbox" name="track_stock" value="1" class="form-check-input" @checked(old('track_stock', $ing?->track_stock ?? true))><label class="form-check-label">تتبع المخزون</label></div>
        <div class="form-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $ing?->active ?? true))><label class="form-check-label">فعال</label></div>
        <div class="form-check"
             title="مكوّن مركّب: مكوّن له وصفة فرعية يُحلَّل تلقائياً عند الخصم. مثال: صلصة طحينة = 200g طحينة + 100ml ماء + 1 ليمون → 280g.">
            <input type="hidden" name="is_composite" value="0">
            <input type="checkbox" name="is_composite" value="1" class="form-check-input" id="is_composite_check"
                   @checked(old('is_composite', $ing?->is_composite ?? false))>
            <label class="form-check-label" for="is_composite_check">
                <i class="bi bi-diagram-3"></i>
                مكوّن مركّب (له وصفة فرعية)
            </label>
        </div>
    </div>

    <div class="col-md-4">
        <label class="form-label">
            ناتج الوصفة الفرعية
            <small class="text-muted">(للمركّب فقط)</small>
        </label>
        <input type="number" step="0.0001" min="0.0001" name="composite_yield"
               value="{{ old('composite_yield', $ing?->composite_yield) }}"
               placeholder="مثلاً 280"
               class="form-control">
        <small class="text-muted d-block mt-1">
            الكمية الناتجة من تنفيذ الوصفة كاملة، بالوحدة الأساسية للمكوّن.
        </small>
    </div>

    @if(! $isCreate)
        <div class="col-md-4">
            <label class="form-label">المخزون الحالي</label>
            <input value="{{ rtrim(rtrim(number_format((float) $ing->trackedStock(), 4, '.', ''), '0'), '.') }}"
                   class="form-control bg-light" readonly tabindex="-1">
            <small class="text-muted d-block mt-1">
                لتعديل الكمية: استخدم زر «تسجيل حركة» على شاشة المكونات (إدخال/خصم/هدر/تعديل) — يحافظ على سجل التتبّع.
            </small>
        </div>
    @endif

    <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes', $ing?->notes) }}</textarea></div>
    <div class="col-12 form-actions"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.ingredients.index') }}" class="btn btn-light">إلغاء</a></div>
</div>

@if($isCreate)
</div> {{-- end x-data wrapper --}}

@push('scripts')
{{-- The admin layout only loads @livewireScripts when a page asks for it,
     and Livewire bundles Alpine — without this directive Alpine never
     boots and the x-data block stays inert. --}}
@livewireScripts
<script>
window.ingredientForm = function (config) {
    return {
        branches: config.branches,
        locationsByBranch: config.locationsByBranch,
        supplierIdsByBranch: config.supplierIdsByBranch,
        allSuppliers: config.allSuppliers || [],
        activeBranchId: config.activeBranchId,

        qty: Number(config.initialQty) || 0,
        branchId: Number(config.initialBranchId) || 0,
        locationId: Number(config.initialLocationId) || 0,
        supplierId: Number(config.initialSupplierId) || 0,

        init() {
            // Pre-select the active branch context if any, then pick its
            // default location — branch + location are now both required,
            // so a sensible default saves a click. The user can change
            // either dropdown at any time.
            if (! this.branchId && this.activeBranchId) {
                this.branchId = Number(this.activeBranchId);
            }
            this.$nextTick(() => {
                if (this.branchId && ! this.locationId) {
                    this.applyDefaultLocation();
                }
            });
        },

        locationsForBranch() {
            if (! this.branchId) return [];
            return this.locationsByBranch[this.branchId] || [];
        },

        applyDefaultLocation() {
            const locs = this.locationsForBranch();
            this.locationId = locs[0]?.id || 0;
        },

        // Suppliers narrowed to those serving the selected branch via the
        // branch_supplier pivot. With no branch picked, everything shows
        // (catalog mode for owner-level users seeding global ingredients).
        suppliersForBranch() {
            if (! this.branchId) return this.allSuppliers;
            const allowed = this.supplierIdsByBranch[this.branchId] || [];
            const allowedSet = new Set(allowed.map(Number));
            return this.allSuppliers.filter(s => allowedSet.has(Number(s.id)));
        },

        onBranchChange(initial = false) {
            // 1. Locations: pick the branch's default location if the current
            //    one no longer belongs here. Both fields are required now,
            //    so we always want a valid pick instead of an empty value.
            const locs = this.locationsForBranch();
            const stillValid = locs.some(l => l.id === this.locationId);
            if (! stillValid) {
                this.locationId = locs[0]?.id || 0;
            }
            // 2. Suppliers: if current supplier doesn't serve the new branch,
            //    blank it. Better than silently mismatching.
            if (this.supplierId) {
                const validSuppliers = this.suppliersForBranch();
                if (! validSuppliers.some(s => Number(s.id) === Number(this.supplierId))) {
                    this.supplierId = 0;
                }
            }
        },
    };
};
</script>
@endpush
@endif
