@csrf
@php
    $po = $po ?? null;
    $existingLines = $po?->items ?? collect();
@endphp

<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-truck"></i>
        <span>معلومات أمر الشراء</span>
    </div>
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label">المورد <span class="req">*</span></label>
            <select name="supplier_id" class="form-select form-select-lg" required>
                <option value="">— اختر مورد —</option>
                @foreach($suppliers as $s)
                    <option value="{{ $s->id }}" @selected(old('supplier_id', $po?->supplier_id)==$s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">تاريخ التسليم المتوقع</label>
            <input type="date" name="expected_at"
                value="{{ old('expected_at', optional($po?->expected_at)->format('Y-m-d')) }}"
                class="form-control form-control-lg">
        </div>
        <div class="col-md-3">
            <label class="form-label">رقم PO</label>
            <input type="text" value="{{ $po?->number ?? '— يُنشأ تلقائياً —' }}" class="form-control form-control-lg" disabled>
        </div>
        <div class="col-12">
            <label class="form-label">ملاحظات</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="تعليمات التسليم، مرجع داخلي، ...">{{ old('notes', $po?->notes) }}</textarea>
        </div>
    </div>
</div>

{{-- Line items --}}
<div class="form-section">
    <div class="form-section-head">
        <i class="bi bi-list-ul"></i>
        <span>البنود</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle" id="po-lines-table">
            <thead>
                <tr>
                    <th style="min-width:260px;">المكوّن</th>
                    <th style="width:130px;">الكمية</th>
                    <th style="width:130px;">الوحدة</th>
                    <th style="width:140px;">السعر / وحدة</th>
                    <th style="width:130px;">الإجمالي</th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody id="po-lines-body">
                @forelse($existingLines as $idx => $line)
                    <tr class="po-line">
                        <td>
                            <select name="lines[{{ $idx }}][ingredient_id]" class="form-select po-ingredient" required>
                                <option value="">— اختر مكون —</option>
                                @foreach($ingredients as $ing)
                                    <option value="{{ $ing->id }}"
                                            data-base-unit="{{ $ing->base_unit_id }}"
                                            data-cost="{{ $ing->cost_per_unit }}"
                                            @selected($line->ingredient_id == $ing->id)>
                                        {{ $ing->name }} ({{ $ing->baseUnit->code ?? '' }})
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="number" step="0.0001" min="0.0001" name="lines[{{ $idx }}][quantity_ordered]"
                            value="{{ $line->quantity_ordered }}" class="form-control po-qty" required></td>
                        <td>
                            <select name="lines[{{ $idx }}][unit_id]" class="form-select po-unit" required>
                                @foreach($units as $u)
                                    <option value="{{ $u->id }}" @selected($line->unit_id == $u->id)>{{ $u->name }} ({{ $u->code }})</option>
                                @endforeach
                            </select>
                        </td>
                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $idx }}][unit_price]"
                            value="{{ $line->unit_price }}" class="form-control po-price" required></td>
                        <td><span class="po-subtotal fw-bold" style="color:var(--primary);">
                            {{ \App\Helpers\Money::format($line->subtotal) }}
                        </span></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger po-remove" title="حذف البند">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    {{-- Start with one blank line --}}
                @endforelse
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td colspan="4" class="text-end fw-bold">الإجمالي الكلي</td>
                    <td colspan="2"><span id="po-grand-total" class="fs-5 fw-bold" style="color:var(--primary);">0.00</span></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <button type="button" class="btn btn-add-recipe" id="po-add-line">
        <i class="bi bi-plus-circle"></i> إضافة بند
    </button>
</div>

<div class="form-actions">
    <a href="{{ $po ? route('admin.purchase-orders.show', $po) : route('admin.purchase-orders.index') }}" class="btn btn-light">
        <i class="bi bi-arrow-right"></i> إلغاء
    </a>
    <button class="btn btn-primary">
        <i class="bi bi-save"></i> حفظ كمسودة
    </button>
