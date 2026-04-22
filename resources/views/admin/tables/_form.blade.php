@csrf
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">رقم الطاولة *</label>
        <input name="number" value="{{ old('number', $table->number ?? '') }}" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">اسم</label>
        <input name="name" value="{{ old('name', $table->name ?? '') }}" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">السعة *</label>
        <input type="number" name="capacity" value="{{ old('capacity', $table->capacity ?? 4) }}" class="form-control" min="1" required></div>
    <div class="col-md-4"><label class="form-label">المنطقة</label>
        <input name="zone" value="{{ old('zone', $table->zone ?? '') }}" class="form-control" placeholder="indoor/outdoor/VIP"></div>
    <div class="col-md-4"><label class="form-label">الحالة *</label>
        <select name="status" class="form-select" required>
            <option value="available" @selected(old('status', $table->status ?? 'available')==='available')>متاحة</option>
            <option value="occupied" @selected(old('status', $table->status ?? '')==='occupied')>مشغولة</option>
            <option value="reserved" @selected(old('status', $table->status ?? '')==='reserved')>محجوزة</option>
            <option value="out_of_service" @selected(old('status', $table->status ?? '')==='out_of_service')>خارج الخدمة</option>
        </select>
    </div>
    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $table->active ?? true))>
            <label class="form-check-label">نشطة</label>
        </div>
    </div>
    <div class="col-12"><button class="btn btn-primary">حفظ</button>
        <a href="{{ route('admin.tables.index') }}" class="btn btn-light">إلغاء</a></div>
</div>
