@csrf
@php $item = $item ?? null; @endphp

{{-- Validation summary — surfaces every error (including per-recipe-row unit
     problems) at the top so a rejected save never looks like it "did nothing".
     Each recipe error also renders inline next to its row below. --}}
@if ($errors->any())
    <div class="alert alert-danger" role="alert" style="border-radius:10px;">
        <div class="fw-bold mb-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            تعذّر حفظ الصنف — صحّح الأخطاء التالية:
        </div>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<style>
    /* ── Section card ─────────────────────────────────── */
    .mi-section {
        background: #fff;
        border: 1px solid rgba(15, 71, 49, .1);
        border-radius: 12px;
        margin-bottom: 1.1rem;
        overflow: hidden;
    }
    .mi-section-head {
        display: flex; align-items: center; gap: .55rem;
        padding: .85rem 1.1rem;
        background: linear-gradient(135deg, rgba(15, 71, 49, .04), rgba(15, 71, 49, .01));
        border-bottom: 1px solid rgba(15, 71, 49, .08);
    }
    .mi-section-head i { color: var(--accent); font-size: 1.05rem; }
    .mi-section-head .title {
        font-weight: 700; font-size: 1rem; color: var(--primary);
        flex-grow: 1;
    }
    .mi-section-head .hint {
        font-size: 12px; color: #6c757d; font-weight: 500;
    }
    .mi-section-body { padding: 1.1rem; }

    /* ── Toggle cards ─────────────────────────────────── */
    .mi-toggle {
        display: flex; align-items: flex-start; gap: .65rem;
        background: #fafbfa;
        border: 1px solid rgba(15, 71, 49, .1);
        border-radius: 10px;
        padding: .75rem .9rem;
        cursor: pointer;
        transition: all .15s ease;
    }
    .mi-toggle:hover { border-color: rgba(15, 71, 49, .25); }
    .mi-toggle:has(input:checked) {
        background: rgba(15, 71, 49, .04);
        border-color: rgba(15, 71, 49, .25);
    }
    .mi-toggle input[type=checkbox] {
        width: 18px; height: 18px;
        margin: 0; flex-shrink: 0; margin-top: 2px;
    }
    .mi-toggle .title { font-weight: 700; font-size: 14px; color: var(--primary); }
    .mi-toggle .desc { font-size: 12px; color: #6c757d; margin-top: 2px; line-height: 1.45; }

    /* ── Chip select (allergens / modifiers) ──────────── */
    .mi-chips {
        display: flex; flex-wrap: wrap; gap: .45rem;
        padding: .75rem;
        background: #fafbfa;
        border: 1px dashed rgba(15, 71, 49, .15);
        border-radius: 10px;
        min-height: 60px;
    }
    .mi-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .42rem .8rem;
        background: #fff;
        border: 1px solid rgba(15, 71, 49, .15);
        border-radius: 999px;
        font-size: 13px;
        cursor: pointer;
        transition: all .15s ease;
        user-select: none;
    }
    .mi-chip input { display: none; }
    .mi-chip:hover {
        border-color: var(--primary);
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(15, 71, 49, .08);
    }
    .mi-chip:has(input:checked) {
        background: var(--primary);
        color: #fff;
        border-color: var(--primary);
    }
    .mi-chips-empty {
        color: #9ca3af; font-size: 13px;
        padding: .35rem .15rem;
    }

    /* ── Modifier group cards ─────────────────────────── */
    .mi-mod-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: .65rem;
    }
    .mi-mod-card {
        position: relative;
        background: #fff;
        border: 1.5px solid rgba(15, 71, 49, .12);
        border-radius: 10px;
        padding: .8rem .9rem;
        cursor: pointer;
        transition: all .15s ease;
        display: flex; flex-direction: column;
        min-height: 110px;
    }
    .mi-mod-card input { display: none; }
    .mi-mod-card:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(15, 71, 49, .08);
    }
    .mi-mod-card:has(input:checked) {
        background: rgba(15, 71, 49, .04);
        border-color: var(--primary);
        border-width: 2px;
        padding: calc(.8rem - .5px) calc(.9rem - .5px);
    }
    .mi-mod-card:has(input:checked)::after {
        content: '\f26a'; /* bi-check-circle-fill */
        font-family: bootstrap-icons;
        position: absolute;
        top: .5rem;
        inset-inline-start: .5rem;
        color: var(--primary);
        font-size: 1.1rem;
    }
    .mi-mod-head {
        display: flex; align-items: center; gap: .35rem;
        font-weight: 700;
        color: var(--primary);
        font-size: 14px;
        margin-bottom: .15rem;
    }
    .mi-mod-rule {
        font-size: 11.5px;
        color: #6c757d;
        margin-bottom: .55rem;
        display: flex; align-items: center; gap: .25rem;
    }
    .mi-mod-rule.required { color: #b02a37; }
    .mi-mod-list {
        display: flex; flex-direction: column; gap: .25rem;
        font-size: 12.5px;
        margin-top: auto;
    }
    .mi-mod-item {
        display: flex; align-items: center; justify-content: space-between;
        gap: .5rem;
        padding: .25rem .5rem;
        background: rgba(15, 71, 49, .03);
        border-radius: 6px;
        line-height: 1.4;
    }
    .mi-mod-item .price {
        font-variant-numeric: tabular-nums;
        font-weight: 600;
        color: var(--accent);
        font-size: 11.5px;
    }
    .mi-mod-item .price.zero { color: #6c757d; font-weight: 400; }
    .mi-mod-empty-list {
        font-size: 11.5px;
        color: #b02a37;
        background: rgba(220, 53, 69, .06);
        padding: .35rem .5rem;
        border-radius: 6px;
        margin-top: auto;
    }

    /* ── Image upload ─────────────────────────────────── */
    .mi-image-upload {
        display: flex; align-items: center; gap: 1rem;
        padding: .9rem;
        background: #fafbfa;
        border: 1px dashed rgba(15, 71, 49, .2);
        border-radius: 10px;
    }
    .mi-image-preview {
        width: 110px; height: 110px;
        border-radius: 10px;
        background: #f1f3f4;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        overflow: hidden;
        border: 1px solid rgba(15, 71, 49, .08);
    }
    .mi-image-preview img { width: 100%; height: 100%; object-fit: cover; }
    .mi-image-preview .placeholder {
        color: #9ca3af; text-align: center; font-size: 12px;
    }
    .mi-image-preview .placeholder i { font-size: 1.6rem; display: block; margin-bottom: 4px; }
    .mi-image-controls { flex-grow: 1; min-width: 0; }

    /* ── Recipe rows ──────────────────────────────────── */
    .mi-recipe-list { display: flex; flex-direction: column; gap: .55rem; margin-bottom: .8rem; }
    .mi-recipe-row {
        display: grid;
        grid-template-columns: minmax(0, 5fr) minmax(0, 2fr) minmax(0, 2fr) auto;
        gap: .5rem;
        align-items: center;
        padding: .55rem;
        background: #fafbfa;
        border: 1px solid rgba(15, 71, 49, .08);
        border-radius: 10px;
    }
    @media (max-width: 768px) {
        .mi-recipe-row {
            grid-template-columns: 1fr 1fr;
        }
    }
    /* A recipe row rejected by validation (e.g. incompatible unit) */
    .mi-recipe-row.has-error {
        border-color: #dc3545;
        background: #fdf3f4;
    }
    .mi-recipe-row-error {
        color: #b02a37;
        font-size: 12.5px;
        font-weight: 600;
        margin: -.25rem .15rem .15rem;
        display: flex;
        align-items: center;
        gap: .3rem;
    }
    /* Read-only unit — fixed by the chosen ingredient's base unit. Looks like a
       field but isn't editable, so the user just reads it (e.g. غرام). */
    .mi-unit-fixed {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: calc(1.5em + .5rem + 2px);
        padding: .25rem .5rem;
        background: #eef2f0;
        border: 1px solid rgba(15, 71, 49, .12);
        border-radius: 8px;
        color: #2f4f3f;
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap;
    }
    /* The ingredient picker is a searchable Choices.js dropdown. Its panel is
       absolutely positioned, so the section's `overflow:hidden` (rounded card)
       was clipping it — the list looked cut off. Let the recipe row/section
       overflow visibly and float the panel above with a tall, scrollable list. */
    .mi-section--recipe,
    .mi-section--recipe .mi-section-body,
    #recipe-wrap,
    .mi-recipe-row { overflow: visible; }
    .mi-recipe-row .choices { margin-bottom: 0; }
    .mi-recipe-row .choices__list--dropdown,
    .mi-recipe-row .choices__list[aria-expanded] {
        z-index: 50;
    }
    .mi-recipe-row .choices__list--dropdown .choices__list {
        max-height: 260px;     /* show several options instead of a clipped sliver */
        overflow-y: auto;
    }
    .mi-recipe-row .choices__item {
        font-size: 13.5px;
        padding: .5rem .75rem;  /* readable, comfortably tappable rows */
        white-space: normal;
    }
    .mi-add-recipe {
        display: inline-flex; align-items: center; gap: .35rem;
        background: rgba(15, 71, 49, .04);
        color: var(--primary);
        border: 1px dashed rgba(15, 71, 49, .25);
        border-radius: 10px;
        padding: .55rem 1rem;
        font-weight: 600;
        font-size: 13.5px;
        transition: all .15s ease;
    }
    .mi-add-recipe:hover {
        background: rgba(15, 71, 49, .08);
        border-color: var(--primary);
    }

    /* ── Form actions ─────────────────────────────────── */
    .mi-actions {
        display: flex; justify-content: flex-end; gap: .5rem;
        padding: 1rem;
        background: #fafbfa;
        border: 1px solid rgba(15, 71, 49, .08);
        border-radius: 12px;
    }

    .req { color: #dc3545; }
</style>

{{-- 1. Identity ─────────────────────────────────────── --}}
<div class="mi-section">
    <div class="mi-section-head">
        <i class="bi bi-info-circle-fill"></i>
        <span class="title">المعلومات الأساسية</span>
        <span class="hint">اسم الصنف وتصنيفه</span>
    </div>
    <div class="mi-section-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">اسم الصنف <span class="req">*</span></label>
                <input name="name" value="{{ old('name', $item->name ?? '') }}" class="form-control form-control-lg" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">الاسم بالإنجليزية</label>
                <input name="name_en" value="{{ old('name_en', $item->name_en ?? '') }}" class="form-control form-control-lg" dir="ltr">
            </div>
            <div class="col-md-4">
                <label class="form-label">القسم <span class="req">*</span></label>
                @php
                    // Disabled+selected placeholder: without it the browser
                    // silently pre-selects the first category and `required`
                    // never fires — a wrong non-choice the user never made.
                    $selectedCategory = old('category_id', $item->category_id ?? '');
                @endphp
                <select name="category_id" class="form-select" required data-relax-choice data-choice-search-placeholder="ابحث عن قسم...">
                    <option value="" disabled @selected($selectedCategory === '' || $selectedCategory === null)>— اختر القسم —</option>
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected($selectedCategory==$c->id)>{{ $c->name }}</option>@endforeach
                </select>
                @if($categories->isEmpty())
                    <small class="text-danger d-block mt-1" style="font-size: 11.5px;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        لا توجد أقسام بعد — <a href="{{ route('admin.categories.create') }}" target="_blank" class="alert-link">أنشئ أول قسم</a> ثم عُد لحفظ الصنف.
                    </small>
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label">المحطة <small class="text-muted">(اختياري)</small></label>
                @php $selectedStation = old('station_id', $item->station_id ?? ''); @endphp
                <select name="station_id" class="form-select">
                    <option value="">— الافتراضية من القسم —</option>
                    @foreach($stations as $s)<option value="{{ $s->id }}" @selected($selectedStation==$s->id)>{{ $s->name }}</option>@endforeach
                </select>
                @if($selectedStation === '' || $selectedStation === null)
                    <small class="text-muted d-block mt-1" style="font-size: 11.5px;">
                        <i class="bi bi-display"></i>
                        بدون محطة يرث الصنف محطة القسم الافتراضية؛ وإن لم توجد، يظهر على الشاشة الرئيسية (المطبخ) موسوماً «بدون محطة».
                    </small>
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label">SKU <small class="text-muted">(اختياري)</small></label>
                <input name="sku" value="{{ old('sku', $item->sku ?? '') }}" class="form-control" dir="ltr" placeholder="MI-001">
            </div>
            <div class="col-md-6">
                <label class="form-label">الوصف</label>
                <textarea name="description" class="form-control" rows="2" placeholder="وصف مختصر يظهر للزبون">{{ old('description', $item->description ?? '') }}</textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">الوصف بالإنجليزية</label>
                <textarea name="description_en" class="form-control" rows="2" dir="ltr">{{ old('description_en', $item->description_en ?? '') }}</textarea>
            </div>
        </div>
    </div>
</div>

{{-- 2. Pricing & timing ─────────────────────────────── --}}
<div class="mi-section">
    <div class="mi-section-head">
        <i class="bi bi-cash-coin"></i>
        <span class="title">السعر والتحضير</span>
        <span class="hint">السعر المعروض للزبون والوقت اللازم</span>
    </div>
    <div class="mi-section-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">السعر <span class="req">*</span></label>
                <div class="input-group input-group-lg">
                    <input type="number" step="0.01" name="price" value="{{ old('price', $item->price ?? 0) }}" class="form-control" required>
                    <span class="input-group-text" style="background: rgba(184, 135, 42, .1); color: #8a6920; font-weight: 800;">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">وقت التحضير</label>
                <div class="input-group">
                    <input type="number" min="0" name="prep_time_minutes" value="{{ old('prep_time_minutes', $item->prep_time_minutes ?? 10) }}" class="form-control">
                    <span class="input-group-text"><i class="bi bi-clock"></i> دقيقة</span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">السعرات الحرارية</label>
                <div class="input-group">
                    <input type="number" min="0" name="calories" value="{{ old('calories', $item->calories ?? '') }}" class="form-control" placeholder="مثلاً 350">
                    <span class="input-group-text">kcal</span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">ترتيب العرض</label>
                <input type="number" name="display_order" value="{{ old('display_order', $item->display_order ?? 0) }}" class="form-control" placeholder="0">
                <small class="text-muted d-block mt-1" style="font-size: 11.5px;">الأرقام الأصغر تظهر أولاً</small>
            </div>
        </div>
    </div>
</div>

{{-- 3. Image ───────────────────────────────────────── --}}
<div class="mi-section">
    <div class="mi-section-head">
        <i class="bi bi-image-fill"></i>
        <span class="title">صورة الصنف</span>
        <span class="hint">JPG / PNG — حد أقصى 5MB</span>
    </div>
    <div class="mi-section-body">
        <div class="mi-image-upload">
            <div class="mi-image-preview">
                @if($item?->image)
                    <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}">
                @else
                    <div class="placeholder">
                        <i class="bi bi-image"></i>
                        <small>لا توجد صورة</small>
                    </div>
                @endif
            </div>
            <div class="mi-image-controls">
                <input type="file" name="image" class="form-control" accept="image/*">
                <small class="text-muted d-block mt-2">
                    <i class="bi bi-info-circle"></i>
                    اختر صورة عمودية أو مربعة لأفضل عرض. تُصغَّر تلقائياً إلى مقاس مناسب.
                </small>
            </div>
        </div>
    </div>
</div>

{{-- 4. Visibility flags ─────────────────────────────── --}}
<div class="mi-section">
    <div class="mi-section-head">
        <i class="bi bi-toggle-on"></i>
        <span class="title">إعدادات العرض</span>
        <span class="hint">تحكَّم متى وكيف يظهر الصنف للزبون</span>
    </div>
    <div class="mi-section-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="mi-toggle mb-0">
                    <input type="hidden" name="is_available" value="0">
                    <input type="checkbox" name="is_available" value="1" @checked(old('is_available', $item?->is_available ?? true))>
                    <div>
                        <div class="title"><i class="bi bi-check-circle-fill text-success"></i> متوفر للطلب</div>
                        <div class="desc">إذا كان الصنف غير متوفر، يبقى مخفياً في قائمة الزبون.</div>
                    </div>
                </label>
            </div>
            <div class="col-md-6">
                <label class="mi-toggle mb-0">
                    <input type="hidden" name="is_featured" value="0">
                    <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $item?->is_featured ?? false))>
                    <div>
                        <div class="title"><i class="bi bi-star-fill" style="color: #b8872a;"></i> مميز اليوم</div>
                        <div class="desc">يظهر بشارة ذهبية وفي قسم «المميز» أعلى القائمة.</div>
                    </div>
                </label>
            </div>
        </div>
    </div>
