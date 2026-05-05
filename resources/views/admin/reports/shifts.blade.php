@extends('layouts.admin')@section('title','تقرير الورديات')
@section('content')
<x-admin.breadcrumb title="تقرير الورديات" icon="bi-clock-history"
    subtitle="متابعة الافتتاح والإغلاق ومبيعات كل وردية وفروقات الكاش"
    :crumbs="[['label' => 'التقارير', 'url' => route('admin.reports.index')]]" />

<x-admin.stat-rail :stats="[
    ['label' => 'ورديات مفتوحة', 'value' => $shiftStats['open'], 'icon' => 'bi-clock', 'color' => $shiftStats['open'] > 0 ? 'warning' : 'muted'],
    ['label' => 'ورديات اليوم', 'value' => $shiftStats['today'], 'icon' => 'bi-calendar-check', 'color' => 'primary'],
    ['label' => 'مبيعات الشهر', 'value' => \App\Helpers\Money::format($shiftStats['month_sales']), 'icon' => 'bi-cash-stack', 'color' => 'success'],
    ['label' => 'فرق الكاش الشهري', 'value' => \App\Helpers\Money::format($shiftStats['cash_variance']), 'icon' => 'bi-wallet2', 'color' => abs($shiftStats['cash_variance']) > 1 ? 'danger' : 'success'],
]" />

<x-admin.data-panel title="سجل الورديات" icon="bi-clock-history" :count="$shifts->total()">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th>موظف</th>
                    <th>افتتاح</th>
                    <th>إغلاق</th>
                    <th>مدة</th>
                    <th>كاش</th>
                    <th>كارد</th>
                    <th>أخرى</th>
                    <th>مجموع</th>
                    <th>فرق</th>
                    <th>حالة</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shifts as $s)
                    <tr>
                        <td class="fw-bold">{{ $s->user->name }}</td>
                        <td>{{ $s->opened_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $s->closed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td>{{ $s->closed_at ? $s->opened_at->diffForHumans($s->closed_at, true) : 'مفتوح' }}</td>
                        <td>{{ \App\Helpers\Money::format($s->cash_sales) }}</td>
                        <td>{{ \App\Helpers\Money::format($s->card_sales) }}</td>
                        <td>{{ \App\Helpers\Money::format($s->other_sales) }}</td>
                        <td class="fw-bold" style="color: var(--primary);">{{ \App\Helpers\Money::format($s->total_sales) }}</td>
                        <td class="{{ (float) $s->cash_variance < 0 ? 'text-danger' : 'text-success' }} fw-bold">
                            {{ \App\Helpers\Money::format($s->cash_variance) }}
                        </td>
                        <td>
                            @if($s->closed_at)
                                <span class="badge bg-success-transparent text-success">مغلقة</span>
                            @else
                                <span class="badge bg-warning-transparent text-warning">مفتوحة</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10">
                        <x-admin.empty-state
                            icon="bi-clock-history"
                            title="لا توجد ورديات"
                            message="لا يوجد سجل ورديات لعرضه حالياً." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>
        {{ $shifts->links() }}
    </x-slot:footer>
</x-admin.data-panel>
@endsection
