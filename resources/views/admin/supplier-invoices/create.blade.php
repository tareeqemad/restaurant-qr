@extends('layouts.admin')
@section('title', 'فاتورة مورد جديدة')

@section('content')
<x-admin.breadcrumb title="فاتورة مورد جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'فواتير الموردين', 'url' => route('admin.supplier-invoices.index')]]" />

<x-admin.data-panel title="فاتورة مورد جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
    </x-slot:actions>


    <form method="POST" action="{{ route('admin.supplier-invoices.store') }}" enctype="multipart/form-data">
            @csrf

            <div class="form-section">
                <div class="form-section-head">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>معلومات الفاتورة</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">رقم الفاتورة من المورد <span class="req">*</span></label>
                        <input name="number" value="{{ old('number') }}" class="form-control form-control-lg" required dir="ltr">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">المورد <span class="req">*</span></label>
                        <select name="supplier_id" class="form-select form-select-lg" required data-relax-choice data-choice-search-placeholder="ابحث عن مورد...">
                            <option value="">— اختر —</option>
                            @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" @selected(old('supplier_id', $po?->supplier_id)==$s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">أمر شراء مرتبط (اختياري)</label>
                        <select name="purchase_order_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن أمر شراء...">
                            <option value="">— غير مرتبط —</option>
                            @foreach($pos as $p)
                                <option value="{{ $p->id }}" @selected(old('purchase_order_id', $po?->id)==$p->id)>
                                    {{ $p->number }} ({{ $p->supplier?->name }}) — {{ number_format($p->total, 2) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">تاريخ الفاتورة <span class="req">*</span></label>
                        <input type="date" name="invoice_date" value="{{ old('invoice_date', today()->toDateString()) }}" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">تاريخ الاستحقاق</label>
                        <input type="date" name="due_date" value="{{ old('due_date') }}" class="form-control">
</x-admin.data-panel>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <i class="bi bi-cash-coin"></i>
                    <span>المبالغ</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">الفرعي</label>
                        <input type="number" step="0.01" min="0" name="subtotal" value="{{ old('subtotal', $po?->subtotal ?? 0) }}" class="form-control" id="si-sub">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الضريبة</label>
                        <input type="number" step="0.01" min="0" name="tax_total" value="{{ old('tax_total', 0) }}" class="form-control" id="si-tax">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الإجمالي <span class="req">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="total" value="{{ old('total', $po?->total ?? 0) }}" class="form-control form-control-lg fw-bold" id="si-total" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-head">
                    <i class="bi bi-paperclip"></i>
                    <span>مرفقات</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">صورة الفاتورة (PDF/JPG/PNG — حد أقصى 5MB)</label>
                        <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light">
                    <i class="bi bi-arrow-right"></i> إلغاء
                </a>
                <button class="btn btn-primary">
                    <i class="bi bi-save"></i> حفظ الفاتورة
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const sub   = document.getElementById('si-sub');
    const tax   = document.getElementById('si-tax');
    const total = document.getElementById('si-total');

    const recalc = () => {
        const v = (parseFloat(sub.value) || 0) + (parseFloat(tax.value) || 0);
        total.value = v.toFixed(2);
    };

    // Only auto-recalc if the user hasn't manually set a total
    let totalEdited = false;
    total.addEventListener('input', () => { totalEdited = true; });
    sub.addEventListener('input', () => { if (!totalEdited) recalc(); });
    tax.addEventListener('input', () => { if (!totalEdited) recalc(); });
})();
</script>
@endsection
