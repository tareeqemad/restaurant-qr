@extends('layouts.admin')@section('title','أكثر الأصناف مبيعاً')
@section('content')
<x-admin.breadcrumb title="أكثر الأصناف مبيعاً" icon="bi-trophy"
    subtitle="ترتيب الأصناف حسب الكمية أو الإيراد"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

<x-admin.data-panel title="الأصناف الأكثر حركة" icon="bi-trophy-fill" :count="$rows->total()">
    <x-slot:filters>
        <form class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">من تاريخ</label>
                <input type="date" name="from" value="{{ $from }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted fw-bold">إلى تاريخ</label>
                <input type="date" name="to" value="{{ $to }}" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted fw-bold">اختصارات</label>
                <div class="btn-group w-100">
                    <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">اليوم</a>
                    <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">7 أيام</a>
                    <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="btn btn-light btn-sm">الشهر</a>
                </div>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>#</th>
                    <th>الصنف</th>
                    <th>الكمية</th>
                    <th>الإجمالي</th>
                    <th>متوسط القطعة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $i => $r)
                    <tr>
                        <td>{{ $i + 1 + ($rows->currentPage() - 1) * 50 }}</td>
                        <td class="fw-bold">{{ $r->name_snapshot }}</td>
                        <td>{{ number_format((float) $r->qty, 2) }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($r->total) }}</td>
                        <td>{{ \App\Helpers\Money::format((float) $r->qty > 0 ? (float) $r->total / (float) $r->qty : 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">
                        <x-admin.empty-state
                            icon="bi-trophy"
                            title="ما في بيانات"
                            message="لم تُباع أي أصناف ضمن الفترة المختارة." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>
        {{ $rows->links() }}
    </x-slot:footer>
</x-admin.data-panel>
@endsection
