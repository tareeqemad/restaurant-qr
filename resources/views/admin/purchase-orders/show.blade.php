@extends('layouts.admin')
@section('title', $po->number)

@section('content')
<x-admin.breadcrumb
    title="أمر شراء {{ $po->number }}"
    icon="bi-truck"
    subtitle="{{ $po->supplier?->name ?? 'بدون مورد' }}">
    <x-slot:actions>
        {{-- The PO has a strict workflow: draft → approved → sent →
             (partially_received) → received. At each state only one or
             two actions make sense. The model exposes is*able()
             helpers; we gate the buttons on those AND on policy. The
             policy alone isn't enough because BasePolicy::before bypasses
             every check for owner-level, so without the state guard a
             super-admin would see every button at once. --}}
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($po->isCancellable())
                <span class="badge bg-{{ $po->statusColor() }}-transparent text-{{ $po->statusColor() }} fs-13 px-3 py-2 me-2">
                    <i class="bi bi-info-circle"></i> {{ $po->statusLabel() }}
                </span>
            @endif

            {{-- Primary next-step action — exactly one of these renders. --}}
            @if($po->isApprovable())
                @can('approve', $po)
                    <form method="POST" action="{{ route('admin.purchase-orders.approve', $po) }}">@csrf
                        <button class="btn btn-success" title="اعتماد الأمر للسماح بإرساله للمورد">
                            <i class="bi bi-check2-circle"></i> اعتماد الأمر
                        </button>
                    </form>
                @endcan
            @elseif($po->isSendable())
                @can('send', $po)
                    <form method="POST" action="{{ route('admin.purchase-orders.send', $po) }}">@csrf
                        <button class="btn btn-primary" title="تأكيد إرسال الأمر للمورد">
                            <i class="bi bi-send-check"></i> إرسال للمورد
                        </button>
                    </form>
                @endcan
            @elseif($po->isReceivable())
                @can('receive', $po)
                    <a href="{{ route('admin.purchase-orders.receive-form', $po) }}" class="btn btn-success" title="تسجيل استلام البضاعة من المورد">
                        <i class="bi bi-box-arrow-in-down"></i>
                        {{ $po->status === 'partially_received' ? 'استلام دفعة جديدة' : 'استلام البضاعة' }}
                    </a>
                @endcan
            @endif

            {{-- Invoicing — relevant once any goods have arrived. --}}
            @if($po->isInvoiceable())
                <a href="{{ route('admin.supplier-invoices.create', ['po' => $po->id]) }}" class="btn btn-outline-primary" title="تسجيل فاتورة المورد المرتبطة بهذا الأمر">
                    <i class="bi bi-receipt"></i> فاتورة المورد
                </a>
            @endif

            {{-- Edit — only meaningful while the PO is still a draft. --}}
            @if($po->isEditable())
                @can('update', $po)
                    <a href="{{ route('admin.purchase-orders.edit', $po) }}" class="btn btn-light" title="تعديل بنود الأمر">
                        <i class="bi bi-pencil"></i> تعديل
                    </a>
                @endcan
            @endif

            {{-- Cancel — last in the row, danger style, only while alive. --}}
            @if($po->isCancellable())
                @can('cancel', $po)
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelPO" title="إلغاء أمر الشراء">
                        <i class="bi bi-x-circle"></i> إلغاء
                    </button>
                @endcan
            @endif
        </div>
    </x-slot:actions>
</x-admin.breadcrumb>

<x-admin.stat-rail :stats="[
    ['label' => 'الحالة', 'value' => $po->statusLabel(), 'icon' => 'bi-info-circle', 'color' => match($po->statusColor()) { 'success' => 'success', 'danger' => 'danger', 'warning' => 'accent', 'info' => 'primary', default => 'muted' }],
    ['label' => 'الاعتماد', 'value' => $po->approved_at ? 'معتمد' : 'بانتظار', 'icon' => $po->approved_at ? 'bi-check-circle-fill' : 'bi-hourglass-split', 'color' => $po->approved_at ? 'success' : 'warning'],
    ['label' => 'البنود', 'value' => $po->items->count(), 'icon' => 'bi-list-ul', 'color' => 'primary'],
    ['label' => 'الإجمالي', 'value' => \App\Helpers\Money::format($po->total), 'icon' => 'bi-cash-coin', 'color' => 'success'],
    ['label' => 'استلامات', 'value' => $po->receipts->count(), 'icon' => 'bi-clipboard2-check', 'color' => 'info'],
    ['label' => 'فواتير', 'value' => $po->supplierInvoices->count(), 'icon' => 'bi-receipt', 'color' => 'accent'],
]" />