</div>

{{-- 5. Allergens & Modifiers ────────────────────────── --}}
<div class="mi-section">
    <div class="mi-section-head">
        <i class="bi bi-shield-exclamation"></i>
        <span class="title">الحساسية والإضافات</span>
        <span class="hint">معلومات للزبون وخيارات تخصيص الصنف</span>
    </div>
    <div class="mi-section-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>مسببات الحساسية</span>
                    <small class="text-muted" style="font-size: 11.5px;">انقر للتحديد</small>
                </label>
                @php $selectedAllergens = old('allergens', optional($item)->allergens?->pluck('id')->toArray() ?? []); @endphp
                <div class="mi-chips">
                    @forelse($allergens as $a)
                        <label class="mi-chip">
                            <input type="checkbox" name="allergens[]" value="{{ $a->id }}" @checked(in_array($a->id, $selectedAllergens))>
                            <span>{{ $a->icon }}</span>
                            <span>{{ $a->name }}</span>
                        </label>
                    @empty
                        <span class="mi-chips-empty">لم يتم إعداد أي مسببات حساسية بعد.</span>
                    @endforelse
                </div>
                <small class="text-muted d-block mt-1" style="font-size: 11.5px;">
                    <i class="bi bi-info-circle"></i>
                    اختر فقط المسببات الموجودة فعلاً في الصنف — تظهر للزبون كتنبيه.
                </small>
            </div>
            <div class="col-md-6">
                <label class="form-label d-flex justify-content-between align-items-center">
                    <span>مجموعات الإضافات</span>
                    <small class="text-muted" style="font-size: 11.5px;">حجم، إضافات، تخصيصات</small>
                </label>
                @php $selectedMods = old('modifier_groups', optional($item)->modifierGroups?->pluck('id')->toArray() ?? []); @endphp
                <div class="mi-chips">
                    @forelse($modifierGroups as $g)
                        <label class="mi-chip">
                            <input type="checkbox" name="modifier_groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $selectedMods))>
                            <i class="bi bi-sliders2"></i>
                            <span>{{ $g->name }}</span>
                        </label>
                    @empty
                        <span class="mi-chips-empty">لم يتم إعداد أي مجموعات إضافات بعد.</span>
                    @endforelse
                </div>
                <small class="text-muted d-block mt-1" style="font-size: 11.5px;">
                    <i class="bi bi-info-circle"></i>
                    تتيح للزبون تخصيص الطلب (الحجم، الإضافات، الإكسترا).
                    <a href="{{ route('admin.modifiers.index') }}" target="_blank" class="text-decoration-none">إدارة المجموعات والخيارات →</a>
                </small>
            </div>
        </div>
    </div>
