@extends('layouts.admin')
@section('title', 'كشف خصم رواتب '.$closure->month->format('Y-m'))

@section('content')
<x-admin.breadcrumb
    :title="'كشف خصم رواتب لشهر '.$closure->month->format('Y-m')"
    icon="bi-printer"
    :crumbs="[
        ['label' => 'بدل وجبات الموظفين', 'url' => route('admin.staff-meals.index')],
        ['label' => 'الإقفالات', 'url' => route('admin.staff-meals.closures')],
    ]">
    <x-slot:actions>
        <button onclick="window.print()" class="btn btn-primary btn-sm">
            <i class="bi bi-printer"></i> طباعة
        </button>
    </x-slot:actions>
</x-admin.breadcrumb>

<div class="card mb-3 closure-meta">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">الشهر</small>
                <strong class="fs-5">{{ $closure->month->translatedFormat('F Y') }}</strong>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">الفرع</small>
                <strong>{{ $closure->branch?->name ?? 'كل الفروع' }}</strong>
            </div>
            <div class="col-md-2">
                <small class="text-muted d-block">الطريقة</small>
                @php
                    $methodLabel = match($closure->method) {
                        'payroll_deduction' => 'خصم من الرواتب',
                        'cash'              => 'تحصيل نقدي',
                        'writeoff'          => 'إعدام',
                        default             => $closure->method,
                    };
                @endphp
                <strong>{{ $methodLabel }}</strong>
            </div>
            <div class="col-md-2">
                <small class="text-muted d-block">عدد الموظفين</small>
                <strong>{{ $closure->staff_count }}</strong>
            </div>
            <div class="col-md-2">
                <small class="text-muted d-block">الإجمالي</small>
                <strong class="text-primary fs-5">{{ \App\Helpers\Money::format($closure->total_amount) }}</strong>
            </div>
        </div>
        @if($closure->notes)
            <div class="mt-3 p-2 bg-light rounded">
                <small class="text-muted"><i class="bi bi-sticky"></i> ملاحظات الإقفال:</small>
                <div>{{ $closure->notes }}</div>
            </div>
        @endif
        <div class="mt-2 small text-muted">
            أُغلق بواسطة <strong>{{ $closure->closedBy?->name ?? '—' }}</strong>
            بتاريخ {{ $closure->closed_at?->format('Y-m-d H:i') }}.
        </div>
    </div>
</div>

<x-admin.data-panel title="بنود الكشف" icon="bi-list-ol" :count="$sheet->count()">
    @if($sheet->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            لا توجد حركات في هذا الإقفال.
        </div>
    @else
        <table class="table align-middle table-striped">
            <thead class="bg-light">
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>الدور</th>
                    <th class="text-end">البدل الشهري</th>
                    <th class="text-end">المخصوم</th>
                    <th class="text-end">التجاوز</th>
                    <th class="text-end">عدد الحركات</th>
                    <th class="signature-col d-print-table-cell d-none">التوقيع</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sheet as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><strong>{{ $row['user']?->name ?? '—' }}</strong></td>
                        <td><small class="text-muted">{{ $row['user']?->role }}</small></td>
                        <td class="text-end">{{ \App\Helpers\Money::format($row['allowance'] ?? 0) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::format($row['total']) }}</td>
                        <td class="text-end {{ $row['overflow'] > 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                            @if($row['overflow'] > 0) +{{ \App\Helpers\Money::format($row['overflow']) }} @else — @endif
                        </td>
                        <td class="text-end">{{ $row['charges_count'] }}</td>
                        <td class="d-print-table-cell d-none" style="width:130px"></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td colspan="4" class="text-end">الإجمالي</td>
                    <td class="text-end text-primary fs-5">{{ \App\Helpers\Money::format($closure->total_amount) }}</td>
                    <td class="text-end">{{ \App\Helpers\Money::format($sheet->sum('overflow')) }}</td>
                    <td class="text-end">{{ $closure->charge_count }}</td>
                    <td class="d-print-table-cell d-none"></td>
                </tr>
            </tfoot>
        </table>

        {{-- Approval signatures — only visible when printed.
             Three columns: HR, Finance, GM. Matches the typical
             payroll approval chain. --}}
        <div class="signatures-row d-print-flex d-none mt-5 pt-4 border-top">
            <div class="signature-block">
                <div>__________________________</div>
                <strong>الموارد البشرية</strong>
            </div>
            <div class="signature-block">
                <div>__________________________</div>
                <strong>المحاسبة</strong>
            </div>
            <div class="signature-block">
                <div>__________________________</div>
                <strong>المدير العام</strong>
            </div>
        </div>
    @endif
</x-admin.data-panel>

@push('styles')
<style>
.signature-block { flex: 1; text-align: center; }
@media print {
    .page-header .relax-page-actions,
    .breadcrumb,
    .relax-page-side .breadcrumb { display: none !important; }
    .closure-meta { border: 1px solid #000 !important; }
    .d-print-flex { display: flex !important; }
    .d-print-table-cell { display: table-cell !important; }
    body { background: white; }
    .signatures-row { gap: 2rem; }
}
</style>
@endpush
@endsection
