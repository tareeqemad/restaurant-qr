@extends('layouts.admin')

@section('title', 'عكس أو تصحيح قيد')

@section('content')
<x-admin.breadcrumb
    title="عكس أو تصحيح قيد"
    icon="bi-arrow-counterclockwise"
    subtitle="يعكس النظام القيد الأصلي تلقائيا، ويمكنك ترحيل قيد صحيح بدلا منه في نفس العملية"
    :crumbs="[
        ['label' => 'المحاسبة', 'url' => route('admin.accounting.index')],
        ['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')],
        ['label' => $entry->entry_no],
    ]" />

@if($isReversed)
    <div class="alert alert-warning">
        هذا القيد تم عكسه مسبقا. لا يمكن عكس نفس القيد مرتين.
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-5">
        <x-admin.data-panel title="القيد الأصلي" icon="bi-journal-text">
            <div class="d-grid gap-2 small">
                <div><strong>الرقم:</strong> {{ $entry->entry_no }}</div>
                <div><strong>التاريخ:</strong> {{ optional($entry->posted_on)->format('Y-m-d') }}</div>
                <div><strong>الوصف:</strong> {{ $entry->description }}</div>
                <div><strong>الفرع:</strong> {{ $entry->branch?->name ?? 'كل الفروع' }}</div>
            </div>

            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الحساب</th>
                            <th class="text-end">مدين</th>
                            <th class="text-end">دائن</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entry->lines->sortBy('line_no') as $line)
                            <tr>
                                <td>
                                    <strong>{{ $line->account?->code }}</strong>
                                    <span class="text-muted d-block">{{ $line->account?->name }}</span>
                                </td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($line->debit) }}</td>
                                <td class="text-end">{{ \App\Helpers\Money::formatAccounting($line->credit) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-lg-7">
        <x-admin.data-panel title="إجراء التصحيح" icon="bi-tools">
            <form method="POST" action="{{ route('admin.accounting.journal.adjust.store', $entry) }}">
                @csrf

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">نوع الإجراء</label>
                        <select name="mode" id="adjustment-mode" class="form-select" @disabled($isReversed)>
                            <option value="reverse" @selected(old('mode') === 'reverse')>عكس القيد فقط</option>
                            <option value="correct" @selected(old('mode') === 'correct')>عكس ثم ترحيل قيد مصحح</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">تاريخ القيد</label>
                        <input type="date" name="posted_on" value="{{ old('posted_on', now()->toDateString()) }}" class="form-control" @disabled($isReversed) required>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">سبب العكس/التصحيح</label>
                        <textarea name="reason" rows="2" class="form-control" @disabled($isReversed) required>{{ old('reason') }}</textarea>
                    </div>
                    <div class="col-12 correction-only">
                        <label class="form-label fw-bold">وصف القيد المصحح</label>
                        <input type="text" name="correction_description" value="{{ old('correction_description', 'تصحيح قيد '.$entry->entry_no) }}" class="form-control" @disabled($isReversed)>
                    </div>
                </div>

                <div class="correction-only mt-4">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="fw-bold mb-0">سطور القيد المصحح</h6>
                        <span class="text-muted small">عدّل السطور لتكون الصورة الصحيحة بعد العكس</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th style="min-width: 220px;">الحساب</th>
                                    <th>الوصف</th>
                                    <th class="text-end" style="width: 130px;">مدين</th>
                                    <th class="text-end" style="width: 130px;">دائن</th>
                                    <th style="width: 110px;">العملة</th>
                                    <th style="width: 130px;">سعر الصرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($entry->lines->sortBy('line_no')->values() as $i => $line)
                                    <tr>
                                        <td>
                                            <select name="lines[{{ $i }}][account_id]" class="form-select" @disabled($isReversed)>
                                                @foreach($accounts as $account)
                                                    <option value="{{ $account->id }}" @selected((int) old("lines.$i.account_id", $line->account_id) === (int) $account->id)>
                                                        {{ $account->code }} - {{ $account->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="lines[{{ $i }}][description]" value="{{ old("lines.$i.description", $line->description) }}" class="form-control" @disabled($isReversed)>
                                        </td>
                                        <td>
                                            <input type="number" step="0.0001" min="0" name="lines[{{ $i }}][foreign_debit]" value="{{ old("lines.$i.foreign_debit", (float) $line->foreign_debit ?: ((float) $line->debit ?: '')) }}" class="form-control text-end" @disabled($isReversed)>
                                        </td>
                                        <td>
                                            <input type="number" step="0.0001" min="0" name="lines[{{ $i }}][foreign_credit]" value="{{ old("lines.$i.foreign_credit", (float) $line->foreign_credit ?: ((float) $line->credit ?: '')) }}" class="form-control text-end" @disabled($isReversed)>
                                        </td>
                                        <td>
                                            <select name="lines[{{ $i }}][currency_code]" class="form-select" @disabled($isReversed)>
                                                @foreach($currencies as $currency)
                                                    <option value="{{ $currency->code }}" @selected(old("lines.$i.currency_code", $line->currency_code ?: $baseCurrencyCode) === $currency->code)>{{ $currency->code }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="0.000001" min="0.000001" name="lines[{{ $i }}][exchange_rate]" value="{{ old("lines.$i.exchange_rate", (float) ($line->exchange_rate ?: 1)) }}" class="form-control text-end" @disabled($isReversed)>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a href="{{ route('admin.accounting.journal') }}" class="btn btn-light">إلغاء</a>
                    <button class="btn btn-warning" @disabled($isReversed)>
                        <i class="bi bi-arrow-counterclockwise"></i>
                        تنفيذ
                    </button>
                </div>
            </form>
        </x-admin.data-panel>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const mode = document.getElementById('adjustment-mode');
    const sync = () => {
        const show = mode && mode.value === 'correct';
        document.querySelectorAll('.correction-only').forEach((el) => {
            el.style.display = show ? '' : 'none';
        });
    };
    mode?.addEventListener('change', sync);
    sync();
});
</script>
@endpush
@endsection
