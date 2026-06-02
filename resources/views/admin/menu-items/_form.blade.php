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
                <select name="category_id" class="form-select" required data-relax-choice data-choice-search-placeholder="ابحث عن قسم...">
                    @foreach($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $item->category_id ?? '')==$c->id)>{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">المحطة <small class="text-muted">(اختياري)</small></label>
                <select name="station_id" class="form-select">
                    <option value="">— الافتراضية من القسم —</option>
                    @foreach($stations as $s)<option value="{{ $s->id }}" @selected(old('station_id', $item->station_id ?? '')==$s->id)>{{ $s->name }}</option>@endforeach
                </select>
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
                // the unit dropdown only offers global units of the same type
                // so a count item can't be paired with a weight unit.
                'unit_type' => $i->baseUnit->unit_type ?? null,
                // The ingredient's base unit id — used as the default unit pick
                // when a row is added, so "خيار" defaults to غرام (its base),
                // not whichever weight unit happens to sort first.
                'base_unit_id' => $i->base_unit_id,
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
                    $selectedValue = $row['unit'];
                    $rowIngredient = $ingredients->firstWhere('id', $row['ingredient_id']);
                    $rowAltUnits   = $rowIngredient?->units?->where('active', true) ?? collect();
                    $rowType       = $rowIngredient?->baseUnit?->unit_type;
                    // Mirror the JS: a unit only has meaning once a real
                    // ingredient is chosen, and only same-type global units apply.
                    $rowGlobals    = $rowType ? $units->where('unit_type', $rowType) : collect();
                    $rowError      = $errors->first("recipe.{$idx}.unit_id")
                                     ?: $errors->first("recipe.{$idx}.ingredient_id")
                                     ?: $errors->first("recipe.{$idx}.quantity");
                @endphp
                <div class="mi-recipe-row @if($rowError) has-error @endif">
                    <select name="recipe[{{ $idx }}][ingredient_id]" class="form-select form-select-sm @if($rowError) is-invalid @endif" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن..." onchange="rebuildUnitOptions(this)">
                        <option value="">— اختر مكون —</option>
                        @foreach($ingredients as $ing)<option value="{{ $ing->id }}" @selected($row['ingredient_id']==$ing->id)>{{ $ing->name }} ({{ $ing->baseUnit->code ?? '' }})</option>@endforeach
                    </select>
                    <input type="number" step="0.0001" name="recipe[{{ $idx }}][quantity]" value="{{ $row['quantity'] }}" class="form-control form-control-sm" placeholder="الكمية">
                    <select name="recipe[{{ $idx }}][unit_id]" class="form-select form-select-sm mi-unit-select @if($rowError) is-invalid @endif" data-relax-choice data-choice-search="false" @disabled(! $rowIngredient)>
                        @if(! $rowIngredient)
                            <option value="">— اختر المكوّن أولاً —</option>
                        @else
                            @if($rowAltUnits->isNotEmpty())
                                <optgroup label="وحدات هذا المكوّن">
                                    @foreach($rowAltUnits as $u)
                                        <option value="iu:{{ $u->id }}" @selected($selectedValue === 'iu:'.$u->id)>
                                            {{ $u->name }} (×{{ rtrim(rtrim((string) $u->factor_to_base, '0'), '.') }} {{ $rowIngredient->baseUnit?->code }})
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                            <optgroup label="وحدات عامة">
                                @foreach($rowGlobals as $u)
                                    <option value="u:{{ $u->id }}" @selected($selectedValue === 'u:'.$u->id)>{{ $u->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
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
// Ingredients now carry their own units (tbsp/scoop/pack) so the
// per-row unit dropdown can switch between chef-friendly measurements
// and the global g/ml/pcs grid the moment the chef picks an ingredient.
const ingredients = @json($ingredientsWithUnits);
const units = @json($units->map(fn($u) => ['id'=>$u->id,'label'=>$u->name,'type'=>$u->unit_type]));

function unitOptionsFor(ingredientId) {
    const ing = ingredients.find(i => Number(i.id) === Number(ingredientId));
    // No ingredient yet → the unit has no meaning. Show a single placeholder
    // and let the caller disable the select, so nobody saves a unit-only row.
    if (! ing) {
        return '<option value="">— اختر المكوّن أولاً —</option>';
    }
    let html = '';
    if (ing.units && ing.units.length > 0) {
        html += '<optgroup label="وحدات هذا المكوّن">';
        for (const u of ing.units) {
            html += `<option value="iu:${u.id}">${u.name} (×${u.factor})</option>`;
        }
        html += '</optgroup>';
    }
    // Only offer global units of the SAME measurement family as the ingredient
    // (weight↔weight, volume↔volume, count↔count).
    const globals = ing.unit_type ? units.filter(u => u.type === ing.unit_type) : units;
    html += '<optgroup label="وحدات عامة">';
    for (const u of globals) {
        html += `<option value="u:${u.id}">${u.label}</option>`;
    }
    html += '</optgroup>';
    return html;
}

// Called when ingredient changes on a row — rebuild the unit dropdown
// so the chef sees the right tbsp/scoop options for THIS ingredient.
// The unit select is a Choices.js instance (data-relax-choice), so we tear it
// down, rewrite the native <option>s, then re-init — editing innerHTML on a
// live Choices instance corrupts it (the dropdown stays open / won't commit).
function rebuildUnitOptions(ingredientSelect) {
    const row = ingredientSelect.closest('.mi-recipe-row');
    const unitSelect = row?.querySelector('select[name$="[unit_id]"]');
    if (! unitSelect) return;

    const ing = ingredients.find(i => Number(i.id) === Number(ingredientSelect.value));
    const hasIngredient = !! ing;

    window.relaxChoices?.destroy(unitSelect);
    unitSelect.innerHTML = unitOptionsFor(ingredientSelect.value);
    unitSelect.disabled = ! hasIngredient;

    // Default to the ingredient's OWN base unit (e.g. خيار → غرام), not
    // whichever weight unit happens to sort first (which could be طن).
    const want = ing && ing.base_unit_id ? 'u:' + ing.base_unit_id : null;
    if (want && [...unitSelect.options].some(o => o.value === want)) {
        unitSelect.value = want;
    } else if (unitSelect.options.length > 0) {
        unitSelect.selectedIndex = 0;
    }

    if (hasIngredient) {
        window.relaxChoices?.refresh(unitSelect);
    }
}

function addRecipeRow() {
    const idx = recipeIdx++;
    const ingOpts = ingredients.map(i => `<option value="${i.id}">${i.name}</option>`).join('');
    const html = `
      <div class="mi-recipe-row">
        <select name="recipe[${idx}][ingredient_id]" class="form-select form-select-sm" onchange="rebuildUnitOptions(this)"><option value="">— اختر مكون —</option>${ingOpts}</select>
        <input type="number" step="0.0001" name="recipe[${idx}][quantity]" class="form-control form-control-sm" placeholder="الكمية">
        <select name="recipe[${idx}][unit_id]" class="form-select form-select-sm mi-unit-select" disabled>${unitOptionsFor(null)}</select>
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
