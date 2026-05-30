@extends('layouts.admin')

@section('title', 'ميزان المراجعة')

@section('content')
<x-admin.breadcrumb
    title="ميزان المراجعة"
    icon="bi-columns-gap"
    subtitle="مطابقة إجمالي المدين والدائن لكل الحسابات المرحّلة"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]"
>
    @if(auth()->user()?->hasPermission('chart_of_accounts.viewAny'))
        {{-- Direct shortcut for the accountant: gives a second discovery
             path so they don't have to dig through the sidebar to find
             "شجرة الحسابات". --}}
        <x-slot:actions>
            <a href="{{ route('admin.accounts.index') }}" class="btn btn-outline-primary">
                <i class="bi bi-diagram-3-fill"></i> إدارة شجرة الحسابات
            </a>
        </x-slot:actions>
    @endif
</x-admin.breadcrumb>

{{-- Plain-language explainer — accountants of all backgrounds open this
     page and the abstract "balanced/unbalanced" label means nothing
     without context. Two sentences in clear Arabic save the support ping. --}}
<div class="alert alert-info d-flex align-items-start gap-3 mb-3">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        <strong class="d-block mb-1">شو وظيفة هذه الصفحة؟</strong>
        هذا تقرير مراجعة محاسبية يثبت أن مجموع <strong>المدين</strong> = مجموع <strong>الدائن</strong> في كل
        القيود اليومية. إذا الميزان <span class="text-success">متوازن</span> = الكتب سليمة محاسبياً.
        لو بدك تشوف <strong>الأرباح/الخسائر</strong> أو <strong>دفتر الديون</strong> فاستخدم التقارير المخصصة بدلاً
        من هذه الصفحة.
    </div>
</div>

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
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input type="hidden" name="show_empty" value="0">
                    <input type="checkbox" id="show_empty" name="show_empty" value="1" class="form-check-input"
                           @checked($showEmpty)>
                    <label for="show_empty" class="form-check-label fw-bold">إظهار الحسابات الفارغة</label>
                </div>
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-primary">
                    <i class="bi bi-search"></i> استعلام
                </button>
            </div>
            @if($hiddenZeroCount > 0 && ! $showEmpty)
                <div class="col-12">
                    <small class="text-muted">
                        <i class="bi bi-eye-slash"></i>
                        تم إخفاء <strong>{{ $hiddenZeroCount }}</strong> حساب بدون حركة في هذه الفترة.
                        فعّل "إظهار الحسابات الفارغة" أعلاه لعرضها جميعاً.
                    </small>
                </div>
            @endif
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
                @forelse($accounts as $account)
                    @php
                        $hasMovement = ! $account->is_zero;
                    @endphp
                    <tr class="{{ $hasMovement ? '' : 'text-muted' }}">
                        <td class="fw-bold text-primary">{{ $account->code }}</td>
                        <td class="fw-bold">
                            {{ $account->name }}
                            @if(! $account->is_active)
                                <span class="badge bg-warning text-dark ms-1"
                                      title="هذا الحساب معطّل من شجرة الحسابات لكن له حركة في هذه الفترة">
                                    معطّل
                                </span>
                            @endif
                        </td>
                        <td>{{ $typeLabels[$account->type] ?? $account->type }}</td>
                        <td>{{ $normalBalanceLabels[$account->normal_balance] ?? $account->normal_balance }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::format($account->movement_debit) }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::format($account->movement_credit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::format($account->balance_debit) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::format($account->balance_credit) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            لا توجد حركات محاسبية في هذه الفترة.
                        </td>
                    </tr>
                @endforelse
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
