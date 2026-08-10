@extends('layouts.admin')
@section('title', 'قيد محاسبي يدوي')

@section('content')
<x-admin.breadcrumb
    title="قيد يدوي"
    icon="bi-journal-plus"
    subtitle="لعملية لم يسجلها النظام تلقائياً فقط"
    :crumbs="[
        ['label' => 'المحاسبة', 'url' => route('admin.accounting.index')],
        ['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')],
    ]"/>

<div class="alert alert-light border d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-info-circle-fill text-primary"></i>
    <span>اختر حساباً مديناً وحساباً دائناً بنفس القيمة. النظام يتأكد من التوازن قبل الحفظ.</span>
</div>

<form method="POST" action="{{ route('admin.accounting.manual-entry.store') }}"
      x-data="manualEntry()" x-init="addLine(); addLine()">
    @csrf
    {{-- One-shot token: blocks a duplicate post (F5 / back-then-resubmit) since
         a manual journal has no source document to dedupe on. --}}
    <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">

    {{-- ────────── Header ────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-light"><strong>بيانات القيد</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">تاريخ القيد *</label>
                    <input type="date" name="posted_on" value="{{ old('posted_on', now()->toDateString()) }}"
                           class="form-control" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-bold">وصف القيد *</label>
                    <input type="text" name="description" value="{{ old('description') }}"
                           class="form-control" required maxlength="255"
                           placeholder="مثلاً: تسوية فروقات بنكية لشهر مايو">
                </div>
            </div>
        </div>
    </div>

    {{-- ────────── Lines ────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-light d-flex align-items-center">
            <strong><i class="bi bi-list-ol"></i> سطور القيد</strong>
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto"
                    @click="addLine()">
                <i class="bi bi-plus-lg"></i> سطر جديد
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th style="min-width:260px">الحساب</th>
                            <th style="min-width:130px" class="text-end">مدين</th>
                            <th style="min-width:130px" class="text-end">دائن</th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(line, idx) in lines" :key="line.key">
                            <tr>
                                <td>
                                    <select :name="`lines[${idx}][account_id]`" x-model="line.account_id"
                                            class="form-select form-select-sm" required>
                                        <option value="">— اختر حساباً —</option>
                                        @foreach($accounts->groupBy('type') as $type => $group)
                                            @php $typeLabel = ['asset'=>'أصول','liability'=>'التزامات','equity'=>'حقوق','revenue'=>'إيرادات','contra_revenue'=>'مقابل إيراد','expense'=>'مصاريف'][$type] ?? $type; @endphp
                                            <optgroup label="{{ $typeLabel }}">
                                                @foreach($group as $acc)
                                                    <option value="{{ $acc->id }}">
                                                        {{ $acc->code }} — {{ $acc->name }}
                                                        @if($acc->isProtected()) 🔒 @endif
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <details class="mt-2">
                                        <summary class="small text-muted" style="cursor:pointer">عملة أو وصف مختلف (اختياري)</summary>
                                        <div class="row g-2 mt-1">
                                            <div class="col-sm-4">
                                                <label class="form-label small mb-1">العملة</label>
                                                <select :name="`lines[${idx}][currency_code]`" x-model="line.currency_code"
                                                        @change="line.exchange_rate = line.currency_code === baseCurrencyCode ? 1 : ''"
                                                        class="form-select form-select-sm">
                                                    @foreach($currencies as $currency)
                                                        <option value="{{ $currency->code }}">{{ $currency->code }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-sm-4">
                                                <label class="form-label small mb-1">سعر الصرف</label>
                                                <input type="number" step="0.000001" min="0.000001"
                                                       :name="`lines[${idx}][exchange_rate]`" x-model.number="line.exchange_rate"
                                                       class="form-control form-control-sm text-end">
                                            </div>
                                            <div class="col-sm-4">
                                                <label class="form-label small mb-1">وصف السطر</label>
                                                <input type="text" :name="`lines[${idx}][description]`" x-model="line.description"
                                                       class="form-control form-control-sm" maxlength="255">
                                            </div>
                                        </div>
                                    </details>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           :name="`lines[${idx}][foreign_debit]`" x-model.number="line.foreign_debit"
                                           @input="if (line.foreign_debit > 0) line.foreign_credit = 0"
                                           class="form-control form-control-sm text-end" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           :name="`lines[${idx}][foreign_credit]`" x-model.number="line.foreign_credit"
                                           @input="if (line.foreign_credit > 0) line.foreign_debit = 0"
                                           class="form-control form-control-sm text-end" placeholder="0.00">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            @click="removeLine(idx)" :disabled="lines.length <= 2"
                                            title="حذف السطر">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-light fw-bold">
                        <tr>
                            <td class="text-end">الإجمالي</td>
                            <td class="text-end" x-text="totalDebit().toFixed(2)"></td>
                            <td class="text-end" x-text="totalCredit().toFixed(2)"></td>
                            <td>
                                <span x-show="isBalanced()" class="badge bg-success">
                                    <i class="bi bi-check-circle"></i> متوازن
                                </span>
                                <span x-show="! isBalanced()" class="badge bg-danger">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    فرق: <span x-text="Math.abs(totalDebit() - totalCredit()).toFixed(2)"></span>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- ────────── Actions ────────── --}}
    <div class="d-flex justify-content-end gap-2">
        <a href="{{ route('admin.accounting.journal') }}" class="btn btn-light">إلغاء</a>
        <button class="btn btn-primary" :disabled="! isBalanced() || lines.filter(l => l.account_id).length < 2">
            <i class="bi bi-check-circle-fill"></i> ترحيل القيد
        </button>
    </div>
</form>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script>
function manualEntry() {
    return {
        lines: [],
        nextKey: 1,
        baseCurrencyCode: @js($baseCurrencyCode),
        addLine() {
            this.lines.push({
                key: this.nextKey++,
                account_id: '',
                foreign_debit: 0,
                foreign_credit: 0,
                currency_code: this.baseCurrencyCode,
                exchange_rate: 1,
                description: ''
            });
        },
        removeLine(idx) {
            if (this.lines.length > 2) this.lines.splice(idx, 1);
        },
        baseAmount(amount, rate) { return (parseFloat(amount) || 0) * (parseFloat(rate) || 1); },
        totalDebit()  { return this.lines.reduce((s, l) => s + this.baseAmount(l.foreign_debit, l.exchange_rate), 0); },
        totalCredit() { return this.lines.reduce((s, l) => s + this.baseAmount(l.foreign_credit, l.exchange_rate), 0); },
        isBalanced() {
            const d = this.totalDebit(), c = this.totalCredit();
            return Math.abs(d - c) < 0.01 && d > 0;
        },
    };
}
</script>
@endpush
@endsection
