@extends('layouts.admin')
@section('title', 'التحويلات بانتظار التأكيد')

@section('content')
<x-admin.breadcrumb
    title="التحويلات بانتظار التأكيد"
    icon="bi-bank"
    subtitle="أكّد التحويلات من تطبيق البنك ثم اضغط ✓ — الفاتورة تُغلق تلقائياً"
    :crumbs="[['label' => 'الكاشير', 'url' => route('admin.cashier.index')]]">
    <x-slot:actions>
        <a href="{{ route('admin.cashier.transfers.report') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-file-earmark-text"></i> تقرير اليوم
        </a>
    </x-slot:actions>
</x-admin.breadcrumb>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

{{-- Auto-refresh indicator. The page reloads itself every 15s so a
     cashier who keeps the queue open sees newly-recorded transfers
     without manually hitting F5. The reload is suppressed while any
     modal is open (verify / reject) or an input is focused (search) so
     the user isn't interrupted mid-action. --}}
<div class="d-flex justify-content-end mb-2">
    <small class="text-muted">
        <i class="bi bi-arrow-clockwise"></i>
        تحديث تلقائي كل
        <span id="autoRefreshCountdown" class="fw-bold">15</span>
        ثانية
    </small>
</div>

<form method="GET" class="row g-2 mb-3">
    <div class="col-md-6">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="اسم المحوّل، الزبون، رقم الطاولة، أو المبلغ…"
                   value="{{ $search }}">
            <button class="btn btn-primary">بحث</button>
            @if($search !== '')
                <a href="{{ route('admin.cashier.transfers.queue') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            @endif
        </div>
    </div>
</form>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-hourglass-split text-warning"></i> بانتظار التأكيد</strong>
        <span class="badge bg-warning text-dark fs-12">{{ $pending->count() }}</span>
    </div>
    <div class="card-body p-0">
        @if($pending->isEmpty())
            <div class="text-center text-muted p-5">
                <i class="bi bi-check2-circle fs-1 d-block mb-2 text-success"></i>
                @if($search !== '')
                    <p class="mb-0">لا نتائج للبحث "<strong>{{ $search }}</strong>".</p>
                @else
                    <p class="mb-0">ولا تحويل بانتظار. كل شي تأكد.</p>
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الوقت</th>
                            <th>الطاولة</th>
                            <th>الزبون</th>
                            <th>المحوّل</th>
                            <th>المبلغ المُدّعى</th>
                            <th>سجّله</th>
                            <th>ملاحظات</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pending as $t)
                            <tr>
                                <td class="small text-muted">{{ $t->created_at->format('H:i') }}</td>
                                <td>
                                    @if($t->tableSession?->table)
                                        <strong>#{{ $t->tableSession->table->number }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($t->customer_name_snapshot)
                                        {{ $t->customer_name_snapshot }}
                                        @if($t->customer_phone_snapshot)
                                            <small class="text-muted d-block" dir="ltr">{{ $t->customer_phone_snapshot }}</small>
                                        @endif
                                    @else
                                        <span class="text-muted">walk-in</span>
                                    @endif
                                </td>
                                <td><strong>{{ $t->sender_name }}</strong></td>
                                <td><strong class="text-primary">{{ \App\Helpers\Money::format($t->amount) }}</strong></td>
                                <td class="small">{{ $t->recordedBy?->name ?? '—' }}</td>
                                <td class="small text-muted">{{ $t->notes ?? '—' }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-success"
                                            data-bs-toggle="modal" data-bs-target="#verifyModal{{ $t->id }}">
                                        <i class="bi bi-check2"></i> تأكدت
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal" data-bs-target="#rejectModal{{ $t->id }}">
                                        <i class="bi bi-x-lg"></i> رفض
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($recentlyClosed->isNotEmpty())
    <div class="card">
        <div class="card-header">
            <strong><i class="bi bi-clock-history"></i> تحويلات اليوم (تم الإغلاق)</strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الوقت</th>
                            <th>الطاولة</th>
                            <th>المحوّل</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>الكاشير</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentlyClosed as $t)
                            <tr>
                                <td class="small text-muted">{{ ($t->verified_at ?? $t->rejected_at ?? $t->updated_at)->format('H:i') }}</td>
                                <td>{{ $t->tableSession?->table?->number ?? '—' }}</td>
                                <td>{{ $t->sender_name }}</td>
                                <td>
                                    @if($t->status === 'verified' && $t->payment && abs((float) $t->payment->amount - (float) $t->amount) > 0.001)
                                        <strong>{{ \App\Helpers\Money::format($t->payment->amount) }}</strong>
                                        <small class="text-muted d-block">
                                            <s>{{ \App\Helpers\Money::format($t->amount) }}</s>
                                        </small>
                                    @else
                                        {{ \App\Helpers\Money::format($t->amount) }}
                                    @endif
                                </td>
                                <td>
                                    @if($t->status === 'verified')
                                        <span class="badge bg-success"><i class="bi bi-check2"></i> مؤكد</span>
                                    @else
                                        <span class="badge bg-danger" title="{{ $t->rejection_reason }}">
                                            <i class="bi bi-x-lg"></i> مرفوض
                                        </span>
                                    @endif
                                </td>
                                <td class="small">{{ $t->verifiedBy?->name ?? '—' }}</td>
                                <td class="text-end">
                                    @if($t->status === 'rejected')
                                        <form action="{{ route('admin.cashier.transfers.reopen', $t) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-warning"
                                                    onclick="return confirm('إعادة فتح هذا التحويل ووضعه بقائمة الانتظار؟');">
                                                <i class="bi bi-arrow-counterclockwise"></i> إعادة فتح
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

{{-- Verify modals — editable amount + cashier notes --}}
@foreach($pending as $t)
    <div class="modal fade" id="verifyModal{{ $t->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.cashier.transfers.verify', $t) }}" method="POST">
                    @csrf
                    <div class="modal-header bg-success-transparent">
                        <h5 class="modal-title">
                            <i class="bi bi-check2-circle text-success"></i>
                            تأكيد التحويل من {{ $t->sender_name }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert bg-light border small mb-3">
                            <div><strong>الطاولة:</strong> #{{ $t->tableSession?->table?->number ?? '—' }}</div>
                            <div><strong>الزبون:</strong> {{ $t->customer_name_snapshot ?? 'walk-in' }}</div>
                            <div><strong>المحوّل:</strong> {{ $t->sender_name }}</div>
                            <div><strong>سجّله:</strong> {{ $t->recordedBy?->name ?? '—' }} الساعة {{ $t->created_at->format('H:i') }}</div>
                            @if($t->notes)
                                <div><strong>ملاحظة الجرسون:</strong> {{ $t->notes }}</div>
                            @endif
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                المبلغ الفعلي الذي وصل البنك <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.01" min="0.01" name="verified_amount"
                                   class="form-control" required
                                   value="{{ number_format((float) $t->amount, 2, '.', '') }}">
                            <small class="text-muted">
                                المُدّعى: <strong>{{ \App\Helpers\Money::format($t->amount) }}</strong> —
                                عدّله لو وصل غير ذلك. الفرق يبقى كرصيد على الفاتورة (دين/تسوية على الحساب).
                            </small>
                        </div>

                        <div class="mb-2">
                            <label class="form-label">ملاحظات الكاشير</label>
                            <textarea name="verification_notes" rows="2" class="form-control" maxlength="500"
                                      placeholder="اختياري — مثلاً: وصل من حساب باسم مختلف، أو وصل متأخر"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                        <button class="btn btn-success">
                            <i class="bi bi-check2"></i> تأكيد وتسجيل الدفعة
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

{{-- Reject modals --}}
@foreach($pending as $t)
    <div class="modal fade" id="rejectModal{{ $t->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="{{ route('admin.cashier.transfers.reject', $t) }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">رفض التحويل من {{ $t->sender_name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small mb-3">
                            <i class="bi bi-info-circle"></i>
                            الرفض يسجّل سبباً يراه الجرسون. لو وصل التحويل لاحقاً، تقدر تعيد فتحه من قسم "تم الإغلاق".
                        </div>
                        <label class="form-label fw-bold">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required maxlength="500"
                                  placeholder="مثلاً: المبلغ مش موجود في كشف البنك، أو المبلغ مختلف عن اللي قاله الجرسون"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                        <button class="btn btn-danger"><i class="bi bi-x-lg"></i> تأكيد الرفض</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

@push('scripts')
<script>
(function () {
    // Auto-refresh the queue every 15s so newly-recorded transfers
    // appear without the cashier needing to hit F5. Skip the reload
    // when a Bootstrap modal is open (verify/reject in progress) or
    // when the user is typing in an input/textarea (search box,
    // rejection reason, etc.) — interrupting either would be rude.
    const REFRESH_SECONDS = 15;
    let remaining = REFRESH_SECONDS;
    const counter = document.getElementById('autoRefreshCountdown');

    setInterval(function () {
        const modalOpen = document.querySelector('.modal.show');
        const focused = document.activeElement;
        const typing = focused && (focused.tagName === 'INPUT' || focused.tagName === 'TEXTAREA');

        if (modalOpen || typing) {
            // Reset the countdown so we don't immediately reload the
            // moment the cashier closes the modal mid-second.
            remaining = REFRESH_SECONDS;
            if (counter) counter.textContent = remaining + ' (متوقف)';
            return;
        }

        remaining -= 1;
        if (counter) counter.textContent = remaining;
        if (remaining <= 0) {
            window.location.reload();
        }
    }, 1000);
})();
</script>
@endpush
@endsection
