@extends('layouts.admin')
@section('title', 'بدل وجبات: '.$user->name)

@section('content')
<x-admin.breadcrumb
    :title="$user->name"
    icon="bi-cup-hot-fill"
    :crumbs="[['label' => 'بدل وجبات الموظفين', 'url' => route('admin.staff-meals.index')]]" />

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">الحد الشهري</small>
            <h4 class="mb-0">{{ \App\Helpers\Money::format($summary['allowance'] ?? 0) }}</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center"><div class="card-body">
            <small class="text-muted d-block">المُستهلك هذا الشهر</small>
            <h4 class="mb-0 text-accent">{{ \App\Helpers\Money::format($summary['used']) }}</h4>
        </div></div>
    </div>
    <div class="col-md-3">
        <div class="card text-center {{ ($summary['remaining'] ?? 0) < 0 ? 'border-danger' : 'border-success' }}">
            <div class="card-body">
                <small class="text-muted d-block">المتبقي</small>
                <h4 class="mb-0 {{ ($summary['remaining'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                    {{ \App\Helpers\Money::format($summary['remaining'] ?? 0) }}
                </h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-warning"><div class="card-body">
            <small class="text-muted d-block">إجمالي المستحق (كل الأشهر)</small>
            <h4 class="mb-0 text-warning">{{ \App\Helpers\Money::format($summary['outstanding']) }}</h4>
        </div></div>
    </div>
</div>

@if(($summary['gifted'] ?? 0) > 0)
    <div class="alert alert-info d-flex align-items-center gap-2 small mb-3">
        <i class="bi bi-gift-fill fs-5"></i>
        <div>
            تلقى هذا الموظف <strong>{{ \App\Helpers\Money::format($summary['gifted']) }}</strong>
            كهدايا وإعفاءات هذا الشهر (لم تُحسب على بدله).
        </div>
    </div>
@endif

@if($summary['outstanding'] > 0)
    <div class="card mb-3 border-success">
        <div class="card-header bg-success text-white">
            <strong><i class="bi bi-cash-coin"></i> تسوية المستحق</strong>
        </div>
        <div class="card-body">
            <form action="{{ route('admin.staff-meals.settle', $user) }}" method="POST">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">المبلغ</label>
                        <div class="input-group">
                            <input type="number" name="amount" step="0.01" min="0.01"
                                   max="{{ $summary['outstanding'] }}"
                                   value="{{ number_format($summary['outstanding'], 2, '.', '') }}"
                                   class="form-control form-control-lg text-end fw-bold" required>
                            <span class="input-group-text">ش.إ</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">طريقة التسوية</label>
                        <select name="method" class="form-select form-select-lg" required>
                            <option value="payroll_deduction">خصم من الراتب</option>
                            <option value="cash">دفع نقدي</option>
                            <option value="writeoff">شطب (مكافأة/استثناء)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-success btn-lg w-100">
                            <i class="bi bi-check-circle-fill"></i> تأكيد التسوية
                        </button>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted">ملاحظات (اختياري)</label>
                        <input type="text" name="notes" class="form-control" placeholder="مثلاً: راتب شهر مايو">
                    </div>
                </div>
                <div class="alert alert-info mt-2 mb-0 small">
                    <i class="bi bi-info-circle"></i>
                    التسوية تبدأ من أقدم الرسوم (FIFO). المبلغ الأقصى = إجمالي المستحق.
                </div>
            </form>
        </div>
    </div>
@endif

{{-- Per-item breakdown for the current month — aggregates everything
     the employee consumed (whether at a table or via quick-consume),
     with snapshot prices so the report honors price changes correctly. --}}
