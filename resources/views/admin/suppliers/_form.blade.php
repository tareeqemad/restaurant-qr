@csrf
@php
    $supplier = $supplier ?? null;
    $branches = $branches ?? collect();
    $selectedBranchIds = $selectedBranchIds ?? [];
    // Owner-level (or non-existent in legacy callers) ⇒ everything is editable.
    $accessibleBranchIds = $accessibleBranchIds ?? $branches->pluck('id')->all();
@endphp

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
        @php $currency = config('restaurant.currency_symbol', '₪'); @endphp
        <div class="col-md-4">
            <label class="form-label">
                مهلة التوريد بالأيام
                <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                   title="عدد الأيام التي يستغرقها المورد لتسليم الطلب من تاريخ الإرسال."></i>
            </label>
            <input type="number" min="0" max="365" name="lead_time_days"
                   value="{{ old('lead_time_days', $supplier->lead_time_days ?? '') }}"
                   class="form-control" placeholder="مثلاً 2">
            <small class="form-text text-muted d-block mt-1" style="line-height: 1.5;">
                <i class="bi bi-clock"></i>
                الفترة المتوقَّعة بين <strong>إرسال أمر الشراء</strong> ووصول البضاعة. يُستخدم لاقتراح موعد إعادة الطلب قبل أن يَنفد المخزون.
            </small>
        </div>
        <div class="col-md-4">
            <label class="form-label">
                شروط الدفع بالأيام
                <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                   title="مهلة سداد فاتورة المورد من تاريخ الاستلام (Net 30 = دفع خلال 30 يوماً)."></i>
            </label>
            <input type="number" min="0" max="365" name="payment_terms_days"
                   value="{{ old('payment_terms_days', $supplier->payment_terms_days ?? '') }}"
                   class="form-control" placeholder="مثلاً 30">
            <small class="form-text text-muted d-block mt-1" style="line-height: 1.5;">
                <i class="bi bi-cash-coin"></i>
                مهلة دفع الفاتورة بعد استلام البضاعة. <strong>0</strong> = دفع فوري، <strong>30</strong> = آجل شهري.
                يُستخدم لتنبيهك قبل استحقاق الدفعة.
            </small>
        </div>
        <div class="col-md-4">
            <label class="form-label">
                أقل قيمة طلب ({{ $currency }})
                <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                   title="الحد الأدنى الذي يقبله المورد للتسليم. يُحذِّر النظام عند إنشاء أمر شراء أقل من هذا المبلغ."></i>
            </label>
            <input type="number" step="0.01" min="0" name="minimum_order_amount"
                   value="{{ old('minimum_order_amount', $supplier->minimum_order_amount ?? '') }}"
                   class="form-control" placeholder="مثلاً 100">
            <small class="form-text text-muted d-block mt-1" style="line-height: 1.5;">
                <i class="bi bi-bag-check"></i>
                المبلغ الأدنى الذي يقبله المورد لتنفيذ التوصيل. اتركه فارغاً لو لا يوجد حد أدنى.
            </small>
        </div>
        <div class="col-12">
            @php
                $deliveryDays = old('delivery_days', $supplier->delivery_days ?? []);
                $weekDays = [
                    0 => 'الأحد',
                    1 => 'الإثنين',
                    2 => 'الثلاثاء',
                    3 => 'الأربعاء',
                    4 => 'الخميس',
                    5 => 'الجمعة',
                    6 => 'السبت',
                ];
            @endphp
            <label class="form-label">
                أيام التوصيل
                <i class="bi bi-info-circle text-muted small" style="cursor: help;"
                   title="الأيام التي يقوم فيها المورد بالتوصيل أسبوعياً."></i>
            </label>
            <div class="d-flex flex-wrap gap-2 mt-1">
                @foreach($weekDays as $dayNo => $dayName)
                    <label class="d-inline-flex align-items-center gap-2 px-3 py-2 border rounded" style="cursor: pointer; transition: all .15s ease;">
                        <input type="checkbox"
                               name="delivery_days[]"
                               value="{{ $dayNo }}"
                               class="form-check-input m-0"
                               @checked(in_array($dayNo, array_map('intval', $deliveryDays ?? [])))>
                        <span>{{ $dayName }}</span>
                    </label>
                @endforeach
            </div>
            <small class="form-text text-muted d-block mt-1">
                <i class="bi bi-calendar-week"></i>
                الأيام المحددة فقط ستُقترح لمواعيد استلام أوامر الشراء.
            </small>
        </div>
        <div class="col-12">
            <label class="form-label">ملاحظات</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="شروط الدفع، أوقات التوصيل، إلخ...">{{ old('notes', $supplier->notes ?? '') }}</textarea>
        </div>
    </div>
</div>

@if($branches->count() > 0)
    <div class="form-section">
        <div class="form-section-head">
            <i class="bi bi-building"></i>
            <span>الفروع التي يخدمها هذا المورّد <span class="req">*</span></span>
        </div>
        <div class="alert alert-info py-2 mb-2" style="font-size: 13px;">
            <i class="bi bi-info-circle"></i>
            اختر فرعاً واحداً على الأقل. موظفو الفرع لن يروا هذا المورّد إلا إذا كان مرتبطاً بفرعهم.
        </div>
        <div class="row g-2">
            @foreach($branches as $branch)
                @php
                    $isChecked   = in_array($branch->id, old('branch_ids', $selectedBranchIds));
                    $isEditable  = in_array($branch->id, $accessibleBranchIds);
                @endphp
                <div class="col-md-4 col-sm-6">
                    <label class="d-flex align-items-center gap-2 px-3 py-2 border rounded mb-0"
                           style="cursor: {{ $isEditable ? 'pointer' : 'not-allowed' }}; transition: all .15s ease; min-height: 46px;
                                  @if(!$isEditable) background: rgba(108, 117, 125, .06); @endif"
                           @if(!$isEditable) title="هذا الفرع خارج نطاق صلاحيتك — لا تستطيع تعديل الربط به." @endif>
                        @if($isEditable)
                            <input type="checkbox"
                                   name="branch_ids[]"
                                   value="{{ $branch->id }}"
                                   class="form-check-input m-0 flex-shrink-0"
                                   @checked($isChecked)>
                        @else
                            <input type="checkbox"
                                   class="form-check-input m-0 flex-shrink-0"
                                   @checked($isChecked)
                                   disabled>
                        @endif
                        <span class="flex-grow-1 @if(!$isEditable) text-muted @endif">{{ $branch->name }}</span>
                        @if(!$isEditable)
                            <i class="bi bi-lock-fill text-muted small flex-shrink-0" title="خارج صلاحيتك"></i>
                        @endif
                    </label>
                </div>
            @endforeach
        </div>
    </div>
@endif

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
