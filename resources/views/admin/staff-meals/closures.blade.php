@extends('layouts.admin')
@section('title', 'إقفالات بدل الوجبات')

@section('content')
<x-admin.breadcrumb
    title="إقفالات بدل الوجبات الشهرية"
    icon="bi-archive-fill"
    :subtitle="'سجل عمليات إقفال الأشهر وكشوف خصم الرواتب'"
    :crumbs="[['label' => 'بدل وجبات الموظفين', 'url' => route('admin.staff-meals.index')]]"/>

<x-admin.data-panel title="الإقفالات السابقة" icon="bi-archive" :count="$closures->total()">
    @if($closures->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الشهر</th>
                        <th>الفرع</th>
                        <th>الطريقة</th>
                        <th class="text-end">الموظفون</th>
                        <th class="text-end">الحركات</th>
                        <th class="text-end">إجمالي الخصم</th>
                        <th>أغلقه</th>
                        <th>التاريخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($closures as $c)
                        @php
                            $methodBadge = match($c->method) {
                                'payroll_deduction' => ['خصم رواتب', 'primary'],
                                'cash'              => ['نقد', 'success'],
                                'writeoff'          => ['إعدام', 'danger'],
                                default             => [$c->method, 'secondary'],
                            };
                        @endphp
                        <tr>
                            <td><strong>{{ $c->month->format('Y-m') }}</strong></td>
                            <td>{{ $c->branch?->name ?? 'كل الفروع' }}</td>
                            <td><span class="badge bg-{{ $methodBadge[1] }}">{{ $methodBadge[0] }}</span></td>
                            <td class="text-end">{{ $c->staff_count }}</td>
                            <td class="text-end">{{ $c->charge_count }}</td>
                            <td class="text-end fw-bold">{{ \App\Helpers\Money::format($c->total_amount) }}</td>
                            <td><small>{{ $c->closedBy?->name ?? '—' }}</small></td>
                            <td><small class="text-muted">{{ $c->closed_at?->format('Y-m-d H:i') }}</small></td>
                            <td>
                                <a href="{{ route('admin.staff-meals.closures.show', $c) }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-printer"></i> الكشف
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $closures->links() }}</div>
    @else
        <div class="text-center py-5 text-muted">
            <i class="bi bi-archive fs-1 d-block mb-2"></i>
            لا توجد إقفالات شهرية بعد. استخدم زر «إقفال الشهر» من شاشة بدل الوجبات لبدء أول إقفال.
        </div>
    @endif
</x-admin.data-panel>
@endsection
