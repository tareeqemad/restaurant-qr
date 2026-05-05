{{--
  Shared form partial for create + edit screens. Expects:
    $expense   App\Models\Expense (new or existing)
    $suppliers Collection of suppliers
    $openShift App\Models\Shift|null — current open shift in the active branch
--}}
@php
    $cur = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', 'د.أ'));
@endphp

<div class="row g-3">
    {{-- Description --}}
    <div class="col-md-8">
        <label class="form-label">الوصف <span class="text-danger">*</span></label>
        <input type="text" name="description" class="form-control"
               value="{{ old('description', $expense->description) }}"
               required maxlength="255"
               placeholder="مثلاً: فاتورة الكهرباء لشهر نيسان">
        @error('description')<small class="text-danger">{{ $message }}</small>@enderror
    </div>

    {{-- Amount --}}
    <div class="col-md-4">
        <label class="form-label">المبلغ ({{ $cur }}) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" class="form-control text-start"
               value="{{ old('amount', $expense->amount) }}" required dir="ltr">
        @error('amount')<small class="text-danger">{{ $message }}</small>@enderror
    </div>

    {{-- Category — driven by Lookups admin screen, not a hardcoded enum --}}
    <div class="col-md-4">
        <label class="form-label">
            التصنيف <span class="text-danger">*</span>
            @can('viewAny', \App\Models\Lookup::class)
                <a href="{{ route('admin.lookups.index', ['group' => 'expense_categories']) }}" target="_blank"
                   class="ms-1 small text-muted" title="إدارة التصنيفات">
                    <i class="bi bi-gear-fill"></i>
                </a>
            @endcan
        </label>
        <select name="expense_category_id" class="form-select" required>
            <option value="">— اختر —</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected(old('expense_category_id', $expense->expense_category_id)==$cat->id)>
                    {{ $cat->label }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- Payment method --}}
    <div class="col-md-4">
        <label class="form-label">طريقة الدفع <span class="text-danger">*</span></label>
        <select name="payment_method" id="exp_payment_method" class="form-select" required>
            @foreach($paymentMethods as $key => $label)
                <option value="{{ $key }}" @selected(old('payment_method', $expense->payment_method)===$key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Expense date --}}
    <div class="col-md-4">
        <label class="form-label">تاريخ المصروف <span class="text-danger">*</span></label>
        <input type="date" name="expense_date" class="form-control"
               value="{{ old('expense_date', optional($expense->expense_date)->format('Y-m-d') ?: now()->toDateString()) }}"
               required max="{{ now()->toDateString() }}">
    </div>

    {{-- Payment reference (cheque #, transfer ref) --}}
    <div class="col-md-6">
        <label class="form-label">رقم المرجع <small class="text-muted">(شيك / تحويل)</small></label>
        <input type="text" name="payment_reference" class="form-control"
               value="{{ old('payment_reference', $expense->payment_reference) }}"
               maxlength="100" placeholder="اختياري">
    </div>

    {{-- Vendor name (free text) --}}
    <div class="col-md-6">
        <label class="form-label">المورّد / الجهة</label>
        <input type="text" name="vendor_name" class="form-control"
               value="{{ old('vendor_name', $expense->vendor_name) }}"
               maxlength="150" placeholder="اسم الجهة المستفيدة">
    </div>

    {{-- Supplier (linked) --}}
    <div class="col-md-6">
        <label class="form-label">مورّد مسجّل <small class="text-muted">(اختياري)</small></label>
        <select name="supplier_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن مورد...">
            <option value="">— لا شيء —</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected(old('supplier_id', $expense->supplier_id)==$s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Open shift link --}}
    <div class="col-md-6">
        <label class="form-label">ربط بوردية كاشير</label>
        @if($openShift)
            <select name="shift_id" class="form-select">
                <option value="">— بدون ربط —</option>
                <option value="{{ $openShift->id }}" @selected(old('shift_id', $expense->shift_id)==$openShift->id) selected>
                    وردية #{{ $openShift->id }} — {{ $openShift->user->name ?? 'كاشير' }} (مفتوحة منذ {{ $openShift->opened_at->format('H:i') }})
                </option>
            </select>
            <small class="text-muted">سيتم خصم المبلغ من درج هذه الوردية عند الاعتماد إذا كان الدفع نقداً.</small>
        @else
            <input type="text" class="form-control" disabled value="لا توجد وردية مفتوحة حالياً">
            <small class="text-muted">سيتم تسجيل المصروف بدون ربط بدرج الكاشير.</small>
        @endif
    </div>

    {{-- Attachment --}}
    <div class="col-md-6">
        <label class="form-label">إرفاق إيصال <small class="text-muted">(PDF / صورة، حد أقصى 5MB)</small></label>
        <input type="file" name="attachment" class="form-control"
               accept=".pdf,.jpg,.jpeg,.png,.webp">
        @if($expense->exists && $expense->attachment_path)
            <small class="d-block mt-1">
                <i class="bi bi-paperclip"></i>
                <a href="{{ asset('storage/'.$expense->attachment_path) }}" target="_blank">الإيصال الحالي</a>
                — اختر ملفاً جديداً لاستبداله.
            </small>
        @endif
    </div>

    {{-- Notes --}}
    <div class="col-12">
        <label class="form-label">ملاحظات</label>
        <textarea name="notes" class="form-control" rows="3" maxlength="1000"
                  placeholder="تفاصيل إضافية…">{{ old('notes', $expense->notes) }}</textarea>
    </div>

    @if($categories->isEmpty())
        <div class="col-12">
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle-fill"></i>
                لا توجد تصنيفات مصروفات مفعّلة. أضف تصنيفاً من
                <a href="{{ route('admin.lookups.index', ['group' => 'expense_categories']) }}" class="alert-link">إدارة الثوابت</a>
                قبل حفظ المصروف.
            </div>
        </div>
    @endif
</div>
