@extends('layouts.admin')

@section('title', 'ميزان المراجعة')

@section('content')
<x-admin.breadcrumb
    title="ميزان المراجعة"
    icon="bi-columns-gap"
    subtitle="مطابقة إجمالي المدين والدائن لكل الحسابات المرحّلة"
    :crumbs="[['label' => 'الحسابات', 'url' => route('admin.cashier.index')]]"
/>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="accounting-metric">
            <span>حسابات عليها حركة</span>
            <strong>{{ $activeAccountsCount }}</strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="accounting-metric">
            <span>إجمالي المدين</span>
            <strong>{{ \App\Helpers\Money::format($totalBalanceDebit) }}</strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="accounting-metric">
            <span>إجمالي الدائن</span>
            <strong>{{ \App\Helpers\Money::format($totalBalanceCredit) }}</strong>
        </div>
    </div>
    <div class="col-md-3">
        <div class="accounting-metric {{ $isBalanced ? 'is-balanced' : 'is-unbalanced' }}">
            <span>حالة الميزان</span>
            <strong>{{ $isBalanced ? 'متوازن' : 'غير متوازن' }}</strong>
        </div>
    </div>
</div>

<x-admin.data-panel title="ميزان المراجعة" icon="bi-columns-gap" :count="$accounts->count()">
    <x-slot:filters>
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">من تاريخ</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary">
                    <i class="bi bi-search"></i>
                    استعلام
                </button>
            </div>
            <div class="col-md-3 d-grid">
                <a href="{{ route('admin.accounting.trial-balance') }}" class="btn btn-light">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    كل القيود حتى اليوم
                </a>
            </div>
        </form>
    </x-slot:filters>

    <div class="alert {{ $isBalanced ? 'alert-success' : 'alert-danger' }} d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi {{ $isBalanced ? 'bi-check2-circle' : 'bi-exclamation-triangle' }}"></i>
        <div>
            {{ $isBalanced ? 'الميزان متوازن، إجمالي الأرصدة المدينة يساوي إجمالي الأرصدة الدائنة.' : 'يوجد فرق في ميزان المراجعة ويحتاج تدقيق القيود.' }}
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle accounting-trial-table mb-0">
            <thead class="bg-light">
                <tr>
                    <th>الكود</th>
                    <th>الحساب</th>
                    <th>النوع</th>
                    <th>طبيعته</th>
                    <th class="text-end">حركة مدينة</th>
                    <th class="text-end">حركة دائنة</th>
                    <th class="text-end">رصيد مدين</th>
                    <th class="text-end">رصيد دائن</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accounts as $account)
                    @php
                        $hasMovement = $account->movement_debit > 0 || $account->movement_credit > 0;
                    @endphp
                    <tr class="{{ $hasMovement ? '' : 'text-muted' }}">
                        <td class="fw-bold text-primary">{{ $account->code }}</td>
                        <td class="fw-bold">{{ $account->name }}</td>
                        <td>{{ $typeLabels[$account->type] ?? $account->type }}</td>
                        <td>{{ $normalBalanceLabels[$account->normal_balance] ?? $account->normal_balance }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::format($account->movement_debit) }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::format($account->movement_credit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::format($account->balance_debit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::format($account->balance_credit) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-light fw-bold">
                <tr>
                    <td colspan="4">المجموع</td>
                    <td class="text-end">{{ \App\Helpers\Money::format($totalMovementDebit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::format($totalMovementCredit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::format($totalBalanceDebit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::format($totalBalanceCredit) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-admin.data-panel>

@push('styles')
<style>
.accounting-metric {
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    background: #fff;
    padding: 1rem;
    min-height: 92px;
    display: grid;
    align-content: center;
    gap: .25rem;
}
.accounting-metric span {
    color: #64748b;
    font-size: .82rem;
    font-weight: 800;
}
.accounting-metric strong {
    color: #111827;
    font-size: 1.35rem;
}
.accounting-metric.is-balanced {
    border-color: rgba(16, 185, 129, .25);
    background: rgba(16, 185, 129, .07);
}
.accounting-metric.is-unbalanced {
    border-color: rgba(239, 68, 68, .25);
    background: rgba(239, 68, 68, .07);
}
.accounting-trial-table th,
.accounting-trial-table td {
    white-space: nowrap;
}
</style>
@endpush
@endsection
