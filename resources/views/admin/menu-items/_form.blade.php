@csrf
@php $item = $item ?? null; @endphp

{{-- Section: Basic Info --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-info-circle-fill"></i>
        <span>المعلومات الأساسية</span>
    </div>
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
            <label class="form-label">المحطة</label>
            <select name="station_id" class="form-select">
                <option value="">— الافتراضية من القسم —</option>
                @foreach($stations as $s)<option value="{{ $s->id }}" @selected(old('station_id', $item->station_id ?? '')==$s->id)>{{ $s->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">SKU</label>
            <input name="sku" value="{{ old('sku', $item->sku ?? '') }}" class="form-control" dir="ltr" placeholder="MI-001">
        </div>
        <div class="col-md-6">
            <label class="form-label">الوصف</label>
            <textarea name="description" class="form-control" rows="2" placeholder="وصف مختصر للزبون">{{ old('description', $item->description ?? '') }}</textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">الوصف بالإنجليزية</label>
            <textarea name="description_en" class="form-control" rows="2" dir="ltr">{{ old('description_en', $item->description_en ?? '') }}</textarea>
        </div>
    </div>
</div>

{{-- Section: Pricing & Timing --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-cash-coin"></i>
        <span>السعر والتحضير</span>
    </div>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">السعر <span class="req">*</span></label>
            <div class="input-group input-group-lg">
                <input type="number" step="0.01" name="price" value="{{ old('price', $item->price ?? 0) }}" class="form-control" required>
                <span class="input-group-text" style="background:rgba(184,135,42,.1); color:#8a6920; font-weight:800;">{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}</span>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">وقت التحضير (دقائق)</label>
            <div class="input-group">
                <input type="number" name="prep_time_minutes" value="{{ old('prep_time_minutes', $item->prep_time_minutes ?? 10) }}" class="form-control">
                <span class="input-group-text"><i class="bi bi-clock"></i></span>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">السعرات الحرارية</label>
            <div class="input-group">
                <input type="number" name="calories" value="{{ old('calories', $item->calories ?? '') }}" class="form-control">
                <span class="input-group-text">kcal</span>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">ترتيب العرض</label>
            <input type="number" name="display_order" value="{{ old('display_order', $item->display_order ?? 0) }}" class="form-control">
        </div>
    </div>
</div>

{{-- Section: Media & Visibility --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-image-fill"></i>
        <span>الصورة والتوفر</span>
    </div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">صورة الصنف</label>
            <div class="image-upload">
                @if($item?->image)
                    <img src="{{ $item->imageUrl() }}" class="image-upload-preview">
                @else
                    <div class="image-upload-placeholder">
                        <i class="bi bi-image"></i>
                        <small>لا توجد صورة</small>
                    </div>
                @endif
                <div class="image-upload-controls flex-grow-1">
                    <input type="file" name="image" class="form-control" accept="image/*">
                    <small class="text-muted d-block mt-1"><i class="bi bi-info-circle"></i> JPG/PNG — حد أقصى 5MB</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label d-block mb-2">الإعدادات</label>

            <div class="toggle-card">
                <input type="hidden" name="is_available" value="0">
                <input type="checkbox" name="is_available" value="1" id="is_available" class="form-check-input" @checked(old('is_available', $item?->is_available ?? true))>
                <label for="is_available" class="flex-grow-1">
                    <div class="fw-bold"><i class="bi bi-check-circle-fill"></i> متوفر للطلب</div>
                    <small class="text-muted">يظهر في قائمة الزبون</small>
                </label>
            </div>

            <div class="toggle-card mt-2">
                <input type="hidden" name="is_featured" value="0">
                <input type="checkbox" name="is_featured" value="1" id="is_featured" class="form-check-input" @checked(old('is_featured', $item?->is_featured ?? false))>
                <label for="is_featured" class="flex-grow-1">
                    <div class="fw-bold"><i class="bi bi-star-fill" style="color:#b8872a;"></i> مميز اليوم</div>
                    <small class="text-muted">شارة ذهبية + قسم خاص</small>
                </label>
            </div>
        </div>
    </div>

</div>

{{-- Section: Allergens & Modifiers --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-shield-exclamation"></i>
        <span>الحساسية والإضافات</span>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">مسببات الحساسية</label>
            <div class="chips-panel">
                @php $selectedAllergens = old('allergens', optional($item)->allergens?->pluck('id')->toArray() ?? []); @endphp
                @foreach($allergens as $a)
                    <label class="chip-check">
                        <input type="checkbox" name="allergens[]" value="{{ $a->id }}" @checked(in_array($a->id, $selectedAllergens))>
                        <span>{{ $a->icon }} {{ $a->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label">مجموعات الإضافات (Modifiers)</label>
            <div class="chips-panel">
                @php $selectedMods = old('modifier_groups', optional($item)->modifierGroups?->pluck('id')->toArray() ?? []); @endphp
                @foreach($modifierGroups as $g)
                    <label class="chip-check">
                        <input type="checkbox" name="modifier_groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $selectedMods))>
                        <span><i class="bi bi-sliders2"></i> {{ $g->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- Section: Recipe --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-basket2-fill"></i>
        <span>مكونات الوصفة (لخصم المخزون تلقائياً)</span>
    </div>
    <div id="recipe-wrap" class="recipe-list">
        @php $existingRecipe = optional($item)->recipeItems ?? collect(); @endphp
        @foreach($existingRecipe as $idx => $r)
            <div class="recipe-row row g-2 align-items-center">
                <div class="col-md-5">
                    <select name="recipe[{{ $idx }}][ingredient_id]" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مكوّن...">
                        <option value="">— اختر مكون —</option>
                        @foreach($ingredients as $ing)<option value="{{ $ing->id }}" @selected($r->ingredient_id==$ing->id)>{{ $ing->name }} ({{ $ing->baseUnit->code ?? '' }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="number" step="0.0001" name="recipe[{{ $idx }}][quantity]" value="{{ $r->quantity }}" class="form-control" placeholder="الكمية">
                </div>
                <div class="col-md-3">
                    <select name="recipe[{{ $idx }}][unit_id]" class="form-select">
                        @foreach($units as $u)<option value="{{ $u->id }}" @selected($r->unit_id==$u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.recipe-row').remove()"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        @endforeach
    </div>
    <button type="button" class="btn btn-add-recipe" onclick="addRecipeRow()">
        <i class="bi bi-plus-circle"></i> إضافة مكون للوصفة
    </button>
</div>

{{-- Sticky action bar --}}
<div class="form-actions">
    <a href="{{ route('admin.menu-items.index') }}" class="btn btn-light">
        <i class="bi bi-arrow-right"></i> إلغاء
    </a>
    <button class="btn btn-primary">
        <i class="bi bi-check-circle-fill"></i> حفظ الصنف
    </button>
</div>

@push('scripts')
<script>
let recipeIdx = {{ $existingRecipe->count() ?? 0 }};
const ingredients = @json($ingredients->map(fn($i) => ['id'=>$i->id,'label'=>$i->name.' ('.($i->baseUnit->code ?? '').')']));
const units = @json($units->map(fn($u) => ['id'=>$u->id,'label'=>$u->name]));
function addRecipeRow() {
    const idx = recipeIdx++;
    const ingOpts = ingredients.map(i => `<option value="${i.id}">${i.label}</option>`).join('');
    const unitOpts = units.map(u => `<option value="${u.id}">${u.label}</option>`).join('');
    const html = `
      <div class="row g-2 mb-2 recipe-row">
          <div class="col-md-5"><select name="recipe[${idx}][ingredient_id]" class="form-select"><option value="">— مكون —</option>${ingOpts}</select></div>
          <div class="col-md-3"><input type="number" step="0.0001" name="recipe[${idx}][quantity]" class="form-control" placeholder="الكمية"></div>
          <div class="col-md-3"><select name="recipe[${idx}][unit_id]" class="form-select">${unitOpts}</select></div>
          <div class="col-md-1"><button type="button" class="btn btn-outline-danger" onclick="this.closest('.recipe-row').remove()"><i class="bi bi-x"></i></button></div>
      </div>`;
    const wrap = document.getElementById('recipe-wrap');
    wrap.insertAdjacentHTML('beforeend', html);
    const ingredientSelect = wrap.lastElementChild?.querySelector('select[name$="[ingredient_id]"]');
    if (ingredientSelect) {
        ingredientSelect.setAttribute('data-relax-choice', '');
        ingredientSelect.setAttribute('data-choice-search-placeholder', 'ابحث عن مكوّن...');
        window.relaxChoices?.refresh(ingredientSelect);
    }
}
</script>
@endpush
