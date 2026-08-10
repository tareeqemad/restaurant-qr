@extends('layouts.admin')

@section('title', 'إقفال الشهر')

@section('content')
<x-admin.breadcrumb
    title="إقفال الشهر"
    icon="bi-calendar-check"
    subtitle="راجع الشهر ثم اقفله لمنع تعديل قيوده"
    :crumbs="[['label' => 'المحاسبة', 'url' => route('admin.accounting.index')]]" />

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-light"><strong>إضافة شهر</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.accounting.periods.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-bold">من</label>
                        <input type="date" name="starts_on" class="form-control" value="{{ old('starts_on', now()->startOfMonth()->toDateString()) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">إلى</label>
                        <input type="date" name="ends_on" class="form-control" value="{{ old('ends_on', now()->endOfMonth()->toDateString()) }}" required>
                    </div>
                    <p class="small text-muted">سيُسمّي النظام الفترة تلقائياً حسب تاريخ البداية.</p>
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-circle"></i> إضافة الشهر</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <x-admin.data-panel title="الشهور" icon="bi-calendar-range" :count="$periods->total()">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th>الشهر</th>
                            <th>الحالة</th>
                            <th>الفحص</th>
                            <th class="text-end">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($periods as $period)
                            @php
                                $checks = collect($periodChecklists[$period->id] ?? []);
                                $issues = $checks->where('ok', false);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $period->name }}</strong>
                                    <span class="text-muted d-block small">
                                        {{ $period->starts_on?->toDateString() }} — {{ $period->ends_on?->toDateString() }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $period->isClosed() ? 'bg-secondary' : 'bg-success' }}">
                                        {{ $period->isClosed() ? 'مقفل' : 'مفتوح' }}
                                    </span>
                                </td>
                                <td>
                                    @if($period->isClosed())
                                        <span class="text-muted small">تم الإقفال</span>
                                    @elseif($issues->isEmpty())
                                        <span class="text-success small fw-bold"><i class="bi bi-check-circle"></i> جاهز للإقفال</span>
                                    @else
                                        <details>
                                            <summary class="text-warning small fw-bold" style="cursor:pointer">
                                                {{ $issues->count() }} ملاحظة قبل الإقفال
                                            </summary>
                                            <div class="small mt-2 d-grid gap-1">
                                                @foreach($issues as $check)
                                                    <span>• {{ $check['label'] }}</span>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($period->isClosed())
                                        <form method="POST" action="{{ route('admin.accounting.periods.reopen', $period) }}" class="d-inline"
                                              onsubmit="return confirm('إعادة فتح هذا الشهر؟')">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">إعادة فتح</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.accounting.periods.close', $period) }}" class="d-inline"
                                              onsubmit="return confirm('إقفال هذا الشهر ومنع تعديل قيوده؟')">
                                            @csrf
                                            <button class="btn btn-sm btn-primary">إقفال الشهر</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">لا توجد شهور محاسبية بعد.</td>
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
