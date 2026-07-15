<?php

use App\Enums\OrderStatus;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\Table;
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
            // The `orders` relation below is filtered to ACTIVE statuses, but
            // the idle-close rule needs the full picture (were all orders
            // cancelled?) plus the invoice balance — piggyback cheap counts
            // on the session instead of loading every historical order.
            'activeSession' => fn ($s) => $s->withCount([
                'orders as all_orders_count',
                'orders as cancelled_orders_count' => fn ($o) => $o
                    ->where('status', OrderStatus::Cancelled->value),
            ]),
            'activeSession.assignedWaiter',
            'activeSession.invoice',
            'activeSession.orders' => fn ($orders) => $orders
                ->whereIn('status', OrderStatus::active())
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


<div class="tables-board" wire:poll.visible.15s="refreshFromBroadcast">
    @php
        $stats = $this->stats;
        $attentionMinutes = (int) config('restaurant.order.session_attention_minutes', 75);
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
            <h2>حالة الصالة الآن</h2>
        </div>

        <a href="{{ route('admin.orders.index') }}" class="tb-tasks-cta">
            <i class="bi bi-person-check-fill"></i>
            مهام الجرسون
            @if($stats['pending_orders'] > 0)
                <span class="tb-tasks-cta-count">{{ $stats['pending_orders'] }}</span>
            @endif
        </a>
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
    @endphp

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
                    $orders = $session?->orders ?? collect(); // active statuses only
                    $activeCount = $orders->count();
                    $pendingCount = $orders->where('status', OrderStatus::Pending->value)->count();
                    $readyCount = $orders->where('status', OrderStatus::Ready->value)->count();
                    $openMinutes = $session?->opened_at ? (int) $session->opened_at->diffInMinutes(now()) : 0;
                    $lastSeenAt = $session?->last_activity_at ?? $session?->opened_at;
                    $idleMinutes = $lastSeenAt ? (int) $lastSeenAt->diffInMinutes(now()) : null;
                    $waiterName = $session?->assignedWaiter?->name;
                    $invoice = $session?->invoice;
                    $billRequested = filled($session?->bill_requested_at);
                    $isLongSession = $session && $openMinutes >= $attentionMinutes;
                    $needsAttention = $pendingCount > 0 || $readyCount > 0 || $isLongSession;
                    $statusClass = str_replace('_', '-', $t->status);
                    $zoneColor = $t->zone->color ?? '#667085';

                    // Idle-close eligibility — mirrors TableController@closeSession:
                    // the session sat idle past the attention threshold AND carries
                    // zero financial exposure (no orders, all cancelled, or the
                    // invoice is fully settled).
                    $allOrdersCount = (int) ($session?->all_orders_count ?? 0);
                    $cancelledCount = (int) ($session?->cancelled_orders_count ?? 0);
                    $invoiceSettled = $invoice && $invoice->status !== 'cancelled' && (float) $invoice->balance <= 0;
                    $zeroExposure = $allOrdersCount === $cancelledCount || $invoiceSettled;
                    $canCloseIdle = $session && $idleMinutes !== null
                        && $idleMinutes >= $attentionMinutes
                        && $zeroExposure;

                    // ONE contextual primary action — the card leads with the next
                    // operational decision instead of a wall of equal buttons.
                    $primaryKey = match (true) {
                        (bool) $session && ($pendingCount > 0 || $readyCount > 0) => 'waiter',
                        (bool) $session && ($billRequested || $invoice !== null)  => 'cashier',
                        (bool) $session && $activeCount > 0                       => 'waiter',
                        (bool) $session                                           => 'resume',
                        $t->status === 'available'                                => 'start',
                        default                                                   => null,
                    };
                    $primary = match ($primaryKey) {
                        'waiter'  => ['url' => route('admin.orders.index'), 'label' => 'مهام الجرسون', 'icon' => 'bi-person-check-fill'],
                        'cashier' => ['url' => route('admin.cashier.show', $session), 'label' => 'كاشير', 'icon' => 'bi-cash-stack'],
                        'start'   => ['url' => route('admin.waiter-orders.create', $t), 'label' => 'تشغيل', 'icon' => 'bi-play-fill'],
                        'resume'  => ['url' => route('admin.waiter-orders.create', $t), 'label' => 'إضافة طلب', 'icon' => 'bi-plus-lg'],
                        default   => null,
                    };

                    // Show branch tag only when the admin is in "all branches"
                    // mode — within a single branch context every card belongs
                    // to the same place, so the badge would be noise.
                    $showBranchTag = \App\Support\BranchContext::current() === null && $t->branch;
                @endphp

                <article class="tb-card tb-card--{{ $statusClass }} {{ $session ? 'is-active' : '' }} {{ $needsAttention ? 'needs-attention' : '' }} {{ $readyCount > 0 ? 'has-ready' : '' }}"
                    style="--status-color: {{ $meta['color'] }}; --zone-color: {{ $zoneColor }};"
                    wire:key="table-card-{{ $t->id }}">
                    <div class="tb-card-statusbar"></div>

                    @if($showBranchTag)
                        <span class="tb-branch-tag" title="فرع {{ $t->branch->name }}">
                            <i class="bi bi-building"></i>
                            <span>{{ $t->branch->name }}</span>
                        </span>
                    @endif

                    <div class="tb-card-main">
                        <div class="tb-table-identity">
                            <span class="tb-table-label">طاولة{{ $t->name ? ' · '.$t->name : '' }}</span>
                            <strong class="tb-num">{{ $t->number }}</strong>
                            <span class="tb-card-sub">
                                <span title="عدد المقاعد"><i class="bi bi-people"></i> {{ $t->capacity }}</span>
                                @if($t->zone)
                                    <span class="tb-zone-mini" title="المنطقة">
                                        <span class="tb-zone-tag-dot" style="--zone-color: {{ $zoneColor }};"></span>
                                        {{ $t->zone->label }}
                                    </span>
                                @endif
                            </span>
                        </div>
                        <span class="tb-status-pill">
                            <i class="bi {{ $meta['icon'] }}"></i>
                            {{ $meta['label'] }}
                        </span>
                    </div>

                    @if($billRequested || $pendingCount > 0 || $readyCount > 0 || $isLongSession || $canCloseIdle)
                        <div class="tb-attention-stack">
                            @if($billRequested && $invoice === null)
                                <a href="{{ route('admin.cashier.show', $session) }}" class="tb-attention tb-attention--ready">
                                    <i class="bi bi-receipt-cutoff"></i>
                                    طلب الفاتورة · {{ \App\Support\Duration::since($session->bill_requested_at) }}
                                </a>
                            @endif
                            @if($pendingCount > 0)
                                <a href="{{ route('admin.orders.index') }}" class="tb-attention tb-attention--pending">
                                    <i class="bi bi-person-check"></i>
                                    {{ $pendingCount }} بانتظار الاعتماد
                                </a>
                            @endif
                            @if($readyCount > 0)
                                <a href="{{ route('admin.orders.index') }}" class="tb-attention tb-attention--ready">
                                    <i class="bi bi-bell-fill"></i>
                                    {{ $readyCount }} جاهز للتقديم
                                </a>
                            @endif
                            @if($isLongSession)
                                <span class="tb-attention tb-attention--long">
                                    <i class="bi bi-hourglass-split"></i>
                                    جلسة طويلة تحتاج متابعة · {{ \App\Support\Duration::short($openMinutes) }}
                                </span>
                            @endif
                            @if($canCloseIdle)
                                <form action="{{ route('admin.tables.close-session', $t) }}" method="POST"
                                      class="m-0"
                                      onsubmit="return confirm('إغلاق الجلسة الراكدة على طاولة {{ $t->number }}؟ (لا توجد مستحقات مفتوحة عليها)');">
                                    @csrf
                                    <button type="submit" class="tb-attention tb-attention--long tb-attention-btn">
                                        <i class="bi bi-x-circle"></i>
                                        إغلاق الجلسة الراكدة · خاملة {{ \App\Support\Duration::since($lastSeenAt) }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if($session)
                        <div class="tb-session-info">
                            <span title="الجرسون المسؤول">
                                <i class="bi bi-person-badge"></i>
                                {{ $waiterName ?: 'بدون جرسون' }}
                            </span>
                            <strong title="آخر نشاط بالجلسة">{{ \App\Support\Duration::since($lastSeenAt) }}</strong>
                        </div>
                    @endif

                    <div class="tb-actions" aria-label="إجراءات الطاولة {{ $t->number }}">
                        @if($primary)
                            <a href="{{ $primary['url'] }}" class="tb-btn tb-btn-primary tb-btn-main" title="{{ $primary['label'] }}">
                                <i class="bi {{ $primary['icon'] }}"></i>
                                <span>{{ $primary['label'] }}</span>
                            </a>
                        @endif

                        {{-- Alpine (not Bootstrap-JS) dropdown: the board polls every
                             15s and a Livewire morph strips Bootstrap's client-added
                             .show class, slamming the menu shut mid-interaction.
                             Alpine state survives morphs, so :class re-applies .show
                             and an open transfer form stays open across refreshes. --}}
                        <div class="dropdown tb-more" x-data="{ open: false }"
                             @click.outside="open = false" @keydown.escape.window="open = false">
                            <button type="button" class="tb-more-btn" @click="open = !open"
                                :aria-expanded="open ? 'true' : 'false'"
                                aria-label="إجراءات إضافية لطاولة {{ $t->number }}">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu tb-more-menu" :class="{ show: open }">
                                @if($session && $primaryKey !== 'cashier' && ($invoice !== null || $activeCount > 0))
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.cashier.show', $session) }}">
                                            <i class="bi bi-cash-stack"></i>
                                            كاشير
                                        </a>
                                    </li>
                                @endif
                                @if($session && $primaryKey !== 'waiter' && $activeCount > 0)
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.orders.index') }}">
                                            <i class="bi bi-person-check-fill"></i>
                                            مهام الجرسون
                                        </a>
                                    </li>
                                @endif
                                <li>
                                    <a class="dropdown-item" href="{{ route('admin.tables.qr-print', $t) }}">
                                        <i class="bi bi-qr-code"></i>
                                        طباعة QR
                                    </a>
                                </li>
                                @can('update', $t)
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.tables.edit', $t) }}">
                                            <i class="bi bi-pencil-square"></i>
                                            تعديل الطاولة
                                        </a>
                                    </li>
                                @endcan
                                @can('transfer', $t)
                                    @if($session && $availableTransferTables->isNotEmpty())
                                        <li><hr class="dropdown-divider"></li>
                                        <li class="tb-more-transfer">
                                            <form action="{{ route('admin.tables.transfer', $t) }}" method="POST"
                                                onsubmit="return confirm('نقل جلسة طاولة {{ $t->number }} إلى الطاولة المختارة؟');">
                                                @csrf
                                                <span class="tb-more-transfer-label">
                                                    <i class="bi bi-arrow-left-right"></i>
                                                    نقل الجلسة إلى
                                                </span>
                                                <div class="tb-more-transfer-row">
                                                    <select name="target_table_id" class="tb-transfer-select" required aria-label="نقل الجلسة إلى طاولة">
                                                        <option value="">اختر طاولة...</option>
                                                        @foreach($availableTransferTables as $availableTable)
                                                            <option value="{{ $availableTable->id }}">
                                                                طاولة {{ $availableTable->number }}{{ $availableTable->name ? ' - '.$availableTable->name : '' }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="tb-transfer-go">نقل</button>
                                                </div>
                                            </form>
                                        </li>
                                    @endif
                                @endcan
                                @can('delete', $t)
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form action="{{ route('admin.tables.destroy', $t) }}" method="POST"
                                            onsubmit="return confirm('تأكيد حذف الطاولة {{ $t->number }}؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="dropdown-item tb-more-danger">
                                                <i class="bi bi-trash"></i>
                                                حذف الطاولة
                                            </button>
                                        </form>
                                    </li>
                                @endcan
                            </ul>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
{{-- Styles moved to public/assets/dashtic/css/tables-board.css, loaded in
     the <head> by admin/tables/index.blade.php (@push('styles')) — a
     component-body <style> flashed unstyled cards for a beat. --}}
