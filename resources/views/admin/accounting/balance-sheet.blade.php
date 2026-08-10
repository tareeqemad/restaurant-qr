@extends('layouts.admin')

@section('title', 'الميزانية العمومية')

@section('content')
<x-admin.breadcrumb
    title="الميزانية العمومية"
    icon="bi-bank"
    subtitle="أصول = التزامات + حقوق ملكية من دفتر القيود"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-4">
        <label class="form-label small text-muted fw-bold">كما في تاريخ</label>
        <input type="date" name="as_of" value="{{ $asOf }}" class="form-control">
    </div>
    <div class="col-md-3 d-grid">
        <button class="btn btn-primary"><i class="bi bi-search"></i> استعلام</button>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="accounting-metric">
            <span>إجمالي الأصول</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($totalAssets) }}</strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="accounting-metric">
            <span>الالتزامات + حقوق الملكية</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($totalLiabilities + $totalEquity) }}</strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="accounting-metric {{ $isBalanced ? 'is-balanced' : 'is-unbalanced' }}">
            <span>الحالة</span>
            <strong>{{ $isBalanced ? 'متوازنة' : 'تحتاج مراجعة' }}</strong>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <x-admin.data-panel title="الأصول" icon="bi-wallet2" :count="$assets->count()">
            @include('admin.accounting.partials.balance-rows', ['rows' => $assets])
            <div class="border-top pt-2 mt-2 d-flex justify-content-between fw-bold">
                <span>إجمالي الأصول</span>
                <span>{{ \App\Helpers\Money::formatAccounting($totalAssets) }}</span>
            </div>
        </x-admin.data-panel>
    </div>
    <div class="col-lg-6">
        <x-admin.data-panel title="الالتزامات وحقوق الملكية" icon="bi-diagram-3" :count="$liabilities->count() + $equity->count() + 1">
            <h6 class="text-muted fw-bold">الالتزامات</h6>
            @include('admin.accounting.partials.balance-rows', ['rows' => $liabilities])
            <div class="border-top pt-2 mt-2 d-flex justify-content-between fw-bold">
                <span>إجمالي الالتزامات</span>
                <span>{{ \App\Helpers\Money::formatAccounting($totalLiabilities) }}</span>
            </div>

            <h6 class="text-muted fw-bold mt-4">حقوق الملكية</h6>
            @include('admin.accounting.partials.balance-rows', ['rows' => $equity])
            <div class="d-flex justify-content-between">
                <span>أرباح/خسائر جارية غير مقفلة</span>
                <strong>{{ \App\Helpers\Money::formatAccounting($currentEarnings) }}</strong>
            </div>
            <div class="border-top pt-2 mt-2 d-flex justify-content-between fw-bold">
                <span>إجمالي حقوق الملكية</span>
                <span>{{ \App\Helpers\Money::formatAccounting($totalEquity) }}</span>
            </div>
        </x-admin.data-panel>
    </div>
</div>

@push('styles')
<style>
.accounting-metric {
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    background: #fff;
    padding: 1rem;
}
.accounting-metric span { color: #64748b; font-size: .82rem; font-weight: 800; display:block; }
.accounting-metric strong { color: #111827; font-size: 1.35rem; }
.accounting-metric.is-balanced { border-color: rgba(16, 185, 129, .25); background: rgba(16, 185, 129, .07); }
.accounting-metric.is-unbalanced { border-color: rgba(239, 68, 68, .25); background: rgba(239, 68, 68, .07); }
</style>
@endpush
@endsection
