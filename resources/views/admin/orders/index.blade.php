@extends('layouts.admin')
@section('title', 'الطلبات')

@section('content')
<x-admin.breadcrumb title="الطلبات" icon="bi-receipt"
    subtitle="إدارة ومتابعة كل الطلبات الواردة" />

{{-- Quick KPI rail --}}
<x-admin.stat-rail :stats="[
    ['label' => 'بانتظار الاعتماد', 'value' => $stats['pending'],       'icon' => 'bi-hourglass-split', 'color' => 'accent'],
    ['label' => 'طلبات اليوم',      'value' => $stats['today_count'],   'icon' => 'bi-receipt',        'color' => 'primary'],
    ['label' => 'نشطة الآن',        'value' => $stats['today_active'],  'icon' => 'bi-activity',       'color' => 'success'],
    ['label' => 'إيراد اليوم',      'value' => \App\Helpers\Money::format($stats['today_revenue']), 'icon' => 'bi-cash-coin', 'color' => 'accent'],
]" />

<x-admin.data-panel title="قائمة الطلبات" :count="$orders->total()" icon="bi-receipt">
    <x-slot:actions>
        @if(request()->hasAny(['status', 'date', 'search']))
            <a href="{{ route('admin.orders.index') }}" class="btn btn-light">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </a>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3">
                <input name="search" value="{{ request('search') }}" class="form-control" placeholder="🔍 رقم الطلب">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="pending"   @selected(request('status')==='pending')>بانتظار</option>
                    <option value="approved"  @selected(request('status')==='approved')>معتمد</option>
                    <option value="preparing" @selected(request('status')==='preparing')>قيد التحضير</option>
                    <option value="ready"     @selected(request('status')==='ready')>جاهز</option>
                    <option value="delivered" @selected(request('status')==='delivered')>تم التسليم</option>
                    <option value="completed" @selected(request('status')==='completed')>مكتمل</option>
                    <option value="cancelled" @selected(request('status')==='cancelled')>ملغى</option>
                </select>
            </div>
            <div class="col-md-3">
                <input type="date" name="date" value="{{ request('date') }}" class="form-control">
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5">
                    <i class="bi bi-funnel"></i> استعلام
                </button>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            @php $showBranchCol = (bool) session('view_all_branches'); @endphp
            <thead class="bg-light">
                <tr>
                    <th>الرقم</th>
                    @if($showBranchCol)<th>الفرع</th>@endif
                    <th>الطاولة</th>
                    <th>الأصناف</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th>الوقت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $o)
                    <tr>
                        <td class="fw-bold">{{ $o->number }}</td>
                        @if($showBranchCol)
                            <td><x-admin.branch-tag :branch="$o->branch" /></td>
                        @endif
                        <td>{{ $o->table?->number ?? '—' }}</td>
                        <td>{{ $o->items->count() }}</td>
                        <td>{{ \App\Helpers\Money::format($o->total) }}</td>
                        <td><span class="badge bg-{{ $o->statusColor() }}">{{ $o->statusLabel() }}</span></td>
                        <td>{{ $o->created_at->diffForHumans() }}</td>
                        <td>
                            <a href="{{ route('admin.orders.show', $o) }}" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> تفاصيل
                            </a>
                            @if($o->status === 'pending')
                                <form action="{{ route('admin.orders.approve', $o) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-success">
                                        <i class="bi bi-check2"></i> اعتماد
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state
                            icon="bi-receipt"
                            title="ما في طلبات بعد"
                            message="حالما يطلب الزبائن من خلال الـ QR، الطلبات ستظهر هنا للاعتماد والمتابعة." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:footer>
        {{ $orders->links() }}
    </x-slot:footer>
</x-admin.data-panel>

@push('scripts')
<script>
/* Paginated Blade list — refresh every 20 s so the operator sees new
   orders without manual refresh. Longer interval than the board because
   this is a list, not a live feed. Pauses on hidden tab and skips when
   the user is typing in a filter/search. */
(function () {
    const INTERVAL = 20000;
    function maybeReload() {
        if (document.hidden) return schedule();
        const el = document.activeElement;
        if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) return schedule();
        location.reload();
    }
    function schedule() { setTimeout(maybeReload, INTERVAL); }
    schedule();
})();
</script>
@endpush
@endsection
