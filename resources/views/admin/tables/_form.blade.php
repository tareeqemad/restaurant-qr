@csrf
@php
    $zones = $zones ?? collect();
    $currentZoneId = old('zone_lookup_id', $table->zone_lookup_id ?? null);
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">رقم الطاولة *</label>
        <input name="number" value="{{ old('number', $table->number ?? '') }}" class="form-control" required>
        <small class="text-muted">يمكن استخدام نفس الرقم في فروع مختلفة.</small>
    </div>

    <div class="col-md-4">
        <label class="form-label">اسم</label>
        <input name="name" value="{{ old('name', $table->name ?? '') }}" class="form-control" placeholder="اختياري">
    </div>

    <div class="col-md-4">
        <label class="form-label">السعة *</label>
        <input type="number" name="capacity" value="{{ old('capacity', $table->capacity ?? 4) }}" class="form-control" min="1" required>
    </div>

    {{-- ═══════════════ Zone — driven by Lookups admin ═══════════════ --}}
    <div class="col-md-6">
        <label class="form-label">
            المنطقة
            @can('viewAny', \App\Models\Lookup::class)
                <a href="{{ route('admin.lookups.index', ['group' => 'zones']) }}" target="_blank"
                   class="ms-1 small text-muted" title="إدارة المناطق في شاشة الثوابت">
                    <i class="bi bi-gear-fill"></i>
                </a>
            @endcan
        </label>
        <select name="zone_lookup_id" class="form-select">
            <option value="">— بدون منطقة —</option>
            @foreach($zones as $z)
                <option value="{{ $z->id }}" @selected((int)$currentZoneId === (int)$z->id)>
                    {{ $z->label }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">
            لإضافة / تعديل المناطق، استخدم
            <a href="{{ route('admin.lookups.index', ['group' => 'zones']) }}" target="_blank">شاشة إدارة الثوابت</a>.
        </small>
    </div>

    <div class="col-md-4">
        <label class="form-label">الحالة *</label>
        <select name="status" class="form-select" required>
            <option value="available"      @selected(old('status', $table->status ?? 'available')==='available')>متاحة</option>
            <option value="occupied"       @selected(old('status', $table->status ?? '')==='occupied')>مشغولة</option>
            <option value="reserved"       @selected(old('status', $table->status ?? '')==='reserved')>محجوزة</option>
            <option value="out_of_service" @selected(old('status', $table->status ?? '')==='out_of_service')>خارج الخدمة</option>
        </select>
    </div>

    <div class="col-md-2 d-flex align-items-end">
        <div class="form-check">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $table->active ?? true))>
            <label class="form-check-label">نشطة</label>
        </div>
    </div>

    <div class="col-12 pt-2">
        <button class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> حفظ</button>
        <a href="{{ route('admin.tables.index') }}" class="btn btn-light">إلغاء</a>
    </div>
</div>
