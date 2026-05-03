{{--
  Kitchen order card — DOMINANT table number design.
  The table number is a large colored "ribbon" at the top — visible from
  across the kitchen. Order number, items, and actions sit below.

  Variables: $order, $items, $age_min, $urgency, $notes, $column
--}}

<article class="kb-card kb-urg-{{ $urgency }}">
    @php
        $isTableOrder = (bool) $order->table;
        $originLabel = $isTableOrder ? 'طاولة' : $order->sourceLabel();
        $originValue = $isTableOrder
            ? $order->table?->number
            : ($order->order_type === 'delivery' ? 'DLV' : 'TOGO');
    @endphp
    {{-- Hero table ribbon --}}
    <div class="kb-table-ribbon">
        <div class="kb-table-ribbon-inner">
            <span class="kb-table-label">{{ $originLabel }}</span>
            <span class="kb-table-big">{{ $originValue ?? '—' }}</span>
        </div>
        <div class="kb-table-ribbon-side">
            <span class="kb-age-chip" title="منذ {{ $age_min }} دقيقة">
                <i class="bi bi-clock-fill"></i>
                <strong>{{ $age_min < 1 ? '<1' : $age_min }}</strong>
                <span class="kb-age-unit">د</span>
            </span>
            <div class="kb-order-mini">#{{ $order->number }}</div>
        </div>
    </div>

    {{-- Items --}}
    <div class="kb-items">
        @foreach($items as $it)
            @php
                $itemClass = [
                    'approved'  => 'is-approved',
                    'preparing' => 'is-preparing',
                    'ready'     => 'is-ready',
                    'cancelled' => 'is-cancelled',
                ][$it->status] ?? '';
            @endphp
            <div class="kb-item {{ $itemClass }}">
                <span class="kb-item-qty">×{{ $it->quantity }}</span>
                <div class="kb-item-body">
                    <div class="kb-item-name">{{ $it->name_snapshot }}</div>
                    @if($it->modifiers->count())
                        <div class="kb-item-mods">
                            @foreach($it->modifiers as $m)
                                <span>{{ $m->name_snapshot }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if(!empty($it->notes))
                        <div class="kb-item-note">
                            <i class="bi bi-chat-left-text-fill"></i>
                            {{ $it->notes }}
                        </div>
                    @endif
                </div>
                <div class="kb-item-actions">
                    @if($it->status === 'approved')
                        <button type="button" wire:click="startItem({{ $it->id }})"
                                wire:loading.attr="disabled" wire:target="startItem({{ $it->id }})"
                                class="kb-item-btn" title="ابدأ">
                            <span wire:loading.remove wire:target="startItem({{ $it->id }})"><i class="bi bi-play-fill"></i></span>
                            <span wire:loading wire:target="startItem({{ $it->id }})" class="spinner-border spinner-border-sm"></span>
                        </button>
                    @elseif($it->status === 'preparing')
                        <button type="button" wire:click="markReady({{ $it->id }})"
                                wire:loading.attr="disabled" wire:target="markReady({{ $it->id }})"
                                class="kb-item-btn kb-item-btn-success" title="جاهز">
                            <span wire:loading.remove wire:target="markReady({{ $it->id }})"><i class="bi bi-check2"></i></span>
                            <span wire:loading wire:target="markReady({{ $it->id }})" class="spinner-border spinner-border-sm"></span>
                        </button>
                    @elseif($it->status === 'ready')
                        <span class="kb-item-badge">✓</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if(!empty($notes))
        <div class="kb-card-note">
            <i class="bi bi-sticky-fill"></i>
            {{ $notes }}
        </div>
    @endif

    <footer class="kb-card-foot">
        @if($column === 'waiting')
            <button type="button" wire:click="startAllInOrder({{ $order->id }})"
                    wire:loading.attr="disabled" wire:target="startAllInOrder({{ $order->id }})"
                    class="kb-card-btn kb-card-btn-primary">
                <span wire:loading.remove wire:target="startAllInOrder({{ $order->id }})">
                    <i class="bi bi-play-fill"></i> ابدأ الكل
                </span>
                <span wire:loading wire:target="startAllInOrder({{ $order->id }})">
                    <span class="spinner-border spinner-border-sm"></span> جارٍ الحفظ…
                </span>
            </button>
        @elseif($column === 'cooking')
            <button type="button" wire:click="markAllReady({{ $order->id }})"
                    wire:loading.attr="disabled" wire:target="markAllReady({{ $order->id }})"
                    class="kb-card-btn kb-card-btn-success">
                <span wire:loading.remove wire:target="markAllReady({{ $order->id }})">
                    <i class="bi bi-check-circle-fill"></i> الكل جاهز
                </span>
                <span wire:loading wire:target="markAllReady({{ $order->id }})">
                    <span class="spinner-border spinner-border-sm"></span> جارٍ الحفظ…
                </span>
            </button>
        @elseif($column === 'ready')
            <div class="kb-card-ready-label">
                <i class="bi bi-bell-fill"></i>
                ينتظر النادل
            </div>
        @endif
    </footer>
</article>
