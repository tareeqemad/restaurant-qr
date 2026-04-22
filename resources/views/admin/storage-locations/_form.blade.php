@csrf
@php $location = $location ?? null; @endphp

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-geo-alt-fill"></i>
        <span>بيانات الموقع</span>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">الكود <span class="req">*</span></label>
            <input name="code" value="{{ old('code', $location->code ?? '') }}" class="form-control" required dir="ltr" placeholder="main_kitchen">
            <small class="text-muted">بالإنجليزية، بدون مسافات</small>
        </div>
        <div class="col-md-4">
            <label class="form-label">الاسم <span class="req">*</span></label>
            <input name="name" value="{{ old('name', $location->name ?? '') }}" class="form-control form-control-lg" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">الترتيب</label>
            <input type="number" name="display_order" value="{{ old('display_order', $location->display_order ?? 0) }}" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">أيقونة</label>
            <input name="icon" value="{{ old('icon', $location->icon ?? '') }}" class="form-control" dir="ltr" placeholder="bi-fire">
            <small class="text-muted">اسم bootstrap-icons</small>
        </div>
        <div class="col-md-4">
            <label class="form-label">اللون</label>
            <input type="color" name="color" value="{{ old('color', $location->color ?? '#1f4733') }}" class="form-control form-control-color">
        </div>
        <div class="col-md-4">
            <label class="d-block form-label">الإعدادات</label>
            <label class="d-flex align-items-center gap-2">
                <input type="hidden" name="is_default" value="0">
                <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $location->is_default ?? false))>
                افتراضي
            </label>
            <label class="d-flex align-items-center gap-2 mt-1">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" @checked(old('active', $location->active ?? true))>
                فعّال
            </label>
        </div>
        <div class="col-12">
            <label class="form-label">الوصف</label>
            <textarea name="description" class="form-control" rows="2">{{ old('description', $location->description ?? '') }}</textarea>
        </div>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('admin.storage-locations.index') }}" class="btn btn-light">إلغاء</a>
    <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ</button>
</div>
