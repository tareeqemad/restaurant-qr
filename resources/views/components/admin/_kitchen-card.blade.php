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

        // Order-level ETA — set the moment status flipped to "preparing"
        // (OrderTimingService::stampPrepStart). Shown as a "due by" badge
        // so the chef sees the order's overall deadline, not just per-item.
        $orderEta = $order->estimated_ready_at;
        $orderOverdue = $orderEta && now()->greaterThan($orderEta);
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
            @if($orderEta && $column === 'cooking')
                <span class="kb-eta-chip {{ $orderOverdue ? 'is-overdue' : '' }}"
                      title="ينتهي تحضيره عند {{ $orderEta->format('H:i') }}">
                    <i class="bi bi-flag-fill"></i>
                    <strong>{{ $orderEta->format('H:i') }}</strong>
                </span>
            @endif
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

                // Per-item timing — drives the prep-time badge + delay
                // coloring on items that are actively being cooked.
                $prepTime    = (int) ($it->menuItem?->prep_time_minutes ?? 0);
                $elapsedMin  = null;
                $delayClass  = '';
                if ($it->status === 'preparing' && $it->prep_started_at) {
                    $elapsedMin = (int) max(0, round($it->prep_started_at->diffInMinutes(now())));
                    if ($prepTime > 0) {
                        $ratio = $elapsedMin / $prepTime;
                        $delayClass = match (true) {
                            $ratio >= 1.0 => 'kb-delay-red',      // over time
                            $ratio >= 0.8 => 'kb-delay-amber',    // close to deadline
                            default       => 'kb-delay-ok',
                        };
                    }
                }
            @endphp
            <div class="kb-item {{ $itemClass }} {{ $delayClass }}">
                <span class="kb-item-qty">×{{ $it->quantity }}</span>
                <div class="kb-item-body">
                    <div class="kb-item-name">
                        {{ $it->name_snapshot }}
                        @if($prepTime > 0)
                            {{-- Static prep_time tag — always shown so the
                                 chef knows the target before starting. --}}
                            <span class="kb-prep-tag" title="وقت التحضير المتوقع">
                                <i class="bi bi-clock"></i> {{ $prepTime }}د
                            </span>
                        @endif
                        @if($elapsedMin !== null)
                            {{-- Live elapsed — only appears while cooking.
                                 Colored amber/red by the wrapper class. --}}
                            <span class="kb-elapsed-tag" title="منقضي منذ بدء التحضير">
                                <i class="bi bi-hourglass-split"></i>
                                منقضي {{ $elapsedMin < 1 ? '<1' : $elapsedMin }}د
                            </span>
                        @endif
                    </div>
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

@once
@push('styles')
<style>
    /* ─── Per-item prep time + elapsed badges ─────────────────────── */
    .kb-prep-tag,
    .kb-elapsed-tag {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 1px 7px;
        border-radius: 99px;
        font-size: .68rem;
        font-weight: 700;
        margin-inline-start: 6px;
        white-space: nowrap;
        line-height: 1.4;
    }
    .kb-prep-tag {
        background: #f1f5f9;
        color: #475569;
    }
    .kb-prep-tag i { font-size: .65rem; }

    .kb-elapsed-tag {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #bbf7d0;
    }
    .kb-elapsed-tag i { font-size: .65rem; }

    /* Elapsed badge colors flip with the wrapping item state */
    .kb-item.kb-delay-amber .kb-elapsed-tag {
        background: #fef3c7;
        color: #92400e;
        border-color: #fcd34d;
    }
    .kb-item.kb-delay-red .kb-elapsed-tag {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fca5a5;
        animation: kbPulseRed 2s ease-in-out infinite;
    }
    .kb-item.kb-delay-red {
        background: rgba(254, 226, 226, .35);
        border-inline-start: 3px solid #dc2626;
    }
    .kb-item.kb-delay-amber {
        background: rgba(254, 243, 199, .25);
        border-inline-start: 3px solid #f59e0b;
    }
    @keyframes kbPulseRed {
        0%, 100% { opacity: 1; }
        50%      { opacity: .55; }
    }

    /* ─── Order-level ETA chip on the ribbon ──────────────────────── */
    .kb-eta-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        background: rgba(255, 255, 255, .9);
        color: #1e3a8a;
        border-radius: 99px;
        font-size: .7rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
        margin-inline-start: 6px;
    }
    .kb-eta-chip i { font-size: .65rem; color: #2563eb; }
    .kb-eta-chip.is-overdue {
        background: #fee2e2;
        color: #991b1b;
    }
    .kb-eta-chip.is-overdue i { color: #dc2626; }
</style>
@endpush
@endonce