</div>

{{-- 6. Recipe ───────────────────────────────────────── --}}
<div class="mi-section mi-section--recipe">
    <div class="mi-section-head">
        <i class="bi bi-basket2-fill"></i>
        <span class="title">مكونات الوصفة</span>
        <span class="hint">لخصم المخزون تلقائياً عند البيع</span>
    </div>
    <div class="mi-section-body">
        @php
            $existingRecipe = optional($item)->recipeItems ?? collect();
            // Load each ingredient's own units (tbsp/scoop/etc.) so the
            // recipe row can offer chef-friendly measurements instead of
            // forcing everything into the global g/ml grid.
            $ingredientsWithUnits = $ingredients->loadMissing('units', 'baseUnit')->map(fn ($i) => [
                'id'    => $i->id,
                'name'  => $i->name . ' (' . ($i->baseUnit->code ?? '') . ')',
                // The ingredient's measurement family (weight/volume/count) —
                // kept for validation parity, though the unit is now locked to
                // the ingredient's own base unit (no manual override needed).
                'unit_type' => $i->baseUnit->unit_type ?? null,
                // The ingredient's base unit — the recipe quantity is always
                // entered in THIS unit, so we lock + display it instead of
                // offering a dropdown the user shouldn't have to touch.
                'base_unit_id'   => $i->base_unit_id,
                'base_unit_name' => $i->baseUnit->name ?? '',
                'units' => $i->units->where('active', true)->map(fn ($u) => [
                    'id'     => $u->id,
                    'name'   => $u->name,
                    'factor' => (float) $u->factor_to_base,
                ])->values()->all(),
            ]);
        @endphp
        @php
            // After a rejected save, re-render the rows the user actually
            // SUBMITTED (old input) so each inline error lands on the right row
            // and nothing they typed is lost. On a fresh load, render the saved
            // recipe. Normalise both to a single {ingredient_id, quantity, unit}
            // shape where `unit` is the prefixed select value ("u:5" / "iu:9").
            if (old('recipe') !== null) {
                $recipeRows = collect(old('recipe'))->map(fn ($r) => [
                    'ingredient_id' => $r['ingredient_id'] ?? null,
                    'quantity'      => $r['quantity'] ?? null,
                    'unit'          => $r['unit_id'] ?? '',
                ])->values();
            } else {
                $recipeRows = $existingRecipe->map(fn ($r) => [
                    'ingredient_id' => $r->ingredient_id,
                    'quantity'      => $r->quantity,
                    'unit'          => $r->ingredient_unit_id ? 'iu:'.$r->ingredient_unit_id : 'u:'.$r->unit_id,
                ])->values();
            }
        @endphp
        <div id="recipe-wrap" class="mi-recipe-list">
            @foreach($recipeRows as $idx => $row)
                @php
                    $rowIngredient = $ingredients->firstWhere('id', $row['ingredient_id']);
                    // The recipe quantity is entered in the ingredient's base
                    // unit — lock + display it, no dropdown to fiddle with.
                    $rowUnitName   = $rowIngredient?->baseUnit?->name;
                    $rowUnitValue  = $rowIngredient ? 'u:'.$rowIngredient->base_unit_id : '';
                    // Trim trailing zeros so 50.0000 shows as 50, 1.5000 as 1.5.
                    $rowQty        = $row['quantity'] !== null && $row['quantity'] !== ''
                        ? rtrim(rtrim(number_format((float) $row['quantity'], 4, '.', ''), '0'), '.')
                        : '';
                    $rowError      = $errors->first("recipe.{$idx}.unit_id")
                                     ?: $errors->first("recipe.{$idx}.ingredient_id")
                                     ?: $errors->first("recipe.{$idx}.quantity");
                @endphp
                <div class="mi-recipe-row @if($rowError) has-error @endif">
                    <select name="recipe[{{ $idx }}][ingredient_id]" class="form-select form-select-sm @if($rowError) is-invalid @endif" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن..." onchange="rebuildUnitOptions(this)">
                        <option value="">— اختر مكون —</option>
                        @foreach($ingredients as $ing)<option value="{{ $ing->id }}" @selected($row['ingredient_id']==$ing->id)>{{ $ing->name }} ({{ $ing->baseUnit->code ?? '' }})</option>@endforeach
                    </select>
                    <input type="number" step="any" min="0" name="recipe[{{ $idx }}][quantity]" value="{{ $rowQty }}" class="form-control form-control-sm" placeholder="الكمية">
                    {{-- Unit is fixed by the ingredient (its base unit). Show it
                         read-only and carry the value in a hidden field. --}}
                    <input type="hidden" name="recipe[{{ $idx }}][unit_id]" value="{{ $rowUnitValue }}">
                    <div class="mi-unit-fixed">{{ $rowUnitName ?: '—' }}</div>
                    <button type="button" class="btn btn-outline-danger btn-sm" title="حذف"
                            onclick="this.closest('.mi-recipe-row').remove()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                @if($rowError)
                    <div class="mi-recipe-row-error">
                        <i class="bi bi-exclamation-circle-fill"></i> {{ $rowError }}
                    </div>
                @endif
            @endforeach
        </div>
        <button type="button" class="mi-add-recipe" onclick="addRecipeRow()">
            <i class="bi bi-plus-circle-fill"></i> إضافة مكوّن للوصفة
        </button>
        <small class="text-muted d-block mt-2" style="font-size: 11.5px;">
            <i class="bi bi-info-circle"></i>
            عند بيع كل وحدة من هذا الصنف، تُخصم الكميات المحدّدة هنا من المخزون تلقائياً.
        </small>
    </div>
