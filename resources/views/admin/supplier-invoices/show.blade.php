@extends('layouts.admin')
@section('title', $invoice->number)

@section('content')
<x-admin.breadcrumb title="{{ $invoice- />

<x-admin.stat-rail :stats="[
    ['label' => 'الحالة',       'value' => $invoice->statusLabel(),                 'icon' => 'bi-info-circle', 'color' => match($invoice->statusColor()) { 'success' => 'success', 'danger' => 'danger', 'warning' => 'accent', default => 'muted' }],
    ['label' => 'إجمالي الفاتورة','value' => \App\Helpers\Money::format($invoice->total),     'icon' => 'bi-receipt',     'color' => 'primary'],
    ['label' => 'مدفوع',          'value' => \App\Helpers\Money::format($invoice->paid_total),'icon' => 'bi-check-circle-fill','color' => 'success'],
    ['label' => 'متبقي',          'value' => \App\Helpers\Money::format($invoice->balance),   'icon' => 'bi-alarm',       'color' => $invoice->balance > 0 ? 'danger' : 'muted'],
]" />

<div class="row g-3">
    <div class="col-xl-4">
        <x-admin.data-panel title="تفاصيل الفاتورة" icon="bi-info-circle">
            <div class="p-3">
                @php
                    $rows = [
                        ['رقم الفاتورة',     $invoice->number,                                   'bi-hash',  true],
                        ['المورد',            $invoice->supplier?->name,                         'bi-truck'],
                        ['PO مرتبط',          $invoice->purchaseOrder?->number,                  'bi-link-45deg', true],
                        ['تاريخ الفاتورة',    $invoice->invoice_date?->format('Y-m-d'),          'bi-calendar'],
                        ['الاستحقاق',         optional($invoice->due_date)->format('Y-m-d'),     'bi-calendar-event'],
                        ['تاريخ السداد',     $invoice->paid_at?->format('Y-m-d H:i'),            'bi-check2-circle'],
                        ['أنشئت بواسطة',      $invoice->creator?->name,                          'bi-person'],
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
                                <div class="fw-bold" style="{{ ($mono ?? false) ? 'font-family: \'Courier New\', monospace;' : '' }}">{{ $value }}</div>
                            </div>
                        </div>
                    @endif
                @endforeach

                <hr>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted">الفرعي</span>
                    <span>{{ \App\Helpers\Money::format($invoice->subtotal) }}</span>
                </div>
                @if($invoice->tax_total > 0)
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">الضريبة</span>
                        <span>{{ \App\Helpers\Money::format($invoice->tax_total) }}</span>
                    </div>
                @endif
                <div class="d-flex justify-content-between fw-bold fs-5 pt-2" style="border-top: 2px solid var(--accent);">
                    <span>الإجمالي</span>
                    <span style="color: var(--primary);">{{ \App\Helpers\Money::format($invoice->total) }}</span>
                </div>

                @if($invoice->notes)
                    <div class="mt-3 p-2 rounded" style="background:rgba(var(--accent-rgb),.05); border-right:3px solid var(--accent);">
                        <div class="small fw-bold text-muted mb-1">ملاحظات:</div>
                        <div style="white-space:pre-wrap;">{{ $invoice->notes }}</div>
                    </div>
                @endif
            </div>
        </x-admin.data-panel>
    </div>

    <div class="col-xl-8">
        <x-admin.data-panel title="الدفعات" :count="$invoice->payments->count()" icon="bi-cash-stack">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>التاريخ</th>
                            <th>القيمة</th>
                            <th>الطريقة</th>
                            <th>المرجع</th>
                            <th>بواسطة</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoice->payments as $pay)
                            <tr>
                                <td>{{ $pay->paid_on->format('Y-m-d') }}</td>
                                <td class="fw-bold" style="color:var(--primary);">{{ \App\Helpers\Money::format($pay->amount) }}</td>
                                <td><span class="badge bg-secondary">{{ $pay->methodLabel() }}</span></td>
                                <td class="text-muted small">{{ $pay->reference ?? '—' }}</td>
                                <td class="small">{{ $pay->payer?->name ?? '—' }}</td>
                                <td class="small text-muted">{{ Str::limit($pay->notes, 40) ?? '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6">
                                <x-admin.empty-state icon="bi-cash-stack" message="لا دفعات بعد" compact />
                            </td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td class="text-end fw-bold">مجموع المدفوع</td>
                            <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($invoice->paid_total) }}</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-admin.data-panel>
    </div>
</div>

{{-- Pay modal --}}
@can('pay', $invoice)
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.supplier-invoices.pay', $invoice) }}">
                @csrf
                <div class="modal-header">
                    <h5>تسجيل دفعة — {{ $invoice->number }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small mb-3">
                        <strong>المتبقي:</strong> {{ \App\Helpers\Money::format($invoice->balance) }}
                    </div>
                    <div class="mb-2">
                        <label class="form-label">القيمة <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" max="{{ $invoice->balance }}"
                               name="amount" value="{{ $invoice->balance }}" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">طريقة الدفع <span class="text-danger">*</span></label>
                        <select name="method" class="form-select" required>
                            <option value="cash">نقدي</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                            <option value="cheque">شيك</option>
                            <option value="card">بطاقة</option>
                            <option value="credit_note">إشعار دائن</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">التاريخ <span class="text-danger">*</span></label>
                        <input type="date" name="paid_on" value="{{ today()->toDateString() }}" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">رقم المرجع</label>
                        <input type="text" name="reference" class="form-control" placeholder="رقم الشيك / رقم التحويل">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-success"><i class="bi bi-check-circle-fill"></i> تسجيل الدفعة</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

{{-- Cancel modal --}}
@can('cancel', $invoice)
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.supplier-invoices.cancel', $invoice) }}">
                @csrf
                <div class="modal-header">
                    <h5>إلغاء فاتورة مورد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">السبب <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                    <button class="btn btn-danger">تأكيد الإلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endsection
