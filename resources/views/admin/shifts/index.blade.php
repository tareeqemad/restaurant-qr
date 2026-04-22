@extends('layouts.admin')@section('title','الورديات')
@section('content')
<x-admin.breadcrumb title="الورديات" icon="bi-clock-history"
    subtitle="افتتاح، إغلاق، ومعاينة الشفت الحالي" />

<div class="row g-3">
    <div class="col-lg-5">
        @if($activeShift)
            <div class="card border-success"><div class="card-body">
                <h5 class="fw-bold text-success"><i class="bi bi-clock"></i> شفت مفتوح</h5>
                <p>افتتح: {{ $activeShift->opened_at->format('Y-m-d H:i') }}</p>
                <p>كاش افتتاح: {{ \App\Helpers\Money::format($activeShift->cash_opening) }}</p>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#close"><i class="bi bi-box-arrow-right"></i> إغلاق الشفت</button>
                <div class="modal fade" id="close"><div class="modal-dialog"><div class="modal-content">
                    <form action="{{ route('admin.shifts.close', $activeShift) }}" method="POST">@csrf
                        <div class="modal-header"><h5>إغلاق الشفت</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-2"><label class="form-label">الكاش المتوفر عند الإغلاق</label><input type="number" step="0.01" name="cash_closing" class="form-control" required></div>
                            <div class="mb-2"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control"></textarea></div>
                        </div>
                        <div class="modal-footer"><button class="btn btn-danger">إغلاق</button></div>
                    </form>
                </div></div></div>
            </div></div>
        @else
            <div class="card"><div class="card-body">
                <h5 class="fw-bold">فتح شفت جديد</h5>
                <form action="{{ route('admin.shifts.store') }}" method="POST">@csrf
                    <label class="form-label">الكاش الابتدائي</label>
                    <input type="number" step="0.01" name="cash_opening" class="form-control mb-2" required>
                    <button class="btn btn-primary"><i class="bi bi-play"></i> فتح الشفت</button>
                </form>
            </div></div>
        @endif
    </div>

    <div class="col-lg-7">
        <div class="card"><div class="card-header"><strong>تاريخ الورديات</strong></div>
        <div class="card-body">
        <table class="table table-sm"><thead><tr><th>موظف</th><th>افتتاح</th><th>إغلاق</th><th>كاش</th><th>كارد</th><th>فرق</th><th>حالة</th></tr></thead>
        <tbody>@foreach($shifts as $s)
            <tr><td>{{ $s->user->name }}</td>
                <td>{{ $s->opened_at->format('m-d H:i') }}</td>
                <td>{{ $s->closed_at?->format('m-d H:i') ?? '—' }}</td>
                <td>{{ number_format((float)$s->cash_sales, 2) }}</td>
                <td>{{ number_format((float)$s->card_sales, 2) }}</td>
                <td class="{{ (float)$s->cash_variance < 0 ? 'text-danger' : '' }}">{{ number_format((float)$s->cash_variance, 2) }}</td>
                <td>{!! $s->status === 'open' ? '<span class="badge bg-success">مفتوح</span>' : '<span class="badge bg-secondary">مغلق</span>' !!}</td>
            </tr>
        @endforeach</tbody></table>
        {{ $shifts->links() }}
        </div></div>
    </div>
</div>
@endsection
