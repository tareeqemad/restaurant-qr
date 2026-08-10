@extends('layouts.admin')

@section('title', 'ميزان المراجعة')

@section('content')
<x-admin.breadcrumb
    title="ميزان المراجعة"
    icon="bi-columns-gap"
    subtitle="مراجعة المدين والدائن"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="accounting-metric">
            <span>حسابات عليها حركة</span>
            <strong>{{ $activeAccountsCount }}</strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="accounting-metric">
            <span>إجمالي المدين</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($totalBalanceDebit) }}</strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="accounting-metric">
            <span>إجمالي الدائن</span>
            <strong>{{ \App\Helpers\Money::formatAccounting($totalBalanceCredit) }}</strong>
        </div>
    </div>
</div>

<div class="alert {{ $isBalanced ? 'alert-success' : 'alert-danger' }} d-flex align-items-center gap-2 py-2 mb-3">
    <i class="bi {{ $isBalanced ? 'bi-check2-circle' : 'bi-exclamation-triangle' }}"></i>
    <strong>{{ $isBalanced ? 'الميزان متوازن.' : 'يوجد فرق ويجب مراجعة القيود.' }}</strong>
</div>

@php($filtersActive = request()->hasAny(['from', 'to', 'show_empty']))
<details class="card mb-3" @if($filtersActive) open @endif>
    <summary class="card-header accounting-filter-summary">
        <span><i class="bi bi-calendar3 me-2"></i>تغيير الفترة</span>
        <small>اختياري</small>
    </summary>
    <div class="card-body">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">من</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">إلى</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input type="hidden" name="show_empty" value="0">
                    <input type="checkbox" id="show_empty" name="show_empty" value="1" class="form-check-input" @checked($showEmpty)>
                    <label for="show_empty" class="form-check-label">الحسابات بلا حركة</label>
                </div>
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary"><i class="bi bi-search"></i> عرض</button>
            </div>
            @if($hiddenZeroCount > 0 && ! $showEmpty)
                <div class="col-12"><small class="text-muted">تم إخفاء {{ $hiddenZeroCount }} حساباً بلا حركة.</small></div>
            @endif
        </form>
    </div>
</details>

<x-admin.data-panel title="الحسابات" icon="bi-columns-gap" :count="$accounts->count()">
    <div class="table-responsive">
        <table class="table align-middle accounting-trial-table mb-0">
            <thead class="bg-light">
                <tr>
                    <th>الحساب</th>
                    <th class="text-end">حركة مدينة</th>
                    <th class="text-end">حركة دائنة</th>
                    <th class="text-end">رصيد مدين</th>
                    <th class="text-end">رصيد دائن</th>
                </tr>
            </thead>
            <tbody>
                @forelse($accounts as $account)
                    <tr class="{{ $account->is_zero ? 'text-muted' : '' }}">
                        <td>
                            <strong class="text-primary">{{ $account->code }}</strong>
                            <span class="fw-bold ms-1">{{ $account->name }}</span>
                            @if(! $account->is_active)
                                <span class="badge bg-warning text-dark ms-1">معطّل</span>
                            @endif
                        </td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($account->movement_debit) }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($account->movement_credit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::formatAccounting($account->balance_debit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::formatAccounting($account->balance_credit) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            لا توجد حركات محاسبية في هذه الفترة.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-light fw-bold">
                <tr>
                    <td>المجموع</td>
                    <td class="text-end">{{ \App\Helpers\Money::formatAccounting($totalMovementDebit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::formatAccounting($totalMovementCredit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::formatAccounting($totalBalanceDebit) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::formatAccounting($totalBalanceCredit) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-admin.data-panel>

@push('styles')
<style>
.accounting-metric {
    min-height: 84px;
    display: grid;
    align-content: center;
    gap: .25rem;
    padding: 1rem;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    background: #fff;
}
.accounting-metric span { color: #64748b; font-size: .82rem; font-weight: 800; }
.accounting-metric strong { color: #111827; font-size: 1.25rem; }
.accounting-filter-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    font-weight: 800;
    list-style: none;
}
.accounting-filter-summary::-webkit-details-marker { display: none; }
.accounting-filter-summary small { color: #64748b; font-weight: 500; }
.accounting-trial-table th,
.accounting-trial-table td { white-space: nowrap; }
</style>
@endpush
@endsection
