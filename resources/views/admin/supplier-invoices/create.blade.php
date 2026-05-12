@extends('layouts.admin')
@section('title', 'فاتورة مورد جديدة')

@section('content')
<x-admin.breadcrumb title="فاتورة مورد جديدة" icon="bi-plus-circle-fill"
    :crumbs="[['label' => 'فواتير الموردين', 'url' => route('admin.supplier-invoices.index')]]" />

<x-admin.data-panel title="فاتورة مورد جديدة" icon="bi-plus-circle-fill">
    <x-slot:actions>
        <a href="{{ route('admin.supplier-invoices.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-right"></i> رجوع للقائمة
        </a>
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
                            <option value="{{ $s->id }}" @selected(old('supplier_id', $po?->supplier_id) == $s->id)>
                                {{ $s->name }}
                                @if($s->payment_terms_days !== null)
                                    — دفع {{ $s->payment_terms_days }} يوم
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">أمر شراء مرتبط</label>
                    <select name="purchase_order_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن أمر شراء...">
                        <option value="">— غير مرتبط —</option>
                        @foreach($pos as $p)
                            <option value="{{ $p->id }}" @selected(old('purchase_order_id', $po?->id) == $p->id)>
                                {{ $p->number }} ({{ $p->supplier?->name }}) — {{ \App\Helpers\Money::format($p->total) }}
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
                    <small class="text-muted">لو تركته فارغاً يستخدم شروط دفع المورد إن وجدت.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">صورة الفاتورة</label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
        </div>

        @if($po)
            <div class="form-section">
                <div class="form-section-head">
                    <i class="bi bi-list-check"></i>
                    <span>بنود الفاتورة مقابل أمر الشراء {{ $po->number }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle" id="supplier-invoice-lines">
                        <thead class="bg-light">
                            <tr>
                                <th>المكوّن</th>
                                <th>مستلم</th>
                                <th>كمية الفاتورة</th>
                                <th>سعر الفاتورة</th>
                                <th>ضريبة</th>
                                <th>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($po->items as $idx => $line)
                                @php
                                    // Default price: the ACTUAL received price the cashier
                                    // recorded at receipt time — not the original ordered
                                    // price on the PO line. Suppliers often deliver at a
                                    // different price than the quote (market fluctuations,
                                    // discount applied, premium charged) and the supplier
                                    // invoice must match the cash they actually want.
                                    //
                                    // Resolution order:
                                    //   1. Weighted-average over all receipts for this line
                                    //      (handles partial receipts at different prices).
                                    //   2. The most recent receipt's price.
                                    //   3. Fallback: the PO ordered price (only when nothing
                                    //      has been received yet — edge case).
                                    $receipts = $line->receiptItems ?? collect();
                                    if ($receipts->isNotEmpty()) {
                                        $totalQty   = (float) $receipts->sum('quantity_received');
                                        $totalValue = (float) $receipts->sum(fn ($r) => (float) $r->quantity_received * (float) $r->unit_price);
                                        $receivedPrice = $totalQty > 0
                                            ? $totalValue / $totalQty
                                            : (float) $receipts->sortByDesc('id')->first()->unit_price;
                                    } else {
                                        $receivedPrice = (float) $line->unit_price;
                                    }
                                    $qty   = (float) old("lines.$idx.quantity", $line->quantity_received ?: $line->quantity_ordered);
                                    $price = (float) old("lines.$idx.unit_price", $receivedPrice);
                                @endphp
                                <tr class="invoice-line">
                                    <td>
                                        <input type="hidden" name="lines[{{ $idx }}][purchase_order_item_id]" value="{{ $line->id }}">
                                        <input type="hidden" name="lines[{{ $idx }}][ingredient_id]" value="{{ $line->ingredient_id }}">
                                        <input type="hidden" name="lines[{{ $idx }}][unit_id]" value="{{ $line->unit_id }}">
                                        <input type="hidden" name="lines[{{ $idx }}][description]" value="{{ $line->ingredient?->name }}">
                                        <div class="fw-bold">{{ $line->ingredient?->name }}</div>
                                        <small class="text-muted">{{ $line->unit?->code }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $line->isFullyReceived() ? 'success' : 'warning' }}">
                                            {{ \App\Helpers\Qty::format($line->quantity_received) }}
                                        </span>
                                    </td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $idx }}][quantity]" value="{{ $qty }}" class="form-control invoice-qty"></td>
                                    <td>
                                        <input type="number" step="0.0001" min="0"
                                               name="lines[{{ $idx }}][unit_price]"
                                               value="{{ $price }}"
                                               class="form-control invoice-price">
                                        @php
                                            $orderedPrice = (float) $line->unit_price;
                                            $diff = $price - $orderedPrice;
                                        @endphp
                                        @if(abs($diff) > 0.001 && $orderedPrice > 0)
                                            <small class="d-block mt-1 fs-11 {{ $diff > 0 ? 'text-warning' : 'text-info' }}"
                                                   title="السعر في الـ PO الأصلي كان {{ number_format($orderedPrice, 4) }}">
                                                <i class="bi bi-info-circle"></i>
                                                سعر الاستلام الفعلي
                                                ({{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 4) }}
                                                vs الـ PO)
                                            </small>
                                        @endif
                                    </td>
                                    <td><input type="number" step="0.0001" min="0" name="lines[{{ $idx }}][tax_total]" value="{{ old("lines.$idx.tax_total", 0) }}" class="form-control invoice-tax"></td>
                                    <td class="fw-bold invoice-line-total" style="color: var(--primary);">0.00</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

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
</x-admin.data-panel>

@push('scripts')
<script>
(function () {
    const sub = document.getElementById('si-sub');
    const tax = document.getElementById('si-tax');
    const total = document.getElementById('si-total');
    const lineRows = Array.from(document.querySelectorAll('.invoice-line'));

    function recalcLines() {
        if (!lineRows.length) return;

        let subtotal = 0;
        let taxTotal = 0;
        lineRows.forEach(row => {
            const qty = parseFloat(row.querySelector('.invoice-qty')?.value || '0') || 0;
            const price = parseFloat(row.querySelector('.invoice-price')?.value || '0') || 0;
            const lineTax = parseFloat(row.querySelector('.invoice-tax')?.value || '0') || 0;
            const lineSubtotal = qty * price;
            subtotal += lineSubtotal;
            taxTotal += lineTax;
            row.querySelector('.invoice-line-total').textContent = (lineSubtotal + lineTax).toFixed(2);
        });

        sub.value = subtotal.toFixed(2);
        tax.value = taxTotal.toFixed(2);
        total.value = (subtotal + taxTotal).toFixed(2);
    }

    function recalcHeader() {
        if (lineRows.length) return;
        const value = (parseFloat(sub.value) || 0) + (parseFloat(tax.value) || 0);
        total.value = value.toFixed(2);
    }

    document.addEventListener('input', event => {
        if (event.target.matches('.invoice-qty, .invoice-price, .invoice-tax')) {
            recalcLines();
        }
        if (event.target === sub || event.target === tax) {
            recalcHeader();
        }
    });

    recalcLines();
})();
</script>
@endpush
@endsection
