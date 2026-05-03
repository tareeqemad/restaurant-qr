@csrf
@php $ing = $ingredient ?? null; @endphp
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">SKU</label><input name="sku" value="{{ old('sku', $ing?->sku) }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name', $ing?->name) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $ing?->name_en) }}" class="form-control"></div>

    <div class="col-md-4"><label class="form-label">الوحدة الأساسية *</label>
        <select name="base_unit_id" class="form-select" required>
            @foreach($units as $u)<option value="{{ $u->id }}" @selected(old('base_unit_id', $ing?->base_unit_id)==$u->id)>{{ $u->name }} ({{ $u->code }})</option>@endforeach
        </select></div>
    <div class="col-md-4"><label class="form-label">المورد</label>
        <select name="supplier_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مورد..."><option value="">—</option>
            @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $ing?->supplier_id)==$s->id)>{{ $s->name }}</option>@endforeach
        </select></div>
    <div class="col-md-4"><label class="form-label">التكلفة/وحدة *</label><input type="number" step="0.0001" name="cost_per_unit" value="{{ old('cost_per_unit', $ing?->cost_per_unit ?? 0) }}" class="form-control" required></div>

    <div class="col-md-4"><label class="form-label">المخزون الحالي *</label><input type="number" step="0.0001" name="current_stock" value="{{ old('current_stock', $ing?->current_stock ?? 0) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">حد الطلب *</label><input type="number" step="0.0001" name="reorder_threshold" value="{{ old('reorder_threshold', $ing?->reorder_threshold ?? 0) }}" class="form-control" required></div>
    <div class="col-md-4 d-flex align-items-end gap-3">
        <div class="form-check"><input type="hidden" name="track_stock" value="0"><input type="checkbox" name="track_stock" value="1" class="form-check-input" @checked(old('track_stock', $ing?->track_stock ?? true))><label class="form-check-label">تتبع المخزون</label></div>
        <div class="form-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $ing?->active ?? true))><label class="form-check-label">فعال</label></div>
    </div>

    <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="2">{{ old('notes', $ing?->notes) }}</textarea></div>
    <div class="col-12"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.ingredients.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
