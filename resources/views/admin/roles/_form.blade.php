@csrf
@php $r = $role ?? null; $selected = $r ? $r->permissions->pluck('id')->toArray() : []; @endphp
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">اسم المعرّف *</label><input name="name" value="{{ old('name', $r?->name) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">التسمية *</label><input name="label" value="{{ old('label', $r?->label) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">الترتيب</label><input type="number" name="display_order" value="{{ old('display_order', $r?->display_order ?? 0) }}" class="form-control"></div>
    <div class="col-12"><label class="form-label">الوصف</label><textarea name="description" class="form-control" rows="2">{{ old('description', $r?->description) }}</textarea></div>

    <div class="col-12"><hr><h6 class="fw-bold mb-2">الصلاحيات</h6>
        <div class="row g-2">
            @foreach($permissions as $group => $perms)
                <div class="col-md-4"><div class="border rounded p-2">
                    <div class="fw-bold mb-1 text-primary">{{ $group }}</div>
                    @foreach($perms as $p)
                        <label class="d-block"><input type="checkbox" name="permissions[]" value="{{ $p->id }}" @checked(in_array($p->id, old('permissions', $selected)))> {{ $p->name }}</label>
                    @endforeach
                </div></div>
            @endforeach
        </div>
    </div>

    <div class="col-12"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.roles.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
