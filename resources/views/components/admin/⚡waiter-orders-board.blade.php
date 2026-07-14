<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\Duration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use AuthorizesRequests;

    public bool $peakMode = true;

    #[Url(as: 'focus', except: 'all')]
    public string $focus = 'all';

    /**
     * One lateness definition for the whole board: a task counts as "late"
     * once it has waited this many minutes. Shared by BOTH the hero urgent
     * stat and the "المتأخر" focus tab so their numbers always agree (they
     * used to disagree — the stat only counted late *pending* orders). We
     * kept the tab's stricter definition: any task kind at 5+ minutes.
     */
    public const LATE_AFTER_MINUTES = 5;

    /**
     * Age stops adding priority points past this (4h). Keeps ready-first
     * tier ordering intact while preventing one forgotten ticket from
     * accumulating enough points to bury newer urgent work forever.
     */
    private const AGE_PRIORITY_CAP_MINUTES = 240;

    #[Computed]
    public function groups(): array
    {
        $since = now()->subHours(8);

        $orders = Order::with([
            'table.zone',
            'customer',
            'items.station',
            'items.modifiers',
            'tableSession.assignedWaiter',
            'tableSession.customer',
            'approver',
        ])
            ->where(function ($q) use ($since) {
                $q->where('created_at', '>=', $since)
                    ->orWhereIn('status', [
                        OrderStatus::Pending->value,
                        OrderStatus::Approved->value,
                        OrderStatus::Preparing->value,
                        OrderStatus::Ready->value,
                        OrderStatus::Delivered->value,
                    ]);
            })
            ->whereNotIn('status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Completed->value,
            ])
            ->orderBy('created_at')
            ->get();

        $pending = $orders->where('status', OrderStatus::Pending->value)->values();

        $ready = $orders
            ->reject(fn (Order $order) => $order->status === OrderStatus::Pending->value)
            ->filter(fn (Order $order) => $order->items->contains(
                fn (OrderItem $item) => $item->status === OrderItemStatus::Ready->value
            ))
            ->values();

        $production = $orders
            ->whereIn('status', [
                OrderStatus::Approved->value,
                OrderStatus::Preparing->value,
            ])
            ->reject(fn (Order $order) => $order->items->contains(
                fn (OrderItem $item) => $item->status === OrderItemStatus::Ready->value
            ))
            ->values();

        $billRequests = TableSession::with([
            'table.zone',
            'customer',
            'assignedWaiter',
            'orders.items',
            'invoice',
        ])
            ->where('status', 'active')
            ->whereNotNull('bill_requested_at')
            ->whereDoesntHave('invoice', fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy('bill_requested_at')
            ->get();

        $served = $orders
            ->where('status', OrderStatus::Delivered->value)
            ->take(8)
            ->values();

        return [
            'pending' => $pending,
            'production' => $production,
            'ready' => $ready,
            'billing' => $billRequests,
            'served' => $served,
        ];
    }

    #[Computed]
    public function stats(): array
    {
        $groups = $this->groups;

        // Count ready ORDERS by how long their oldest ready item has sat —
        // matches the kitchen's escalation thresholds (3 / 8 mins). The waiter
        // sound system uses these to chime on transitions: 0→cold = pickup
        // chime, cold→hot = warning beep when food is going to waste.
        $readyCold = 0;
        $readyHot = 0;
        foreach ($groups['ready'] as $order) {
            $oldestReadyAt = $order->items
                ->where('status', OrderItemStatus::Ready->value)
                ->pluck('ready_at')->filter()->min();
            if (! $oldestReadyAt) continue;
            $ageMin = (int) $oldestReadyAt->diffInMinutes(now());
            if ($ageMin >= 8)      $readyHot++;
            elseif ($ageMin >= 3)  $readyCold++;
        }

        return [
            'pending' => $groups['pending']->count(),
            // Same lateness definition as the "المتأخر" focus tab — one
            // shared threshold over every task kind, so the hero number
            // always matches what the tab actually lists.
            'urgent' => $this->allPeakTasks
                ->filter(fn ($task) => $task['age_min'] >= self::LATE_AFTER_MINUTES)
                ->count(),
            'production' => $groups['production']->count(),
            'ready_items' => $groups['ready']->sum(fn ($order) => $order->items
                ->where('status', OrderItemStatus::Ready->value)
                ->count()),
            'ready_orders' => $groups['ready']->count(),
            'ready_cold' => $readyCold,
            'ready_hot' => $readyHot,
            'billing' => $groups['billing']->count(),
        ];
    }

    /**
     * Stock shortages across the pending column, keyed by order id. Lets the
     * board warn the waiter *before* they hit "اعتماد" — and point at the
     * exact lines to cancel — instead of failing the whole approve later.
     * Only orders with an actual shortage appear in the map.
     *
     * @return array<int, array{issues: array, short_item_ids: int[]}>
     */
    #[Computed]
    public function stockReports(): array
    {
        $reports = [];
        foreach ($this->groups['pending'] as $order) {
            $report = app(InventoryService::class)->orderStockReport($order);
            if (! empty($report['issues'])) {
                $reports[$order->id] = $report;
            }
        }

        return $reports;
    }

    public function approveOrder(int $orderId): void
    {
        $order = Order::with('items')->findOrFail($orderId);
        $this->authorize('approve', $order);

        try {
            app(OrderService::class)->approve($order, auth()->id());
            $this->clearComputed();
            session()->flash('success', 'تم اعتماد الطلب وإرساله للمطبخ والبار.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Cancel a single pending line — the waiter's escape hatch when an item
     * can't be fulfilled (out-of-stock ingredient). Pending items haven't
     * deducted stock yet, so this just removes the line and re-totals.
     */
    public function cancelItem(int $itemId): void
    {
        $item = OrderItem::with('order')->findOrFail($itemId);
        $this->authorize('cancel', $item->order);

        try {
            app(OrderService::class)->cancelItem(
                $item,
                auth()->id(),
                'نقص في المكونات — أُلغي من لوحة الجرسون'
            );
            $this->clearComputed();
            session()->flash('success', 'تم إلغاء الصنف "'.$item->name_snapshot.'".');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function serveItem(int $itemId): void
    {
        $item = OrderItem::with('order')->findOrFail($itemId);
        $this->authorize('serve', $item->order);

        try {
            app(OrderService::class)->markItemServed($item, auth()->id());
            $this->clearComputed();
            session()->flash('success', 'تم تعليم الصنف كمقدّم.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function serveReadyItems(int $orderId): void
    {
        $order = Order::with('items')->findOrFail($orderId);
        $this->authorize('serve', $order);

        try {
            $readyItems = $order->items->where('status', OrderItemStatus::Ready->value);
            foreach ($readyItems as $item) {
                app(OrderService::class)->markItemServed($item, auth()->id());
            }

            $this->clearComputed();
            session()->flash('success', 'تم تقديم كل الأصناف الجاهزة في الطلب.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /** Every actionable task (unfiltered), sorted most-important first. */
    #[Computed]
    public function allPeakTasks()
    {
        $groups = $this->groups;
        $tasks = collect();

        foreach ($groups['pending'] as $order) {
            $ageMin = (int) $order->created_at->diffInMinutes(now());
            $tasks->push([
                'kind' => 'pending',
                'label' => 'قبول الطلب',
                'title' => $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel(),
                'subtitle' => $ageMin >= self::LATE_AFTER_MINUTES ? 'متأخر عن الاعتماد' : 'طلب جديد بانتظار الاعتماد',
                'age_min' => $ageMin,
                'priority' => ($ageMin >= self::LATE_AFTER_MINUTES ? 4000 : 1600) + min($ageMin, self::AGE_PRIORITY_CAP_MINUTES),
                'order' => $order,
            ]);
        }

        foreach ($groups['ready'] as $order) {
            $readyCount = $order->items->where('status', OrderItemStatus::Ready->value)->count();
            if ($readyCount < 1) {
                continue;
            }

            $oldestReadyAt = $order->items
                ->where('status', OrderItemStatus::Ready->value)
                ->pluck('ready_at')
                ->filter()
                ->min();
            $ageMin = $oldestReadyAt ? (int) $oldestReadyAt->diffInMinutes(now()) : (int) $order->created_at->diffInMinutes(now());

            // Tier the ready urgency the same way the kitchen does so the
            // colour story is consistent across screens. Cold food (8+ min on
            // the pass) jumps above pending in priority — the order itself
            // can wait, but a steak going cold can't. Age fine-tunes the
            // order *within* a tier but is capped (AGE_PRIORITY_CAP_MINUTES),
            // so the tier bases stay decisive: red (5200) always beats a late
            // pending (4000 + capped age ≤ 4240), and one forgotten ticket
            // can't accumulate enough points to camp at #1 forever.
            $readyUrgency = $ageMin >= 8 ? 'red' : ($ageMin >= 3 ? 'amber' : 'green');
            $priorityBase = match ($readyUrgency) {
                'red'   => 5200,        // beats even an old pending (4000 + capped age)
                'amber' => 3400,        // above fresh pending (1600), below late pending
                default => 3000,
            };

            // Name the ready plates on the card so the waiter knows what to
            // carry without opening the order — first names then "+N".
            $readyNames = $order->items
                ->where('status', OrderItemStatus::Ready->value)
                ->pluck('name_snapshot');
            $namesLine = $readyNames->take(3)->implode('، ')
                .($readyNames->count() > 3 ? ' +'.($readyNames->count() - 3) : '');

            $tasks->push([
                'kind' => 'ready',
                'label' => $readyUrgency === 'red' ? 'قدّم فوراً — يبرد!' : 'قدّم الآن',
                'title' => $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel(),
                'subtitle' => $namesLine.($readyUrgency === 'red' ? ' · على الباس منذ '.Duration::short($ageMin) : ''),
                'age_min' => $ageMin,
                'priority' => $priorityBase + $readyCount * 10 + min($ageMin, self::AGE_PRIORITY_CAP_MINUTES),
                'order' => $order,
                'ready_count' => $readyCount,
                'ready_urgency' => $readyUrgency,
            ]);
        }

        foreach ($groups['billing'] as $session) {
            $waitMin = (int) $session->bill_requested_at->diffInMinutes(now());
            $tasks->push([
                'kind' => 'billing',
                'label' => 'فاتورة',
                'title' => 'طاولة '.($session->table?->number ?? '—'),
                'subtitle' => $session->bill_request_note ?: 'الزبون ينتظر إنهاء الحساب',
                'age_min' => $waitMin,
                'priority' => ($waitMin >= self::LATE_AFTER_MINUTES ? 3800 : 2200) + min($waitMin, self::AGE_PRIORITY_CAP_MINUTES),
                'session' => $session,
            ]);
        }

        return $tasks->sortByDesc('priority')->values();
    }

    /** The task list after the focus-tab filter — what the board renders. */
    #[Computed]
    public function peakTasks()
    {
        return $this->allPeakTasks
            ->filter(fn ($task) => $this->focus === 'all'
                || $task['kind'] === $this->focus
                || ($this->focus === 'urgent' && $task['age_min'] >= self::LATE_AFTER_MINUTES))
            ->values();
    }

    public function togglePeakMode(): void
    {
        $this->peakMode = ! $this->peakMode;

        if (! $this->peakMode) {
            $this->focus = 'all';
        }
    }

    public function setFocus(string $focus): void
    {
        if (in_array($focus, ['all', 'urgent', 'pending', 'ready', 'billing'], true)) {
            $this->focus = $focus;
        }
    }

    #[On('echo-private:waiters,.order.created')]
    public function refreshFromCreated(): void
    {
        $this->clearComputed();
    }

    #[On('echo-private:waiters,.order.status_changed')]
    public function refreshFromOrderStatus(): void
    {
        $this->clearComputed();
    }

    #[On('echo-private:waiters,.item.status_changed')]
    public function refreshFromItemStatus(): void
    {
        $this->clearComputed();
    }

    public function refreshBoard(): void
    {
        $this->clearComputed();
    }

    private function clearComputed(): void
    {
        unset($this->groups, $this->stats, $this->allPeakTasks, $this->peakTasks, $this->stockReports);
    }
}
?>

<div class="waiter-board"
     wire:poll.visible.12s="refreshBoard"
     x-data="waiterSound()"
     data-pending-count="{{ $this->stats['pending'] }}"
     data-ready-count="{{ $this->stats['ready_orders'] ?? 0 }}"
     data-ready-cold="{{ $this->stats['ready_cold'] ?? 0 }}"
     data-ready-hot="{{ $this->stats['ready_hot'] ?? 0 }}"
     data-billing-count="{{ $this->stats['billing'] }}">
    @php
        $groups = $this->groups;
        $stats = $this->stats;
        $peakTasks = $this->peakTasks;
        $stockReports = $this->stockReports;
        $columns = [
            'pending' => [
                'title' => 'قبول الطلبات',
                'subtitle' => 'طلبات QR قبل إرسالها للمحطات',
                'icon' => 'bi-send-check',
                'tone' => 'pending',
                'kind' => 'order',
            ],
            'production' => [
                'title' => 'تحت التحضير',
                'subtitle' => 'تابعها بدون إزعاج المطبخ والبار',
                'icon' => 'bi-fire',
                'tone' => 'production',
                'kind' => 'order',
            ],
            'ready' => [
                'title' => 'جاهز للتقديم',
                'subtitle' => 'استلم الأصناف الجاهزة وقدمها',
                'icon' => 'bi-bell-fill',
                'tone' => 'ready',
                'kind' => 'order',
            ],
            'billing' => [
                'title' => 'طلبات الفاتورة',
                'subtitle' => 'زبائن أنهوا الجلسة وينتظرون الكاشير',
                'icon' => 'bi-receipt-cutoff',
                'tone' => 'billing',
                'kind' => 'bill',
            ],
        ];
        $visibleColumns = $peakMode
            ? array_intersect_key($columns, array_flip(['pending', 'ready', 'billing']))
            : $columns;
        $focusOptions = [
            'all' => ['label' => 'الكل', 'icon' => 'bi-lightning-charge-fill'],
            'urgent' => ['label' => 'المتأخر', 'icon' => 'bi-exclamation-triangle-fill'],
            'pending' => ['label' => 'قبول', 'icon' => 'bi-send-check'],
            'ready' => ['label' => 'جاهز', 'icon' => 'bi-bell-fill'],
            'billing' => ['label' => 'فاتورة', 'icon' => 'bi-receipt-cutoff'],
        ];
    @endphp

    @if (session('success') || session('error'))
        <div class="waiter-flash {{ session('error') ? 'is-error' : 'is-success' }}">
            <i class="bi {{ session('error') ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' }}"></i>
            {{ session('error') ?: session('success') }}
        </div>
    @endif

    <section class="waiter-hero waiter-hero--compact">
        <div class="waiter-hero-copy">
            <h2>مهام الجرسون</h2>
            @if($stats['production'] > 0)
                <span class="waiter-production-chip" title="طلبات المطبخ والبار يحضّرونها — لا تحتاج إجراء منك">
                    <i class="bi bi-fire"></i>
                    {{ $stats['production'] }} طلب تحت التحضير
                </span>
            @endif
            <button type="button" @click="toggleSound()"
                    class="waiter-sound-btn"
                    :class="enabled ? 'is-on' : 'is-off'"
                    title="تنبيه صوتي عند جاهزية الطلب أو تأخره">
                <i class="bi" :class="enabled ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                <span x-text="enabled ? 'الصوت يعمل' : 'تفعيل الصوت'"></span>
            </button>
        </div>

        @if($peakMode)
            <div class="waiter-focus-tabs" aria-label="فلترة المهام">
                @foreach($focusOptions as $focusKey => $option)
                    <button type="button"
                        wire:click="setFocus('{{ $focusKey }}')"
                        class="waiter-focus-tab {{ $focus === $focusKey ? 'is-active' : '' }}">
                        <i class="bi {{ $option['icon'] }}"></i>
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        <button type="button" wire:click="togglePeakMode" class="waiter-peak-toggle {{ ! $peakMode ? 'is-active' : '' }}">
            <i class="bi {{ $peakMode ? 'bi-grid-3x3-gap-fill' : 'bi-list-task' }}"></i>
            {{ $peakMode ? 'عرض المراحل بالتفصيل' : 'العودة لقائمة المهام' }}
        </button>
    </section>

    @php $visiblePeakTasks = $peakTasks; @endphp
    @if($peakMode)
        <section class="waiter-peak-queue">
            <header class="waiter-peak-head">
                <div>
                    <span><i class="bi bi-lightning-charge-fill"></i> أولوية التنفيذ الآن</span>
                    <small>اعتمد، قدّم الجاهز، أو افتح الفاتورة. مرتّبة من الأهم.</small>
                </div>
                <strong>{{ $visiblePeakTasks->count() }}</strong>
            </header>
            <div class="waiter-peak-list">
                @forelse($visiblePeakTasks as $task)
                    @php
                        // Ready tasks have their own escalation (3/8 min on the pass).
                        // Other tasks fall back to the shared lateness threshold.
                        $isHot = $task['kind'] === 'ready'
                            ? ($task['ready_urgency'] ?? 'green') === 'red'
                            : $task['age_min'] >= $this::LATE_AFTER_MINUTES;
                        $isWarm = $task['kind'] === 'ready'
                            && ($task['ready_urgency'] ?? 'green') === 'amber';
                    @endphp
                    @php
                        $taskReport = $task['kind'] === 'pending'
                            ? ($stockReports[$task['order']->id] ?? null)
                            : null;
                        $taskShortItems = $taskReport
                            ? $task['order']->items->whereIn('id', $taskReport['short_item_ids'])
                            : collect();
                    @endphp
                    <article class="waiter-peak-task waiter-peak-task--{{ $task['kind'] }} {{ $isHot ? 'is-hot' : '' }} {{ $isWarm ? 'is-warm' : '' }}">
                        <div class="waiter-peak-task-main">
                            <span class="waiter-peak-label">{{ $task['label'] }}</span>
                            <strong>{{ $task['title'] }}</strong>
                            <small>{{ $task['subtitle'] }}</small>

                            @if($taskReport)
                                <div class="waiter-stock-warning">
                                    <div class="waiter-stock-warning-head">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        مكوّنات ناقصة — راجِع قبل الاعتماد
                                    </div>
                                    <div class="waiter-stock-issues">
                                        @foreach($taskReport['issues'] as $issue)
                                            <span class="waiter-stock-issue-chip">
                                                {{ $issue['ingredient'] }}:
                                                متاح {{ rtrim(rtrim(number_format($issue['available'], 2), '0'), '.') }}
                                                / مطلوب {{ rtrim(rtrim(number_format($issue['required'], 2), '0'), '.') }}
                                            </span>
                                        @endforeach
                                    </div>
                                    @foreach($taskShortItems as $shortItem)
                                        <div class="waiter-stock-short-item">
                                            <span>
                                                <i class="bi bi-x-octagon-fill text-danger"></i>
                                                {{ $shortItem->name_snapshot }}
                                            </span>
                                            <button type="button"
                                                    wire:click="cancelItem({{ $shortItem->id }})"
                                                    wire:confirm="إلغاء الصنف &quot;{{ $shortItem->name_snapshot }}&quot; من الطلب؟"
                                                    class="waiter-stock-cancel-btn">
                                                <i class="bi bi-x-circle"></i> إلغاء الصنف
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <span class="waiter-age {{ $isHot ? 'is-hot' : '' }} {{ $isWarm ? 'is-warm' : '' }}">
                            {{ \App\Support\Duration::short($task['age_min']) }}
                        </span>
                        <div class="waiter-peak-action">
                            @if($task['kind'] === 'pending')
                                <button type="button" wire:click="approveOrder({{ $task['order']->id }})" class="waiter-main-btn">
                                    <i class="bi bi-send-check"></i>
                                    اعتماد
                                </button>
                            @elseif($task['kind'] === 'ready')
                                <button type="button" wire:click="serveReadyItems({{ $task['order']->id }})" class="waiter-main-btn">
                                    <i class="bi bi-check2-all"></i>
                                    تقديم {{ $task['ready_count'] }}
                                </button>
                            @elseif($task['kind'] === 'billing')
                                <a href="{{ route('admin.cashier.show', $task['session']) }}" class="waiter-main-btn waiter-main-btn--bill">
                                    <i class="bi bi-cash-coin"></i>
                                    فتح
                                </a>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="waiter-empty waiter-empty--peak">
                        <i class="bi bi-check2-circle"></i>
                        <span>لا يوجد ضغط حالياً</span>
                    </div>
                @endforelse
            </div>
        </section>
    @endif

    @if(! $peakMode)
    <div class="waiter-flow {{ $peakMode ? 'waiter-flow--peak' : '' }}">
        @foreach($visibleColumns as $key => $column)
            @php $records = $groups[$key]; @endphp
            <section class="waiter-lane waiter-lane--{{ $column['tone'] }}">
                <header class="waiter-lane-head">
                    <div>
                        <span><i class="bi {{ $column['icon'] }}"></i> {{ $column['title'] }}</span>
                        <small>{{ $column['subtitle'] }}</small>
                    </div>
                    <strong>{{ $records->count() }}</strong>
                </header>

                <div class="waiter-lane-body">
                    @forelse($records as $record)
                        @if($column['kind'] === 'bill')
                            @php
                                $session = $record;
                                $waitMin = (int) $session->bill_requested_at->diffInMinutes(now());
                                $ordersTotal = $session->orders->sum('total');
                                $guestName = $session->customer?->name ?: $session->customer_name;
                            @endphp

                            <article class="waiter-order waiter-bill-card {{ $waitMin >= $this::LATE_AFTER_MINUTES ? 'is-urgent' : '' }}">
                                <div class="waiter-order-head">
                                    <div>
                                        <span class="waiter-order-number">طلب فاتورة</span>
                                        <strong>طاولة {{ $session->table?->number ?? '—' }}</strong>
                                    </div>
                                    <span class="waiter-age {{ $waitMin >= $this::LATE_AFTER_MINUTES ? 'is-hot' : '' }}">
                                        {{ \App\Support\Duration::short($waitMin) }}
                                    </span>
                                </div>

                                <div class="waiter-context">
                                    @if($session->table?->zone)
                                        <span>
                                            <i class="bi bi-geo-alt-fill"></i>
                                            {{ $session->table->zone->label }}
                                        </span>
                                    @endif
                                    <span>
                                        <i class="bi {{ $session->customer_id ? 'bi-person-check-fill' : 'bi-person' }}"></i>
                                        {{ $guestName ?: 'ضيف QR' }}
                                    </span>
                                    <span>
                                        <i class="bi bi-receipt"></i>
                                        {{ $session->orders->count() }} طلب
                                    </span>
                                </div>

                                @if($session->bill_request_note)
                                    <div class="waiter-note">
                                        <i class="bi bi-chat-left-text"></i>
                                        {{ $session->bill_request_note }}
                                    </div>
                                @endif

                                <div class="waiter-bill-summary">
                                    <span>إجمالي الجلسة</span>
                                    <strong>{{ \App\Helpers\Money::format($ordersTotal) }}</strong>
                                </div>

                                <div class="waiter-order-foot">
                                    <span class="waiter-total">ينتظر الكاشير</span>
                                    <div class="waiter-actions">
                                        <a href="{{ route('admin.cashier.show', $session) }}" class="waiter-main-btn waiter-main-btn--bill">
                                            <i class="bi bi-cash-coin"></i>
                                            فتح الفاتورة
                                        </a>
                                    </div>
                                </div>
                            </article>
                        @else
                            @php
                                $order = $record;
                                $ageMin = (int) $order->created_at->diffInMinutes(now());
                                $isUrgent = $key === 'pending' && $ageMin >= $this::LATE_AFTER_MINUTES;
                                $readyItemsCount = $order->items->where('status', OrderItemStatus::Ready->value)->count();
                                $stationGroups = $order->items->groupBy(fn ($item) => $item->station?->name ?? 'بدون محطة');
                                $session = $order->tableSession;
                                $guestName = $order->customer?->name ?: ($session?->customer?->name ?: ($session?->customer_name ?: $order->customer_name));
                                $waiterName = $session?->assignedWaiter?->name ?: $order->approver?->name;
                                $originName = $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel();
                                $orderReport = $key === 'pending' ? ($stockReports[$order->id] ?? null) : null;
                                $shortItemIds = $orderReport['short_item_ids'] ?? [];
                            @endphp

                            <article class="waiter-order {{ $isUrgent ? 'is-urgent' : '' }}">
                                <div class="waiter-order-head">
                                    <div>
                                        <span class="waiter-order-number">{{ $order->number }}</span>
                                        <strong>{{ $originName }}</strong>
                                    </div>
                                    <span class="waiter-age {{ $isUrgent ? 'is-hot' : '' }}">
                                        {{ \App\Support\Duration::short($ageMin) }}
                                    </span>
                                </div>

                                <div class="waiter-context">
                                    @if($order->table?->zone)
                                        <span>
                                            <i class="bi bi-geo-alt-fill"></i>
                                            {{ $order->table->zone->label }}
                                        </span>
                                    @endif
                                    <span>
                                        <i class="bi {{ ($order->customer_id || $session?->customer_id) ? 'bi-person-check-fill' : 'bi-person' }}"></i>
                                        {{ $guestName ?: 'ضيف QR' }}
                                    </span>
                                    @if($session?->cover_count)
                                        <span>
                                            <i class="bi bi-people-fill"></i>
                                            {{ $session->cover_count }} أشخاص
                                        </span>
                                    @endif
                                    <span>
                                        <i class="bi bi-person-badge"></i>
                                        {{ $waiterName ?: 'لم يخصص بعد' }}
                                    </span>
                                </div>

                                @if($order->customer_notes)
                                    <div class="waiter-note">
                                        <i class="bi bi-chat-left-text"></i>
                                        {{ $order->customer_notes }}
                                    </div>
                                @endif

                                @if($orderReport)
                                    <div class="waiter-stock-warning">
                                        <div class="waiter-stock-warning-head">
                                            <i class="bi bi-exclamation-triangle-fill"></i>
                                            مكوّنات ناقصة — لا يمكن تحضير الأصناف المعلَّمة. ألغِها قبل الاعتماد.
                                        </div>
                                        <div class="waiter-stock-issues">
                                            @foreach($orderReport['issues'] as $issue)
                                                <span class="waiter-stock-issue-chip">
                                                    {{ $issue['ingredient'] }}:
                                                    متاح {{ rtrim(rtrim(number_format($issue['available'], 2), '0'), '.') }}
                                                    / مطلوب {{ rtrim(rtrim(number_format($issue['required'], 2), '0'), '.') }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="waiter-items">
                                    @foreach($order->items as $item)
                                        @php
                                            $qty = rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.');
                                            $itemReady = $item->status === OrderItemStatus::Ready->value;
                                            $itemServed = $item->status === OrderItemStatus::Served->value;
                                            $itemShort = in_array($item->id, $shortItemIds, true);
                                        @endphp
                                        <div class="waiter-item waiter-item--{{ str_replace('_', '-', $item->status) }} {{ $itemReady ? 'is-ready-to-serve' : '' }} {{ $itemShort ? 'is-short' : '' }}">
                                            <div class="waiter-item-main">
                                                <span class="waiter-item-qty">x{{ $qty }}</span>
                                                <span class="waiter-item-name">{{ $item->name_snapshot }}</span>
                                            </div>
                                            <span class="waiter-station" style="--station-color: {{ $item->station->color ?? '#667085' }};">
                                                {{ $item->station?->name ?? 'بدون محطة' }}
                                            </span>
                                            @if($itemShort)
                                                <button type="button"
                                                        wire:click="cancelItem({{ $item->id }})"
                                                        wire:confirm="إلغاء الصنف &quot;{{ $item->name_snapshot }}&quot; من الطلب؟"
                                                        class="waiter-stock-cancel-btn">
                                                    <i class="bi bi-x-circle"></i> إلغاء
                                                </button>
                                            @elseif($itemReady)
                                                <button type="button" wire:click="serveItem({{ $item->id }})" class="waiter-serve-btn">
                                                    <i class="bi bi-check2"></i>
                                                    قدّم
                                                </button>
                                            @elseif($itemServed)
                                                <span class="waiter-served-badge">
                                                    <i class="bi bi-check2-all"></i>
                                                    تم
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                <div class="waiter-stations">
                                    @foreach($stationGroups as $stationName => $items)
                                        <span>
                                            <i class="bi bi-arrow-left-short"></i>
                                            {{ $stationName }}
                                            <b>{{ $items->count() }}</b>
                                        </span>
                                    @endforeach
                                </div>

                                <div class="waiter-order-foot">
                                    <span class="waiter-total">{{ \App\Helpers\Money::format($order->total) }}</span>
                                    <div class="waiter-actions">
                                        <a href="{{ route('admin.orders.show', $order) }}" class="waiter-icon-btn" title="تفاصيل">
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        @if($key === 'pending')
                                            <button type="button" wire:click="approveOrder({{ $order->id }})" class="waiter-main-btn">
                                                <i class="bi bi-send-check"></i>
                                                اعتماد وإرسال
                                            </button>
                                        @elseif($key === 'ready' && $readyItemsCount > 0)
                                            <button type="button" wire:click="serveReadyItems({{ $order->id }})" class="waiter-main-btn">
                                                <i class="bi bi-check2-all"></i>
                                                تقديم الجاهز
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endif
                    @empty
                        <div class="waiter-empty">
                            <i class="bi {{ $column['icon'] }}"></i>
                            <span>لا يوجد مهام هنا</span>
                        </div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
    @endif

    <script>
    /* Waiter sound system. Mirrors the kitchen system but listens to waiter
     * signals: a new pending order, a fresh ready ticket on the pass, a bill
     * request, and the warning beep when food has been sitting on the pass
     * too long (3+ then 8+ minutes). All state lives on `window` so it
     * survives Livewire morphs — chef and waiter both unlock once per shift.
     */
    function waiterSound() {
        return {
            enabled: window.__wbSoundEnabled !== false,
            init() {
                window.__wbSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                this.checkChanges();

                // Watch data-* attributes directly (Livewire v4 dropped
                // the morph.updated event we used to rely on).
                if (this._observer) this._observer.disconnect();
                this._observer = new MutationObserver(() => {
                    this.enabled = window.__wbSoundEnabled !== false;
                    this.checkChanges();
                });
                this._observer.observe(this.$root, {
                    attributes: true,
                    attributeFilter: [
                        'data-pending-count', 'data-ready-count',
                        'data-ready-cold', 'data-ready-hot', 'data-billing-count',
                    ],
                });
            },
            snapshot() {
                const r = this.$root;
                return {
                    pending: parseInt(r.dataset.pendingCount || '0', 10),
                    ready:   parseInt(r.dataset.readyCount   || '0', 10),
                    cold:    parseInt(r.dataset.readyCold    || '0', 10),
                    hot:     parseInt(r.dataset.readyHot     || '0', 10),
                    billing: parseInt(r.dataset.billingCount || '0', 10),
                };
            },
            toggleSound() {
                this.enabled = !this.enabled;
                window.__wbSoundEnabled = this.enabled;
                window.__refreshAudioBanner?.();
                if (this.enabled) this.unlockAudio();
            },
            unlockAudio() {
                window.unlockAudioCtx?.();
            },
            checkChanges() {
                const cur = this.snapshot();
                // First mount → record baseline, don't beep.
                if (typeof window.__wbPrev !== 'object') {
                    window.__wbPrev = cur;
                    return;
                }
                const prev = window.__wbPrev;
                if (this.enabled) {
                    // New ready ticket = "come pick this up". Highest UX value.
                    if (cur.ready > prev.ready)         this.playPickup();
                    // New pending order needing approval — softer chime.
                    else if (cur.pending > prev.pending) this.playNewOrder();
                    // New bill request — distinctive low-high tone.
                    else if (cur.billing > prev.billing) this.playBill();
                    // Any ready item just escalated to red (8+ min cold) = urgent warning.
                    else if (cur.hot > prev.hot)         this.playWarning();
                    // Crossed 3-min threshold without escalating to 8 = soft nudge.
                    else if (cur.cold > prev.cold)       this.playNudge();
                }
                window.__wbPrev = cur;
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
            playPickup()    { this.beep(1175, 0.16, 'sine', 0.4); setTimeout(() => this.beep(1568, 0.22, 'sine', 0.4), 150); setTimeout(() => this.beep(1976, 0.22, 'sine', 0.4), 320); },
            playNewOrder()  { this.beep(880, 0.14, 'sine', 0.3); setTimeout(() => this.beep(1175, 0.18, 'sine', 0.3), 150); },
            playBill()      { this.beep(523, 0.18, 'sine', 0.3); setTimeout(() => this.beep(784, 0.22, 'sine', 0.3), 200); },
            playWarning()   { this.beep(440, 0.18, 'square', 0.28); setTimeout(() => this.beep(440, 0.18, 'square', 0.28), 280); setTimeout(() => this.beep(440, 0.22, 'square', 0.32), 580); },
            playNudge()     { this.beep(660, 0.12, 'sine', 0.22); },
        };
    }
    </script>
</div>
