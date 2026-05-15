@csrf
@php $a = $allergen ?? null; @endphp
<div class="row g-3">
    <div class="col-md-3"><label class="form-label">الرمز *</label><input name="code" value="{{ old('code', $a?->code) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name', $a?->name) }}" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $a?->name_en) }}" class="form-control"></div>
    <div class="col-md-2"><label class="form-label">أيقونة</label><input name="icon" value="{{ old('icon', $a?->icon ?? '🥜') }}" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="display_order" value="{{ old('display_order', $a?->display_order ?? 0) }}" class="form-control"></div>
    <div class="col-md-9"><label class="form-label">الوصف</label><input name="description" value="{{ old('description', $a?->description) }}" class="form-control"></div>
    <div class="col-12 form-actions"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.allergens.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
