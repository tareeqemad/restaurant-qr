@extends('layouts.admin')
@section('title', $po->number)

@section('content')
<x-admin.breadcrumb title="{{ $po- />

<x-admin.stat-rail :stats="[
    ['label' => 'الحالة',          'value' => $po->statusLabel(),            'icon' => 'bi-info-circle', 'color' => match($po->statusColor()) { 'success' => 'success', 'danger' => 'danger', 'warning' => 'accent', 'info' => 'primary', default => 'muted' }],
    ['label' => 'البنود',           'value' => $po->items->count(),           'icon' => 'bi-list-ul',      'color' => 'primary'],
    ['label' => 'الإجمالي',        'value' => \App\Helpers\Money::format($po->total), 'icon' => 'bi-cash-coin', 'color' => 'success'],
    ['label' => 'التسليم المتوقع', 'value' => $po->expected_at?->format('Y-m-d') ?? '—', 'icon' => 'bi-calendar-check', 'color' => 'accent'],
]" />

<div class="row g-3">
    {{-- Header info --}}
    <div class="col-xl-4">
        <x-admin.data-panel title="معلومات أمر الشراء" icon="bi-info-circle">
            <div class="p-3">
                @php
                    $rows = [
                        ['رقم PO',          $po->number,                                     'bi-hash',            true],
                        ['المورد',           $po->supplier?->name,                            'bi-truck'],
                        ['الحالة',           $po->statusLabel(),                              'bi-info-circle'],
                        ['تاريخ الإنشاء',   $po->created_at?->format('Y-m-d H:i'),           'bi-calendar'],
                        ['تاريخ الإرسال',   $po->sent_at?->format('Y-m-d H:i'),              'bi-send'],
                        ['التسليم المتوقع', optional($po->expected_at)->format('Y-m-d'),     'bi-calendar-check'],
                        ['تاريخ الاستلام',  $po->received_at?->format('Y-m-d H:i'),          'bi-box-arrow-in-down'],
                        ['أنشأ بواسطة',     $po->creator?->name,                             'bi-person'],
                        ['استلم بواسطة',    $po->receiver?->name,                            'bi-person-check'],
                    ];
                @endphp
                @foreach($rows as $row)
                    @php [$label, $value, $icon, $mono] = array_pad($row, 4, false); @endphp
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
                        <div class="small fw-bold text-muted mb-1">ملاحظات:</div>
                        <div style="white-space:pre-wrap;">{{ $po->notes }}</div>
                    </div>
                @endif
                @if($po->cancel_reason)
                    <div class="mt-3 p-2 rounded alert-danger" style="border-right:3px solid #b91c1c;">
                        <div class="small fw-bold mb-1">سبب الإلغاء:</div>
                        <div>{{ $po->cancel_reason }}</div>
                    </div>
                @endif
            </div>
        </x-admin.data-panel>
    </div>

    {{-- Line items --}}
    <div class="col-xl-8">
        <x-admin.data-panel title="بنود أمر الشراء" :count="$po->items->count()" icon="bi-list-ul">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>المكوّن</th>
                            <th>مطلوب</th>
                            <th>مستلم</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                            <th>التقدم</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($po->items as $line)
                            @php $pct = $line->receivedPercent(); @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold">{{ $line->ingredient?->name ?? '—' }}</div>
                                    @if($line->notes)
                                        <small class="text-muted">{{ $line->notes }}</small>
                                    @endif
                                </td>
                                <td>{{ number_format((float)$line->quantity_ordered, 4) }} {{ $line->unit?->code ?? '' }}</td>
                                <td>
                                    @if($line->isFullyReceived())
                                        <span class="badge bg-success">
                                            <i class="bi bi-check2"></i>
                                            {{ number_format((float)$line->quantity_received, 4) }}
                                        </span>
                                    @else
                                        <span class="badge bg-{{ $line->quantity_received > 0 ? 'warning' : 'secondary' }}">
                                            {{ number_format((float)$line->quantity_received, 4) }}
                                        </span>
                                    @endif
                                </td>
                                <td>{{ \App\Helpers\Money::format($line->unit_price) }}</td>
                                <td class="fw-bold" style="color:var(--primary);">
                                    {{ \App\Helpers\Money::format($line->subtotal) }}
                                </td>
                                <td style="min-width:150px;">
                                    <div class="progress" style="height:8px; background:rgba(var(--primary-rgb),.08);">
                                        <div class="progress-bar" role="progressbar" style="width: {{ $pct }}%; background:linear-gradient(90deg, var(--primary), var(--accent));"></div>
                                    </div>
                                    <small class="text-muted">{{ number_format($pct, 0) }}%</small>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="4" class="text-end fw-bold">الإجمالي الكلي</td>
                            <td colspan="2" class="fw-bold fs-5" style="color:var(--primary);">
                                {{ \App\Helpers\Money::format($po->total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-admin.data-panel>
    </div>
</div>

{{-- Cancel modal --}}
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
                    <p>سيُلغى أمر الشراء {{ $po->number }}.</p>
                    @if($po->status === 'partially_received')
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            هناك بنود مستلمة جزئياً. الاستلامات السابقة ستبقى في المخزون.
                        </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
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
