@csrf
@php $s = $station ?? null; @endphp
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">الكود *</label><input name="code" value="{{ old('code', $s?->code) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">الاسم *</label><input name="name" value="{{ old('name', $s?->name) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $s?->name_en) }}" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">اللون</label><input type="color" name="color" value="{{ old('color', $s?->color ?? '#b91c1c') }}" class="form-control form-control-color" style="width:100%"></div>
    <div class="col-md-3"><label class="form-label">الأيقونة</label><input name="icon" value="{{ old('icon', $s?->icon ?? 'bi-fire') }}" class="form-control"></div>
    <div class="col-md-3">
        <label class="form-label">موقع خصم المخزون</label>
        <select name="storage_location_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن موقع...">
            <option value="">المخزن الافتراضي</option>
            @foreach(($storageLocations ?? collect()) as $location)
                <option value="{{ $location->id }}" @selected(old('storage_location_id', $s?->storage_location_id) == $location->id)>
                    {{ $location->name }}{{ $location->code ? ' - '.$location->code : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">يستخدم عند اعتماد الطلبات وخصم مكونات الأصناف.</small>
    </div>
    <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="display_order" value="{{ old('display_order', $s?->display_order ?? 0) }}" class="form-control"></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $s?->active ?? true))><label class="form-check-label">نشطة</label></div></div>
    <div class="col-12 form-actions"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.stations.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
