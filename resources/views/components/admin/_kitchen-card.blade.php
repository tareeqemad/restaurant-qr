{{--
  Kitchen order card — DOMINANT table number design.
  The table number is a large colored "ribbon" at the top — visible from
  across the kitchen. Order number, items, and actions sit below.

  Variables: $order, $items, $age_min, $urgency, $notes, $column
--}}

<article class="kb-card kb-urg-{{ $urgency }}">
    {{-- Hero table ribbon --}}
    <div class="kb-table-ribbon">
        <div class="kb-table-ribbon-inner">
            <span class="kb-table-label">طاولة</span>
            <span class="kb-table-big">{{ $order->table?->number ?? '—' }}</span>
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
                        <button type="button" wire:click="startItem({{ $it->id }})" class="kb-item-btn" title="ابدأ">
                            <i class="bi bi-play-fill"></i>
                        </button>
                    @elseif($it->status === 'preparing')
                        <button type="button" wire:click="markReady({{ $it->id }})" class="kb-item-btn kb-item-btn-success" title="جاهز">
                            <i class="bi bi-check2"></i>
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
            <button type="button" wire:click="startAllInOrder({{ $order->id }})" class="kb-card-btn kb-card-btn-primary">
                <i class="bi bi-play-fill"></i>
                ابدأ الكل
            </button>
        @elseif($column === 'cooking')
            <button type="button" wire:click="markAllReady({{ $order->id }})" class="kb-card-btn kb-card-btn-success">
                <i class="bi bi-check-circle-fill"></i>
                الكل جاهز
            </button>
        @elseif($column === 'ready')
            <div class="kb-card-ready-label">
                <i class="bi bi-bell-fill"></i>
                ينتظر النادل
            </div>
        @endif
    </footer>
</article>
