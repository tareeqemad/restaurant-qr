@extends('layouts.admin')
@section('title', 'لوحة المخزون والمشتريات')

@section('content')
<x-admin.breadcrumb
    title="لوحة المخزون والمشتريات"
    icon="bi-speedometer2"
    subtitle="{{ $branchName ? 'مركز قرار سريع لفرع '.$branchName : 'مركز قرار سريع للمخزون والمشتريات عبر النظام' }}" />

<x-admin.stat-rail :stats="[
    ['label' => 'قيمة المخزون', 'value' => \App\Helpers\Money::format($stats['stock_value']), 'icon' => 'bi-cash-stack', 'color' => 'primary'],
    ['label' => 'يحتاج إجراء', 'value' => $actionCount, 'icon' => 'bi-lightning-charge-fill', 'color' => $actionCount > 0 ? 'danger' : 'success'],
    ['label' => 'مخزون منخفض', 'value' => $stats['low_stock'], 'icon' => 'bi-exclamation-triangle-fill', 'color' => $stats['low_stock'] > 0 ? 'accent' : 'muted'],
    ['label' => 'نافد', 'value' => $stats['out_stock'], 'icon' => 'bi-x-octagon-fill', 'color' => $stats['out_stock'] > 0 ? 'danger' : 'muted'],
    ['label' => 'تكلفة طلب مقترحة', 'value' => \App\Helpers\Money::format($stats['reorder_cost']), 'icon' => 'bi-cart-plus-fill', 'color' => 'success'],
    ['label' => 'مستحقات الموردين', 'value' => \App\Helpers\Money::format($stats['ap_due']), 'icon' => 'bi-receipt', 'color' => $stats['ap_overdue'] > 0 ? 'danger' : 'info'],
    ['label' => 'فروقات فواتير', 'value' => $stats['invoice_variances'], 'icon' => 'bi-slash-circle', 'color' => $stats['invoice_variances'] > 0 ? 'danger' : 'muted'],
]" />

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <x-admin.data-panel title="قائمة الإجراء المطلوبة" icon="bi-list-check" :count="$actionCount">
            <div class="inventory-action-grid">
                <a href="{{ route('admin.ingredients.index', ['low_stock' => 1]) }}" class="inventory-action action-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>
                        <strong>{{ $stats['low_stock'] + $stats['out_stock'] }} مكون منخفض أو نافد</strong>
                        <small>ابدأ منها قبل ساعات الذروة حتى لا يتوقف صنف من المنيو.</small>
                    </span>
                </a>
                <a href="{{ route('admin.reports.reorder-suggestions') }}" class="inventory-action action-success">
                    <i class="bi bi-cart-plus-fill"></i>
                    <span>
                        <strong>{{ \App\Helpers\Money::format($stats['reorder_cost']) }} طلب مقترح</strong>
                        <small>حوّل النواقص إلى أوامر شراء مجمعة حسب المورد.</small>
                    </span>
                </a>
                <a href="{{ route('admin.batches.index') }}" class="inventory-action action-warning">
                    <i class="bi bi-calendar-x-fill"></i>
                    <span>
                        <strong>{{ $stats['expiring_batches'] }} دفعة قريبة الانتهاء</strong>
                        <small>راجع FIFO والعروض اليومية قبل تسجيل هدر.</small>
                    </span>
                </a>
                <a href="{{ route('admin.purchase-orders.index', ['status' => 'sent']) }}" class="inventory-action action-info">
                    <i class="bi bi-truck"></i>
                    <span>
                        <strong>{{ $stats['overdue_pos'] }} أمر شراء متأخر</strong>
                        <small>تابع الموردين قبل ما تتحول النواقص إلى نفاد فعلي.</small>
                    </span>
                </a>
                <a href="{{ route('admin.supplier-invoices.index', ['overdue' => 1]) }}" class="inventory-action action-danger">
                    <i class="bi bi-alarm-fill"></i>
                    <span>
                        <strong>{{ \App\Helpers\Money::format($stats['ap_overdue']) }} مستحقات متأخرة</strong>
                        <small>رتب الدفع حسب الاستحقاق حتى لا يتعطل التوريد.</small>
                    </span>
                </a>
                <a href="{{ route('admin.supplier-invoices.create') }}" class="inventory-action action-muted">
                    <i class="bi bi-file-earmark-plus-fill"></i>
                    <span>
                        <strong>{{ $stats['uninvoiced_pos'] }} أمر مستلم بلا فاتورة</strong>
                        <small>أغلق الحلقة بين الاستلام والحسابات.</small>
                    </span>
                </a>
                <a href="{{ route('admin.supplier-invoices.index') }}" class="inventory-action action-warning">
                    <i class="bi bi-slash-circle-fill"></i>
                    <span>
                        <strong>{{ $stats['invoice_variances'] }} فرق فاتورة/استلام</strong>
                        <small>راجع البنود التي لا تطابق الكمية أو القيمة المستلمة.</small>
                    </span>
                </a>
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-5">
        <x-admin.data-panel title="طلب اليوم المقترح" icon="bi-bag-plus-fill" :count="$reorderQueue->count()">
            @if($reorderQueue->isEmpty())
                <x-admin.empty-state
                    icon="bi-check-circle"
                    title="لا توجد نواقص حرجة"
                    message="المخزون المتتبع فوق حدود الطلب حالياً." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>المكون</th>
                                <th class="text-end">المخزون</th>
                                <th class="text-end">المقترح</th>
                                <th class="text-end">تكلفة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reorderQueue as $ingredient)
                                <tr>
                                    <td>
                                        <div class="fw-bold">{{ $ingredient->name }}</div>
                                        <small class="text-muted">{{ $ingredient->supplier?->name ?? 'بدون مورد افتراضي' }}</small>
                                    </td>
                                    @php $dashBase = $ingredient->baseUnit?->code; @endphp
                                    <td class="text-end {{ $ingredient->dashboard_stock <= 0 ? 'text-danger fw-bold' : 'text-warning fw-bold' }}"
                                        title="{{ number_format($ingredient->dashboard_stock, 4) }} {{ $dashBase }}">
                                        {{ \App\Helpers\QuantityFormatter::smart((float) $ingredient->dashboard_stock, $dashBase) }}
                                    </td>
                                    <td class="text-end"
                                        title="{{ number_format($ingredient->dashboard_need_qty, 4) }} {{ $dashBase }}">
                                        {{ \App\Helpers\QuantityFormatter::smart((float) $ingredient->dashboard_need_qty, $dashBase) }}
                                    </td>
                                    <td class="text-end fw-bold" style="color: var(--primary);">
                                        {{ \App\Helpers\Money::format($ingredient->dashboard_need_cost) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <x-admin.data-panel title="دفعات قريبة الانتهاء" icon="bi-calendar-x" :count="$expiringBatches->count()">
            @if($expiringBatches->isEmpty())
                <x-admin.empty-state icon="bi-calendar-check" title="الصلاحيات مستقرة" message="لا توجد دفعات متبقية تنتهي خلال 7 أيام." />
            @else
                <div class="inventory-list">
                    @foreach($expiringBatches as $batch)
                        @php $days = $batch->daysUntilExpiry(); @endphp
                        <a href="{{ route('admin.batches.index', ['ingredient_id' => $batch->ingredient_id]) }}" class="inventory-list-row">
                            <span>
                                <strong>{{ $batch->ingredient?->name }}</strong>
                                <small>{{ $batch->storageLocation?->name ?? 'موقع عام' }} · #{{ $batch->batch_number ?: $batch->id }}</small>
                            </span>
                            <span class="{{ $days < 0 ? 'text-danger' : ($days <= 2 ? 'text-warning' : 'text-muted') }} fw-bold">
                                {{ $days < 0 ? 'منتهية' : 'بعد '.$days.' يوم' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-xl-4">
        <x-admin.data-panel title="أوامر شراء متأخرة" icon="bi-truck" :count="$overduePurchaseOrders->count()">
            @if($overduePurchaseOrders->isEmpty())
                <x-admin.empty-state icon="bi-send-check" title="لا تأخير توريد" message="لا توجد أوامر مرسلة تجاوزت تاريخ التسليم المتوقع." />
            @else
                <div class="inventory-list">
                    @foreach($overduePurchaseOrders as $po)
                        <a href="{{ route('admin.purchase-orders.show', $po) }}" class="inventory-list-row">
                            <span>
                                <strong>{{ $po->number }}</strong>
                                <small>{{ $po->supplier?->name ?? '—' }} · {{ \App\Helpers\Money::format($po->total) }}</small>
                            </span>
                            <span class="text-danger fw-bold">{{ $po->expected_at?->diffForHumans() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-xl-4">
        <x-admin.data-panel title="فواتير يجب متابعتها" icon="bi-receipt-cutoff" :count="$supplierInvoiceQueue->count()">
            @if($supplierInvoiceQueue->isEmpty())
                <x-admin.empty-state icon="bi-check-circle" title="لا توجد فواتير قريبة" message="لا توجد فواتير موردين متأخرة أو مستحقة خلال 7 أيام." />
            @else
                <div class="inventory-list">
                    @foreach($supplierInvoiceQueue as $invoice)
                        <a href="{{ route('admin.supplier-invoices.show', $invoice) }}" class="inventory-list-row">
                            <span>
                                <strong>{{ $invoice->number }}</strong>
                                <small>{{ $invoice->supplier?->name ?? '—' }} · {{ $invoice->statusLabel() }}</small>
                            </span>
                            <span class="{{ $invoice->isOverdue() ? 'text-danger' : 'text-warning' }} fw-bold">
                                {{ \App\Helpers\Money::format($invoice->balance) }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <x-admin.data-panel title="فروقات الفواتير والاستلام" icon="bi-slash-circle-fill" :count="$invoiceVarianceItems->count()">
            @if($invoiceVarianceItems->isEmpty())
                <x-admin.empty-state icon="bi-check-circle" title="لا فروقات مطابقة" message="بنود فواتير الموردين المسجلة مطابقة للاستلام حالياً." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الفاتورة</th>
                                <th>البند</th>
                                <th class="text-end">فرق كمية</th>
                                <th class="text-end">فرق قيمة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoiceVarianceItems as $item)
                                <tr>
                                    <td>
                                        @if($item->supplierInvoice)
                                            <a href="{{ route('admin.supplier-invoices.show', $item->supplierInvoice) }}" class="fw-bold">
                                                {{ $item->supplierInvoice->number }}
                                            </a>
                                            <small class="d-block text-muted">{{ $item->supplierInvoice->supplier?->name ?? '—' }}</small>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $item->description }}</td>
                                    <td class="text-end {{ abs((float) $item->variance_qty) > 0.0001 ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $item->variance_qty !== null ? \App\Helpers\Qty::format($item->variance_qty) : '—' }}
                                    </td>
                                    <td class="text-end {{ abs((float) $item->variance_total) > 0.01 ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $item->variance_total !== null ? \App\Helpers\Money::format($item->variance_total) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-xl-6">
        <x-admin.data-panel title="آخر الاستلامات" icon="bi-clipboard2-check" :count="$recentReceipts->count()">
            @if($recentReceipts->isEmpty())
                <x-admin.empty-state icon="bi-clipboard2-check" title="لا استلامات بعد" message="كل عملية استلام PO جديدة ستظهر هنا برقم مستقل." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>رقم الاستلام</th>
                                <th>PO</th>
                                <th>المورد</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentReceipts as $receipt)
                                <tr>
                                    <td class="fw-bold" style="font-family:'Courier New', monospace;">{{ $receipt->number }}</td>
                                    <td>
                                        @if($receipt->purchaseOrder)
                                            <a href="{{ route('admin.purchase-orders.show', $receipt->purchaseOrder) }}">{{ $receipt->purchaseOrder->number }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $receipt->supplier?->name ?? '—' }}</td>
                                    <td>{{ $receipt->received_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-xl-6">
        <x-admin.data-panel title="هدر آخر 7 أيام" icon="bi-trash3-fill" :count="$highWaste->count()">
            @if($highWaste->isEmpty())
                <x-admin.empty-state icon="bi-check-circle" title="لا هدر مسجل" message="لا توجد حركات هدر خلال آخر 7 أيام." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>المكون</th>
                                <th class="text-end">أحداث</th>
                                <th class="text-end">كمية</th>
                                <th class="text-end">تكلفة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($highWaste as $row)
                                <tr>
                                    <td class="fw-bold">{{ $row->name }}</td>
                                    <td class="text-end">{{ $row->events_count }}</td>
                                    <td class="text-end" title="{{ number_format((float) $row->qty, 4) }} {{ $row->unit_code }}">
                                        {{ \App\Helpers\QuantityFormatter::smart((float) $row->qty, $row->unit_code) }}
                                    </td>
                                    <td class="text-end text-danger fw-bold">{{ \App\Helpers\Money::format($row->total_cost) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-xl-6">
        <x-admin.data-panel title="أوامر مفتوحة" icon="bi-inboxes-fill" :count="$openPurchaseOrders->count()">
            @if($openPurchaseOrders->isEmpty())
                <x-admin.empty-state icon="bi-check-circle" title="لا أوامر مفتوحة" message="لا توجد أوامر شراء مسودة أو مرسلة حالياً." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>PO</th>
                                <th>المورد</th>
                                <th class="text-end">الإجمالي</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($openPurchaseOrders as $po)
                                <tr>
                                    <td><a href="{{ route('admin.purchase-orders.show', $po) }}" class="fw-bold">{{ $po->number }}</a></td>
                                    <td>{{ $po->supplier?->name ?? '—' }}</td>
                                    <td class="text-end">{{ \App\Helpers\Money::format($po->total) }}</td>
                                    <td><span class="badge bg-{{ $po->statusColor() }}">{{ $po->statusLabel() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

@push('styles')
<style>
.inventory-action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: .75rem;
}
.inventory-action,
.inventory-list-row {
    text-decoration: none;
    border: 1px solid rgba(var(--primary-rgb), .12);
    border-radius: 8px;
    background: #fff;
}
.inventory-action {
    padding: .85rem;
    display: flex;
    gap: .75rem;
    align-items: center;
    transition: transform .15s ease, border-color .15s ease;
}
.inventory-action:hover,
.inventory-list-row:hover {
    transform: translateY(-1px);
    border-color: rgba(var(--accent-rgb), .45);
}
.inventory-action > i {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 38px;
    font-size: 1.05rem;
}
.inventory-action span {
    display: grid;
    gap: 2px;
}
.inventory-action strong,
.inventory-list-row strong {
    color: var(--primary);
}
.inventory-action small,
.inventory-list-row small {
    color: #64748b;
    line-height: 1.4;
}
.action-danger > i { background: rgba(185, 28, 28, .08); color: #b91c1c; }
.action-warning > i { background: rgba(var(--accent-rgb), .12); color: #8a6920; }
.action-success > i { background: rgba(21, 128, 61, .08); color: #15803d; }
.action-info > i { background: rgba(3, 105, 161, .08); color: #0369a1; }
.action-muted > i { background: rgba(100, 116, 139, .08); color: #64748b; }
.inventory-list {
    display: grid;
    gap: .6rem;
}
.inventory-list-row {
    padding: .7rem .8rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
}
.inventory-list-row > span:first-child {
    display: grid;
    gap: 2px;
    min-width: 0;
}
</style>
@endpush
@endsection
