@extends('layouts.admin')
@section('title', 'الجرد')

@section('content')
<x-admin.breadcrumb title="الجرد الدوري" icon="bi-clipboard-check-fill"
    subtitle="جرد فعلي للمخزون وتسوية الفروقات" />

<x-admin.stat-rail :stats="[
    ['label' => 'مسودات جرد',         'value' => $stats['draft_count'],                        'icon' => 'bi-file-earmark-fill', 'color' => 'accent'],
    ['label' => 'عمليات هذا الشهر',    'value' => $stats['month_count'],                        'icon' => 'bi-calendar-check',    'color' => 'primary'],
    ['label' => 'آخر اعتماد',           'value' => $stats['last_finalized']?->diffForHumans() ?? '—', 'icon' => 'bi-clock-history',  'color' => 'muted'],
    ['label' => 'فروقات الشهر (تكلفة)', 'value' => \App\Helpers\Money::format($stats['month_variance']),'icon' => 'bi-plus-slash-minus','color' => abs($stats['month_variance']) > 100 ? 'danger' : 'success'],
]" />

<x-admin.data-panel title="سجل عمليات الجرد" :count="$counts->total()" icon="bi-clipboard-check-fill">
    <x-slot:actions>
        <a href="{{ route('admin.stock-counts.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> جرد جديد
        </a>
    </x-slot:actions>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>رقم</th>
                    <th>تاريخ</th>
                    <th>عدد المكونات</th>
                    <th>الحالة</th>
                    <th>أنشأه</th>
                    <th>اعتمده</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($counts as $c)
                    <tr>
                        <td>
                            <a href="{{ route('admin.stock-counts.show', $c) }}" class="fw-bold" style="color:var(--primary); text-decoration:none; font-family:'Courier New', monospace;">
                                {{ $c->number }}
                            </a>
                        </td>
                        <td>{{ $c->count_date->format('Y-m-d') }}</td>
                        <td><span class="badge bg-secondary">{{ $c->items_count }}</span></td>
                        <td><span class="badge bg-{{ $c->statusColor() }}">{{ $c->statusLabel() }}</span></td>
                        <td class="small">{{ $c->creator?->name ?? '—' }}</td>
                        <td class="small">
                            {{ $c->finalizer?->name ?? '—' }}
                            @if($c->finalized_at)
                                <br><small class="text-muted">{{ $c->finalized_at->diffForHumans() }}</small>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.stock-counts.show', $c) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i>
                                {{ $c->isEditable() ? 'متابعة' : 'عرض' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state
                            icon="bi-clipboard-check"
                            title="ما في عمليات جرد بعد"
                            message="ابدأ جرد فعلي للمخزون لتسوية أي فروقات بين النظام والواقع.">
                            <x-slot:cta>
                                <a href="{{ route('admin.stock-counts.create') }}" class="btn btn-primary">
                                    <i class="bi bi-plus-lg"></i> جرد جديد
                                </a>
                            </x-slot:cta>
                        </x-admin.empty-state>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>{{ $counts->links() }}</x-slot:footer>
</x-admin.data-panel>
@endsection
