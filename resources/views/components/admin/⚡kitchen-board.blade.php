<?php

use App\Enums\OrderItemStatus;
use App\Models\OrderItem;
use App\Services\OrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kitchen Display v2 — table-first navigation.
 *
 * The TABLE number is the dominant visual element on every card so the chef
 * can scan from across the room and know exactly which table each order is for.
 *
 * Layout: 3 columns (waiting / cooking / ready). Cards are grouped per-order.
 * Sorting can switch between FIFO (oldest first), urgency, or table number.
 * A quick-filter strip shows every active table number as tappable pills.
 */
new class extends Component
{
    public int $stationId;
    public string $stationCode;
    public string $stationName;
    public string $stationColor = '#1f4733';

    public bool $soundEnabled = true;

    #[Url(as: 'sort', except: 'time')]
    public string $sortBy = 'time';     // 'time' | 'urgency' | 'table'

    #[Url(as: 'table', except: '')]
    public string $filterTable = '';

    public function mount(int $stationId, string $stationCode, string $stationName, ?string $stationColor = null): void
    {
        abort_unless(auth()->user()?->canAccessStation($stationCode), 403);

        $this->stationId   = $stationId;
        $this->stationCode = $stationCode;
        $this->stationName = $stationName;
        $this->stationColor = $stationColor ?: '#1f4733';
    }

    /**
     * Emoji that represents this station type. Picked so the chef/bartender
     * sees the right symbol at a glance — reinforces "this is my screen".
     */
    public function stationEmoji(): string
    {
        return match ($this->stationCode) {
            'kitchen' => '🍳',
            'bar'     => '🍹',
            'dessert' => '🍰',
            'coffee'  => '☕',
            'grill'   => '🔥',
            default   => '🍽️',
        };
    }

    /**
     * Urgency thresholds depend on station type:
     *  - Drinks (bar/coffee) prep in 2-5 min → tighter thresholds
     *  - Food prep is 5-15 min → looser thresholds
     */
    protected function ageThresholds(): array
    {
        return in_array($this->stationCode, ['bar', 'coffee'], true)
            ? ['amber' => 2, 'orange' => 5, 'red' => 10]   // drinks
            : ['amber' => 3, 'orange' => 8, 'red' => 15];  // food
    }

    #[Computed]
    public function ordersByColumn(): array
    {
        $rows = OrderItem::with(['order.table', 'modifiers'])
            ->where('station_id', $this->stationId)
            ->whereIn('status', [
                OrderItemStatus::Approved->value,
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
            ])
            ->whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->orderBy('approved_at')
            ->get();

        if ($this->filterTable !== '') {
            $filter = $this->filterTable;
            $rows = $rows->filter(fn($it) => str_contains((string) $it->order?->table?->number, $filter));
        }

        $byOrder = $rows->groupBy('order_id');

        $waiting = collect();
        $cooking = collect();
        $ready   = collect();

        foreach ($byOrder as $orderId => $items) {
            $first = $items->first();
            $order = $first->order;
            if (!$order) continue;

            $statuses = $items->pluck('status')->unique()->values();
            $bucket = $statuses->contains(OrderItemStatus::Approved->value) ? 'waiting'
                    : ($statuses->contains(OrderItemStatus::Preparing->value) ? 'cooking' : 'ready');

            $ageMin = (int) $first->approved_at?->diffInMinutes(now());
            $urgency = $this->urgency($ageMin, $bucket);

            $card = [
                'order'        => $order,
                'items'        => $items,
                'age_min'      => $ageMin,
                'urgency'      => $urgency,
                'urgency_rank' => $this->urgencyRank($urgency),
                'table_num'    => (int) ($order->table?->number ?? 0),
                'notes'        => trim((string) ($order->customer_notes ?? '')),
            ];

            match ($bucket) {
                'waiting' => $waiting->push($card),
                'cooking' => $cooking->push($card),
                'ready'   => $ready->push($card),
            };
        }

        return [
            'waiting' => $this->sortColumn($waiting),
            'cooking' => $this->sortColumn($cooking),
            'ready'   => $this->sortColumn($ready),
        ];
    }

    #[Computed]
    public function totalActive(): int
    {
        $c = $this->ordersByColumn;
        return $c['waiting']->count() + $c['cooking']->count() + $c['ready']->count();
    }

    #[Computed]
    public function loadStats(): array
    {
        $cols = $this->ordersByColumn;
        $cards = $cols['waiting']->merge($cols['cooking'])->merge($cols['ready']);
        $activeItems = $cards->sum(fn ($card) => $card['items']->count());
        $oldestAge = (int) ($cards->max('age_min') ?? 0);
        $redCards = $cards->where('urgency', 'red')->count();

        $level = match (true) {
            $redCards > 0 || $activeItems >= 18 => 'red',
            $activeItems >= 12 => 'orange',
            $activeItems >= 6 => 'amber',
            default => 'green',
        };

        return [
            'level' => $level,
            'active_items' => $activeItems,
            'waiting_items' => $cols['waiting']->sum(fn ($card) => $card['items']->count()),
            'cooking_items' => $cols['cooking']->sum(fn ($card) => $card['items']->count()),
            'ready_items' => $cols['ready']->sum(fn ($card) => $card['items']->count()),
            'oldest_age' => $oldestAge,
            'red_cards' => $redCards,
        ];
    }

    #[Computed]
    public function activeTables(): array
    {
        $c = $this->ordersByColumn;
        return $c['waiting']->merge($c['cooking'])->merge($c['ready'])
            ->pluck('table_num')->filter()->unique()->sort()->values()->toArray();
    }

    // ── Actions ──────────────────────────────────────────────────────

    public function startItem(int $itemId, OrderService $service): void
    {
        if ($item = OrderItem::whereKey($itemId)->where('station_id', $this->stationId)->first()) {
            $this->ensureStationAccess($item);
            $service->startPreparing($item, auth()->id());
        }
        unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
    }

    public function markReady(int $itemId, OrderService $service): void
    {
        if ($item = OrderItem::whereKey($itemId)->where('station_id', $this->stationId)->first()) {
            $this->ensureStationAccess($item);
            $service->markItemReady($item);
        }
        unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
    }

    public function startAllInOrder(int $orderId, OrderService $service): void
    {
        $this->ensureStationAccess();

        foreach (OrderItem::where('order_id', $orderId)
            ->where('station_id', $this->stationId)
            ->where('status', OrderItemStatus::Approved->value)->get() as $i) {
            $service->startPreparing($i, auth()->id());
        }
        unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
    }

    public function markAllReady(int $orderId, OrderService $service): void
    {
        $this->ensureStationAccess();

        foreach (OrderItem::where('order_id', $orderId)
            ->where('station_id', $this->stationId)
            ->where('status', OrderItemStatus::Preparing->value)->get() as $i) {
            $service->markItemReady($i);
        }
        unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
    }

    public function toggleSound(): void { $this->soundEnabled = !$this->soundEnabled; }

    public function setSort(string $sort): void
    {
        if (in_array($sort, ['time', 'urgency', 'table'])) {
            $this->sortBy = $sort;
        }
    }

    public function focusTable(string $num): void
    {
        $this->filterTable = $this->filterTable === $num ? '' : $num;
    }

    public function clearFilter(): void { $this->filterTable = ''; }

    /**
     * Refresh on broadcast events only — no polling. Subscribes to the PRIVATE
     * `waiters` channel (auth in routes/channels.php requires a staff role).
     * When anything changes anywhere, Reverb pushes the event and Livewire
     * re-renders this component — no refresh, no timer.
     */
    #[On('echo-private:waiters,.order.created')]
    #[On('echo-private:waiters,.order.status_changed')]
    #[On('echo-private:waiters,.item.status_changed')]
    public function refreshFromBroadcast(): void
    {
        unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    protected function sortColumn($cards)
    {
        return match ($this->sortBy) {
            'urgency' => $cards->sortBy([
                ['urgency_rank', 'desc'],
                ['age_min',      'desc'],
            ])->values(),
            'table'   => $cards->sortBy([
                ['table_num', 'asc'],
                ['age_min',   'desc'],
            ])->values(),
            default   => $cards->sortByDesc('age_min')->values(),
        };
    }

    protected function ensureStationAccess(?OrderItem $item = null): void
    {
        abort_unless(auth()->user()?->canAccessStation($this->stationCode), 403);

        if ($item) {
            abort_unless((int) $item->station_id === (int) $this->stationId, 404);
        }
    }

    protected function urgency(int $ageMin, string $bucket): string
    {
        if ($bucket === 'ready') return 'green';
        $t = $this->ageThresholds();
        if ($ageMin >= $t['red'])    return 'red';
        if ($ageMin >= $t['orange']) return 'orange';
        if ($ageMin >= $t['amber'])  return 'amber';
        return 'green';
    }

    protected function urgencyRank(string $u): int
    {
        return ['red' => 4, 'orange' => 3, 'amber' => 2, 'green' => 1][$u] ?? 0;
    }
}
?>

{{-- Livewire polling mode (works on shared hosting, no websocket needed).
     `visible` modifier pauses polling when the tab is backgrounded, so it
     doesn't hit the server while the chef's screen is minimised. 5s interval
     matches the urgency — a dish that just landed must appear quickly. --}}
<div class="kb-wrap"
     {{-- 8s instead of 5s: broadcast events (echo-private) handle truly
          urgent updates; polling is the fallback. 5s was wasteful. --}}
     wire:poll.visible.8s="refreshFromBroadcast"
     style="--station-color: {{ $stationColor }};">
    @php $load = $this->loadStats; @endphp
    {{-- Header --}}
    <div class="kb-header">
        <div class="kb-header-title">
            <span class="kb-icon">{{ $this->stationEmoji() }}</span>
            <div>
                <div class="kb-station-name">{{ $stationName }}</div>
                <div class="kb-meta">
                    <span class="kb-clock" x-data x-init="
                        const tick = () => { $el.textContent = new Date().toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit', second:'2-digit'}); };
                        tick(); setInterval(tick, 1000);
                    ">00:00:00</span>
                    <span>·</span>
                    <span>{{ $this->totalActive }} طلب نشط</span>
                    @if($filterTable)
                        <span>·</span>
                        <span class="kb-filter-tag">
                            <i class="bi bi-filter"></i>
                            طاولة {{ $filterTable }}
                            <button wire:click="clearFilter" type="button"><i class="bi bi-x-lg"></i></button>
                        </span>
                    @endif
                </div>
            </div>
        </div>
        <div class="kb-header-tools">
            <div class="kb-sort-group">
                <span class="kb-sort-label">ترتيب:</span>
                <button wire:click="setSort('time')"    type="button" class="kb-sort-btn {{ $sortBy === 'time'    ? 'is-active' : '' }}" title="الأقدم أولاً">
                    <i class="bi bi-clock"></i>
                </button>
                <button wire:click="setSort('urgency')" type="button" class="kb-sort-btn {{ $sortBy === 'urgency' ? 'is-active' : '' }}" title="الأكثر استعجالاً">
                    <i class="bi bi-fire"></i>
                </button>
                <button wire:click="setSort('table')"   type="button" class="kb-sort-btn {{ $sortBy === 'table'   ? 'is-active' : '' }}" title="حسب رقم الطاولة">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </button>
            </div>
            <button wire:click="toggleSound" type="button" class="kb-tool {{ $soundEnabled ? 'is-on' : '' }}" title="التنبيه الصوتي">
                <i class="bi bi-{{ $soundEnabled ? 'volume-up-fill' : 'volume-mute-fill' }}"></i>
            </button>
            <span class="kb-live-dot" title="متصل"></span>
        </div>
    </div>

    <div class="kb-load-strip kb-load-{{ $load['level'] }}">
        <div class="kb-load-main">
            <span>ضغط المحطة</span>
            <strong>{{ $load['active_items'] }}</strong>
            <small>صنف نشط</small>
        </div>
        <div class="kb-load-pill">
            <span>بانتظار</span>
            <strong>{{ $load['waiting_items'] }}</strong>
        </div>
        <div class="kb-load-pill">
            <span>قيد التحضير</span>
            <strong>{{ $load['cooking_items'] }}</strong>
        </div>
        <div class="kb-load-pill">
            <span>جاهز</span>
            <strong>{{ $load['ready_items'] }}</strong>
        </div>
        <div class="kb-load-pill kb-load-pill--age">
            <span>أقدم تأخير</span>
            <strong>{{ $load['oldest_age'] }} د</strong>
        </div>
    </div>

    {{-- Active tables quick filter --}}
    @if(count($this->activeTables) > 0)
        <div class="kb-table-filter">
            <span class="kb-filter-label"><i class="bi bi-grid-3x3-gap-fill"></i> طاولات نشطة:</span>
            @foreach($this->activeTables as $tableNum)
                <button wire:click="focusTable('{{ $tableNum }}')" type="button"
                        class="kb-table-pill {{ $filterTable === (string) $tableNum ? 'is-active' : '' }}">
                    {{ $tableNum }}
                </button>
            @endforeach
        </div>
    @endif

    @php $cols = $this->ordersByColumn; @endphp

    {{-- 3-column board --}}
    <div class="kb-grid">
        <section class="kb-col kb-col-waiting">
            <header>
                <span class="kb-col-dot"></span>
                <h3>بانتظار البدء</h3>
                <span class="kb-col-count">{{ $cols['waiting']->count() }}</span>
            </header>
            <div class="kb-col-body">
                @forelse($cols['waiting'] as $card)
                    @include('components.admin._kitchen-card', $card + ['column' => 'waiting'])
                @empty
                    <div class="kb-empty"><i class="bi bi-hourglass"></i>لا طلبات جديدة</div>
                @endforelse
            </div>
        </section>

        <section class="kb-col kb-col-cooking">
            <header>
                <span class="kb-col-dot"></span>
                <h3>قيد التحضير</h3>
                <span class="kb-col-count">{{ $cols['cooking']->count() }}</span>
            </header>
            <div class="kb-col-body">
                @forelse($cols['cooking'] as $card)
                    @include('components.admin._kitchen-card', $card + ['column' => 'cooking'])
                @empty
                    <div class="kb-empty"><i class="bi bi-fire"></i>لا شي في الطبخ</div>
                @endforelse
            </div>
        </section>

        <section class="kb-col kb-col-ready">
            <header>
                <span class="kb-col-dot"></span>
                <h3>جاهز للتسليم</h3>
                <span class="kb-col-count">{{ $cols['ready']->count() }}</span>
            </header>
            <div class="kb-col-body">
                @forelse($cols['ready'] as $card)
                    @include('components.admin._kitchen-card', $card + ['column' => 'ready'])
                @empty
                    <div class="kb-empty"><i class="bi bi-check-circle"></i>لا شي جاهز</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
