@extends('layouts.admin')
@section('title', 'الاستردادات')

@section('content')
<x-admin.breadcrumb title="الاستردادات" icon="bi-arrow-counterclockwise"
    subtitle="متابعة المبالغ المسترجعة للزبائن" />

<x-admin.stat-rail :stats="[
    ['label' => 'استردادات اليوم',   'value' => $stats['today_count'],                                        'icon' => 'bi-receipt-cutoff',    'color' => 'primary'],
    ['label' => 'قيمة اليوم',         'value' => \App\Helpers\Money::format($stats['today_amount']),            'icon' => 'bi-cash-stack',         'color' => 'accent'],
    ['label' => 'معلّقة',              'value' => $stats['pending'],                                             'icon' => 'bi-hourglass-split',   'color' => 'accent'],
    ['label' => 'قيمة الشهر',         'value' => \App\Helpers\Money::format($stats['month_amount']),            'icon' => 'bi-calendar-month',    'color' => 'danger'],
]" />

<x-admin.data-panel title="سجل الاستردادات" :count="$refunds->total()" icon="bi-arrow-counterclockwise">
    <x-slot:actions>
        @if(request()->hasAny(['search', 'status', 'from', 'to']))
            <a href="{{ route('admin.refunds.index') }}" class="btn btn-light"><i class="bi bi-x-circle"></i> مسح</a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3"><input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 رقم الاسترداد/الفاتورة"></div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="completed" @selected(request('status')==='completed')>مكتمل</option>
                    <option value="pending"   @selected(request('status')==='pending')>معلّق</option>
                    <option value="cancelled" @selected(request('status')==='cancelled')>ملغي</option>
                </select>
            </div>
            <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control" placeholder="من"></div>
            <div class="col-md-2"><input type="date" name="to"   value="{{ request('to')   }}" class="form-control" placeholder="إلى"></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button></div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>رقم الاسترداد</th>
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
                    <tr>
                        <td><code>{{ $r->number }}</code></td>
                        <td><code>{{ $r->invoice?->number ?? '—' }}</code></td>
                        <td>{{ $r->invoice?->tableSession?->table?->number ?? '—' }}</td>
                        <td class="fw-bold" style="color: #b91c1c;">{{ \App\Helpers\Money::format($r->amount) }}</td>
                        <td><span class="badge bg-secondary">{{ $r->methodLabel() }}</span></td>
                        <td><span class="badge bg-{{ $r->statusColor() }}">{{ $r->statusLabel() }}</span></td>
                        <td class="small text-muted" style="max-width:220px;">{{ Str::limit($r->reason, 60) }}</td>
                        <td>{{ $r->processor?->name ?? '—' }}</td>
                        <td class="text-muted small">{{ $r->refunded_at->diffForHumans() }}</td>
                        <td>
                            @if($r->status === 'pending')
                                @can('complete', $r)
                                <form method="POST" action="{{ route('admin.refunds.complete', $r) }}" class="d-inline"
                                      onsubmit="return confirm('إتمام الاسترداد؟');">
                                    @csrf
                                    <button class="btn btn-sm btn-success" title="إتمام"><i class="bi bi-check2"></i></button>
                                </form>
                                @endcan
                                @can('cancel', $r)
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRef{{ $r->id }}">
                                    <i class="bi bi-x"></i>
                                </button>
                                <div class="modal fade" id="cancelRef{{ $r->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="{{ route('admin.refunds.cancel', $r) }}">
                                                @csrf
                                                <div class="modal-header"><h5>إلغاء الاسترداد {{ $r->number }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3"><label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                                                    <textarea name="reason" class="form-control" rows="3" required></textarea></div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                                    <button class="btn btn-danger">تأكيد</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10">
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
@endsection
