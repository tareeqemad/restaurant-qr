@extends('layouts.admin')
@section('title', 'دين الزبون: ' . $customer->name)

@section('content')
<x-admin.breadcrumb
    title="{{ $customer->name }}"
    icon="bi-wallet2"
    :subtitle="'هاتف: ' . $customer->phone . ' · ' . $openInvoices->count() . ' فاتورة مفتوحة'" />

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body text-center">
                <small class="text-muted d-block mb-1">إجمالي الدين الحالي</small>
                <h2 class="text-danger mb-0">{{ number_format($outstanding, 2) }}</h2>
                <small class="text-muted">ش.إ</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <small class="text-muted d-block mb-1">الحد الائتماني</small>
                @if($customer->credit_limit === null)
                    <h3 class="text-muted mb-0">بدون حد</h3>
                @else
                    <h3 class="mb-0">{{ number_format((float) $customer->credit_limit, 2) }}</h3>
                    <small class="text-muted">
                        متبقّي: <strong class="{{ ($creditAvail ?? 0) <= 0 ? 'text-danger' : 'text-success' }}">
                            {{ number_format($creditAvail ?? 0, 2) }}
                        </strong> ش.إ
                    </small>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <small class="text-muted d-block mb-1">تعديل الحد الائتماني</small>
                <form action="{{ route('admin.customers.debts.credit_limit', $customer) }}" method="POST"
                      class="d-flex gap-2">
                    @csrf
                    <input type="number" step="0.01" min="0" name="credit_limit"
                           class="form-control form-control-sm"
                           value="{{ $customer->credit_limit }}"
                           placeholder="اتركه فاضي = بدون حد">
                    <button class="btn btn-sm btn-primary">حفظ</button>
                </form>
                <small class="text-muted mt-1 d-block">فاضي = بدون حد. القيمة بـ ش.إ.</small>
            </div>
        </div>
    </div>
</div>

{{-- Payment form — central action on the page --}}
<div class="card mb-3 border-success">
    <div class="card-header bg-success text-white">
        <i class="bi bi-cash-coin"></i> <strong>تسديد دفعة</strong>
    </div>
    <div class="card-body">
        <form action="{{ route('admin.customers.debts.payment', $customer) }}" method="POST">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">المبلغ المدفوع <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="amount" step="0.01" min="0.01" required
                               class="form-control form-control-lg text-end fw-bold"
                               max="{{ $outstanding }}"
                               value="{{ number_format($outstanding, 2, '.', '') }}">
                        <span class="input-group-text">ش.إ</span>
                    </div>
                    <small class="text-muted">الأقصى: {{ number_format($outstanding, 2) }} ش.إ</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">طريقة الدفع <span class="text-danger">*</span></label>
                    <select name="method" class="form-select form-select-lg" required>
                        <option value="cash">نقدا</option>
                        <option value="card">فيزا</option>
                        <option value="transfer">تحويل بنكي</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">مرجع (اختياري)</label>
                    <input type="text" name="reference" class="form-control" placeholder="رقم العملية…">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-success btn-lg w-100">
                        <i class="bi bi-check-circle-fill"></i> تسجيل الدفعة
                    </button>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted">ملاحظات (اختياري)</label>
                    <input type="text" name="notes" class="form-control" placeholder="…">
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0 small">
                <i class="bi bi-info-circle"></i>
                النظام راح يوزّع المبلغ تلقائياً: الفواتير الأقدم أولاً (FIFO).
            </div>
        </form>
    </div>
</div>

{{-- Open debt invoices --}}
<div class="card mb-3">
    <div class="card-header">
        <strong><i class="bi bi-file-earmark-text"></i> الفواتير المفتوحة ({{ $openInvoices->count() }})</strong>
    </div>
    <div class="card-body p-0">
        @if($openInvoices->count())
            <table class="table table-hover mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th class="text-end">إجمالي الفاتورة</th>
                        <th class="text-end">مدفوع</th>
                        <th class="text-end">متبقّي</th>
                        <th class="text-center">تاريخ التأجيل</th>
                        <th class="text-center">العمر</th>
                        @can('create', \App\Models\Payment::class)
                            <th class="text-center">إجراء</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($openInvoices as $inv)
                        @php $ageDays = $inv->settled_on_account_at?->diffInDays(now()) ?? 0; @endphp
                        <tr>
                            <td><strong>{{ $inv->number }}</strong></td>
                            <td class="text-end">{{ number_format((float) $inv->total, 2) }}</td>
                            <td class="text-end text-success">{{ number_format((float) $inv->paid_total, 2) }}</td>
                            <td class="text-end fw-bold text-danger">{{ number_format((float) $inv->balance, 2) }}</td>
                            <td class="text-center text-muted">
                                <small>{{ $inv->settled_on_account_at?->format('Y-m-d H:i') }}</small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-{{ $ageDays > 30 ? 'danger' : ($ageDays > 7 ? 'warning' : 'secondary') }}">
                                    {{ (int) $ageDays }} يوم
                                </span>
                            </td>
                            @can('create', \App\Models\Payment::class)
                                <td class="text-center">
                                    {{-- Un-park an accidental settle-on-account: pulls the
                                         invoice OUT of the debt ledger and back into normal
                                         collection. Guards + Arabic errors come from
                                         BillingService::unparkSettleOnAccount via flash. --}}
                                    <form method="POST" action="{{ route('admin.cashier.unpark', $inv) }}" class="d-inline"
                                          onsubmit="return confirm('إلغاء تأجيل الفاتورة {{ $inv->number }}؟ ستُحذف من سجل ديون الزبون وترجع إلى التحصيل العادي.')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-arrow-counterclockwise"></i> إلغاء التأجيل
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="text-center py-4 text-muted">
                <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
                <p class="mb-0">لا فواتير مفتوحة — تمام 👍</p>
            </div>
        @endif
    </div>
</div>

{{-- Payment history --}}
@if($debtPayments->count())
    <div class="card">
        <div class="card-header">
            <strong><i class="bi bi-clock-history"></i> سجل دفعات الديون (آخر {{ $debtPayments->count() }})</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>التاريخ</th>
                        <th>الفاتورة</th>
                        <th class="text-end">المبلغ</th>
                        <th>الطريقة</th>
                        <th>المرجع</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($debtPayments as $p)
                        <tr>
                            <td><small>{{ $p->paid_at?->format('Y-m-d H:i') }}</small></td>
                            <td><small><strong>{{ $p->invoice?->number }}</strong></small></td>
                            <td class="text-end text-success fw-bold">{{ number_format((float) $p->amount, 2) }}</td>
                            <td><small>{{ ['cash'=>'نقد','card'=>'بطاقة','transfer'=>'حوالة','app'=>'تطبيق','credit'=>'دين'][$p->method] ?? $p->method }}</small></td>
                            <td><small dir="ltr">{{ $p->reference }}</small></td>
                            <td><small class="text-muted">{{ $p->notes }}</small></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
