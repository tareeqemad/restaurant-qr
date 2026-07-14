<?php

use App\Enums\OrderItemStatus;
use App\Models\OrderItem;
use App\Models\Station;
use App\Services\OrderService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
     *  button, and that choice persists in localStorage — it survives
     *  reloads and browser restarts on the kitchen tablet. */
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
        $rows = $this->scopeToBoard(
                OrderItem::with(['order.table', 'order.customer', 'modifiers', 'menuItem:id,prep_time_minutes'])
            )
            ->whereIn('status', [
                OrderItemStatus::Approved->value,
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
            ])
            ->whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled', 'completed']))
            ->orderBy('approved_at')
            ->get();

        // Instant lines (بطاقات النت: auto-readied at approval — no station,
        // no prep clock) are fulfilled outside the kitchen entirely. Without
        // this they'd pollute the primary board's ready strip and fire the
        // new-ticket chime for something no cook needs to see. Real orphans
        // readied via «الكل جاهز» keep prep_started_at, so they stay visible.
        $rows = $rows->reject(fn ($it) => $it->status === OrderItemStatus::Ready->value
            && $it->station_id === null
            && $it->prep_started_at === null);

        if ($this->filterTable !== '') {
            // EXACT match on the table number — the old substring match meant
            // filtering table "1" also caught tables 10-19. Numeric numbers
            // compare as ints so "05" still matches the "5" pill; anything
            // non-numeric falls back to a strict string compare.
            $filter = trim($this->filterTable);
            $rows = $rows->filter(function ($it) use ($filter) {
                $num = trim((string) ($it->order?->table?->number ?? ''));
                return is_numeric($num) && is_numeric($filter)
                    ? (int) $num === (int) $filter
                    : $num === $filter;
            });
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

            // Per-card ETA — anchored to THIS card's own lines (earliest
            // prep start + the longest prep among them), NOT the order-level
            // estimated_ready_at: that stamp anchors to whichever station
            // started first, so a bar ticket would show the kitchen's
            // deadline. Only meaningful while this card is cooking.
            $cardEta = null;
            if ($bucket === 'cooking') {
                $started = $items->pluck('prep_started_at')->filter();
                $maxPrep = (int) $items->max(fn ($i) => (int) ($i->menuItem?->prep_time_minutes ?? 0));
                if ($started->isNotEmpty() && $maxPrep > 0) {
                    $cardEta = $started->min()->copy()->addMinutes($maxPrep);
                }
            }

            $card = [
                'order'        => $order,
                'items'        => $items,
                'age_min'      => $ageMin,
                'urgency'      => $urgency,
                'urgency_rank' => $this->urgencyRank($urgency),
                'table_num'    => (int) ($order->table?->number ?? 0),
                'notes'        => trim((string) ($order->customer_notes ?? '')),
                'eta_at'       => $cardEta,
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
    public function loadStats(): array
    {
        $cols = $this->ordersByColumn;
        $cards = $cols['waiting']->merge($cols['cooking'])->merge($cols['ready']);
        $activeItems = $cards->sum(fn ($card) => $card['items']->count());
        $oldestAge = (int) ($cards->max('age_min') ?? 0);
        $redCards = $cards->where('urgency', 'red')->count();

        // Load level tracks TICKET count, not item count or "any red card":
        // the old thresholds painted the header red at a perfectly normal
        // dinner volume (one slow ticket = permanent red). 8/14 open tickets
        // is where a single line cook actually starts to drown.
        $activeTickets = $cards->count();
        $level = match (true) {
            $activeTickets >= 14 => 'red',
            $activeTickets >= 8  => 'amber',
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

    /**
     * Shared wrapper for every wire action. A stale tap (the item moved on
     * another screen between polls) or a workflow guard throws a
     * RuntimeException with an Arabic message — surface it as a toast
     * instead of Livewire's full-screen English error modal, then refresh
     * so the board shows the real state that caused the conflict.
     */
    protected function guardAction(\Closure $fn): void
    {
        try {
            $fn();
        } catch (HttpExceptionInterface $e) {
            // Real authorization failures (abort 403/404) keep their
            // status page — Symfony's HttpException IS a RuntimeException,
            // so it must be rethrown before the catch below swallows it.
            throw $e;
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'warning', message: $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: 'حدث خطأ غير متوقع — أعد المحاولة أو حدّث الصفحة.');
        } finally {
            unset($this->ordersByColumn, $this->activeTables, $this->loadStats);
        }
    }

    public function startItem(int $itemId, OrderService $service): void
    {
        $this->guardAction(function () use ($itemId, $service) {
            if ($item = $this->scopeToBoard(OrderItem::whereKey($itemId))->first()) {
                $this->ensureStationAccess($item);
                $service->startPreparing($item, auth()->id());
            }
        });
    }

    public function markReady(int $itemId, OrderService $service): void
    {
        $this->guardAction(function () use ($itemId, $service) {
            if ($item = $this->scopeToBoard(OrderItem::whereKey($itemId))->first()) {
                $this->ensureStationAccess($item);
                $service->markItemReady($item);
            }
        });
    }

    /** Undo a mis-tapped «جاهز» — the card offers it for ~2 minutes only. */
    public function undoReady(int $itemId, OrderService $service): void
    {
        $this->guardAction(function () use ($itemId, $service) {
            if ($item = $this->scopeToBoard(OrderItem::whereKey($itemId))->first()) {
                $this->ensureStationAccess($item);
                $service->revertItemReady($item, auth()->id());
            }
        });
    }

    /** Undo a mis-tapped «ابدأ» — preparing → approved, prep clock reset. */
    public function undoStart(int $itemId, OrderService $service): void
    {
        $this->guardAction(function () use ($itemId, $service) {
            if ($item = $this->scopeToBoard(OrderItem::whereKey($itemId))->first()) {
                $this->ensureStationAccess($item);
                $service->revertItemStart($item, auth()->id());
            }
        });
    }

    public function startAllInOrder(int $orderId, OrderService $service): void
    {
        $this->guardAction(function () use ($orderId, $service) {
            $this->ensureStationAccess();

            $items = $this->scopeToBoard(OrderItem::where('order_id', $orderId))
                ->where('status', OrderItemStatus::Approved->value)->get();

            // broadcast=false per line, then ONE order-level refresh — see
            // OrderService::broadcastOrderRefresh() for the batching contract.
            foreach ($items as $i) {
                $service->startPreparing($i, auth()->id(), false);
            }
            if ($first = $items->first()) {
                $service->broadcastOrderRefresh($first->order);
            }
        });
    }

    public function markAllReady(int $orderId, OrderService $service): void
    {
        $this->guardAction(function () use ($orderId, $service) {
            $this->ensureStationAccess();

            // Approved lines are included on purpose: «الكل جاهز» used to
            // skip them silently, leaving the ticket stuck half-done. They
            // walk through preparing first so the timing stamps (and the
            // 'preparing' deduction stage) stay consistent.
            $items = $this->scopeToBoard(OrderItem::where('order_id', $orderId))
                ->whereIn('status', [OrderItemStatus::Approved->value, OrderItemStatus::Preparing->value])
                ->get();

            foreach ($items as $i) {
                if ($i->status === OrderItemStatus::Approved->value) {
                    $i = $service->startPreparing($i, auth()->id(), false);
                }
                $service->markItemReady($i, false);
            }
            if ($first = $items->first()) {
                $service->broadcastOrderRefresh($first->order);
            }
        });
    }

    /**
     * Hand a finished ticket to the floor straight from the pass — marks
     * every ready line served so the strip entry clears the moment the
     * runner takes the plates.
     */
    public function serveOrder(int $orderId, OrderService $service): void
    {
        $this->guardAction(function () use ($orderId, $service) {
            $this->ensureStationAccess();

            $items = $this->scopeToBoard(OrderItem::where('order_id', $orderId))
                ->where('status', OrderItemStatus::Ready->value)->get();

            // Serving is a floor hand-off, not a kitchen transition —
            // enforce the SAME policy the waiter board uses (chefs and
            // bartenders are deliberately outside OrderPolicy::serve; the
            // تسليم button is @can-hidden for them, this guards forged calls).
            $order = $items->first()?->order;
            abort_unless($order && auth()->user()->can('serve', $order), 403);

            foreach ($items as $i) {
                $service->markItemServed($i, auth()->id());
            }
        });
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
        $this->guardAction(function () use ($itemId, $disposition, $reason, $service) {
            $item = $this->scopeToBoard(OrderItem::whereKey($itemId))->first();
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
        });
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

    /** Per-request memo — station config can't change mid-render. */
    protected ?bool $primaryBoard = null;

    /**
     * Is this the branch's PRIMARY board? Convention (StationSeeder): the
     * station coded 'kitchen' is the main food line; a branch without one
     * (Gaza starts with zero stations, the owner adds their own) falls back
     * to its first active station by display_order. The primary board doubles
     * as the safety net for orphan tickets — see scopeToBoard().
     */
    protected function isPrimaryBoard(): bool
    {
        if ($this->primaryBoard !== null) {
            return $this->primaryBoard;
        }

        if ($this->stationCode === 'kitchen') {
            return $this->primaryBoard = true;
        }

        // Station uses BelongsToBranch, so these lookups are branch-scoped.
        if (Station::where('code', 'kitchen')->where('active', true)->exists()) {
            return $this->primaryBoard = false;
        }

        $firstId = Station::where('active', true)
            ->orderBy('display_order')->orderBy('id')
            ->value('id');

        return $this->primaryBoard = ((int) $firstId === $this->stationId);
    }

    /**
     * Constrain an OrderItem query to this board's tickets: the station's own
     * items plus — on the PRIMARY board only — orphan items whose station_id
     * is NULL (menu item had no station AND its category had no default, so
     * order time stamped nothing). Without this branch those tickets would
     * never appear on ANY screen and the customer would wait forever.
     *
     * Branch containment is EXPLICIT here, not left to the service layer:
     * orphans are pinned to the station's own branch (otherwise an owner in
     * all-branches mode would see and act on every branch's orphans), and the
     * outer whereHas('order') applies Order's BranchScope to the station arm
     * for branch-scoped users as defense in depth for the wire actions.
     */
    protected function scopeToBoard($query)
    {
        return $query->where(function ($q) {
            $q->where('station_id', $this->stationId);
            if ($this->isPrimaryBoard()) {
                $q->orWhere(function ($orphan) {
                    $orphan->whereNull('station_id')
                        ->whereHas('order', fn ($o) => $o->where('branch_id', $this->stationBranchId()));
                });
            }
        })->whereHas('order');
    }

    /** Per-request memo — the station's branch can't change mid-render. */
    protected ?int $stationBranchId = null;

    protected function stationBranchId(): int
    {
        return $this->stationBranchId ??= (int) Station::withoutGlobalScopes()
            ->whereKey($this->stationId)
            ->value('branch_id');
    }

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
            // Orphans (station_id NULL) are actionable from the primary
            // board only — mirrors the scopeToBoard() read-side rule.
            $ownsItem = (int) $item->station_id === (int) $this->stationId
                || ($item->station_id === null && $this->isPrimaryBoard());
            abort_unless($ownsItem, 404);
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
@php
    $load = $this->loadStats;
    $tickets = $this->ordersByColumn;
    $active = $tickets['waiting']->merge($tickets['cooking']);
    $ready = $tickets['ready'];

    // Shared qty formatter — used by card rows, the ready strip AND the
    // all-day strip so «1.50» never leaks anywhere: whole numbers print
    // «×2», fractional keep only their meaningful decimals («×1.5»).
    $fmtQty = function ($qty): string {
        $qty = (float) $qty;
        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    };

    // All-day batching: identical dishes summed across every open ticket
    // so the cook fires «٣× كباب» in one go instead of reading three cards.
    $allDay = $active->flatMap(fn ($c) => $c['items'])
        ->filter(fn ($i) => in_array($i->status, ['approved', 'preparing'], true))
        ->groupBy('name_snapshot')
        ->map(fn ($g) => $g->sum(fn ($i) => (float) $i->quantity))
        ->sortDesc();

    // Tables with more than one open ticket on THIS board — their cards get
    // a follow-up chip so the kitchen coordinates courses instead of firing
    // blind and having plates land at the table 10 minutes apart.
    $followUpTables = $active->pluck('table_num')->filter()
        ->countBy()->filter(fn ($n) => $n > 1)->keys()->all();
@endphp

{{-- Root wire:key is STABLE on purpose: the old count-based key replaced
     the whole subtree on every count change, which closed open cancel
     popovers and defeated morphing. Chime detection now reads the
     data-*-ids attributes (mutated in place by morph) instead. --}}
<div class="kb-wrap"
     wire:poll.visible.8s="refreshFromBroadcast"
     style="--station-color: {{ $stationColor }};"
     x-data="kitchenSound()"
     data-order-ids="{{ $active->map(fn ($c) => $c['order']->id)->implode(',') }}"
     data-ready-ids="{{ $ready->map(fn ($c) => $c['order']->id)->implode(',') }}"
     data-red-count="{{ $load['red_cards'] ?? 0 }}"
     data-table-filter="{{ $filterTable }}"
     x-init="init()"
     wire:key="kb-{{ $stationCode }}">

    {{-- Action feedback — dismissible toast fed by the `toast` browser event
         every wire action dispatches (stale taps, guard errors, cancels).
         Board-local so it works in wall/tv mode where admin chrome is gone.
         wire:ignore: purely client-driven, polls must not morph it. --}}
    <div class="kb-toast-host" dir="rtl" wire:ignore
         x-data="{ show: false, type: 'success', msg: '', t: null }"
         x-on:toast.window="type = $event.detail.type || 'success'; msg = $event.detail.message || ''; show = true; clearTimeout(t); t = setTimeout(() => show = false, 5000)"
         x-show="show" x-cloak x-transition.opacity.duration.200ms>
        <div class="kb-toast" :class="'kb-toast--' + type" role="status" aria-live="polite">
            <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill' : (type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-octagon-fill')"></i>
            <span x-text="msg"></span>
            <button type="button" @click="show = false" aria-label="إغلاق التنبيه"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>

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
            {{-- Connection state — wire:offline flips the dot red and shows
                 the label the second the browser loses network. The dot used
                 to be static CSS, i.e. green even with the cable unplugged. --}}
            <span class="kb-live-dot" wire:offline.class="is-offline" title="حالة الاتصال بالخادم"></span>
            <span class="kb-live-offline" wire:offline.inline-flex><i class="bi bi-wifi-off"></i> غير متصل</span>
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

    {{-- All-day batching — one slim line the cook reads before firing:
         identical dishes summed across every open ticket. Display only. --}}
    @if($allDay->isNotEmpty())
        <div class="kb-allday">
            <span class="kb-allday-label"><i class="bi bi-fire"></i> إجمالي التحضير:</span>
            <div class="kb-allday-scroll">
                @foreach($allDay as $name => $qty)
                    <span class="kb-allday-chip" wire:key="allday-{{ md5($name) }}">
                        <strong>{{ $fmtQty($qty) }}×</strong> {{ $name }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Ready strip — pickup queue. Sits ABOVE the grid: at 20 open tickets
         the old bottom placement pushed it below the fold, so ready food
         went cold unseen. Horizontally scrollable, keeps mods, and carries
         the serve + undo actions. --}}
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
                         wire:key="ready-{{ $ro->id }}"
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
                                <div class="kb-ready-item" wire:key="ready-it-{{ $it->id }}">
                                    <span class="kb-ready-item-line">×{{ $fmtQty($it->quantity) }} {{ $it->name_snapshot }}</span>
                                    @if($it->modifiers->count())
                                        <span class="kb-ready-item-mods">
                                            @foreach($it->modifiers as $m)
                                                <small>{{ $m->name_snapshot }}</small>
                                            @endforeach
                                        </span>
                                    @endif
                                    @php
                                        // Undo window: 120s after the (possibly mis-tapped) «جاهز».
                                        $readyAgo = ($it->ready_at ?? $it->updated_at)?->diffInSeconds(now());
                                    @endphp
                                    @if($readyAgo !== null && $readyAgo <= 120)
                                        <button type="button" class="kb-undo-mini"
                                                wire:click="undoReady({{ $it->id }})"
                                                wire:loading.attr="disabled" wire:target="undoReady({{ $it->id }})"
                                                title="تراجع — يعيد الصنف لقيد التحضير">
                                            <i class="bi bi-arrow-counterclockwise"></i> تراجع
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="kb-ready-meta">
                            <span class="kb-ready-num">#{{ $ro->number }}</span>
                            <span class="kb-ready-age">{{ \App\Support\Duration::short((int) $card['age_min']) }}</span>
                            {{-- Serving is a FLOOR duty (OrderPolicy::serve — admin/manager/waiter).
                                 A chef/bartender sees the ready entry but not the hand-off button;
                                 the wire action re-checks the same policy server-side. --}}
                            @can('serve', $ro)
                                <button type="button" class="kb-ready-serve"
                                        wire:click="serveOrder({{ $ro->id }})"
                                        wire:loading.attr="disabled" wire:target="serveOrder({{ $ro->id }})"
                                        title="تم التسليم للنادل/الزبون">
                                    <i class="bi bi-check2-circle"></i> تسليم
                                </button>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        </aside>
    @endif

    {{-- Main ticket grid — auto-fit so we get 1/2/3/4 columns based on screen
         width. Tickets stay put; only their internal items change state.
         Sorted by urgency-then-age so red ones bubble up. --}}
    <div class="kb-tickets">
        @forelse($active as $card)
            @include('components.admin._kitchen-card', $card + [
                'column'    => $card['items']->contains(fn($i) => $i->status === 'preparing') ? 'cooking' : 'waiting',
                'follow_up' => $card['table_num'] && in_array($card['table_num'], $followUpTables, true),
            ])
        @empty
            <div class="kb-empty kb-empty--big">
                <i class="bi bi-cup-straw"></i>
                <span>لا طلبات نشطة الآن</span>
                <small>التذكرة الجديدة ستظهر هنا تلقائياً مع صوت تنبيه</small>
            </div>
        @endforelse
    </div>

    <script>
    /* Kitchen sound system.
     * Chef taps "تفعيل الصوت" → AudioContext unlocks (browser autoplay policy).
     * After that:
     *   - a NEW order id appears on the board   → bright two-tone chime
     *   - an id vanishes without being served   → short falling cancel tone
     *   - a card turns red (urgency escalates)  → low warning beep
     * Tracking ID SETS (not counts) means one ticket cancelled + one created
     * in the same poll still chimes — the old count diff stayed silent.
     * No audio files — Web Audio API synthesizes tones, so no assets needed.
     *
     * AudioContext + previous id sets live on `window` (singleton) so they
     * survive Livewire morphs. Without this, ctx would get recreated on
     * every server roundtrip and the chef would have to re-tap sound after
     * every order. The MUTE choice lives in localStorage so it survives
     * reloads and browser restarts on the kitchen tablet. */
    function kitchenSound() {
        return {
            // Reactive Alpine prop (so :class re-renders); localStorage is
            // the persistent source of truth, window mirrors it for the
            // global unlock banner + sibling boards on the same page.
            enabled: (() => {
                try { return localStorage.getItem('kdsSoundMuted') !== '1'; }
                catch (e) { return window.__kbSoundEnabled !== false; }
            })(),
            init() {
                // Mirror the persisted choice + refresh the global banner
                // visibility so it can prompt the chef to tap once.
                window.__kbSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                this.checkChanges();

                // Livewire v4 dropped the `livewire:morph.updated` event used
                // by v2/v3, so we can't hook morphs directly. Watching the
                // data-* attributes on the component root is version-proof:
                // every render writes the latest id lists there, and we react
                // immediately whether Livewire morphs, remounts, or even if
                // some other code updates the dataset.
                if (this._observer) this._observer.disconnect();
                this._observer = new MutationObserver(() => {
                    this.enabled = window.__kbSoundEnabled !== false;
                    this.checkChanges();
                });
                this._observer.observe(this.$root, {
                    attributes: true,
                    attributeFilter: ['data-order-ids', 'data-ready-ids', 'data-red-count', 'data-table-filter'],
                });
            },
            readIds(key) { return (this.$root.dataset[key] || '').split(',').filter(Boolean); },
            readRed()    { return parseInt(this.$root.dataset.redCount || '0', 10); },
            toggleSound() {
                this.enabled = !this.enabled;
                try { localStorage.setItem('kdsSoundMuted', this.enabled ? '0' : '1'); } catch (e) {}
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
                const activeIds = this.readIds('orderIds');
                const readyIds  = this.readIds('readyIds');
                const current   = new Set([...activeIds, ...readyIds]);
                const red = this.readRed();
                const filter = this.$root.dataset.tableFilter || '';

                // First render of the tab — just record the baseline, don't
                // beep on page load (would be noise on every navigation).
                // Same when the table FILTER changes: ids leave the lists
                // because the chef narrowed the view, not because tickets
                // were cancelled — re-baseline silently.
                if (!(window.__kbPrevIds instanceof Set) || filter !== window.__kbPrevFilter) {
                    window.__kbPrevIds = current;
                    window.__kbPrevReadyIds = new Set(readyIds);
                    window.__kbPrevRed = red;
                    window.__kbPrevFilter = filter;
                    return;
                }

                const prev = window.__kbPrevIds;
                const prevReady = window.__kbPrevReadyIds || new Set();
                const added = [...current].filter((id) => !prev.has(id));
                // Vanished without ever reaching the pass = cancelled. Ids
                // that WERE on the ready strip left by being served — silent.
                const cancelled = [...prev].filter((id) => !current.has(id) && !prevReady.has(id));

                if (this.enabled) {
                    if (added.length) this.playNewOrder();
                    else if (cancelled.length) this.playCancelled();
                    else if (red > window.__kbPrevRed) this.playWarning();
                }

                window.__kbPrevIds = current;
                window.__kbPrevReadyIds = new Set(readyIds);
                window.__kbPrevRed = red;
                window.__kbPrevFilter = filter;
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
            playCancelled() {
                // Short FALLING pair — unmistakably different from the rising
                // new-order chime, and brief so it doesn't read as an alarm.
                this.beep(660, 0.1, 'sine', 0.3);
                setTimeout(() => this.beep(440, 0.12, 'sine', 0.3), 110);
            },
            playWarning() {
                this.beep(440, 0.18, 'square', 0.22);
                setTimeout(() => this.beep(440, 0.18, 'square', 0.22), 280);
            },
        };
    }
    </script>
</div>
