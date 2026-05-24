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

    /** Sound on by default. AudioContext is unlocked on the first user
     *  gesture anywhere on the page (see kitchenSound() init below) so the
     *  chef does not have to opt in. They can still mute via the toolbar
     *  button, and that choice persists in the tab. */
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
            'grill'   => '🔥',
            default   => '🍽️',
        };
    }

    /**
     * Urgency thresholds depend on station type:
     *  - Drinks (bar) prep in 2-5 min → tighter thresholds (coffee is made
     *    at the bar in this restaurant, so it falls under the same bucket)
     *  - Food prep is 5-15 min → looser thresholds
     */
    protected function ageThresholds(): array
    {
        return $this->stationCode === 'bar'
            ? ['amber' => 2, 'orange' => 5, 'red' => 10]   // drinks
            : ['amber' => 3, 'orange' => 8, 'red' => 15];  // food
    }

    #[Computed]
    public function ordersByColumn(): array
    {
        // Eager-load menuItem so the card can show each item's
        // prep_time_minutes badge (and color it red if elapsed > prep_time).
        // `order.customer` is needed so the card can show name/phone for
        // takeaway/delivery tickets without an N+1 (`customer_name` on the
        // order itself is the canonical snapshot; the relation is the
        // fallback when the cashier didn't enter a guest record).
        $rows = OrderItem::with(['order.table', 'order.customer', 'modifiers', 'menuItem:id,prep_time_minutes'])
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

            // For ready tickets, age is "how long has this been sitting on the
            // pass uncollected" — use ready_at, not approved_at. Otherwise the
            // ready strip stays green forever and the kitchen never sees that
            // food is going cold while the waiter is slow.
            if ($bucket === 'ready') {
                $oldestReadyAt = $items->pluck('ready_at')->filter()->min();
                $ageMin = $oldestReadyAt ? (int) $oldestReadyAt->diffInMinutes(now())
                                         : (int) $first->approved_at?->diffInMinutes(now());
            } else {
                $ageMin = (int) $first->approved_at?->diffInMinutes(now());
            }

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

    /**
     * Chef-side cancel: customer changed their mind mid-prep, the
     * waiter is busy elsewhere, the chef needs to clear the ticket
     * NOW. `disposition` picks return-to-stock (didn't touch) vs
     * waste-as-loss (already prepping). Reuses OrderService so the
     * cancel goes through the same broadcast + accounting flow as a
     * waiter-initiated cancel.
     */
    public function cancelItemFromKds(int $itemId, string $disposition, string $reason, OrderService $service): void
    {
        $item = OrderItem::whereKey($itemId)->where('station_id', $this->stationId)->first();
        if (! $item) return;
        $this->ensureStationAccess($item);

        $service->cancelItem(
            item:        $item,
            userId:      auth()->id(),
            reason:      $reason,
            disposition: in_array($disposition, ['return', 'waste'], true) ? $disposition : 'return',
            wasteReason: $disposition === 'waste' ? 'إلغاء أثناء التحضير من المطبخ' : null,
        );

        $this->dispatch('toast', type: 'success',
            message: $disposition === 'waste'
                ? "أُلغي «{$item->name_snapshot}» وسُجِّلت المكوّنات كهدر."
                : "أُلغي «{$item->name_snapshot}» وأُعيدت المكوّنات للمخزون.");

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
        // Ready tickets escalate on a tighter clock: the food is already cooked
        // — every minute uncollected is heat lost. 3/8 mins matches how fast
        // hot food actually cools on a pass shelf.
        if ($bucket === 'ready') {
            if ($ageMin >= 8) return 'red';
            if ($ageMin >= 3) return 'amber';
            return 'green';
        }

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

{{-- Kitchen/Bar Display v3 — ticket-grid layout.
     Each order ticket stays put; items inside transition through approved →
     preparing → ready. When the whole ticket is ready it slides into the
     bottom strip so the chef knows what to call out. Big-screen-friendly:
     compact header so 90% of pixels are tickets.

     wire:poll.visible.8s — broadcasts (echo-private) handle urgent updates,
     polling is the fallback. Pauses when tab is backgrounded. --}}
<div class="kb-wrap"
     wire:poll.visible.8s="refreshFromBroadcast"
     style="--station-color: {{ $stationColor }};"
     x-data="kitchenSound()"
     data-active-count="{{ $this->totalActive }}"
     data-red-count="{{ $this->loadStats['red_cards'] ?? 0 }}"
     x-init="init()"
     wire:key="kb-{{ $stationCode }}-{{ $this->totalActive }}-{{ $this->loadStats['red_cards'] ?? 0 }}">
    @php
        $load = $this->loadStats;
        $tickets = $this->ordersByColumn;
        $active = $tickets['waiting']->merge($tickets['cooking']);
        $ready = $tickets['ready'];
    @endphp

    {{-- Compact header — single row.
         Sound toggle is the most important affordance: chef taps it once at
         start of shift to unlock the AudioContext. After that, every new
         order chimes and every late item beeps. --}}
    <div class="kb-header kb-header--compact kb-load-{{ $load['level'] }}">
        <div class="kb-header-title">
            <span class="kb-icon">{{ $this->stationEmoji() }}</span>
            <div>
                <div class="kb-station-name">{{ $stationName }}</div>
                <div class="kb-meta">
                    <span class="kb-clock" x-data x-init="
                        const tick = () => { $el.textContent = new Date().toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit', second:'2-digit'}); };
                        tick(); setInterval(tick, 1000);
                    ">00:00:00</span>
                    <span class="kb-meta-sep">·</span>
                    <span class="kb-meta-active"><strong>{{ $load['active_items'] }}</strong> صنف نشط</span>
                    @if($load['oldest_age'] > 0)
                        <span class="kb-meta-sep">·</span>
                        <span class="kb-meta-age">⏱ {{ $load['oldest_age'] }}د</span>
                    @endif
                    @if($filterTable)
                        <span class="kb-meta-sep">·</span>
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
                <button wire:click="setSort('time')"    type="button" class="kb-sort-btn {{ $sortBy === 'time'    ? 'is-active' : '' }}" title="الأقدم أولاً">
                    <i class="bi bi-clock"></i>
                </button>
                <button wire:click="setSort('urgency')" type="button" class="kb-sort-btn {{ $sortBy === 'urgency' ? 'is-active' : '' }}" title="الأكثر استعجالاً">
                    <i class="bi bi-lightning-charge-fill"></i>
                </button>
                <button wire:click="setSort('table')"   type="button" class="kb-sort-btn {{ $sortBy === 'table'   ? 'is-active' : '' }}" title="حسب رقم الطاولة">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </button>
            </div>
            <button @click="toggleSound()" type="button"
                    class="kb-tool kb-sound-btn"
                    :class="enabled ? 'is-on' : 'is-off'"
                    title="التنبيه الصوتي">
                <i class="bi" :class="enabled ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                <span class="kb-sound-label" x-text="enabled ? 'الصوت يعمل' : 'تفعيل الصوت'"></span>
            </button>
            <span class="kb-live-dot" title="متصل"></span>
        </div>
    </div>

    {{-- Active tables quick filter — only show when many tables, otherwise it's noise. --}}
    @if(count($this->activeTables) > 1)
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

    {{-- Main ticket grid — auto-fit so we get 1/2/3/4 columns based on screen
         width. Tickets stay put; only their internal items change state.
         Sorted by urgency-then-age so red ones bubble up. --}}
    <div class="kb-tickets">
        @forelse($active as $card)
            @include('components.admin._kitchen-card', $card + ['column' => $card['items']->contains(fn($i) => $i->status === 'preparing') ? 'cooking' : 'waiting'])
        @empty
            <div class="kb-empty kb-empty--big">
                <i class="bi bi-cup-straw"></i>
                <span>لا طلبات نشطة الآن</span>
                <small>التذكرة الجديدة ستظهر هنا تلقائياً مع صوت تنبيه</small>
            </div>
        @endforelse
    </div>

    {{-- Ready strip — pickup queue. Smaller, less prominent. Chef knows
         "these are done, call the waiter to pick up". --}}
    @if($ready->count() > 0)
        <aside class="kb-ready-strip">
            <header>
                <i class="bi bi-bell-fill"></i>
                <h4>جاهز للتسليم</h4>
                <span class="kb-ready-count">{{ $ready->count() }}</span>
            </header>
            <div class="kb-ready-list">
                @foreach($ready as $card)
                    @php
                        $ro = $card['order'];
                        $roExternal = $ro->isExternal();
                        $roName = trim((string) ($ro->customer_name ?: $ro->customer?->name ?: ''));
                    @endphp
                    <div class="kb-ready-card kb-urg-{{ $card['urgency'] }} {{ $roExternal ? 'kb-ready-card--external' : '' }}"
                         @if($roExternal) style="--source-color: {{ $ro->sourceColor() }};" @endif>
                        <div class="kb-ready-table">
                            @if($ro->table)
                                <small>طاولة</small>
                                <strong>{{ $ro->table->number }}</strong>
                            @else
                                <small><i class="bi {{ $ro->sourceIcon() }}"></i> {{ $ro->sourceLabel() }}</small>
                                <strong>{{ $ro->order_type === 'delivery' ? 'توصيل' : 'تيكاوي' }}</strong>
                                @if($roName)
                                    <span class="kb-ready-customer">{{ $roName }}</span>
                                @endif
                            @endif
                        </div>
                        <div class="kb-ready-items">
                            @foreach($card['items'] as $it)
                                <span>×{{ (int) $it->quantity }} {{ $it->name_snapshot }}</span>
                            @endforeach
                        </div>
                        <div class="kb-ready-meta">
                            <span class="kb-ready-num">#{{ $ro->number }}</span>
                            <span class="kb-ready-age">{{ $card['age_min'] }}د</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </aside>
    @endif

    <script>
    /* Kitchen sound system.
     * Chef taps "تفعيل الصوت" → AudioContext unlocks (browser autoplay policy).
     * After that:
     *   - new order arrives (active count goes up)  → bright two-tone chime
     *   - item turns red (urgency escalates)        → low warning beep
     * No audio files — Web Audio API synthesizes tones, so no assets needed.
     *
     * AudioContext + previous counts live on `window` (singleton) so they
     * survive Livewire morphs. Without this, ctx would get recreated on
     * every server roundtrip and the chef would have to re-tap sound after
     * every order. The ENABLED state and prev counts also live on window
     * so they don't reset across renders. Persists across page navigation
     * within the same tab — chef enables once at shift start. */
    function kitchenSound() {
        return {
            // Reactive Alpine prop (so :class re-renders); window is the
            // cross-component source of truth that survives morphs.
            enabled: window.__kbSoundEnabled !== false,
            init() {
                // Persist the default-on choice + refresh the global banner
                // visibility so it can prompt the chef to tap once.
                window.__kbSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                this.checkChanges();

                // Livewire v4 dropped the `livewire:morph.updated` event used
                // by v2/v3, so we can't hook morphs directly. Watching the
                // data-* attributes on the component root is version-proof:
                // every render writes the latest counts there, and we react
                // immediately whether Livewire morphs, remounts, or even if
                // some other code updates the dataset.
                if (this._observer) this._observer.disconnect();
                this._observer = new MutationObserver(() => {
                    this.enabled = window.__kbSoundEnabled !== false;
                    this.checkChanges();
                });
                this._observer.observe(this.$root, {
                    attributes: true,
                    attributeFilter: ['data-active-count', 'data-red-count'],
                });
            },
            readActive() { return parseInt(this.$root.dataset.activeCount || '0', 10); },
            readRed()    { return parseInt(this.$root.dataset.redCount    || '0', 10); },
            toggleSound() {
                this.enabled = !this.enabled;
                window.__kbSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                if (this.enabled) this.unlockAudio();
            },
            unlockAudio() {
                // Delegate to the layout's global unlocker so kitchen, waiter,
                // cashier, and the toast helper all share one AudioContext.
                window.unlockAudioCtx?.();
            },
            checkChanges() {
                const active = this.readActive();
                const red = this.readRed();
                // First render of the tab — just record the baseline, don't
                // beep on page load (would be noise on every navigation).
                if (typeof window.__kbPrevActive !== 'number') {
                    window.__kbPrevActive = active;
                    window.__kbPrevRed = red;
                    return;
                }
                if (this.enabled) {
                    if (active > window.__kbPrevActive) this.playNewOrder();
                    else if (red > window.__kbPrevRed)  this.playWarning();
                }
                window.__kbPrevActive = active;
                window.__kbPrevRed = red;
            },
            beep(frequency, duration, type = 'sine', volume = 0.25) {
                const ctx = window.__audioCtx;
                if (!ctx || ctx.state !== 'running') return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = type;
                osc.frequency.setValueAtTime(frequency, ctx.currentTime);
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(volume, ctx.currentTime + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + duration);
                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + duration);
            },
            playNewOrder() {
                this.beep(880,  0.18, 'sine', 0.35);
                setTimeout(() => this.beep(1175, 0.22, 'sine', 0.35), 180);
            },
            playWarning() {
                this.beep(440, 0.18, 'square', 0.22);
                setTimeout(() => this.beep(440, 0.18, 'square', 0.22), 280);
            },
        };
    }
    </script>
</div>
