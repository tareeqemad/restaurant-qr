@extends('layouts.admin')

@section('title', 'المحاسبة')

@section('content')
<x-admin.breadcrumb
    title="المحاسبة"
    icon="bi-calculator"
    subtitle="التقارير والمراجعة والإقفال في مكان واحد" />

<div class="accounting-guide mb-4">
    <i class="bi bi-check2-circle"></i>
    <div>
        <strong>المبيعات والدفعات والمصاريف تُسجّل تلقائياً.</strong>
        <span>راجع النتائج، ثم أقفل الشهر. استخدم القيد اليدوي فقط لعملية لم يسجلها النظام.</span>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4">
        <a href="{{ route('admin.reports.profit-loss') }}" class="accounting-task">
            <span class="accounting-task__icon"><i class="bi bi-graph-up-arrow"></i></span>
            <span>
                <strong>الأرباح والخسائر</strong>
                <small>ملخص الإيرادات والمصاريف والنتيجة</small>
            </span>
            <i class="bi bi-chevron-left accounting-task__arrow"></i>
        </a>
    </div>
    <div class="col-sm-6 col-xl-4">
        <a href="{{ route('admin.accounting.journal') }}" class="accounting-task">
            <span class="accounting-task__icon"><i class="bi bi-journal-text"></i></span>
            <span>
                <strong>دفتر القيود</strong>
                <small>مراجعة العمليات التي سجلها النظام</small>
            </span>
            <i class="bi bi-chevron-left accounting-task__arrow"></i>
        </a>
    </div>
    <div class="col-sm-6 col-xl-4">
        <a href="{{ route('admin.accounting.trial-balance') }}" class="accounting-task">
            <span class="accounting-task__icon"><i class="bi bi-columns-gap"></i></span>
            <span>
                <strong>ميزان المراجعة</strong>
                <small>تأكد أن المدين يساوي الدائن</small>
            </span>
            <i class="bi bi-chevron-left accounting-task__arrow"></i>
        </a>
    </div>
    <div class="col-sm-6 col-xl-4">
        <a href="{{ route('admin.accounting.balance-sheet') }}" class="accounting-task">
            <span class="accounting-task__icon"><i class="bi bi-bank"></i></span>
            <span>
                <strong>الميزانية العمومية</strong>
                <small>الأصول والالتزامات وحقوق الملكية</small>
            </span>
            <i class="bi bi-chevron-left accounting-task__arrow"></i>
        </a>
    </div>
    <div class="col-sm-6 col-xl-4">
        <a href="{{ route('admin.accounting.tax-report') }}" class="accounting-task">
            <span class="accounting-task__icon"><i class="bi bi-percent"></i></span>
            <span>
                <strong>تقرير الضريبة</strong>
                <small>ضريبة المخرجات والمدخلات والمستحق</small>
            </span>
            <i class="bi bi-chevron-left accounting-task__arrow"></i>
        </a>
    </div>
    @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
        <div class="col-sm-6 col-xl-4">
            <a href="{{ route('admin.accounting.opening-balances') }}" class="accounting-task accounting-task--primary">
                <span class="accounting-task__icon"><i class="bi bi-door-open-fill"></i></span>
                <span>
                    <strong>الأرصدة الافتتاحية</strong>
                    <small>إدخال أرصدة بداية العمل أو الانتقال للنظام</small>
                </span>
                <i class="bi bi-chevron-left accounting-task__arrow"></i>
            </a>
        </div>
    @endif
    @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
        <div class="col-sm-6 col-xl-4">
            <a href="{{ route('admin.accounting.periods') }}" class="accounting-task accounting-task--primary">
                <span class="accounting-task__icon"><i class="bi bi-calendar-check"></i></span>
                <span>
                    <strong>إقفال الشهر</strong>
                    <small>آخر خطوة بعد مراجعة التقارير</small>
                </span>
                <i class="bi bi-chevron-left accounting-task__arrow"></i>
            </a>
        </div>
    @endif