</div>

@push('scripts')
<script>
(function () {
    const tbody   = document.getElementById('po-lines-body');
    const addBtn  = document.getElementById('po-add-line');
    const grand   = document.getElementById('po-grand-total');
    const ingOpts = @json($ingredients->map(fn($i) => [
        'id'   => $i->id,
        'name' => $i->name . ' (' . ($i->baseUnit->code ?? '') . ')',
        'base_unit_id' => $i->base_unit_id,
        'cost' => (float) $i->cost_per_unit,
    ]));
    const unitOpts = @json($units->map(fn($u) => ['id' => $u->id, 'name' => $u->name . ' (' . $u->code . ')']));
    const currency = @json(config('restaurant.currency_symbol'));

    let idx = {{ $existingLines->count() }};

    function addLine(prefill = {}) {
        const ingOptsHtml  = ingOpts.map(o => `<option value="${o.id}" data-base-unit="${o.base_unit_id}" data-cost="${o.cost}" ${prefill.ingredient_id == o.id ? 'selected' : ''}>${o.name}</option>`).join('');
        const unitOptsHtml = unitOpts.map(o => `<option value="${o.id}" ${prefill.unit_id == o.id ? 'selected' : ''}>${o.name}</option>`).join('');

        const row = document.createElement('tr');
        row.className = 'po-line';
        row.innerHTML = `
            <td>
                <select name="lines[${idx}][ingredient_id]" class="form-select po-ingredient" required>
                    <option value="">— اختر مكون —</option>
                    ${ingOptsHtml}
                </select>
            </td>
            <td><input type="number" step="0.0001" min="0.0001" name="lines[${idx}][quantity_ordered]" value="${prefill.qty ?? 1}" class="form-control po-qty" required></td>
            <td><select name="lines[${idx}][unit_id]" class="form-select po-unit" required>${unitOptsHtml}</select></td>
            <td><input type="number" step="0.0001" min="0" name="lines[${idx}][unit_price]" value="${prefill.price ?? 0}" class="form-control po-price" required></td>
            <td><span class="po-subtotal fw-bold" style="color:var(--primary);">0.00</span></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger po-remove" title="حذف البند"><i class="bi bi-x-lg"></i></button></td>
        `;
        tbody.appendChild(row);
        idx++;
        recalc();
    }

    function recalc() {
        let total = 0;
        tbody.querySelectorAll('.po-line').forEach(row => {
            const q = parseFloat(row.querySelector('.po-qty').value) || 0;
            const p = parseFloat(row.querySelector('.po-price').value) || 0;
            const sub = q * p;
            total += sub;
            row.querySelector('.po-subtotal').textContent = sub.toFixed(2) + ' ' + currency;
        });
        grand.textContent = total.toFixed(2) + ' ' + currency;
    }

    // Delegated events
    tbody.addEventListener('input', (e) => {
        if (e.target.matches('.po-qty, .po-price')) recalc();
    });
    tbody.addEventListener('change', (e) => {
        if (e.target.matches('.po-ingredient')) {
            const row = e.target.closest('.po-line');
            const selected = e.target.selectedOptions[0];
            if (selected) {
                // Pre-fill base unit & last cost as a hint
                const baseUnit = selected.dataset.baseUnit;
                const lastCost = parseFloat(selected.dataset.cost) || 0;
                const unitSel = row.querySelector('.po-unit');
                if (unitSel && baseUnit) unitSel.value = baseUnit;
                const priceInput = row.querySelector('.po-price');
                if (priceInput && !parseFloat(priceInput.value)) priceInput.value = lastCost;
            }
            recalc();
        }
    });
    tbody.addEventListener('click', (e) => {
        if (e.target.closest('.po-remove')) {
            e.target.closest('.po-line').remove();
            recalc();
        }
    });
    addBtn.addEventListener('click', () => addLine());

    // Start with one blank line on create forms
    if (tbody.children.length === 0) addLine();
    recalc();
})();
</script>
@endpush
