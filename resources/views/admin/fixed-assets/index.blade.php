@extends('layouts.admin')

@section('title', 'الأصول الثابتة')

@section('content')
<x-admin.breadcrumb
    title="الأصول الثابتة"
    icon="bi-building-gear"
    subtitle="سجل الأصول، تكلفة الشراء، مجمع الإهلاك، والقيمة الدفترية"
    :crumbs="[['label' => 'القيود اليومية', 'url' => route('admin.accounting.journal')]]" />

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <x-admin.kpi icon="bi-box-seam" color="primary" :value="$stats['count']" label="عدد الأصول" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-check2-circle" color="success" :value="$stats['active']" label="أصول نشطة" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-cash-stack" color="info" :value="\App\Helpers\Money::formatAccounting($stats['cost'])" label="إجمالي التكلفة" />
    </div>
    <div class="col-md-3">
        <x-admin.kpi icon="bi-graph-down-arrow" color="warning" :value="\App\Helpers\Money::formatAccounting($stats['book'])" label="القيمة الدفترية" />
    </div>
</div>

<form method="GET" class="card mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">الحالة</label>
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    @foreach($statusLabels as $status => $label)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">الفئة</label>
                <select name="category" class="form-select">
                    <option value="">كل الفئات</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">بحث</label>
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="رقم الأصل، الاسم، المورد">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button>
            </div>
        </div>
    </div>
</form>

@if(auth()->user()?->hasPermission('chart_of_accounts.update'))
    <form method="POST" action="{{ route('admin.accounting.fixed-assets.depreciation-run') }}" class="card mb-3">
        @csrf
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row gap-3 align-items-lg-end">
                <div class="flex-grow-1">
                    <h2 class="h6 fw-bold mb-1">ترحيل إهلاك شهر كامل</h2>
                    <div class="text-muted small">يرحل النظام الإهلاك لكل أصل نشط ومستحق ولم يتم ترحيله لنفس الشهر.</div>
                </div>
                <div>
                    <label class="form-label fw-bold">الشهر</label>
                    <input type="month" name="period_month" class="form-control" value="{{ old('period_month', $defaultPeriodMonth) }}" required>
                </div>
                <div>
                    <label class="form-label fw-bold">تاريخ القيد</label>
                    <input type="date" name="posted_on" class="form-control" value="{{ old('posted_on', $defaultPostedOn) }}" required>
                </div>
                <div class="flex-grow-1">
                    <label class="form-label fw-bold">ملاحظات</label>
                    <input type="text" name="notes" class="form-control" value="{{ old('notes') }}" placeholder="اختياري">
                </div>
                <div class="d-grid">
                    <button class="btn btn-warning">
                        <i class="bi bi-calendar-check"></i> ترحيل الإهلاك
                    </button>
                </div>
            </div>
        </div>
    </form>
@endif

<x-admin.data-panel title="سجل الأصول الثابتة" icon="bi-building-gear" :count="$assets->total()">
    <x-slot:actions>
        @if(auth()->user()?->hasPermission('chart_of_accounts.create'))
            <a href="{{ route('admin.accounting.fixed-assets.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> أصل جديد
            </a>
        @endif
    </x-slot:actions>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>الأصل</th>
                    <th>الفئة</th>
                    <th>تاريخ الشراء</th>
                    <th class="text-end">التكلفة</th>
                    <th class="text-end">مجمع الإهلاك</th>
                    <th class="text-end">القيمة الدفترية</th>
                    <th>الحالة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($assets as $asset)
                    <tr>
                        <td>
                            <strong>{{ $asset->asset_number }}</strong>
                            <div>{{ $asset->name }}</div>
                            <div class="text-muted small">{{ $asset->vendor_name }}</div>
                        </td>
                        <td>{{ $asset->category ?? '—' }}</td>
                        <td>{{ $asset->acquisition_date?->toDateString() }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($asset->cost) }}</td>
                        <td class="text-end">{{ \App\Helpers\Money::formatAccounting($asset->accumulated_depreciation) }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\Money::formatAccounting($asset->bookValue()) }}</td>
                        <td>{{ $statusLabels[$asset->status] ?? $asset->status }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.accounting.fixed-assets.show', $asset) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">لا توجد أصول ثابتة بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $assets->links() }}</div>
</x-admin.data-panel>
@endsection
