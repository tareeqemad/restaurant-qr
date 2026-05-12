<?php

use App\Enums\OrderStatus;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tables admin board - live restaurant floor control.
 *
 * Refreshes EVENT-DRIVEN when Reverb/Echo is available, with a light visible
 * poll fallback for shared hosting where realtime workers are not running.
 */
new class extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'zone', except: '')]
    public string $zoneFilter = '';

    #[Computed]
    public function tables()
    {
        $q = Table::with([
            'activeSession.assignedWaiter',
            'activeSession.customer',
            'activeSession.orders' => fn ($orders) => $orders
                ->whereIn('status', OrderStatus::active())
                ->with('items.station')
                ->latest('created_at'),
            'zone',
            'branch:id,name',
        ])->orderBy('number');

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('number', 'like', "%{$s}%")
                   ->orWhere('name', 'like', "%{$s}%")
                   ->orWhereHas('zone', fn ($z) => $z->where('label', 'like', "%{$s}%"));
            });
        }

        if ($this->statusFilter !== 'all') {
            $q->where('status', $this->statusFilter);
        }

        if ($this->zoneFilter !== '') {
            if (ctype_digit($this->zoneFilter)) {
                $q->where('zone_lookup_id', (int) $this->zoneFilter);
            } else {
                $q->whereHas('zone', fn ($z) => $z->where('label', $this->zoneFilter));
            }
        }

        return $q->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'all'            => Table::count(),
            'available'      => Table::where('status', 'available')->count(),
            'occupied'       => Table::where('status', 'occupied')->count(),
            'reserved'       => Table::where('status', 'reserved')->count(),
            'out_of_service' => Table::where('status', 'out_of_service')->count(),
            'pending_orders' => Order::whereNotNull('table_session_id')
                ->where('status', OrderStatus::Pending->value)
                ->count(),
            'ready_orders' => Order::whereNotNull('table_session_id')
                ->where('status', OrderStatus::Ready->value)
                ->count(),
            'long_sessions' => TableSession::where('status', 'active')
                ->where('opened_at', '<=', now()->subMinutes(75))
                ->count(),
        ];
    }

    #[Computed]
    public function availableTables()
    {
        return Table::where('active', true)
            ->where('status', 'available')
            ->whereDoesntHave('activeSession')
            ->orderBy('number')
            ->get(['id', 'number', 'name']);
    }

    #[Computed]
    public function zones()
    {
        $counts = Table::query()
            ->whereNotNull('zone_lookup_id')
            ->selectRaw('zone_lookup_id, COUNT(*) as cnt')
            ->groupBy('zone_lookup_id')
            ->pluck('cnt', 'zone_lookup_id');

        return Lookup::for('zones')->map(function ($z) use ($counts) {
            $z->tables_count = (int) ($counts[$z->id] ?? 0);
            $z->name = $z->label;
            return $z;
        });
    }

    public function setStatus(string $s): void
    {
        $this->statusFilter = $s;
    }

    public function setZone(string $z): void
    {
        $this->zoneFilter = $this->zoneFilter === $z ? '' : $z;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'zoneFilter']);
        $this->statusFilter = 'all';
    }

    #[On('echo-private:waiters,.table.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->tables, $this->availableTables, $this->stats, $this->zones);
    }
}
?>

<style>
    .tb-card { position: relative; }
    .tb-actions > .tb-transfer-form {
        flex: 1 1 100%;
        display: grid;
        grid-template-columns: minmax(130px, 1fr) auto;
        gap: .45rem;
    }
    .tb-transfer-select {
        width: 100%;
        min-height: 38px;
        border: 1px solid rgba(15, 23, 42, .12);
        border-radius: 8px;
        padding: .45rem .65rem;
        background: #fff;
        color: #172033;
        font-size: .82rem;
        font-weight: 700;
    }
    .tb-btn-transfer {
        color: #8a5a05;
        background: #fff8e5;
        border-color: rgba(245, 158, 11, .28);
    }
    .tb-btn-transfer:hover {
        color: #6f4500;
        background: #ffefbd;
    }
    .tb-branch-tag {
        position: absolute;
        top: .55rem;
        inset-inline-start: .55rem;
        z-index: 2;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .55rem;
        background: rgba(15, 71, 49, .92);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        border-radius: 999px;
        backdrop-filter: blur(4px);
        max-width: 60%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        box-shadow: 0 2px 6px rgba(0, 0, 0, .12);
    }
    .tb-branch-tag i { font-size: 10px; flex-shrink: 0; }
