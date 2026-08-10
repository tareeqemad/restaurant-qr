@extends('layouts.admin')

@section('title', 'تقرير الضريبة')

@section('content')
<x-admin.breadcrumb
    title="تقرير {{ $taxLabel }}"
    icon="bi-percent"
    subtitle="تقرير مبني من دفتر القيود وحسابات الضريبة المربوطة"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">من تاريخ</label>
        <input type="date" name="from" value="{{ $from }}" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
        <input type="date" name="to" value="{{ $to }}" class="form-control">
    </div>
    <div class="col-md-3 d-grid">
        <button class="btn btn-primary"><i class="bi bi-search"></i> استعلام</button>
    </div>
</form>

@if($isUsMarket)
    <div class="alert alert-warning">
        السوق الأمريكي يستخدم Sales Tax حسب الولاية/المدينة. هذا التقرير لا يحدد النسبة القانونية؛ هو يجمع ما تم ترحيله فعلياً على حساب ضريبة المبيعات.
    </div>
@endif

<div class="row g-3">
    <div class="col-md-4">
        <div class="tax-card">
            <span>ضريبة مبيعات/مخرجات</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($outputTax) }}</strong>
            <small>الحسابات: {{ implode(', ', $outputCodes) }}</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="tax-card">
            <span>ضريبة مدخلات</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($inputTax) }}</strong>
            <small>الحسابات: {{ implode(', ', $inputCodes) }}</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="tax-card is-total">
            <span>{{ $isUsMarket ? 'المستحق حسب القيود' : 'صافي الضريبة المستحق' }}</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($payable) }}</strong>
        </div>
    </div>
</div>

@push('styles')
<style>
.tax-card {
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    padding: 1rem;
    background: #fff;
    min-height: 120px;
}
.tax-card span,
.tax-card small { display:block; color:#64748b; font-weight:800; }
.tax-card strong { display:block; margin:.35rem 0; font-size:1.45rem; color:#111827; }
.tax-card.is-total { border-color: rgba(16, 185, 129, .25); background: rgba(16, 185, 129, .07); }
</style>
@endpush
@endsection