</div>

{{-- Sticky action bar --}}
<div class="mi-actions">
    <a href="{{ route('admin.menu-items.index') }}" class="btn btn-light">
        <i class="bi bi-arrow-right"></i> إلغاء
    </a>
    <button class="btn btn-primary">
        <i class="bi bi-check-circle-fill"></i> حفظ الصنف
    </button>
</div>

@push('scripts')
<script>
let recipeIdx = {{ $recipeRows->count() }};
// The recipe quantity is always entered in the ingredient's own base unit, so
// the unit is fixed (read-only) by the ingredient — no dropdown to touch.
const ingredients = @json($ingredientsWithUnits);

// Called when the ingredient changes on a row — lock the unit to that
// ingredient's base unit (sets the hidden field + the read-only label).
function rebuildUnitOptions(ingredientSelect) {
    const row = ingredientSelect.closest('.mi-recipe-row');
    if (! row) return;
    const hidden = row.querySelector('input[type="hidden"][name$="[unit_id]"]');
    const label  = row.querySelector('.mi-unit-fixed');

    const ing = ingredients.find(i => Number(i.id) === Number(ingredientSelect.value));
    if (hidden) hidden.value = ing && ing.base_unit_id ? 'u:' + ing.base_unit_id : '';
    if (label)  label.textContent = ing ? (ing.base_unit_name || '—') : '—';
}