@if($itemAggregate->isNotEmpty())
    <div class="card mb-3">
        <div class="card-header">
            <strong><i class="bi bi-bar-chart-line"></i>
                ما استهلكه هذا الشهر ({{ $monthStart->translatedFormat('F Y') }})
            </strong>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الصنف</th>
                        <th class="text-center">الكمية</th>
                        <th class="text-end">متوسط السعر</th>
                        <th class="text-end">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($itemAggregate as $row)
                        <tr>
                            <td><strong>{{ $row->name }}</strong></td>
                            <td class="text-center">
                                <span class="badge bg-primary fs-6">×{{ rtrim(rtrim(number_format($row->total_qty, 2), '0'), '.') }}</span>
                            </td>
                            <td class="text-end text-muted">{{ \App\Helpers\Money::format($row->avg_unit_price) }}</td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::format($row->total_value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-light fw-bold">
                    <tr>
                        <td colspan="3">المجموع</td>
                        <td class="text-end text-primary">{{ \App\Helpers\Money::format($itemAggregate->sum('total_value')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer small text-muted">
            <i class="bi bi-info-circle"></i>
            متوسط السعر يعكس <strong>السعر الفعلي وقت كل عملية</strong> — لو سعر القائمة تغيّر خلال الشهر،
            كل عملية تحتفظ بسعرها الأصلي.
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header"><strong><i class="bi bi-clock-history"></i> سجل الرسوم ({{ $charges->count() }})</strong></div>
    <div class="card-body p-0">
        @if($charges->count())
            <table class="table table-hover mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>التاريخ</th>
                        <th>رقم الطلب</th>
                        <th class="text-end">المبلغ</th>
                        <th>الحالة</th>
                        <th>طريقة التسوية</th>
                        <th>ملاحظات</th>
                        <th class="text-center" style="width:120px">إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($charges as $c)
                        @php
                            $methodLabel = [
                                'cash'              => ['نقدي', 'success'],
                                'payroll_deduction' => ['خصم راتب', 'primary'],
                                'writeoff'          => ['شطب', 'danger'],
                                'gift'              => ['هدية', 'info'],
                            ][$c->settlement_method] ?? [$c->settlement_method, 'secondary'];
                        @endphp
                        <tr class="{{ $c->settled_at ? 'text-muted' : 'fw-bold' }}">
                            <td><small>{{ $c->charged_at?->format('Y-m-d H:i') }}</small></td>
                            <td>
                                @if($c->order)
                                    <code>{{ $c->order->number }}</code>
                                @else
                                    <small class="text-muted">تعديل يدوي</small>
                                @endif
                            </td>
                            <td class="text-end">{{ \App\Helpers\Money::format($c->amount) }}</td>
                            <td>
                                @if($c->settled_at)
                                    @if($c->settlement_method === 'gift')
                                        <span class="badge bg-info"><i class="bi bi-gift-fill"></i> هدية</span>
                                    @else
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> سُويت</span>
                                    @endif
                                @else
                                    <span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> مفتوحة</span>
                                @endif
                            </td>
                            <td>
                                @if($c->settlement_method)
                                    <span class="badge bg-{{ $methodLabel[1] }}">{{ $methodLabel[0] }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td><small class="text-muted">{{ $c->notes }}</small></td>
                            <td class="text-center">
                                @if(! $c->settled_at)
                                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal"
                                            data-bs-target="#waiveModal-{{ $c->id }}"
                                            title="إعفاء جزء أو كامل هذه الحركة">
                                        <i class="bi bi-gift"></i> إعفاء
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="text-center py-4 text-muted">لا رسوم بعد على هذا الموظف.</div>
        @endif
    </div>
</div>

{{-- ────────── Waiver modals (one per open charge) ──────────
     The manager picks the amount to waive (defaulting to the full
     charge), the method (gift vs writeoff), and an optional reason.
     A partial waiver splits the row in two so the audit trail keeps
     the original order reference. --}}
@foreach($charges->where('settled_at', null) as $c)
    <div class="modal fade" id="waiveModal-{{ $c->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('admin.staff-meals.charges.waive', $c) }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-gift text-info"></i>
                        إعفاء من حركة #{{ $c->id }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border small mb-3">
                        <div><strong>الموظف:</strong> {{ $user->name }}</div>
                        @if($c->order)
                            <div><strong>الطلب:</strong> <code>{{ $c->order->number }}</code></div>
                        @endif
                        <div><strong>قيمة الحركة:</strong> {{ \App\Helpers\Money::format($c->amount) }}</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">المبلغ المراد إعفاؤه</label>
                        <div class="input-group">
                            <input type="number" name="amount" step="0.01" min="0.01"
                                   max="{{ $c->amount }}"
                                   value="{{ number_format((float) $c->amount, 2, '.', '') }}"
                                   class="form-control text-end fw-bold" required>
                            <span class="input-group-text">ش.إ</span>
                        </div>
                        <small class="text-muted">القيمة الافتراضية = إعفاء كامل. قلّلها لإعفاء جزئي.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">نوع الإعفاء</label>
                        <select name="method" class="form-select" required>
                            <option value="gift" selected>🎁 هدية / مكافأة (يُسجَّل كمصروف هدايا)</option>
                            <option value="writeoff">شطب / تنازل (يُسجَّل كدين معدوم)</option>
                        </select>
                        <small class="text-muted d-block mt-1">
                            <strong>هدية</strong>: مناسبات، عيد ميلاد، موظف الشهر.
                            <strong>شطب</strong>: تنازل إداري لأسباب أخرى.
                        </small>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">السبب (اختياري)</label>
                        <input type="text" name="reason" maxlength="500" class="form-control"
                               placeholder="مثلاً: عيد ميلاد، تعويض عن خطأ بالطلب…">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-info text-white">
                        <i class="bi bi-check-circle"></i> تنفيذ الإعفاء
                    </button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endsection
