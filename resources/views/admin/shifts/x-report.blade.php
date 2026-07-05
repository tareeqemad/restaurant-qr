@extends('layouts.admin')
@section('title','تقرير الدرج — X')

@section('content')
<x-admin.breadcrumb
    title="تقرير الدرج الآن (X)"
    icon="bi-clipboard-data"
    subtitle="الكاش المتوقع في الدرج هذه اللحظة — بدون إغلاق الشفت."
    :crumbs="[['label' => 'الورديات', 'url' => route('admin.shifts.index')]]" />

@php
    $b = $breakdown;
    $money = fn ($v) => \App\Helpers\Money::format($v);
@endphp

<div class="row g-3 justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm xr-card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <strong><i class="bi bi-cash-stack"></i> تفصيل الكاش المتوقع</strong>
                @if($shift->status === 'open')
                    <span class="badge bg-success-transparent text-success">
                        <i class="bi bi-circle-fill" style="font-size:.5rem"></i> شفت مفتوح
                    </span>
                @else
                    <span class="badge bg-secondary-transparent">مغلق</span>
                @endif
            </div>
            <div class="card-body">
                <div class="text-muted small mb-3">
                    <i class="bi bi-person"></i> {{ $shift->user->name ?? '—' }}
                    · <i class="bi bi-calendar-event"></i> افتُتح {{ $shift->opened_at->format('Y-m-d · H:i') }}
                    ({{ $shift->opened_at->diffForHumans() }})
                </div>

                <table class="table xr-table align-middle mb-0">
                    <tbody>
                        <tr>
                            <td>كاش الافتتاح</td>
                            <td class="text-end xr-plus">+{{ $money($b['cash_opening']) }}</td>
                        </tr>
                        <tr>
                            <td>مبيعات نقدية</td>
                            <td class="text-end xr-plus">+{{ $money($b['cash_sales']) }}</td>
                        </tr>
                        <tr>
                            <td>إيداعات في الدرج (فكة)</td>
                            <td class="text-end xr-plus">+{{ $money($b['cash_pay_ins']) }}</td>
                        </tr>
                        <tr>
                            <td>استردادات نقدية</td>
                            <td class="text-end xr-minus">−{{ $money($b['cash_refunds']) }}</td>
                        </tr>
                        <tr>
                            <td>صرف من الدرج</td>
                            <td class="text-end xr-minus">−{{ $money($b['cash_pay_outs']) }}</td>
                        </tr>
                        <tr>
                            <td>دفعات موردين نقداً</td>
                            <td class="text-end xr-minus">−{{ $money($b['supplier_cash_payments']) }}</td>
                        </tr>
                        <tr class="xr-total">
                            <td><strong>المتوقع في الدرج الآن</strong></td>
                            <td class="text-end"><strong>{{ $money($b['expected_cash']) }}</strong></td>
                        </tr>
                    </tbody>
                </table>

                <div class="xr-note mt-3">
                    <i class="bi bi-info-circle"></i>
                    عُدّ النقد الفعلي في الدرج وقارنه بالرقم أعلاه. هذا عرض للقراءة فقط —
                    لم يُغلَق الشفت ولم يُسجَّل أي فرق. للإغلاق الرسمي استخدم «إغلاق الشفت».
                </div>

                {{-- Context: non-drawer takings, shown for a full picture --}}
                <div class="row g-2 mt-2 xr-context">
                    <div class="col-6">
                        <div class="xr-chip"><span>مبيعات كارد</span><strong>{{ $money($b['card_sales']) }}</strong></div>
                    </div>
                    <div class="col-6">
                        <div class="xr-chip"><span>مبيعات أخرى (تحويل…)</span><strong>{{ $money($b['other_sales']) }}</strong></div>
                    </div>
                    <div class="col-12">
                        <div class="xr-chip"><span>إجمالي المقبوضات (كل الطرق)</span><strong>{{ $money($b['total_sales']) }}</strong></div>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <a href="{{ route('admin.shifts.index') }}" class="btn btn-outline-secondary flex-fill">
                        <i class="bi bi-arrow-right"></i> رجوع للورديات
                    </a>
                    <button onclick="window.print()" class="btn btn-outline-primary">
                        <i class="bi bi-printer"></i> طباعة
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .xr-table td { font-size: .95rem; padding: .6rem .25rem; border-color: #f1f5f9; }
    .xr-plus  { color: #065f46; font-weight: 700; }
    .xr-minus { color: #b91c1c; font-weight: 700; }
    .xr-total td { border-top: 2px solid #0f4731; font-size: 1.15rem; padding-top: .8rem; }
    .xr-total td strong { color: #0f4731; }
    .xr-note {
        background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px;
        padding: .7rem .9rem; font-size: .82rem; color: #1e3a8a; line-height: 1.7;
    }
    .xr-chip {
        display: flex; align-items: center; justify-content: space-between;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
        padding: .5rem .75rem; font-size: .82rem;
    }
    .xr-chip span { color: #64748b; }
    .xr-chip strong { color: #0f172a; }
    @media print {
        .app-sidebar, .app-header, .btn, x-admin-breadcrumb, nav { display: none !important; }
    }
</style>
@endpush
@endsection
