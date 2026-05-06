@extends('layouts.admin')
@section('title', $location->name)

@section('content')
<x-admin.breadcrumb title="{{ $location->name }}" icon="bi-boxes" />

{{-- How stock arrives at a storage location — the system has no
     "إضافة كمية مباشرة" button by design (every change must come from
     a documented source for the audit trail), so this card lays out
     the four legitimate inflow paths and links straight into them. --}}
<div class="card custom-card mb-3" style="border-color: rgba(15, 71, 49, .12);">
    <div class="card-body p-3">
        <div class="d-flex align-items-start gap-3 mb-3">
            <span class="d-inline-flex align-items-center justify-content-center"
                  style="width: 44px; height: 44px; flex: 0 0 44px; border-radius: 12px;
                         background: rgba(15, 71, 49, .08); color: var(--primary); font-size: 1.2rem;">
                <i class="bi bi-info-circle-fill"></i>
            </span>
            <div class="flex-grow-1">
                <div class="fw-bold mb-1">كيف تدخل المكوّنات إلى هذا الموقع؟</div>
                <div class="small text-muted" style="line-height: 1.7;">
                    لا يوجد زر «إضافة كمية مباشرة» قصداً — كل حركة مخزون لازم تكون من <strong>مصدر موثَّق</strong> ليبقى تتبّع التكلفة والكميات سليم.
                    عندك أربع مسارات:
                </div>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-md-6 col-xl-3">
                <a href="{{ route('admin.purchase-orders.create') }}" class="d-block p-3 rounded text-decoration-none h-100"
                   style="background: rgba(15, 71, 49, .04); border: 1px solid rgba(15, 71, 49, .12); color: inherit;">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-truck text-primary"></i>
                        <strong class="small">١. أمر شراء</strong>
                    </div>
                    <div class="small text-muted" style="line-height: 1.5;">
                        عند استلام بضاعة من مورّد، حدِّد هذا الموقع كوجهة في بنود أمر الشراء — تتسجَّل الكمية والتكلفة تلقائياً.
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="{{ route('admin.supplier-invoices.create') }}" class="d-block p-3 rounded text-decoration-none h-100"
                   style="background: rgba(15, 71, 49, .04); border: 1px solid rgba(15, 71, 49, .12); color: inherit;">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-file-earmark-text text-primary"></i>
                        <strong class="small">٢. فاتورة مورّد مباشرة</strong>
                    </div>
                    <div class="small text-muted" style="line-height: 1.5;">
                        لو الشراء بدون أمر شراء مسبق، ادخل الفاتورة وحدِّد الموقع — يتم تحديث المخزون فور حفظها.
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="{{ route('admin.storage-locations.transfer-form') }}" class="d-block p-3 rounded text-decoration-none h-100"
                   style="background: rgba(15, 71, 49, .04); border: 1px solid rgba(15, 71, 49, .12); color: inherit;">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-arrow-left-right text-primary"></i>
                        <strong class="small">٣. نقل بين المواقع</strong>
                    </div>
                    <div class="small text-muted" style="line-height: 1.5;">
                        لنقل كمية من موقع آخر داخل <em>نفس الفرع</em> إلى هنا (مثلاً من مستودع جاف إلى ثلاجة المطبخ).
                    </div>
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="{{ route('admin.branch-transfers.index') }}" class="d-block p-3 rounded text-decoration-none h-100"
                   style="background: rgba(15, 71, 49, .04); border: 1px solid rgba(15, 71, 49, .12); color: inherit;">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-building text-primary"></i>
                        <strong class="small">٤. تحويل بين الفروع</strong>
                    </div>
                    <div class="small text-muted" style="line-height: 1.5;">
                        لاستلام بضاعة قادمة من فرع آخر — يتم تحديد الموقع في بنود التحويل عند الاستلام.
                    </div>
                </a>
            </div>
        </div>

        <div class="small text-muted mt-3" style="line-height: 1.7;">
            <i class="bi bi-lightbulb text-warning"></i>
            <strong>للتعديلات الاستثنائية فقط</strong> (تالف، تصحيح جرد، عيّنة…) استخدم
            <a href="{{ route('admin.stock-counts.create') }}">جرد جديد</a> أو
            <a href="{{ route('admin.waste.create') }}">تسجيل هدر</a> — كل واحدة بتطلب سبب يبقى في سجل الحركات.
        </div>
    </div>
</div>

<x-admin.data-panel title="المكونات في {{ $location->name }}" :count="$stocks->count()" icon="bi-boxes">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>المكوّن</th>
                    <th>الكمية</th>
                    <th>حد الطلب (موقع)</th>
                    <th>التكلفة/وحدة</th>
                    <th>القيمة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($stocks as $s)
                    @php
                        $ing = $s->ingredient;
                        $value = (float) $s->quantity * (float) ($ing?->cost_per_unit ?? 0);
                        $low = $s->isLowStock();
                    @endphp
                    <tr class="{{ $low ? 'table-warning' : '' }}">
                        <td class="fw-bold">{{ $ing?->name ?? '—' }}</td>
                        <td>{{ \App\Helpers\Qty::format($s->quantity) }} {{ $ing?->baseUnit?->code }}</td>
                        <td>{{ \App\Helpers\Qty::format($s->reorder_threshold) }}</td>
                        <td>{{ \App\Helpers\Qty::format(($ing?->cost_per_unit ?? 0)) }}</td>
                        <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($value) }}</td>
                        <td>
                            @if($low)
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> منخفض</span>
                            @else
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> جيد</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-admin.empty-state
                            icon="bi-boxes"
                            title="هذا الموقع فاضي حالياً"
                            message="استخدم أحد المسارات بالأعلى لإدخال مكوّنات إلى هنا — أمر شراء، فاتورة مورّد، نقل بين المواقع، أو تحويل بين الفروع."
                            compact />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>
@endsection