</div>

<details class="card accounting-advanced">
    <summary class="card-header">
        <span><i class="bi bi-tools me-2"></i>إعدادات وعمليات نادرة</span>
        <small>افتحها فقط عند الحاجة</small>
    </summary>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
                <a href="{{ route('admin.accounting.manual-entry.create') }}" class="btn btn-outline-primary">
                    <i class="bi bi-journal-plus"></i> قيد يدوي
                </a>
            @endif
            <a href="{{ route('admin.accounts.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-diagram-3"></i> شجرة الحسابات
            </a>
            @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
                <a href="{{ route('admin.accounting.mappings') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-link-45deg"></i> ربط الحسابات
                </a>
            @endif
        </div>

        <details class="accounting-deep-tools mt-3 pt-3 border-top">
            <summary>
                <span><i class="bi bi-shield-lock me-2"></i>أدوات متقدمة جداً</span>
                <small>نادراً ما تحتاجها</small>
            </summary>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a href="{{ route('admin.accounting.aging') }}" class="btn btn-sm btn-outline-secondary">أعمار الذمم</a>
                <a href="{{ route('admin.accounting.fixed-assets.index') }}" class="btn btn-sm btn-outline-secondary">الأصول الثابتة</a>
                @if(auth()->user()?->hasPermission('chart_of_accounts.update'))
                    <a href="{{ route('admin.accounting.reconciliations') }}" class="btn btn-sm btn-outline-secondary">مطابقة الصندوق والبنك</a>
                    <a href="{{ route('admin.accounting.settlements') }}" class="btn btn-sm btn-outline-secondary">التسويات</a>
                    <a href="{{ route('admin.accounting.fiscal-years') }}" class="btn btn-sm btn-outline-secondary">السنوات المالية</a>
                    <a href="{{ route('admin.accounting.tax-jurisdictions') }}" class="btn btn-sm btn-outline-secondary">قواعد الضريبة</a>
                @endif
            </div>
        </details>
    </div>
</details>

@push('styles')
<style>
.accounting-guide {
    display: flex;
    align-items: center;
    gap: .9rem;
    padding: 1rem 1.15rem;
    border: 1px solid rgba(16, 185, 129, .25);
    border-radius: 10px;
    background: rgba(16, 185, 129, .07);
}
.accounting-guide > i { color: #059669; font-size: 1.65rem; }
.accounting-guide strong,
.accounting-guide span { display: block; }
.accounting-guide span { margin-top: .15rem; color: #64748b; font-size: .88rem; }
.accounting-task {
    display: flex;
    align-items: center;
    gap: .85rem;
    min-height: 104px;
    padding: 1rem;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 10px;
    background: #fff;
    color: inherit;
    transition: border-color .15s ease, transform .15s ease;
}
.accounting-task:hover { border-color: var(--primary); color: inherit; transform: translateY(-2px); }
.accounting-task__icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    border-radius: 10px;
    background: rgba(var(--primary-rgb), .1);
    color: var(--primary);
    font-size: 1.25rem;
}
.accounting-task strong,
.accounting-task small { display: block; }
.accounting-task small { margin-top: .25rem; color: #64748b; }
.accounting-task__arrow { margin-inline-start: auto; color: #94a3b8; }
.accounting-task--primary { border-color: rgba(var(--primary-rgb), .3); background: rgba(var(--primary-rgb), .035); }
.accounting-advanced summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    cursor: pointer;
    font-weight: 800;
    list-style: none;
}
.accounting-advanced summary::-webkit-details-marker { display: none; }
.accounting-advanced summary small { color: #64748b; font-weight: 500; }
@media (max-width: 575.98px) {
    .accounting-guide { align-items: flex-start; }
    .accounting-advanced summary { align-items: flex-start; flex-direction: column; gap: .2rem; }
}
</style>
@endpush
@endsection