<div class="row g-3 mb-3">
    <div class="col-xl-4">
        <x-admin.data-panel title="معلومات أمر الشراء" icon="bi-info-circle">
            <div class="p-3">
                @php
                    $rows = [
                        ['رقم PO', $po->number, 'bi-hash', true],
                        ['المورد', $po->supplier?->name, 'bi-truck', false],
                        ['الحالة', $po->statusLabel(), 'bi-info-circle', false],
                        ['تاريخ الإنشاء', $po->created_at?->format('Y-m-d H:i'), 'bi-calendar', false],
                        ['اعتمد بواسطة', $po->approver?->name, 'bi-person-check', false],
                        ['تاريخ الاعتماد', $po->approved_at?->format('Y-m-d H:i'), 'bi-check2-circle', false],
                        ['تاريخ الإرسال', $po->sent_at?->format('Y-m-d H:i'), 'bi-send', false],
                        ['التسليم المتوقع', optional($po->expected_at)->format('Y-m-d'), 'bi-calendar-check', false],
                        ['تاريخ الاستلام الكامل', $po->received_at?->format('Y-m-d H:i'), 'bi-box-arrow-in-down', false],
                        ['أنشأ بواسطة', $po->creator?->name, 'bi-person', false],
                        ['استلم بواسطة', $po->receiver?->name, 'bi-person-check', false],
                    ];
                @endphp

                @foreach($rows as [$label, $value, $icon, $mono])
                    @if(!empty($value))
                        <div class="d-flex align-items-start gap-2 mb-3">
                            <div style="width:34px; height:34px; border-radius:10px; background:rgba(var(--accent-rgb),.12); color:var(--accent); display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="bi {{ $icon }}"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="text-muted small fw-bold">{{ $label }}</div>
                                <div class="fw-bold" style="{{ $mono ? 'font-family: \'Courier New\', monospace;' : '' }}">{{ $value }}</div>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if($po->notes)
                    <div class="mt-3 p-2 rounded" style="background:rgba(var(--accent-rgb),.05); border-right:3px solid var(--accent);">
                        <div class="small fw-bold text-muted mb-1">ملاحظات</div>
                        <div style="white-space:pre-wrap;">{{ $po->notes }}</div>
                    </div>
                @endif
                @if($po->cancel_reason)
                    <div class="mt-3 p-2 rounded alert-danger" style="border-right:3px solid #b91c1c;">
                        <div class="small fw-bold mb-1">سبب الإلغاء</div>
                        <div>{{ $po->cancel_reason }}</div>
                    </div>
                @endif
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-8">
        <x-admin.data-panel title="بنود أمر الشراء" :count="$po->items->count()" icon="bi-list-ul">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th>مطلوب</th>
                            <th>مستلم</th>
                            <th>مفوتر</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                            <th>التقدم</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($po->items as $line)
                            @php
                                $pct = $line->receivedPercent();
                                $invoicedQty = $line->supplierInvoiceItems->sum('quantity');
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold">{{ $line->ingredient?->name ?? '—' }}</div>
                                    @if($line->notes)<small class="text-muted">{{ $line->notes }}</small>@endif
                                </td>
                                <td>{{ number_format((float) $line->quantity_ordered, 4) }} {{ $line->unit?->code ?? '' }}</td>
                                <td>
                                    <span class="badge bg-{{ $line->isFullyReceived() ? 'success' : ((float) $line->quantity_received > 0 ? 'warning' : 'secondary') }}">
                                        {{ number_format((float) $line->quantity_received, 4) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $invoicedQty >= (float) $line->quantity_received && $invoicedQty > 0 ? 'success' : 'light text-muted' }}">
                                        {{ number_format((float) $invoicedQty, 4) }}
                                    </span>
                                </td>
                                <td>{{ \App\Helpers\Money::format($line->unit_price) }}</td>
                                <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($line->subtotal) }}</td>
                                <td style="min-width:150px;">
                                    <div class="progress" style="height:8px; background:rgba(var(--primary-rgb),.08);">
                                        <div class="progress-bar" style="width: {{ $pct }}%; background:linear-gradient(90deg, var(--primary), var(--accent));"></div>
                                    </div>
                                    <small class="text-muted">{{ number_format($pct, 0) }}%</small>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="5" class="text-end fw-bold">الإجمالي الكلي</td>
                            <td colspan="2" class="fw-bold fs-5" style="color:var(--primary);">{{ \App\Helpers\Money::format($po->total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-admin.data-panel>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <x-admin.data-panel title="سجل الاستلامات" icon="bi-clipboard2-check" :count="$po->receipts->count()">
            @if($po->receipts->isEmpty())
                <x-admin.empty-state icon="bi-box-arrow-in-down" title="لا استلامات بعد" message="عند استلام البضاعة سيظهر هنا رقم استلام مستقل لكل عملية." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>رقم الاستلام</th>
                                <th>التاريخ</th>
                                <th>البنود</th>
                                <th>بواسطة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($po->receipts->sortByDesc('received_at') as $receipt)
                                <tr>
                                    <td class="fw-bold" style="font-family:'Courier New', monospace;">{{ $receipt->number }}</td>
                                    <td>{{ $receipt->received_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $receipt->items->count() }}</td>
                                    <td>{{ $receipt->receiver?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>

    <div class="col-xl-6">
        <x-admin.data-panel title="فواتير المورد المرتبطة" icon="bi-receipt" :count="$po->supplierInvoices->count()">
            @if($po->supplierInvoices->isEmpty())
                <x-admin.empty-state icon="bi-receipt" title="لا فواتير مرتبطة" message="سجّل فاتورة المورد بعد وصول الفاتورة الورقية أو الإلكترونية." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>الفاتورة</th>
                                <th>الإجمالي</th>
                                <th>المتبقي</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($po->supplierInvoices as $invoice)
                                <tr>
                                    <td><a href="{{ route('admin.supplier-invoices.show', $invoice) }}" class="fw-bold">{{ $invoice->number }}</a></td>
                                    <td>{{ \App\Helpers\Money::format($invoice->total) }}</td>
                                    <td class="{{ (float) $invoice->balance > 0 ? 'text-danger fw-bold' : 'text-success fw-bold' }}">{{ \App\Helpers\Money::format($invoice->balance) }}</td>
                                    <td><span class="badge bg-{{ $invoice->statusColor() }}">{{ $invoice->statusLabel() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.data-panel>
    </div>
</div>

@can('cancel', $po)
<div class="modal fade" id="cancelPO" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.purchase-orders.cancel', $po) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">إلغاء أمر الشراء</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if($po->status === 'partially_received')
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            هناك بنود مستلمة جزئياً. الاستلامات السابقة ستبقى في المخزون.
                        </div>
                    @endif
                    <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-danger"><i class="bi bi-x-circle"></i> تأكيد الإلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endsection
