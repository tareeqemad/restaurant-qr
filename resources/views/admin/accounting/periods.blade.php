@extends('layouts.admin')

@section('title', 'الفترات المحاسبية')

@section('content')
<x-admin.breadcrumb
    title="الفترات المحاسبية"
    icon="bi-calendar-lock"
    subtitle="إقفال شهر أو سنة ينشئ قيد إقفال رسمي ويمنع ترحيل أي قيد بتاريخ داخل الفترة"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-light"><strong>فترة جديدة</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounting.periods.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الفترة</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', now()->format('F Y')) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">من</label>
                        <input type="date" name="starts_on" class="form-control" value="{{ old('starts_on', now()->startOfMonth()->toDateString()) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">إلى</label>
                        <input type="date" name="ends_on" class="form-control" value="{{ old('ends_on', now()->endOfMonth()->toDateString()) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> إنشاء الفترة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <x-admin.data-panel title="الفترات" icon="bi-calendar-range" :count="$periods->total()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الفترة</th>
                            <th>المدى</th>
                            <th>الحالة</th>
                            <th>قيد الإقفال</th>
                            <th>أقفلت بواسطة</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($periods as $period)
                            <tr>
                                <td>
                                    <strong>{{ $period->name }}</strong>
                                    @if($period->notes)
                                        <span class="text-muted d-block small">{{ $period->notes }}</span>
                                    @endif
                                    @if(!$period->isClosed())
                                        @php($checks = collect($periodChecklists[$period->id] ?? []))
                                        @if($checks->isNotEmpty())
                                            <div class="small mt-2">
                                                @foreach($checks as $check)
                                                    <span class="badge {{ $check['ok'] ? 'bg-success' : ($check['severity'] === 'block' ? 'bg-danger' : 'bg-warning text-dark') }} me-1 mb-1">
                                                        {{ $check['label'] }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td>{{ $period->starts_on?->toDateString() }} - {{ $period->ends_on?->toDateString() }}</td>
                                <td>
                                    <span class="badge {{ $period->isClosed() ? 'bg-danger' : 'bg-success' }}">
                                        {{ $period->isClosed() ? 'مقفلة' : 'مفتوحة' }}
                                    </span>
                                </td>
                                <td>
                                    @if($period->closingEntry)
                                        <span class="badge bg-light text-dark border">{{ $period->closingEntry->entry_no }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $period->closer?->name ?? '—' }}</td>
                                <td class="text-end">
                                    @if($period->isClosed())
                                        <form method="POST" action="{{ route('admin.accounting.periods.reopen', $period) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">إعادة فتح</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.accounting.periods.close', $period) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger">إقفال</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">لا توجد فترات محاسبية بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $periods->links() }}</div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
