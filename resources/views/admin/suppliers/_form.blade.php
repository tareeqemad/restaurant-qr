@csrf
@php $supplier = $supplier ?? null; @endphp

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-truck"></i>
        <span>معلومات المورد</span>
    </div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">اسم المورد <span class="req">*</span></label>
            <input name="name" value="{{ old('name', $supplier->name ?? '') }}" class="form-control form-control-lg" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">الشخص المسؤول</label>
            <input name="contact_person" value="{{ old('contact_person', $supplier->contact_person ?? '') }}" class="form-control">
        </div>
        <div class="col-md-6">
            <label class="form-label">رقم الهاتف</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                <input name="phone" value="{{ old('phone', $supplier->phone ?? '') }}" class="form-control" dir="ltr" placeholder="+962...">
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label">البريد الإلكتروني</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" name="email" value="{{ old('email', $supplier->email ?? '') }}" class="form-control" dir="ltr">
            </div>
        </div>
        <div class="col-12">
            <label class="form-label">العنوان</label>
            <textarea name="address" class="form-control" rows="2" placeholder="العنوان الكامل (اختياري)">{{ old('address', $supplier->address ?? '') }}</textarea>
        </div>
        <div class="col-12">
            <label class="form-label">ملاحظات</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="شروط الدفع، أوقات التوصيل، إلخ...">{{ old('notes', $supplier->notes ?? '') }}</textarea>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-toggle-on"></i>
        <span>الحالة</span>
    </div>
    <div class="toggle-card">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" id="active" class="form-check-input" @checked(old('active', $supplier->active ?? true))>
        <label for="active" class="flex-grow-1">
            <div class="fw-bold"><i class="bi bi-check-circle-fill" style="color:var(--primary);"></i> مورد فعّال</div>
            <small class="text-muted">يظهر في خيارات إضافة مكون جديد وأوامر الشراء</small>
        </label>
    </div>
</div>

<div class="form-actions">
    <a href="{{ route('admin.suppliers.index') }}" class="btn btn-light">
        <i class="bi bi-arrow-right"></i> إلغاء
    </a>
    <button class="btn btn-primary">
        <i class="bi bi-check-circle-fill"></i> حفظ المورد
    </button>
</div>
