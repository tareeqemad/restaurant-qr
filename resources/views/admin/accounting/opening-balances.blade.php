@extends('layouts.admin')

@section('title', 'الأرصدة الافتتاحية')

@section('content')
<x-admin.breadcrumb
    title="الأرصدة الافتتاحية"
    icon="bi-door-open"
    subtitle="ابدأ الدفاتر بأرصدة الصندوق والبنك والمخزون والذمم ورأس المال"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<div class="alert alert-info d-flex align-items-start gap-3">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        أدخل الأرصدة كما هي في يوم بدء النظام الحقيقي. إذا لم يتوازن القيد، سيضيف النظام الفرق تلقائياً على حساب
        <strong>{{ $equityAccount->code }} - {{ $equityAccount->name }}</strong>.
    </div>
</div>

<form method="POST" action="{{ route('admin.accounting.opening-balances.store') }}">
    @csrf

    <div class="card mb-3">
        <div class="card-header bg-light"><strong>بيانات القيد الافتتاحي</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">تاريخ البداية</label>
                    <input type="date" name="posted_on" value="{{ old('posted_on', now()->toDateString()) }}" class="form-control" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-bold">الوصف</label>
                    <input type="text" name="description" value="{{ old('description', 'الأرصدة الافتتاحية') }}" class="form-control" required>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="hidden" name="auto_balance" value="0">
                        <input type="checkbox" id="auto_balance" name="auto_balance" value="1" class="form-check-input" checked>
                        <label for="auto_balance" class="form-check-label fw-bold">موازنة الفرق تلقائياً على حساب الأرصدة الافتتاحية</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-admin.data-panel title="الحسابات" icon="bi-list-ol" :count="$accounts->count()">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>الحساب</th>
                        <th>النوع</th>
                        <th class="text-end">رصيد مدين</th>
                        <th class="text-end">رصيد دائن</th>
                        <th>العملة</th>
                        <th>سعر الصرف</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($accounts as $index => $account)
                        <tr>
                            <td>
                                <input type="hidden" name="lines[{{ $index }}][account_id]" value="{{ $account->id }}">
                                <strong>{{ $account->code }} - {{ $account->name }}</strong>
                            </td>
                            <td>{{ $account->typeLabel() }}</td>
                            <td><input type="number" step="0.01" min="0" name="lines[{{ $index }}][foreign_debit]" class="form-control text-end" placeholder="0.00"></td>
                            <td><input type="number" step="0.01" min="0" name="lines[{{ $index }}][foreign_credit]" class="form-control text-end" placeholder="0.00"></td>
                            <td>
                                <select name="lines[{{ $index }}][currency_code]" class="form-select">
                                    @foreach($currencies as $currency)
                                        <option value="{{ $currency->code }}" @selected($currency->code === $baseCurrencyCode)>{{ $currency->code }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input type="number" step="0.000001" min="0.000001" name="lines[{{ $index }}][exchange_rate]" class="form-control text-end"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.data-panel>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <a href="{{ route('admin.accounting.journal') }}" class="btn btn-light">رجوع</a>
        <button class="btn btn-primary"><i class="bi bi-check-circle"></i> ترحيل الأرصدة</button>
    </div>
</form>

@if($openingEntries->isNotEmpty())
    <x-admin.data-panel title="آخر قيود افتتاحية" icon="bi-clock-history" class="mt-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>رقم القيد</th>
                        <th>التاريخ</th>
                        <th>الوصف</th>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($openingEntries as $entry)
                        <tr>
                            <td class="fw-bold">{{ $entry->entry_no }}</td>
                            <td>{{ $entry->posted_on?->toDateString() }}</td>
                            <td>{{ $entry->description }}</td>
                            <td>{{ $entry->creator?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.data-panel>
@endif
@endsection
