<?php

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
use App\Services\OrderService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use AuthorizesRequests;

    public bool $peakMode = false;

    #[Url(as: 'focus', except: 'all')]
    public string $focus = 'all';

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

        return [
            'pending' => $groups['pending']->count(),
            'urgent' => $groups['pending']->filter(fn ($order) => $order->created_at->lt(now()->subMinutes(5)))->count(),
            'production' => $groups['production']->count(),
            'ready_items' => $groups['ready']->sum(fn ($order) => $order->items
                ->where('status', OrderItemStatus::Ready->value)
                ->count()),
            'billing' => $groups['billing']->count(),
        ];
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

    #[Computed]
    public function peakTasks()
    {
        $groups = $this->groups;
        $tasks = collect();

        foreach ($groups['pending'] as $order) {
            $ageMin = (int) $order->created_at->diffInMinutes(now());
            $tasks->push([
                'kind' => 'pending',
                'label' => 'قبول الطلب',
                'title' => $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel(),
                'subtitle' => $ageMin >= 5 ? 'متأخر عن الاعتماد' : 'طلب جديد بانتظار الاعتماد',
                'age_min' => $ageMin,
                'priority' => ($ageMin >= 5 ? 4000 : 1600) + $ageMin,
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

            $tasks->push([
                'kind' => 'ready',
                'label' => 'قدّم الآن',
                'title' => $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel(),
                'subtitle' => $readyCount.' صنف جاهز للتقديم',
                'age_min' => $ageMin,
                'priority' => 3000 + $readyCount * 10 + $ageMin,
                'order' => $order,
                'ready_count' => $readyCount,
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
                'priority' => ($waitMin >= 5 ? 3800 : 2200) + $waitMin,
                'session' => $session,
            ]);
        }

        return $tasks
            ->filter(fn ($task) => $this->focus === 'all' || $task['kind'] === $this->focus || ($this->focus === 'urgent' && $task['age_min'] >= 5))
            ->sortByDesc('priority')
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
        unset($this->groups, $this->stats, $this->peakTasks);
    }
}
?>

<div class="waiter-board" wire:poll.visible.12s="refreshBoard">
    @php
        $groups = $this->groups;
        $stats = $this->stats;
        $peakTasks = $this->peakTasks;
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

    <section class="waiter-hero">
        <div class="waiter-hero-copy">
            <span class="waiter-kicker">
                <i class="bi bi-broadcast-pin"></i>
                شاشة خدمة الصالة
            </span>
            <h2>مهام الجرسون اليوم</h2>
            <p>اعتمد طلب الزبون، تابع التحضير، استلم الجاهز، ووجّه طلب الفاتورة للكاشير من نفس الشاشة.</p>
            <div class="waiter-hero-actions">
                <button type="button" wire:click="togglePeakMode" class="waiter-peak-toggle {{ $peakMode ? 'is-active' : '' }}">
                    <i class="bi bi-lightning-charge-fill"></i>
                    {{ $peakMode ? 'وضع الذروة يعمل' : 'تشغيل وضع الذروة' }}
                </button>
                @if($peakMode)
                    <div class="waiter-focus-tabs" aria-label="فلترة مهام الذروة">
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
            </div>
        </div>

        <div class="waiter-metrics">
            <div class="waiter-metric waiter-metric--pending">
                <span>بحاجة اعتماد</span>
                <strong>{{ $stats['pending'] }}</strong>
            </div>
            <div class="waiter-metric waiter-metric--urgent">
                <span>متأخرة</span>
                <strong>{{ $stats['urgent'] }}</strong>
            </div>
            <div class="waiter-metric waiter-metric--ready">
                <span>أصناف جاهزة</span>
                <strong>{{ $stats['ready_items'] }}</strong>
            </div>
            <div class="waiter-metric waiter-metric--billing">
                <span>طلبات فاتورة</span>
                <strong>{{ $stats['billing'] }}</strong>
            </div>
        </div>
    </section>

    @php $visiblePeakTasks = $peakMode ? $peakTasks : $peakTasks->take(8); @endphp
    @if($peakMode || $visiblePeakTasks->isNotEmpty())
        <section class="waiter-peak-queue">
            <header class="waiter-peak-head">
                <div>
                    <span><i class="bi bi-lightning-charge-fill"></i> أولوية التنفيذ الآن</span>
                    <small>اعتمد، قدّم الجاهز، أو افتح الفاتورة. هذه القائمة تختصر الزحمة بدون البحث داخل الأعمدة.</small>
                </div>
                <strong>{{ $visiblePeakTasks->count() }}</strong>
            </header>
            <div class="waiter-peak-list">
                @forelse($visiblePeakTasks as $task)
                    <article class="waiter-peak-task waiter-peak-task--{{ $task['kind'] }} {{ $task['age_min'] >= 5 ? 'is-hot' : '' }}">
                        <div class="waiter-peak-task-main">
                            <span class="waiter-peak-label">{{ $task['label'] }}</span>
                            <strong>{{ $task['title'] }}</strong>
                            <small>{{ $task['subtitle'] }}</small>
                        </div>
                        <span class="waiter-age {{ $task['age_min'] >= 5 ? 'is-hot' : '' }}">
                            {{ $task['age_min'] < 1 ? 'الآن' : $task['age_min'].' د' }}
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

                            <article class="waiter-order waiter-bill-card {{ $waitMin >= 5 ? 'is-urgent' : '' }}">
                                <div class="waiter-order-head">
                                    <div>
                                        <span class="waiter-order-number">طلب فاتورة</span>
                                        <strong>طاولة {{ $session->table?->number ?? '—' }}</strong>
                                    </div>
                                    <span class="waiter-age {{ $waitMin >= 5 ? 'is-hot' : '' }}">
                                        {{ $waitMin < 1 ? 'الآن' : $waitMin . ' د' }}
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
                                $isUrgent = $key === 'pending' && $ageMin >= 5;
                                $readyItemsCount = $order->items->where('status', OrderItemStatus::Ready->value)->count();
                                $stationGroups = $order->items->groupBy(fn ($item) => $item->station?->name ?? 'بدون محطة');
                                $session = $order->tableSession;
                                $guestName = $order->customer?->name ?: ($session?->customer?->name ?: ($session?->customer_name ?: $order->customer_name));
                                $waiterName = $session?->assignedWaiter?->name ?: $order->approver?->name;
                                $originName = $order->table ? 'طاولة '.$order->table->number : $order->sourceLabel();
                            @endphp

                            <article class="waiter-order {{ $isUrgent ? 'is-urgent' : '' }}">
                                <div class="waiter-order-head">
                                    <div>
                                        <span class="waiter-order-number">{{ $order->number }}</span>
                                        <strong>{{ $originName }}</strong>
                                    </div>
                                    <span class="waiter-age {{ $isUrgent ? 'is-hot' : '' }}">
                                        {{ $ageMin < 1 ? 'الآن' : $ageMin . ' د' }}
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

                                <div class="waiter-items">
                                    @foreach($order->items as $item)
                                        @php
                                            $qty = rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.');
                                            $itemReady = $item->status === OrderItemStatus::Ready->value;
                                            $itemServed = $item->status === OrderItemStatus::Served->value;
                                        @endphp
                                        <div class="waiter-item waiter-item--{{ str_replace('_', '-', $item->status) }} {{ $itemReady ? 'is-ready-to-serve' : '' }}">
                                            <div class="waiter-item-main">
                                                <span class="waiter-item-qty">x{{ $qty }}</span>
                                                <span class="waiter-item-name">{{ $item->name_snapshot }}</span>
                                            </div>
                                            <span class="waiter-station" style="--station-color: {{ $item->station->color ?? '#667085' }};">
                                                {{ $item->station?->name ?? 'بدون محطة' }}
                                            </span>
                                            @if($itemReady)
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
</div>
