@extends('layouts.admin')
@section('title', 'تقرير التحويلات — '.$date->format('Y-m-d'))

@section('content')
<x-admin.breadcrumb
    title="تقرير التحويلات البنكية"
    icon="bi-file-earmark-bar-graph"
    :subtitle="$date->format('Y-m-d')"
    :crumbs="[
        ['label' => 'الكاشير', 'url' => route('admin.cashier.index')],
        ['label' => 'بانتظار التأكيد', 'url' => route('admin.cashier.transfers.queue')],
    ]" />

<form method="GET" class="row g-2 align-items-end mb-3">
    <div class="col-md-3">
        <label class="form-label small">التاريخ</label>
        <input type="date" name="date" class="form-control" value="{{ $date->format('Y-m-d') }}">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> عرض</button>
    </div>
</form>

<div class="row g-3 mb-3">
    @foreach([
        ['key' => 'verified', 'label' => 'مؤكد', 'color' => 'success', 'icon' => 'check2-circle'],
        ['key' => 'pending',  'label' => 'بانتظار', 'color' => 'warning', 'icon' => 'hourglass-split'],
        ['key' => 'rejected', 'label' => 'مرفوض', 'color' => 'danger', 'icon' => 'x-octagon'],
    ] as $card)
        <div class="col-md-4">
            <div class="card border-{{ $card['color'] }}">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted small">{{ $card['label'] }}</div>
                            <div class="fs-4 fw-bold">{{ \App\Helpers\Money::format($totals[$card['key']] ?? 0) }}</div>
                            <small class="text-muted">{{ $summary[$card['key']]->count() }} عملية</small>
                        </div>
                        <i class="bi bi-{{ $card['icon'] }} fs-1 text-{{ $card['color'] }}"></i>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>كل التحويلات لهذا اليوم</strong>
        <small class="text-muted">طابق مع كشف البنك للوصول إلى reconciliation كامل.</small>
    </div>
    <div class="card-body p-0">
        @if($rows->isEmpty())
            <div class="text-center text-muted p-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                <p class="mb-0">ولا تحويل في {{ $date->format('Y-m-d') }}.</p>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الوقت</th>
                            <th>الطاولة</th>
                            <th>المحوّل</th>
                            <th>المبلغ المُدّعى</th>
                            <th>المبلغ المؤكد</th>
                            <th>الحالة</th>
                            <th>الجرسون</th>
                            <th>الكاشير</th>
                            <th>ملاحظات</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $t)
                            @php
                                $verifiedDisplay = $t->payment?->amount;
                                $hasDiff = $t->status === 'verified' && $verifiedDisplay !== null
                                    && abs((float) $verifiedDisplay - (float) $t->amount) > 0.001;
                            @endphp
                            <tr>
                                <td class="small text-muted">{{ $t->created_at->format('H:i:s') }}</td>
                                <td>{{ $t->tableSession?->table?->number ?? '—' }}</td>
                                <td>{{ $t->sender_name }}</td>
                                <td>{{ \App\Helpers\Money::format($t->amount) }}</td>
                                <td>
                                    @if($t->status === 'verified')
                                        <strong class="{{ $hasDiff ? 'text-warning' : '' }}">
                                            {{ \App\Helpers\Money::format($verifiedDisplay ?? $t->amount) }}
                                        </strong>
                                        @if($hasDiff)
                                            <i class="bi bi-exclamation-triangle text-warning"
                                               title="تم تعديل المبلغ عند التأكيد"></i>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($t->status === 'verified')
                                        <span class="badge bg-success">مؤكد</span>
                                    @elseif($t->status === 'rejected')
                                        <span class="badge bg-danger">مرفوض</span>
                                    @else
                                        <span class="badge bg-warning text-dark">بانتظار</span>
                                    @endif
                                </td>
                                <td class="small">{{ $t->recordedBy?->name ?? '—' }}</td>
                                <td class="small">{{ $t->verifiedBy?->name ?? '—' }}</td>
                                <td class="small text-muted">
                                    @if($t->status === 'rejected')
                                        {{ $t->rejection_reason }}
                                    @elseif($t->status === 'verified' && $t->verification_notes)
                                        {{ $t->verification_notes }}
                                    @elseif($t->notes)
                                        {{ $t->notes }}
                                    @else
                                        —
                                    @endif
                                </td>
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
        @endif
    </div>
</div>
@endsection
