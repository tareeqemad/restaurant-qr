@csrf
@php $u = $unit ?? null; @endphp
<div class="row g-3">
    <div class="col-md-3"><label class="form-label">الرمز *</label><input name="code" value="{{ old('code', $u?->code) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name', $u?->name) }}" class="form-control" required></div>
    <div class="col-md-5"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $u?->name_en) }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">النوع *</label>
        <select name="unit_type" class="form-select" required>
            <option value="weight" @selected(old('unit_type', $u?->unit_type)==='weight')>وزن</option>
            <option value="volume" @selected(old('unit_type', $u?->unit_type)==='volume')>حجم</option>
            <option value="count" @selected(old('unit_type', $u?->unit_type)==='count')>عدد</option>
            <option value="length" @selected(old('unit_type', $u?->unit_type)==='length')>طول</option>
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">معامل التحويل للأساسية *</label>
        <input type="number" step="0.0000001" name="factor_to_base" value="{{ old('factor_to_base', $u?->factor_to_base ?? 1) }}" class="form-control" required>
        <small class="text-muted">مثال: kg → 1000, g → 1</small></div>
    <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="hidden" name="is_base" value="0"><input type="checkbox" name="is_base" value="1" class="form-check-input" @checked(old('is_base', $u?->is_base))><label class="form-check-label">وحدة أساسية</label></div></div>
    <div class="col-12 form-actions"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.units.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
