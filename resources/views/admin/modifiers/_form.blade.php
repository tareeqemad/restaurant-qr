@csrf
@php $g = $group ?? null; $mods = optional($g)->allModifiers ?? collect(); @endphp
<div class="row g-3">
    <div class="col-md-5"><label class="form-label">اسم المجموعة *</label><input name="name" value="{{ old('name', $g?->name) }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Name (EN)</label><input name="name_en" value="{{ old('name_en', $g?->name_en) }}" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">الترتيب</label><input type="number" name="display_order" value="{{ old('display_order', $g?->display_order ?? 0) }}" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">حد أدنى للاختيار</label><input type="number" name="min_select" value="{{ old('min_select', $g?->min_select ?? 0) }}" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">حد أقصى</label><input type="number" name="max_select" value="{{ old('max_select', $g?->max_select ?? 1) }}" class="form-control" required></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" class="form-check-input" @checked(old('required', $g?->required))><label class="form-check-label">إلزامي</label></div></div>
    <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $g?->active ?? true))><label class="form-check-label">نشطة</label></div></div>

    <div class="col-12"><hr><h6 class="fw-bold">الخيارات داخل المجموعة</h6>
        <div id="mods-wrap">
            @foreach($mods as $i => $m)
                <div class="row g-2 mb-2 mod-row">
                    <input type="hidden" name="modifiers[{{ $i }}][id]" value="{{ $m->id }}">
                    <div class="col-md-3"><input name="modifiers[{{ $i }}][name]" value="{{ $m->name }}" class="form-control" placeholder="الاسم"></div>
                    <div class="col-md-3"><input name="modifiers[{{ $i }}][name_en]" value="{{ $m->name_en }}" class="form-control" placeholder="EN"></div>
                    <div class="col-md-2"><input type="number" step="0.01" name="modifiers[{{ $i }}][price_delta]" value="{{ $m->price_delta }}" class="form-control" placeholder="+ فرق السعر"></div>
                    <div class="col-md-2"><input type="number" name="modifiers[{{ $i }}][display_order]" value="{{ $m->display_order }}" class="form-control" placeholder="الترتيب"></div>
                    <div class="col-md-1"><div class="form-check mt-2"><input type="hidden" name="modifiers[{{ $i }}][active]" value="0"><input type="checkbox" name="modifiers[{{ $i }}][active]" value="1" class="form-check-input" @checked($m->active)></div></div>
                    <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.mod-row').remove()"><i class="bi bi-x"></i></button></div>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-light" onclick="addModRow()"><i class="bi bi-plus"></i> خيار جديد</button>
    </div>

    <div class="col-12"><button class="btn btn-primary">حفظ</button><a href="{{ route('admin.modifiers.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
@push('scripts')
<script>
let modIdx = {{ $mods->count() }};
function addModRow() {
    const idx = modIdx++;
    const html = `<div class="row g-2 mb-2 mod-row">
        <div class="col-md-3"><input name="modifiers[${idx}][name]" class="form-control" placeholder="الاسم"></div>
        <div class="col-md-3"><input name="modifiers[${idx}][name_en]" class="form-control" placeholder="EN"></div>
        <div class="col-md-2"><input type="number" step="0.01" name="modifiers[${idx}][price_delta]" value="0" class="form-control"></div>
        <div class="col-md-2"><input type="number" name="modifiers[${idx}][display_order]" value="0" class="form-control"></div>
        <div class="col-md-1"><div class="form-check mt-2"><input type="hidden" name="modifiers[${idx}][active]" value="0"><input type="checkbox" name="modifiers[${idx}][active]" value="1" class="form-check-input" checked></div></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.mod-row').remove()"><i class="bi bi-x"></i></button></div>
    </div>`;
    document.getElementById('mods-wrap').insertAdjacentHTML('beforeend', html);
}
</script>
@endpush
