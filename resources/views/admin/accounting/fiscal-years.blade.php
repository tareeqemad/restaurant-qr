@extends('layouts.admin')

@section('title', 'السنوات المالية')

@section('content')
<x-admin.breadcrumb
    title="السنوات المالية"
    icon="bi-calendar2-check"
    subtitle="قفل أعلى للتاريخ يدعم الإقفال السنوي حتى لو كانت الشهور مقفلة كفترات مستقلة"
    :crumbs="[['label' => 'الفترات المحاسبية', 'url' => route('admin.accounting.periods')]]" />

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-light"><strong>سنة مالية جديدة</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounting.fiscal-years.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم السنة</label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', 'FY '.now()->format('Y')) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">من</label>
                        <input type="date" name="starts_on" class="form-control" value="{{ old('starts_on', now()->startOfYear()->toDateString()) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">إلى</label>
                        <input type="date" name="ends_on" class="form-control" value="{{ old('ends_on', now()->endOfYear()->toDateString()) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> إنشاء السنة</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <x-admin.data-panel title="السنوات المالية" icon="bi-calendar2-range" :count="$years->total()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>السنة</th>
                            <th>المدى</th>
                            <th>الحالة</th>
                            <th>قيد الإقفال</th>
                            <th>أقفلت بواسطة</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($years as $year)
                            <tr>
                                <td>
                                    <strong>{{ $year->name }}</strong>
                                    @if($year->notes)
                                        <span class="text-muted d-block small">{{ $year->notes }}</span>
                                    @endif
                                    @if(!$year->isClosed())
                                        @php($checks = collect($yearChecklists[$year->id] ?? []))
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
                                <td>{{ $year->starts_on?->toDateString() }} - {{ $year->ends_on?->toDateString() }}</td>
                                <td>
                                    <span class="badge {{ $year->isClosed() ? 'bg-danger' : 'bg-success' }}">
                                        {{ $year->isClosed() ? 'مقفلة' : 'مفتوحة' }}
                                    </span>
                                </td>
                                <td>
                                    @if($year->closingEntry)
                                        <span class="badge bg-light text-dark border">{{ $year->closingEntry->entry_no }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $year->closer?->name ?? '—' }}</td>
                                <td class="text-end">
                                    @if($year->isClosed())
                                        <form method="POST" action="{{ route('admin.accounting.fiscal-years.reopen', $year) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">إعادة فتح</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.accounting.fiscal-years.close', $year) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-danger">إقفال السنة</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">لا توجد سنوات مالية بعد.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $years->links() }}</div>
        </x-admin.data-panel>
    </div>
</div>
@endsection
