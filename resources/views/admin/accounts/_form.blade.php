@csrf
@php
    $a = $account ?? null;
    $isSystem = (bool) $a?->isProtected();
    // Prefilled parent (from ?parent= on create) seeds type + parent_id
    // so the form opens already scoped — accountant just types the new
    // code and name.
    $pre = $prefilledParent ?? null;
    $oldType  = old('type', $a?->type ?? $pre?->type ?? 'asset');
    $oldBal   = old('normal_balance', $a?->normal_balance ?? $pre?->normal_balance ?? 'debit');
    $oldParentId = old('parent_account_id', $a?->parent_account_id ?? $pre?->id);
@endphp

@if($pre)
    <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-diagram-3-fill fs-4"></i>
        <div>
            <strong class="d-block">إضافة حساب فرعي تحت:</strong>
            <code>{{ $pre->code }}</code> — {{ $pre->name }}
            <small class="text-muted d-block">النوع وطبيعة الرصيد عُبّئت تلقائياً لتطابق الحساب الأب.</small>
        </div>
    </div>
@endif

@if($isSystem)
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-shield-lock-fill fs-4 mt-1"></i>
        <div>
            <strong class="d-block">هذا حساب نظامي محمي</strong>
            <small>
                النظام يعتمد على كود <code>{{ $a->code }}</code> في القيود التشغيلية.
                تقدر تعدّل <strong>الاسم والوصف</strong> فقط — الكود والنوع وطبيعة الرصيد مقفولة، والحساب لا يمكن تعطيله أو حذفه.
            </small>
        </div>
    </div>
@endif

<div class="card mb-3">
    <div class="card-header bg-light"><strong>الأساسيات</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">الكود *</label>
                <input type="text" name="code" value="{{ old('code', $a?->code) }}"
                       class="form-control" required maxlength="32"
                       placeholder="مثلاً: 5101"
                       @if($isSystem) readonly @endif>
                @if($isSystem)
                    <small class="text-muted"><i class="bi bi-lock"></i> الكود مقفول لحسابات النظام.</small>
                @else
                    <small class="text-muted">رقم فريد للحساب. لا يتغير بعد الإنشاء.</small>
                @endif
            </div>
            <div class="col-md-8">
                <label class="form-label fw-bold">الاسم *</label>
                <input type="text" name="name" value="{{ old('name', $a?->name) }}"
                       class="form-control" required maxlength="191"
                       placeholder="مثلاً: مصاريف صيانة">
            </div>
            <div class="col-12">
                <label class="form-label">الوصف (اختياري)</label>
                <textarea name="description" rows="2" class="form-control"
                          placeholder="شرح مختصر متى يستعمل هذا الحساب">{{ old('description', $a?->description) }}</textarea>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-light"><strong>التصنيف</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">النوع *</label>
                <select name="type" class="form-select" required {{ $isSystem ? 'disabled' : '' }}>
                    @foreach([
                        'asset' => 'أصل',
                        'liability' => 'التزام',
                        'equity' => 'حقوق ملكية',
                        'revenue' => 'إيراد',
                        'contra_revenue' => 'مقابل إيراد',
                        'expense' => 'مصروف',
                    ] as $val => $label)
                        <option value="{{ $val }}" @selected($oldType === $val)>{{ $label }}</option>
                    @endforeach
                </select>
                @if($isSystem)
                    <input type="hidden" name="type" value="{{ $a->type }}">
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">طبيعة الرصيد *</label>
                <select name="normal_balance" class="form-select" required {{ $isSystem ? 'disabled' : '' }}>
                    <option value="debit"  @selected($oldBal === 'debit')>مدين</option>
                    <option value="credit" @selected($oldBal === 'credit')>دائن</option>
                </select>
                @if($isSystem)
                    <input type="hidden" name="normal_balance" value="{{ $a->normal_balance }}">
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label">ترتيب العرض</label>
                <input type="number" name="display_order" min="0" max="65535"
                       value="{{ old('display_order', $a?->display_order ?? 0) }}"
                       class="form-control">
                <small class="text-muted">الأصغر أولاً عند العرض.</small>
            </div>
            <div class="col-12">
                <label class="form-label">الحساب الأب (اختياري)</label>
                <select name="parent_account_id" class="form-select">
                    <option value="">— حساب جذر (بدون أب) —</option>
                    @foreach($parentOptions as $opt)
                        @if(! $a || $opt->id !== $a->id)
                            <option value="{{ $opt->id }}" @selected($oldParentId == $opt->id)>
                                {{ $opt->code }} — {{ $opt->name }}
                                ({{ ['asset'=>'أصل','liability'=>'التزام','equity'=>'حقوق','revenue'=>'إيراد','contra_revenue'=>'مقابل','expense'=>'مصروف'][$opt->type] ?? $opt->type }})
                            </option>
                        @endif
                    @endforeach
                </select>
                <small class="text-muted">نوع الأب يجب يطابق نوع هذا الحساب.</small>
            </div>
            @if(! $isSystem)
                <div class="col-12">
                    <div class="form-check form-switch fs-5">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="is_active" name="is_active" value="1" class="form-check-input"
                               @checked(old('is_active', $a?->is_active ?? true))>
                        <label for="is_active" class="form-check-label fw-bold">الحساب نشط</label>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2">
    <a href="{{ route('admin.accounts.index') }}" class="btn btn-light">إلغاء</a>
    <button class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> حفظ</button>
</div>