</style>

<div class="tables-board" wire:poll.visible.15s="refreshFromBroadcast">
    @php
        $stats = $this->stats;
        $totalTables = max((int) $stats['all'], 0);
        $availabilityRate = $totalTables > 0 ? round(($stats['available'] / $totalTables) * 100) : 0;
        $busyRate = $totalTables > 0 ? round(($stats['occupied'] / $totalTables) * 100) : 0;
        $statuses = [
            'all'            => ['label' => 'كل الطاولات', 'icon' => 'bi-grid-3x3-gap', 'tone' => 'all'],
            'available'      => ['label' => 'متاحة', 'icon' => 'bi-check2-circle', 'tone' => 'available'],
            'occupied'       => ['label' => 'مشغولة', 'icon' => 'bi-people-fill', 'tone' => 'occupied'],
            'reserved'       => ['label' => 'محجوزة', 'icon' => 'bi-bookmark-check-fill', 'tone' => 'reserved'],
            'out_of_service' => ['label' => 'خارج الخدمة', 'icon' => 'bi-tools', 'tone' => 'oos'],
        ];
    @endphp

    <section class="tb-floor-hero" aria-label="ملخص الصالة">
        <div class="tb-floor-copy">
            <span class="tb-floor-kicker">
                <i class="bi bi-broadcast-pin"></i>
                الصالة الآن
            </span>
            <h2>مخطط الطاولات التشغيلي</h2>
            <p>
                {{ $stats['available'] }} متاحة، {{ $stats['occupied'] }} مشغولة،
                {{ $stats['pending_orders'] }} طلب ينتظر الجرسون،
                {{ $stats['ready_orders'] }} جاهز للتقديم
            </p>
        </div>

        <div class="tb-floor-metrics">
            <div class="tb-floor-metric">
                <span>إجمالي الطاولات</span>
                <strong>{{ $stats['all'] }}</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--available">
                <span>جاهزية الصالة</span>
                <strong>{{ $availabilityRate }}%</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--occupied">
                <span>نسبة الانشغال</span>
                <strong>{{ $busyRate }}%</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--reserved">
                <span>حجوزات</span>
                <strong>{{ $stats['reserved'] }}</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--pending">
                <span>بانتظار الجرسون</span>
                <strong>{{ $stats['pending_orders'] }}</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--ready">
                <span>جاهز للتقديم</span>
                <strong>{{ $stats['ready_orders'] }}</strong>
            </div>
            <div class="tb-floor-metric tb-floor-metric--long">
                <span>جلسات طويلة</span>
                <strong>{{ $stats['long_sessions'] }}</strong>
            </div>
        </div>
    </section>

    <div class="tb-command-bar">
        <div class="tb-status-chips" role="tablist" aria-label="فلترة حالة الطاولات">
            @foreach($statuses as $key => $status)
                <button type="button"
                    wire:click="setStatus('{{ $key }}')"
                    class="tb-chip tb-chip--{{ $status['tone'] }} {{ $statusFilter === $key ? 'is-active' : '' }}">
                    <span class="tb-chip-icon"><i class="bi {{ $status['icon'] }}"></i></span>
                    <span class="tb-chip-text">{{ $status['label'] }}</span>
                    <span class="tb-chip-count">{{ $stats[$key] }}</span>
                </button>
            @endforeach
        </div>

        <div class="tb-command-tools">
            <div class="tb-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text"
                    wire:model.live.debounce.500ms="search"
                    placeholder="رقم الطاولة أو اسم المنطقة"
                    class="tb-search">
                @if($search)
                    <button type="button" wire:click="$set('search', '')" class="tb-search-clear" title="مسح البحث">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </div>

            @if($search || $statusFilter !== 'all' || $zoneFilter)
                <button type="button" wire:click="clearFilters" class="tb-reset-btn">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    مسح الفلاتر
                </button>
            @endif
        </div>
    </div>

    @if($this->zones->count())
        <div class="tb-zone-chips" aria-label="فلترة المناطق">
            <span class="tb-zone-label">
                <i class="bi bi-geo-alt-fill"></i>
                المناطق
            </span>
            @foreach($this->zones as $z)
                @php $isActive = $zoneFilter === (string) $z->id; @endphp
                <button type="button" wire:click="setZone('{{ $z->id }}')"
                    class="tb-zone-chip {{ $isActive ? 'is-active' : '' }}"
                    style="--zone-color: {{ $z->color }};">
                    <span class="tb-zone-dot"></span>
                    <span>{{ $z->name }}</span>
                    <span class="tb-zone-chip-count">{{ $z->tables_count }}</span>
                </button>
            @endforeach
            @if($zoneFilter)
                <button type="button" wire:click="$set('zoneFilter', '')" class="tb-zone-clear" title="مسح فلتر المنطقة">
                    <i class="bi bi-x-lg"></i>
                </button>
            @endif
            @can('viewAny', \App\Models\Lookup::class)
                <a href="{{ route('admin.lookups.index', ['group' => 'zones']) }}" class="tb-zone-manage" title="إدارة المناطق">
                    <i class="bi bi-gear-fill"></i>
                    إدارة
                </a>
            @endcan
        </div>
    @endif

    @php
        $tables = $this->tables;
        $availableTransferTables = $this->availableTables;
        $priorityTables = $tables
            ->map(function ($table) {
                $session = $table->activeSession;
                $orders = $session?->orders ?? collect();
                $pending = $orders->where('status', OrderStatus::Pending->value)->count();
                $ready = $orders->where('status', OrderStatus::Ready->value)->count();
                $preparing = $orders->whereIn('status', [
                    OrderStatus::Approved->value,
                    OrderStatus::Preparing->value,
                ])->count();
                $openMinutes = $session?->opened_at ? (int) $session->opened_at->diffInMinutes(now()) : 0;
                $billRequested = filled($session?->bill_requested_at);

                $score = ($pending * 500) + ($ready * 420) + ($billRequested ? 380 : 0) + ($openMinutes >= 75 ? 240 : 0) + min($openMinutes, 120);
                if (! $session || $score <= 0) {
                    return null;
                }

                $kind = $pending > 0 ? 'pending' : ($ready > 0 ? 'ready' : ($billRequested ? 'billing' : 'long'));
                $label = match ($kind) {
                    'pending' => $pending.' طلب بانتظار الاعتماد',
                    'ready' => $ready.' طلب جاهز للتقديم',
                    'billing' => 'الزبون طلب الفاتورة',
                    default => 'جلسة طويلة تحتاج متابعة',
                };
                $actionLabel = match ($kind) {
                    'billing' => 'افتح الكاشير',
                    default => 'افتح مهام الجرسون',
                };
                $actionUrl = $kind === 'billing'
                    ? route('admin.cashier.show', $session)
                    : route('admin.orders.index');

                return [
                    'table' => $table,
                    'session' => $session,
                    'kind' => $kind,
                    'label' => $label,
                    'action_label' => $actionLabel,
                    'action_url' => $actionUrl,
                    'open_minutes' => $openMinutes,
                    'pending' => $pending,
                    'ready' => $ready,
                    'preparing' => $preparing,
                    'score' => $score,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take(6)
            ->values();
    @endphp

    @if($priorityTables->isNotEmpty())
        <section class="tb-service-queue" aria-label="طابور متابعة الصالة">
            <header class="tb-service-queue-head">
                <div>
                    <span><i class="bi bi-lightning-charge-fill"></i> طابور متابعة الصالة</span>
                    <small>هذه ليست كل التفاصيل. هذه الطاولات التي تحتاج حركة الآن.</small>
                </div>
                <strong>{{ $priorityTables->count() }}</strong>
            </header>
            <div class="tb-service-queue-list">
                @foreach($priorityTables as $task)
                    <a href="{{ $task['action_url'] }}" class="tb-service-task tb-service-task--{{ $task['kind'] }}">
                        <div class="tb-service-task-table">
                            <span>طاولة</span>
                            <strong>{{ $task['table']->number }}</strong>
                        </div>
                        <div class="tb-service-task-main">
                            <strong>{{ $task['label'] }}</strong>
                            <span>
                                {{ $task['preparing'] }} تحت التحضير
                                · {{ $task['open_minutes'] < 1 ? 'جلسة جديدة' : 'مفتوحة '.$task['open_minutes'].' د' }}
                            </span>
                        </div>
                        <span class="tb-service-task-action">
                            {{ $task['action_label'] }}
                            <i class="bi bi-arrow-left"></i>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if($tables->isEmpty())
        <x-admin.empty-state
            icon="bi-grid-3x3-gap"
            title="لا توجد طاولات مطابقة"
            message="جرّب مسح الفلاتر أو إضافة طاولة جديدة.">
            <x-slot:cta>
                <button wire:click="clearFilters" class="btn btn-light me-2">
                    <i class="bi bi-x-circle"></i>
                    مسح الفلاتر
                </button>
                <a href="{{ route('admin.tables.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i>
                    طاولة جديدة
                </a>
            </x-slot:cta>
        </x-admin.empty-state>
    @else
        <div class="tb-grid">
            @foreach($tables as $t)
                @php
                    $statusMap = [
                        'available'      => ['color' => '#16845f', 'label' => 'متاحة', 'icon' => 'bi-check2-circle'],
                        'occupied'       => ['color' => '#b97818', 'label' => 'مشغولة', 'icon' => 'bi-people-fill'],
                        'reserved'       => ['color' => '#2563eb', 'label' => 'محجوزة', 'icon' => 'bi-bookmark-check-fill'],
                        'out_of_service' => ['color' => '#6b7280', 'label' => 'خارج الخدمة', 'icon' => 'bi-tools'],
                    ];
                    $meta = $statusMap[$t->status] ?? ['color' => '#6b7280', 'label' => $t->status, 'icon' => 'bi-circle'];
                    $session = $t->activeSession;
                    $orders = $session?->orders ?? collect();
                    $orderCount = $orders->count();
                    $pendingCount = $orders->where('status', OrderStatus::Pending->value)->count();
                    $productionCount = $orders->whereIn('status', [
                        OrderStatus::Approved->value,
                        OrderStatus::Preparing->value,
                    ])->count();
                    $readyCount = $orders->where('status', OrderStatus::Ready->value)->count();
                    $openMinutes = $session?->opened_at ? (int) $session->opened_at->diffInMinutes(now()) : 0;
                    $idleMinutes = $session?->last_activity_at ? (int) $session->last_activity_at->diffInMinutes(now()) : null;
                    $guestName = $session?->customer?->name ?: $session?->customer_name;
                    $waiterName = $session?->assignedWaiter?->name;
                    $needsAttention = $pendingCount > 0 || $readyCount > 0 || $openMinutes >= 75;
                    $statusClass = str_replace('_', '-', $t->status);
                    $zoneColor = $t->zone->color ?? '#667085';
                @endphp

                @php
                    // Show branch tag only when the admin is in "all branches"
                    // mode — within a single branch context every card belongs
                    // to the same place, so the badge would be noise.
                    $showBranchTag = \App\Support\BranchContext::current() === null && $t->branch;
                @endphp
                <article class="tb-card tb-card--{{ $statusClass }} {{ $session ? 'is-active' : '' }} {{ $needsAttention ? 'needs-attention' : '' }} {{ $readyCount > 0 ? 'has-ready' : '' }}"
                    style="--status-color: {{ $meta['color'] }}; --zone-color: {{ $zoneColor }};">
                    <div class="tb-card-statusbar"></div>

                    @if($showBranchTag)
                        <span class="tb-branch-tag" title="فرع {{ $t->branch->name }}">
                            <i class="bi bi-building"></i>
                            <span>{{ $t->branch->name }}</span>
                        </span>
                    @endif

                    <div class="tb-card-main">
                        <div class="tb-table-identity">
                            <span class="tb-table-label">طاولة</span>
                            <strong class="tb-num">{{ $t->number }}</strong>
                        </div>
                        <span class="tb-status-pill">
                            <i class="bi {{ $meta['icon'] }}"></i>
                            {{ $meta['label'] }}
                        </span>
                    </div>

                    <div class="tb-card-subline">
                        <span class="tb-name">{{ $t->name ?: 'بدون اسم' }}</span>
                        @if($t->zone)
                            <span class="tb-zone-tag" style="--zone-color: {{ $zoneColor }};">
                                <span class="tb-zone-tag-dot"></span>
                                {{ $t->zone->label }}
                            </span>
                        @else
                            <span class="tb-zone-tag tb-zone-tag--muted">
                                <span class="tb-zone-tag-dot"></span>
                                بدون منطقة
                            </span>
                        @endif
                    </div>

                    <div class="tb-card-meta-grid">
                        <div class="tb-meta-cell">
                            <span>المقاعد</span>
                            <strong><i class="bi bi-people"></i> {{ $t->capacity }}</strong>
                        </div>
                        <div class="tb-meta-cell">
                            <span>نشطة</span>
                            <strong><i class="bi bi-receipt"></i> {{ $orderCount }}</strong>
                        </div>
                        <div class="tb-meta-cell {{ $pendingCount > 0 ? 'is-hot' : '' }}">
                            <span>اعتماد</span>
                            <strong><i class="bi bi-person-check"></i> {{ $pendingCount }}</strong>
                        </div>
                        <div class="tb-meta-cell {{ $readyCount > 0 ? 'is-ready' : '' }}">
                            <span>جاهز</span>
                            <strong><i class="bi bi-bell-fill"></i> {{ $readyCount }}</strong>
                        </div>
                    </div>

                    @if($session)
                        <div class="tb-session-info">
                            <span>
                                <i class="bi bi-stopwatch"></i>
                                جلسة مفتوحة
                            </span>
                            <strong>{{ $openMinutes < 1 ? 'الآن' : $openMinutes.' د' }}</strong>
                        </div>

                        <div class="tb-service-strip">
                            <span title="الجرسون المسؤول">
                                <i class="bi bi-person-badge"></i>
                                {{ $waiterName ?: 'غير مخصص' }}
                            </span>
                            <span title="الضيف">
                                <i class="bi {{ $session->customer_id ? 'bi-person-check-fill' : 'bi-person' }}"></i>
                                {{ $guestName ?: 'ضيف QR' }}
                            </span>
                            <span title="آخر نشاط">
                                <i class="bi bi-activity"></i>
                                {{ is_null($idleMinutes) ? 'لا نشاط' : ($idleMinutes < 1 ? 'نشط الآن' : 'منذ '.$idleMinutes.' د') }}
                            </span>
                        </div>

                        @if($pendingCount > 0 || $readyCount > 0 || $openMinutes >= 75)
                            <div class="tb-attention-stack">
                                @if($pendingCount > 0)
                                    <a href="{{ route('admin.orders.index') }}" class="tb-attention tb-attention--pending">
                                        <i class="bi bi-person-check"></i>
                                        {{ $pendingCount }} طلب بانتظار الجرسون
                                    </a>
                                @endif
                                @if($readyCount > 0)
                                    <a href="{{ route('admin.orders.index') }}" class="tb-attention tb-attention--ready">
                                        <i class="bi bi-bell-fill"></i>
                                        {{ $readyCount }} طلب جاهز للتقديم
                                    </a>
                                @endif
                                @if($openMinutes >= 75)
                                    <span class="tb-attention tb-attention--long">
                                        <i class="bi bi-hourglass-split"></i>
                                        جلسة طويلة تحتاج متابعة
                                    </span>
                                    @if($orderCount === 0)
                                        <form action="{{ route('admin.tables.close-session', $t) }}" method="POST"
                                              class="m-0"
                                              onsubmit="return confirm('إغلاق الجلسة الراكدة على طاولة {{ $t->number }}؟ (لا توجد طلبات عليها)');">
                                            @csrf
                                            <button type="submit" class="tb-attention tb-attention--long" style="border:0;cursor:pointer;background:transparent;width:100%;text-align:right;">
                                                <i class="bi bi-x-circle"></i>
                                                إغلاق الجلسة الراكدة
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        @endif
                    @endif

                    <div class="tb-actions" aria-label="إجراءات الطاولة {{ $t->number }}">
                        @if($session && ($pendingCount > 0 || $readyCount > 0 || $productionCount > 0))
                            <a href="{{ route('admin.orders.index') }}" class="tb-btn tb-btn-primary" title="شاشة الجرسون">
                                <i class="bi bi-person-check-fill"></i>
                                <span>تشغيل</span>
                            </a>
                        @endif
                        @can('transfer', $t)
                            @if($session && $availableTransferTables->isNotEmpty())
                                <form action="{{ route('admin.tables.transfer', $t) }}" method="POST"
                                    class="tb-transfer-form"
                                    onsubmit="return confirm('نقل جلسة طاولة {{ $t->number }} إلى الطاولة المختارة؟');">
                                    @csrf
                                    <select name="target_table_id" class="tb-transfer-select" required aria-label="نقل الجلسة إلى طاولة">
                                        <option value="">نقل إلى...</option>
                                        @foreach($availableTransferTables as $availableTable)
                                            <option value="{{ $availableTable->id }}">
                                                طاولة {{ $availableTable->number }}{{ $availableTable->name ? ' - '.$availableTable->name : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="tb-btn tb-btn-transfer" title="نقل الجلسة">
                                        <i class="bi bi-arrow-left-right"></i>
                                        <span>نقل</span>
                                    </button>
                                </form>
                            @endif
                        @endcan
                        <a href="{{ route('admin.tables.qr-print', $t) }}" class="tb-btn" title="طباعة QR">
                            <i class="bi bi-qr-code"></i>
                            <span>QR</span>
                        </a>
                        @can('update', $t)
                            <a href="{{ route('admin.tables.edit', $t) }}" class="tb-btn" title="تعديل الطاولة">
                                <i class="bi bi-pencil-square"></i>
                                <span>تعديل</span>
                            </a>
                        @endcan
                        @if($session && $orderCount > 0)
                            <a href="{{ route('admin.cashier.show', $session) }}" class="tb-btn tb-btn-primary" title="الكاشير">
                                <i class="bi bi-cash-stack"></i>
                                <span>كاشير</span>
                            </a>
                        @endif
                        @can('delete', $t)
                            <form action="{{ route('admin.tables.destroy', $t) }}" method="POST"
                                onsubmit="return confirm('تأكيد حذف الطاولة {{ $t->number }}؟')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="tb-btn tb-btn-danger" title="حذف الطاولة">
                                    <i class="bi bi-trash"></i>
                                    <span>حذف</span>
                                </button>
                            </form>
                        @endcan
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
