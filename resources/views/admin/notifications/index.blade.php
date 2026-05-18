@extends('layouts.admin')
@section('title', 'صندوق الإشعارات')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $notifications */
    $typeLabels = [
        'order.new'             => ['طلب جديد',          'bi-bag-plus-fill'],
        'order.cancelled'       => ['طلب ملغى',          'bi-x-octagon-fill'],
        'reservation.new'       => ['حجز جديد',          'bi-calendar-plus-fill'],
        'reservation.upcoming'  => ['حجز قادم قريباً',   'bi-calendar-event-fill'],
        'review.new'            => ['تقييم جديد',        'bi-star-fill'],
        'stock.low'             => ['مخزون منخفض',       'bi-exclamation-triangle-fill'],
        'refund.issued'         => ['استرداد',           'bi-arrow-counterclockwise'],
        'attendance.late'       => ['تأخر حضور',         'bi-alarm-fill'],
        'shift.closed'          => ['إغلاق وردية',       'bi-cash-stack'],
        'purchase.received'     => ['استلام مشتريات',    'bi-box-seam'],
        'bill.requested'        => ['طلب فاتورة',        'bi-receipt-cutoff'],
        'branch_transfer.sent'      => ['تحويل قادم بين الفروع', 'bi-truck'],
        'branch_transfer.received'  => ['وصل تحويل بين الفروع',  'bi-check-circle-fill'],
    ];

    $severityClass = fn($s) => match($s) {
        'danger'  => 'bg-danger-transparent text-danger',
        'warning' => 'bg-warning-transparent text-warning',
        'success' => 'bg-success-transparent text-success',
        default   => 'bg-info-transparent text-info',
    };

    $activeType     = request('type');
    $activeSeverity = request('severity');
@endphp

@section('content')
<x-admin.breadcrumb title="صندوق الإشعارات" icon="bi-bell-fill"
    subtitle="كل التنبيهات الموجَّهة لك من النظام — طلبات، حجوزات، مخزون، استردادات." />

<x-admin.stat-rail :stats="[
    ['label' => 'الإجمالي',      'value' => $stats['total'],  'icon' => 'bi-collection-fill',           'color' => 'primary'],
    ['label' => 'غير مقروء',     'value' => $stats['unread'], 'icon' => 'bi-bell-fill',                 'color' => 'accent'],
    ['label' => 'اليوم',         'value' => $stats['today'],  'icon' => 'bi-calendar-day-fill',         'color' => 'success'],
    ['label' => 'طلبات جديدة',   'value' => $stats['by_type']['order.new']        ?? 0, 'icon' => 'bi-bag-plus-fill',           'color' => 'primary'],
    ['label' => 'طلبات فواتير',  'value' => $stats['by_type']['bill.requested']   ?? 0, 'icon' => 'bi-receipt-cutoff',          'color' => 'warning'],
    ['label' => 'مخزون منخفض',   'value' => $stats['by_type']['stock.low']        ?? 0, 'icon' => 'bi-exclamation-triangle-fill', 'color' => 'warning'],
    ['label' => 'استردادات',     'value' => $stats['by_type']['refund.issued']    ?? 0, 'icon' => 'bi-arrow-counterclockwise',   'color' => 'danger'],
    ['label' => 'إغلاق ورديات',  'value' => $stats['by_type']['shift.closed']     ?? 0, 'icon' => 'bi-cash-stack',              'color' => 'success'],
]" />

