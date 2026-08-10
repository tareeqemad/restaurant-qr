@extends('layouts.admin')
@section('title', 'الاستردادات')

@section('content')
<x-admin.breadcrumb title="الاستردادات" icon="bi-arrow-counterclockwise"
    subtitle="متابعة المبالغ المسترجعة للزبائن" />

<x-admin.stat-rail :stats="[
    ['label' => 'استردادات اليوم',   'value' => $stats['today_count'],                                        'icon' => 'bi-receipt-cutoff',    'color' => 'primary'],
    ['label' => 'قيمة اليوم',         'value' => \App\Helpers\Money::format($stats['today_amount']),            'icon' => 'bi-cash-stack',         'color' => 'accent'],
    ['label' => 'معلّقة',              'value' => $stats['pending'],                                             'icon' => 'bi-hourglass-split',   'color' => 'accent'],
]" />

<x-admin.data-panel title="سجل الاستردادات" :count="$refunds->total()" icon="bi-arrow-counterclockwise">
    <x-slot:actions>
        @if(request()->hasAny(['search', 'status', 'method', 'from', 'to']))
            <a href="{{ route('admin.refunds.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <details @if(request()->hasAny(['search','status','method','from','to'])) open @endif>
            <summary class="fw-semibold text-primary" style="cursor:pointer">
                <i class="bi bi-search me-1"></i> بحث وتصفية
            </summary>
        <form class="row g-2 align-items-end mt-2">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">بحث</label>
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="رقم الاسترداد / الفاتورة / المرجع">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">الحالة</label>
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="completed" @selected(request('status')==='completed')>مكتمل</option>
                    <option value="pending"   @selected(request('status')==='pending')>معلّق</option>
                    <option value="cancelled" @selected(request('status')==='cancelled')>ملغي</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">الطريقة</label>
                <select name="method" class="form-select">
                    <option value="">كل الطرق</option>
                    @foreach($methods as $key => $label)
                        <option value="{{ $key }}" @selected(request('method') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">من تاريخ</label>
                <input type="date" name="from" value="{{ request('from') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="{{ request('to') }}" class="form-control">
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-funnel"></i> استعلام</button>
            </div>
        </form>
        </details>
    </x-slot:filters>

    @if(request()->hasAny(['search','status','method','from','to']))
        <div class="alert alert-light border py-2 mb-3">
            النتائج: <strong>{{ number_format($filteredStats['count']) }}</strong>
            <span class="mx-2 text-muted">|</span>
            الإجمالي: <strong class="text-danger">{{ \App\Helpers\Money::format($filteredStats['amount']) }}</strong>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table align-middle ref-table">
            <thead class="bg-light">
                <tr>
                    <th>رقم الاسترداد</th>
                    @if($showBranchCol)<th>الفرع</th>@endif
                    <th>الفاتورة</th>
                    <th>الطاولة</th>
                    <th>القيمة</th>
                    <th>الطريقة</th>
                    <th>الحالة</th>
                    <th>السبب</th>
                    <th>بواسطة</th>
                    <th>الوقت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($refunds as $r)
                    <tr class="ref-row ref-row--{{ $r->status }}">
                        <td>
                            <code class="ref-num">{{ $r->number }}</code>
                            @if($r->reference)
                                <small class="text-muted d-block">مرجع: {{ $r->reference }}</small>
                            @endif
                        </td>
                        @if($showBranchCol)
                            <td><x-admin.branch-tag :branch="$r->invoice?->branch" /></td>
                        @endif
                        <td>
                            <code>{{ $r->invoice?->number ?? '—' }}</code>
                            @if($r->invoice?->customer_name || $r->invoice?->customer_phone)
                                <small class="text-muted d-block">
                                    {{ $r->invoice?->customer_name ?? $r->invoice?->customer_phone }}
                                </small>
                            @endif
                        </td>
                        <td>{{ $r->invoice?->tableSession?->table?->number ?? '—' }}</td>
                        <td class="ref-amount">{{ \App\Helpers\Money::format($r->amount) }}</td>
                        <td><span class="badge bg-secondary">{{ $r->methodLabel() }}</span></td>
                        <td><span class="badge bg-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span></td>
                        <td class="small text-muted ref-reason" title="{{ $r->reason }}">{{ \Illuminate\Support\Str::limit($r->reason, 60) }}</td>
                        <td>{{ $r->processor?->name ?? '—' }}</td>
                        <td>
                            <span class="ref-date">{{ optional($r->refunded_at)->format('Y/m/d H:i') ?? '—' }}</span>
                            @if($r->refunded_at)
                                <small class="text-muted d-block">{{ $r->refunded_at->diffForHumans() }}</small>
                            @endif
                        </td>
                        <td class="text-end">
                            @if($r->status === 'pending')
                                @can('complete', $r)
                                <form method="POST" action="{{ route('admin.refunds.complete', $r) }}" class="d-inline"
                                      onsubmit="return confirm('إتمام الاسترداد؟');">
                                    @csrf
                                    <button class="btn btn-sm btn-success" title="إتمام"><i class="bi bi-check2"></i></button>
                                </form>
                                @endcan
                                @can('cancel', $r)
                                <button class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#cancelRefundModal"
                                        data-ref-id="{{ $r->id }}"
                                        data-ref-num="{{ $r->number }}">
                                    <i class="bi bi-x"></i>
                                </button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showBranchCol ? 11 : 10 }}">
                        <x-admin.empty-state
                            icon="bi-arrow-counterclockwise"
                            title="ما في استردادات بعد"
                            message="تظهر هنا كل المبالغ المستردة من فواتير العملاء." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $refunds->links() }}</x-slot:footer>
</x-admin.data-panel>

<div class="modal fade" id="cancelRefundModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="cancelRefundForm" class="modal-content">
            @csrf
            <div class="modal-header bg-danger-transparent">
                <h6 class="modal-title">
                    <i class="bi bi-x-octagon"></i>
                    إلغاء الاسترداد <code id="cancelRefundNumber"></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                          placeholder="مثلاً: رفض بوابة الدفع العملية"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                <button class="btn btn-danger"><i class="bi bi-x-lg"></i> تأكيد الإلغاء</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    .ref-num,
    .ref-date,
    .ref-amount {
        font-family: ui-monospace, monospace;
    }
    .ref-num {
        background: #f9fafb;
        padding: 2px 7px;
        border-radius: 5px;
        color: #1f2937;
        font-weight: 700;
        font-size: .76rem;
    }
    .ref-amount {
        color: #b91c1c;
        font-weight: 800;
        white-space: nowrap;
    }
    .ref-reason {
        max-width: 240px;
        min-width: 180px;
    }
    .ref-row--pending { background: rgba(251, 191, 36, .04); }
    .ref-row--cancelled { opacity: .72; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const baseUrl = "{{ url('admin/refunds') }}";
    const modal = document.getElementById('cancelRefundModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (!trigger) return;

        document.getElementById('cancelRefundForm').action = `${baseUrl}/${trigger.dataset.refId}/cancel`;
        document.getElementById('cancelRefundNumber').textContent = trigger.dataset.refNum || '';
        document.getElementById('cancelRefundForm').querySelector('[name="reason"]').value = '';
    });
})();
</script>
@endpush
@endsection
