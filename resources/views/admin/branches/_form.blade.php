@csrf
@php $b = $branch ?? null; @endphp

<div class="row g-3">

    {{-- ─── Identity ──────────────────────────────────────────── --}}
    <div class="col-md-4">
        <label class="form-label">الرمز (slug) *</label>
        <input name="code" value="{{ old('code', $b?->code) }}"
               class="form-control" required dir="ltr"
               placeholder="main-khan-yunis">
        <small class="text-muted">معرّف فريد للفرع (إنجليزي + شرطات).</small>
    </div>

    <div class="col-md-4">
        <label class="form-label">الاسم *</label>
        <input name="name" value="{{ old('name', $b?->name) }}"
               class="form-control" required
               placeholder="الفرع الرئيسي - خانيونس">
    </div>

    <div class="col-md-4">
        <label class="form-label">الاسم بالإنجليزية</label>
        <input name="name_en" value="{{ old('name_en', $b?->name_en) }}"
               class="form-control" dir="ltr"
               placeholder="Main Branch — Khan Yunis">
    </div>

    {{-- ─── Contact ──────────────────────────────────────────── --}}
    <div class="col-md-4">
        <label class="form-label">المدينة</label>
        <input name="city" value="{{ old('city', $b?->city) }}"
               class="form-control" placeholder="خانيونس">
    </div>

    <div class="col-md-4">
        <label class="form-label">الهاتف</label>
        <input name="phone" value="{{ old('phone', $b?->phone) }}"
               class="form-control" dir="ltr">
    </div>

    <div class="col-md-4">
        <label class="form-label">البريد الإلكتروني</label>
        <input type="email" name="email" value="{{ old('email', $b?->email) }}"
               class="form-control" dir="ltr">
    </div>

    <div class="col-12">
        <label class="form-label">العنوان</label>
        <textarea name="address" class="form-control" rows="2"
                  placeholder="الشارع، الحي، أي تفاصيل توصيل…">{{ old('address', $b?->address) }}</textarea>
    </div>

    {{-- ─── Display & status ─────────────────────────────────── --}}
    <div class="col-md-6">
        <label class="form-label">ترتيب العرض</label>
        <input type="number" name="display_order"
               value="{{ old('display_order', $b?->display_order ?? 0) }}"
               class="form-control" min="0">
        <small class="text-muted">ترتيب الفرع في القوائم وتبديل الفروع.</small>
    </div>

    <div class="col-md-6">
        <label class="form-label d-block">الحالة</label>
        <div class="form-check form-switch mt-2">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                   class="form-check-input"
                   @checked(old('is_active', $b?->is_active ?? true))>
            <label class="form-check-label" for="is_active">
                مفعَّل — يظهر في تبديل الفروع وتُسمح به العمليات
            </label>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label">عرض إجمالي QR للزبون</label>
        <select name="customer_tax_display" class="form-select">
            @php $taxDisplay = old('customer_tax_display', $b?->settingValue('customer_tax_display', 'inherit') ?? 'inherit'); @endphp
            <option value="inherit" @selected($taxDisplay === 'inherit')>حسب إعدادات النظام</option>
            <option value="exclusive" @selected($taxDisplay === 'exclusive')>الإجمالي قبل الضريبة</option>
            <option value="inclusive" @selected($taxDisplay === 'inclusive')>الإجمالي شامل الضريبة</option>
        </select>
        <small class="text-muted">يتحكم بطريقة ظهور إجمالي السلة للزبون في منيو QR لهذا الفرع.</small>
    </div>

</div>

<div class="d-flex justify-content-end gap-2 mt-4">
    <a href="{{ route('admin.branches.index') }}" class="btn btn-light">إلغاء</a>
    <button class="btn btn-primary">
        <i class="bi bi-check-circle-fill"></i> حفظ
    </button>
</div>
