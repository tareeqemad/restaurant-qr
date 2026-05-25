@extends('layouts.admin')
@section('title','تقرير نهاية اليوم')
@section('content')
<x-admin.breadcrumb title="تقرير نهاية اليوم — {{ $date }}" icon="bi-calendar-check"
    subtitle="ملخص شامل للمبيعات والنقدية والخصومات"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]">
    <x-slot:actions>
        <button type="button" class="btn btn-light" onclick="window.print()">
            <i class="bi bi-printer"></i> طباعة
        </button>
    </x-slot:actions>
</x-admin.breadcrumb>

{{-- Print-only header — replaces the breadcrumb on paper, since the
     breadcrumb sits inside the admin chrome that we hide. --}}
<div class="eod-print-header d-none">
    <div class="eod-print-brand">
        <strong>{{ \App\Helpers\Brand::name() }}</strong>
    </div>
    <div class="eod-print-title">تقرير نهاية اليوم</div>
    <div class="eod-print-date">التاريخ: {{ $date }}</div>
    <hr>
</div>

<div class="data-panel-filters mb-3" style="background: white; border-radius: 14px; padding: 1rem; border: 1px solid rgba(var(--primary-rgb), .1);">
    <form class="row g-2 align-items-end">
        <div class="col-md-4 mx-auto">
            <label class="form-label small text-muted fw-bold">تاريخ الإقفال</label>
            <input type="date" name="date" value="{{ $date }}" class="form-control">
        </div>
        <div class="col-12 text-center mt-2">
            <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
        </div>
    </form>