<x-admin.data-panel title="الإشعارات" icon="bi-bell-fill" :count="$notifications->total()">

    <x-slot:actions>
        @if($stats['unread'] > 0)
            <button type="button"
                    class="btn btn-sm btn-primary"
                    onclick="markAllRead(this)">
                <i class="bi bi-check2-all me-1"></i> تحديد الكل كمقروء
            </button>
        @endif
    </x-slot:actions>

    <x-slot:filters>
        <form method="GET" action="{{ route('admin.notifications.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1">الحالة</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all"    @selected($status === 'all')>الكل</option>
                    <option value="unread" @selected($status === 'unread')>غير المقروء فقط</option>
                    <option value="read"   @selected($status === 'read')>المقروء فقط</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1">النوع</label>
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">كل الأنواع</option>
                    @foreach($typeLabels as $key => [$label, $_])
                        <option value="{{ $key }}" @selected($activeType === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fs-12 mb-1">الأهمية</label>
                <select name="severity" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">كل المستويات</option>
                    <option value="info"    @selected($activeSeverity === 'info')>معلومة</option>
                    <option value="success" @selected($activeSeverity === 'success')>نجاح</option>
                    <option value="warning" @selected($activeSeverity === 'warning')>تحذير</option>
                    <option value="danger"  @selected($activeSeverity === 'danger')>حرج</option>
                </select>
            </div>
            <div class="col-md-3 text-md-end">
                @if(request()->anyFilled(['type','severity']) || $status !== 'all')
                    <a href="{{ route('admin.notifications.index') }}" class="btn btn-sm btn-light">
                        <i class="bi bi-x-lg me-1"></i> مسح الفلاتر
                    </a>
                @endif
            </div>
        </form>
    </x-slot:filters>

    @if($notifications->isEmpty())
        <x-admin.empty-state
            icon="bi-bell-slash"
            title="لا إشعارات بهذه الفلاتر"
            message="لا يوجد ما يعرض. جرب تغيير الحالة أو النوع." />
    @else
        <div class="list-group list-group-flush notifications-inbox">
            @foreach($notifications as $n)
                @php
                    $data = $n->data ?? [];
                    $sev  = $data['severity'] ?? 'info';
                    $icon = $data['icon']     ?? 'bi-bell';
                @endphp
                <div class="list-group-item d-flex align-items-start gap-3 py-3 {{ $n->read_at ? 'opacity-75' : '' }}"
                     id="notif-{{ $n->id }}">
                    <span class="notify-avatar {{ $severityClass($sev) }}">
                        <i class="bi {{ $icon }}"></i>
                    </span>

                    <div class="flex-fill min-w-0">
                        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                            <strong class="fs-14 text-dark">{{ $data['title'] ?? 'إشعار' }}</strong>
                            <small class="text-muted fs-11 flex-shrink-0">
                                {{ optional($n->created_at)->diffForHumans() }}
                            </small>
                        </div>
                        @if(!empty($data['body']))
                            <div class="text-muted fs-13 mb-2">{{ $data['body'] }}</div>
                        @endif

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            @if(!empty($data['action_url']))
                                <a href="{{ $data['action_url'] }}"
                                   class="btn btn-sm btn-primary"
                                   onclick="markRead('{{ $n->id }}', null)">
                                    <i class="bi bi-arrow-left-circle me-1"></i>
                                    {{ $data['action_label'] ?? 'فتح' }}
                                </a>
                            @endif
                            @if(! $n->read_at)
                                <button type="button"
                                        class="btn btn-sm btn-outline-success"
                                        onclick="markRead('{{ $n->id }}', this)">
                                    <i class="bi bi-check2 me-1"></i> مقروء
                                </button>
                            @else
                                <span class="badge bg-light text-muted">
                                    <i class="bi bi-check2-all me-1"></i>
                                    {{ optional($n->read_at)->diffForHumans() }}
                                </span>
                            @endif
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    onclick="dismiss('{{ $n->id }}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-slot:footer>
        {{ $notifications->links() }}
    </x-slot:footer>
</x-admin.data-panel>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const post = (url) => fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    }).then(r => r.json());
    const del = (url) => fetch(url, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    }).then(r => r.json());

    window.markRead = function (id, btn) {
        post(`{{ url('admin/notifications') }}/${id}/read`).then((res) => {
            if (! res.ok) return;
            const row = document.getElementById('notif-' + id);
            if (row) row.classList.add('opacity-75');
            if (btn) btn.replaceWith(Object.assign(document.createElement('span'),
                { className: 'badge bg-light text-muted', innerHTML: '<i class="bi bi-check2-all me-1"></i> الآن' }));
            updateBellBadge(res.unread);
        });
    };

    window.markAllRead = function (btn) {
        post(`{{ route('admin.notifications.read-all') }}`).then((res) => {
            if (res.ok) location.reload();
        });
    };

    window.dismiss = function (id) {
        if (! confirm('حذف هذا الإشعار نهائياً؟')) return;
        del(`{{ url('admin/notifications') }}/${id}`).then((res) => {
            if (! res.ok) return;
            const row = document.getElementById('notif-' + id);
            row?.remove();
            updateBellBadge(res.unread);
        });
    };

    function updateBellBadge(count) {
        const badge = document.querySelector('#headerNotifications .header-icon-badge');
        if (! badge) return;
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }
})();
</script>
@endpush
