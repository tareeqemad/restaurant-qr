@extends('layouts.admin')
@section('title', 'قيد محاسبي يدوي')

@section('content')
<x-admin.breadcrumb
    title="قيد محاسبي يدوي"
    icon="bi-journal-plus"
    subtitle="سجّل أي عملية محاسبية مباشرة على أي حساب في الشجرة"
    :crumbs="[
        ['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')],
    ]"/>

<div class="alert alert-light border d-flex align-items-start gap-3 mb-3"
     style="border-right:4px solid var(--bs-primary)!important;">
    <i class="bi bi-info-circle-fill text-primary fs-4 mt-1"></i>
    <div class="small">
        <strong class="d-block mb-1">متى تستعمل القيد اليدوي؟</strong>
        <ul class="mb-0" style="padding-inline-start: 1.2rem;">
            <li>لتسجيل عمليات لا يلتقطها النظام تلقائياً (مثلاً تسوية بنكية، استهلاك أصول، تحويل بين حسابات داخلية).</li>
            <li>لاستخدام <strong>الحسابات المخصّصة</strong> اللي أنشأتها بشجرة الحسابات (الحسابات النظامية يستخدمها النظام تلقائياً).</li>
            <li><strong>قاعدة ذهبية:</strong> مجموع المدين = مجموع الدائن. النظام يرفض القيد لو ما اتوازن.</li>
        </ul>
    </div>
</div>

<form method="POST" action="{{ route('admin.accounting.manual-entry.store') }}"
      x-data="manualEntry()" x-init="addLine(); addLine()">
    @csrf

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
                            <th style="width:50%">الحساب</th>
                            <th style="width:18%" class="text-end">مدين</th>
                            <th style="width:18%" class="text-end">دائن</th>
                            <th>وصف السطر (اختياري)</th>
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
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           :name="`lines[${idx}][debit]`" x-model.number="line.debit"
                                           @input="if (line.debit > 0) line.credit = 0"
                                           class="form-control form-control-sm text-end" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           :name="`lines[${idx}][credit]`" x-model.number="line.credit"
                                           @input="if (line.credit > 0) line.debit = 0"
                                           class="form-control form-control-sm text-end" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="text" :name="`lines[${idx}][description]`" x-model="line.description"
                                           class="form-control form-control-sm" maxlength="255"
                                           placeholder="(اختياري)">
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
                            <td></td>
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
        addLine() {
            this.lines.push({ key: this.nextKey++, account_id: '', debit: 0, credit: 0, description: '' });
        },
        removeLine(idx) {
            if (this.lines.length > 2) this.lines.splice(idx, 1);
        },
        totalDebit()  { return this.lines.reduce((s, l) => s + (parseFloat(l.debit)  || 0), 0); },
        totalCredit() { return this.lines.reduce((s, l) => s + (parseFloat(l.credit) || 0), 0); },
        isBalanced() {
            const d = this.totalDebit(), c = this.totalCredit();
            return Math.abs(d - c) < 0.01 && d > 0;
        },
    };
}
</script>
@endpush
@endsection