</div>

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي المقبوض', 'value' => \App\Helpers\Money::format($summary['total_collected']), 'icon' => 'bi-wallet2', 'color' => 'success'],
    ['label' => 'إجمالي الفواتير', 'value' => \App\Helpers\Money::format($summary['total_billed']), 'icon' => 'bi-receipt', 'color' => 'primary'],
    ['label' => 'المبيعات بدون ضريبة', 'value' => \App\Helpers\Money::format($summary['gross_sales']), 'icon' => 'bi-cash-stack', 'color' => 'accent'],
    ['label' => 'عدد الطلبات', 'value' => $summary['orders_count'], 'icon' => 'bi-bag-check', 'color' => $summary['orders_cancelled'] > 0 ? 'warning' : 'info'],
    ['label' => 'الفواتير المدفوعة', 'value' => $summary['invoices_paid'].' / '.$summary['invoices_count'], 'icon' => 'bi-check-circle', 'color' => 'success'],
    ['label' => 'الخصومات', 'value' => \App\Helpers\Money::format($summary['discount_total']), 'icon' => 'bi-tag', 'color' => $summary['discount_total'] > 0 ? 'warning' : 'muted'],
]" />

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header"><strong><i class="bi bi-cash"></i> الدفعات حسب الطريقة</strong></div>
        <div class="card-body p-0">
            <table class="table mb-0"><thead class="bg-light"><tr><th>الطريقة</th><th>عدد</th><th>الإجمالي</th></tr></thead><tbody>
                @forelse($byMethod as $method => $data)
                    <tr><td>
                        @switch($method)@case('cash')كاش @break @case('card')كارد @break @case('transfer')حوالة @break @case('app')تطبيق @break @case('credit')دين @break @endswitch
                    </td><td>{{ $data['count'] }}</td><td class="fw-bold">{{ \App\Helpers\Money::format($data['total']) }}</td></tr>
                @empty<tr><td colspan="3" class="text-center text-muted py-3">لا دفعات</td></tr>@endforelse
                </tbody><tfoot class="bg-light fw-bold"><tr><td>الإجمالي</td><td>{{ $byMethod->sum('count') }}</td><td>{{ \App\Helpers\Money::format($byMethod->sum('total')) }}</td></tr></tfoot>
            </table>
        </div></div>

        <div class="card mb-3"><div class="card-header"><strong><i class="bi bi-receipt"></i> حالات الفواتير</strong></div>
        <ul class="list-group list-group-flush">
            <li class="list-group-item d-flex justify-content-between"><span>مدفوعة كاملة</span><strong class="text-success">{{ $summary['invoices_paid'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>غير مدفوعة/جزئية</span><strong class="text-warning">{{ $summary['invoices_unpaid'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>شطب</span><strong class="text-dark">{{ $summary['invoices_writeoff'] }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>الخصومات</span><strong>{{ \App\Helpers\Money::format($summary['discount_total']) }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>الضريبة المحصّلة</span><strong>{{ \App\Helpers\Money::format($summary['tax_total']) }}</strong></li>
            <li class="list-group-item d-flex justify-content-between"><span>رسوم الخدمة</span><strong>{{ \App\Helpers\Money::format($summary['service_total']) }}</strong></li>
        </ul></div>

        @if($shifts->count())
            <div class="card"><div class="card-header"><strong><i class="bi bi-clock"></i> ورديات اليوم</strong></div>
            <table class="table mb-0"><thead class="bg-light"><tr><th>موظف</th><th>الفترة</th><th>الإجمالي</th><th>الفرق</th></tr></thead><tbody>
            @foreach($shifts as $s)
                <tr><td>{{ $s->user->name }}</td>
                    <td><small>{{ $s->opened_at->format('H:i') }} - {{ $s->closed_at?->format('H:i') ?? 'مفتوح' }}</small></td>
                    <td class="fw-bold">{{ \App\Helpers\Money::format($s->total_sales) }}</td>
                    <td class="{{ (float)$s->cash_variance < 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float)$s->cash_variance, 2) }}</td>
                </tr>
            @endforeach
            </tbody></table></div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card"><div class="card-header"><strong><i class="bi bi-trophy"></i> أكثر الأصناف مبيعاً</strong></div>
        <div class="card-body p-0">
        <table class="table mb-0"><thead class="bg-light"><tr><th>#</th><th>الصنف</th><th>كمية</th><th>إجمالي</th></tr></thead><tbody>
            @forelse($topItems as $i => $r)
                <tr><td>{{ $i + 1 }}</td><td class="fw-bold">{{ $r->name_snapshot }}</td><td>{{ number_format((float)$r->qty, 1) }}</td><td>{{ \App\Helpers\Money::format($r->total) }}</td></tr>
            @empty<tr><td colspan="4" class="text-center text-muted py-3">—</td></tr>@endforelse
        </tbody></table>
        </div></div>
    </div>

    <div class="col-lg-3">
        <div class="card">
            <div class="card-header">
                <strong><i class="bi bi-boxes"></i> استهلاك المخزون</strong>
            </div>
            <div class="px-3 pt-2 pb-1 small text-muted" style="line-height: 1.55; background: rgba(15, 71, 49, .03); border-bottom: 1px solid rgba(15, 71, 49, .06);">
                المكوّنات اللي خرجت من المستودع اليوم — سواء استُهلكت في تحضير الأصناف المباعة (<span class="text-primary fw-bold">بيع</span>) أو سُجِّلت كهدر (<span class="text-danger fw-bold">هدر</span>).
                التكلفة محسوبة بـ <strong>متوسط سعر الشراء</strong> الفعلي للمكوّن وقت الحركة.
            </div>
        <div class="card-body p-0">
        <table class="table mb-0"><thead class="bg-light"><tr><th>المكون</th><th>النوع</th><th>كمية</th><th>تكلفة</th></tr></thead><tbody>
            @php
                $usageCost = 0;
                $wasteCost = 0;
            @endphp
            @forelse($inventoryUsage as $name => $rows)
                @foreach($rows as $row)
                    @php
                        $isWaste = $row->type === 'waste';
                        if ($isWaste) {
                            $wasteCost += (float) $row->cost;
                        } else {
                            $usageCost += (float) $row->cost;
                        }
                    @endphp
                    <tr>
                        <td class="small">{{ $name }}</td>
                        <td>
                            @if($isWaste)
                                <span class="badge bg-danger-transparent text-danger">هدر</span>
                            @else
                                <span class="badge bg-primary-transparent text-primary">بيع</span>
                            @endif
                        </td>
                        <td title="{{ number_format((float) $row->qty, 4) }} {{ $row->base_unit_code ?? '' }}">
                            {{ \App\Helpers\QuantityFormatter::smart((float) $row->qty, $row->base_unit_code) }}
                        </td>
                        <td class="small">{{ number_format((float) $row->cost, 2) }}</td>
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">—</td></tr>
            @endforelse
        </tbody>
        @if(($usageCost + $wasteCost) > 0)
            <tfoot class="bg-light">
                <tr>
                    <td colspan="3" class="text-primary"><strong>تكلفة الاستهلاك (بيع)</strong></td>
                    <td class="fw-bold text-primary">{{ number_format($usageCost, 2) }}</td>
                </tr>
                @if($wasteCost > 0)
                    <tr>
                        <td colspan="3" class="text-danger"><strong>تكلفة الهدر</strong></td>
                        <td class="fw-bold text-danger">{{ number_format($wasteCost, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td colspan="3"><strong>الإجمالي</strong></td>
                    <td class="fw-bold">{{ number_format($usageCost + $wasteCost, 2) }}</td>
                </tr>
            </tfoot>
        @endif
        </table>
        </div></div>
    </div>
</div>

{{-- ─── Print-friendly styling ────────────────────────────────────
     Goals:
       1. Hide all admin chrome (sidebar, header, breadcrumbs, filter
          form, print button itself) so only the report renders.
       2. Show the print-only header block (brand + date) instead.
       3. Stack the three columns into one flow — A4 portrait is too
          narrow for the 5/4/3 grid, items get cropped otherwise.
       4. Force backgrounds + borders to print so the report doesn't
          come out as a wall of plain text.
       5. One-page when possible: trim padding, shrink stat cards. --}}
@push('styles')
<style>
@media print {
    /* Strip the admin shell — only the report content prints. */
    body { background: white !important; }
    .app-sidebar,
    .app-header,
    .breadcrumb-wrap,
    .breadcrumb-actions,
    .data-panel-filters,
    .stat-rail,
    nav, header, footer,
    .alert,
    button { display: none !important; }
    .app-content,
    .main-content,
    .content,
    main { margin: 0 !important; padding: 8mm !important; max-width: 100% !important; }

    /* Show the print-only header. */
    .eod-print-header.d-none { display: block !important; }
    .eod-print-header {
        text-align: center;
        margin-bottom: 6mm;
        font-family: 'Tajawal', sans-serif;
    }
    .eod-print-brand   { font-size: 16pt; }
    .eod-print-title   { font-size: 20pt; font-weight: 900; margin-top: 2mm; }
    .eod-print-date    { font-size: 11pt; color: #444; margin-top: 1mm; }
    .eod-print-header hr { border-top: 2px solid #000; margin-top: 3mm; }

    /* Stack the columns vertically — three side-by-side tables don't
       fit a portrait A4 and end up clipped. Each section gets its
       own width and a small break-inside hint. */
    .col-lg-5, .col-lg-4, .col-lg-3 {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        page-break-inside: avoid;
    }
    .row.g-3 > [class*="col-"] { margin-bottom: 6mm; }

    /* Cards: kill drop-shadows + soften borders for ink-friendly
       output. Force backgrounds in card headers so the section
       titles still stand out on paper. */
    .card {
        box-shadow: none !important;
        border: 1px solid #999 !important;
        border-radius: 4px !important;
        page-break-inside: avoid;
    }
    .card-header {
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        border-bottom: 1px solid #999 !important;
        padding: 4mm 5mm !important;
    }
    .card-body, .card-body.p-0 { padding: 0 !important; }

    /* Tables: tighter rows, visible borders, repeating headers
       across page breaks so a 30-row table stays readable. */
    table { width: 100% !important; border-collapse: collapse !important; }
    table thead { display: table-header-group; }
    table th, table td {
        padding: 2mm 3mm !important;
        border-bottom: 1px solid #ccc !important;
        font-size: 10pt !important;
    }
    table thead th {
        background: #e8e8e8 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        font-weight: 800;
    }
    table tfoot td {
        background: #f5f5f5 !important;
        -webkit-print-color-adjust: exact !important;
        font-weight: 800;
        border-top: 2px solid #000 !important;
    }

    /* Badges: text-only, no colored background on paper. */
    .badge {
        background: transparent !important;
        color: #000 !important;
        border: 1px solid #999 !important;
        padding: 1px 4px !important;
        font-weight: 700 !important;
    }

    /* Hint colors: convert to print-safe greyscale equivalents
       so important rows still stand out (variance, danger…). */
    .text-success { color: #1a6b34 !important; }
    .text-danger  { color: #8b1a1a !important; }
    .text-warning { color: #7a5800 !important; }
    .text-primary { color: #1a2a6b !important; }

    @page {
        size: A4 portrait;
        margin: 10mm 10mm 15mm 10mm;
    }
}
</style>
@endpush
@endsection