function addRecipeRow() {
    const idx = recipeIdx++;
    const ingOpts = ingredients.map(i => `<option value="${i.id}">${i.name}</option>`).join('');
    const html = `
      <div class="mi-recipe-row">
        <select name="recipe[${idx}][ingredient_id]" class="form-select form-select-sm" onchange="rebuildUnitOptions(this)"><option value="">— اختر مكون —</option>${ingOpts}</select>
        <input type="number" step="any" min="0" name="recipe[${idx}][quantity]" class="form-control form-control-sm" placeholder="الكمية">
        <input type="hidden" name="recipe[${idx}][unit_id]" value="">
        <div class="mi-unit-fixed">—</div>
        <button type="button" class="btn btn-outline-danger btn-sm" title="حذف" onclick="this.closest('.mi-recipe-row').remove()"><i class="bi bi-x-lg"></i></button>
      </div>`;
    const wrap = document.getElementById('recipe-wrap');
    wrap.insertAdjacentHTML('beforeend', html);
    const ingredientSelect = wrap.lastElementChild?.querySelector('select[name$="[ingredient_id]"]');
    if (ingredientSelect) {
        ingredientSelect.setAttribute('data-relax-choice', '');
        ingredientSelect.setAttribute('data-choice-search-placeholder', 'ابحث عن مكوّن...');
        window.relaxChoices?.refresh(ingredientSelect);
    }
    // The unit select stays a plain disabled dropdown until an ingredient is
    // picked; rebuildUnitOptions() then fills + Choices-enables it.
}
</script>
@endpush
