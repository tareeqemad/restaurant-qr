@extends('layouts.admin')
@section('title', 'التحويلات بين الفروع')

@section('content')
<x-admin.breadcrumb
    title="التحويلات بين الفروع"
    icon="bi-arrow-left-right"
    subtitle="نقل المكونات بين فروع مختلفة — out من المصدر و in للوجهة في نفس اللحظة." />

<x-admin.stat-rail :stats="[
    ['label' => 'في الطريق',         'value' => $stats['in_transit'],        'icon' => 'bi-truck',                'color' => 'warning'],
    ['label' => 'مستلم اليوم',        'value' => $stats['received_today'],   'icon' => 'bi-check-circle-fill',     'color' => 'success'],
    ['label' => 'مسودات',             'value' => $stats['draft'],             'icon' => 'bi-pencil-square',         'color' => 'secondary'],
    ['label' => 'إجمالي الشهر',       'value' => $stats['this_month'],        'icon' => 'bi-calendar-month-fill',   'color' => 'primary'],
]" />

<x-admin.data-panel title="قائمة التحويلات" icon="bi-list-ul" :count="$transfers->total()">
    <x-slot:actions>
        <a href="{{ route('admin.branch-transfers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> تحويل جديد
        </a>
    </x-slot:actions>

    <x-slot:filters>
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1">رقم التحويل</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm" placeholder="BT-...">
            </div>
            <div class="col-md-4">
                <label class="form-label fs-12 mb-1">الحالة</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">كل الحالات</option>
                    <option value="draft"      @selected(request('status') === 'draft')>مسودة</option>
                    <option value="in_transit" @selected(request('status') === 'in_transit')>في الطريق</option>
                    <option value="received"   @selected(request('status') === 'received')>مستلم</option>
                    <option value="cancelled"  @selected(request('status') === 'cancelled')>ملغي</option>
                </select>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary btn-sm px-5"><i class="bi bi-search"></i> استعلام</button>
            </div>
        </form>
    </x-slot:filters>

    @if($transfers->isEmpty())
        <x-admin.empty-state
            icon="bi-truck"
            title="لا تحويلات"
            message="ابدأ تحويلاً جديداً لنقل المكونات بين فروعك." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الرقم</th>
                        <th>من</th>
                        <th></th>
                        <th>إلى</th>
                        <th>عدد البنود</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transfers as $t)
                        <tr>
                            <td><strong style="font-family: monospace;">{{ $t->number }}</strong></td>
                            <td>{{ $t->fromBranch->name }}</td>
                            <td><i class="bi bi-arrow-left text-accent"></i></td>
                            <td>{{ $t->toBranch->name }}</td>
                            <td>{{ $t->items()->count() }}</td>
                            <td><span class="badge bg-{{ $t->statusColor() }}">{{ $t->statusLabel() }}</span></td>
                            <td class="fs-13">
                                {{ $t->created_at->format('Y-m-d H:i') }}
                                @if($t->sent_at)
                                    <br><small class="text-muted">أُرسل: {{ $t->sent_at->diffForHumans() }}</small>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.branch-transfers.show', $t) }}" class="btn btn-sm btn-light">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-slot:footer>
        {{ $transfers->links() }}
    </x-slot:footer>
</x-admin.data-panel>
@endsection
