@csrf
@php $category = $category ?? null; @endphp

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-info-circle-fill"></i>
        <span>المعلومات الأساسية</span>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">الاسم <span class="req">*</span></label>
            <input name="name" value="{{ old('name', $category->name ?? '') }}" class="form-control form-control-lg" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">الاسم بالإنجليزية</label>
            <input name="name_en" value="{{ old('name_en', $category->name_en ?? '') }}" class="form-control form-control-lg" dir="ltr">
        </div>
        <div class="col-md-6">
            <label class="form-label">المحطة الافتراضية</label>
            <select name="default_station_id" class="form-select">
                <option value="">—</option>
                @foreach($stations as $s)
                    <option value="{{ $s->id }}" @selected(old('default_station_id', $category->default_station_id ?? '')==$s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">الترتيب</label>
            <input type="number" name="display_order" value="{{ old('display_order', $category->display_order ?? 0) }}" class="form-control">
        </div>
        <div class="col-md-3">
            <label class="form-label d-block">نشط</label>
            <div class="toggle-card">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" id="cat-active" class="form-check-input" @checked(old('active', $category->active ?? true))>
                <label for="cat-active" class="flex-grow-1"><strong>نشط</strong></label>
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">الوصف</label>
            <textarea name="description" class="form-control" rows="2">{{ old('description', $category->description ?? '') }}</textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">Description (EN)</label>
            <textarea name="description_en" class="form-control" rows="2" dir="ltr">{{ old('description_en', $category->description_en ?? '') }}</textarea>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-image-fill"></i>
        <span>المظهر البصري</span>
    </div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label">صورة القسم</label>
            <div class="image-upload">
                @if(!empty($category?->image))
                    <img src="{{ $category->imageUrl() }}" class="image-upload-preview" alt="">
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
            <label class="form-label">لون</label>
            <input type="color" name="color" value="{{ old('color', $category->color ?? '#166534') }}" class="form-control form-control-color" style="width:100%; height:50px;">
        </div>

        {{-- Advanced options — icon class + CDN URL are developer-flavored
             fields that scare off first-time users, so they live behind a
             collapse. Auto-expanded when either already carries a value,
             otherwise the user couldn't see what a colleague configured. --}}
        @php
            $advImageUrl = old('image_url', (str_starts_with($category->image ?? '', 'http') ? $category->image : ''));
            $advIcon     = old('icon', $category->icon ?? '');
            $advOpen     = ($advImageUrl !== '' && $advImageUrl !== null) || ($advIcon !== '' && $advIcon !== null);
        @endphp
        <div class="col-12">
            <button class="btn btn-sm btn-light border" type="button"
                    data-bs-toggle="collapse" data-bs-target="#cat-advanced-options"
                    aria-expanded="{{ $advOpen ? 'true' : 'false' }}" aria-controls="cat-advanced-options">
                <i class="bi bi-sliders"></i>
                خيارات متقدمة
                <i class="bi bi-chevron-down small"></i>
            </button>
            <div class="collapse{{ $advOpen ? ' show' : '' }} mt-3" id="cat-advanced-options">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">أو رابط صورة (URL)</label>
                        <input type="url" name="image_url" value="{{ $advImageUrl }}"
                            class="form-control" dir="ltr" placeholder="https://images.unsplash.com/...">
                        <small class="text-muted">مفيد لاستخدام صور من Unsplash أو CDN — يغني عن رفع ملف</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">أيقونة بديلة</label>
                        <input name="icon" value="{{ $advIcon }}" class="form-control" dir="ltr" placeholder="bi-fire">
                        <small class="text-muted">صنف أيقونة Bootstrap (مثل bi-fire) — تُستخدم لو لا توجد صورة</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('admin.categories.index') }}" class="btn btn-light">
        <i class="bi bi-arrow-right"></i> إلغاء
    </a>
    <button class="btn btn-primary">
        <i class="bi bi-check-circle-fill"></i> حفظ
    </button>
</div>
