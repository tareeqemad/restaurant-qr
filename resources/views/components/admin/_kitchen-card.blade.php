{{--
  Kitchen order card — DOMINANT table number design.
  The table number is a large colored "ribbon" at the top — visible from
  across the kitchen. Order number, items, and actions sit below.

  Variables: $order, $items, $age_min, $urgency, $notes, $column
--}}

<article class="kb-card kb-urg-{{ $urgency }} {{ $order->isExternal() ? 'kb-card--external' : 'kb-card--dinein' }}"
         @if($order->isExternal()) style="--source-color: {{ $order->sourceColor() }};" @endif>
    @php
        $isTableOrder = (bool) $order->table;
        $isDelivery   = $order->order_type === 'delivery';
        $isTakeaway   = $order->order_type === 'takeaway';

        // Big-ribbon text: table number for dine-in, channel verb for
        // external. Verb (تيكاوي / توصيل) reads from across the kitchen
        // faster than a 3-letter abbreviation — that's the whole point of
        // the ribbon.
        if ($isTableOrder) {
            $ribbonLabel = 'طاولة';
            $ribbonBig   = $order->table?->number;
        } elseif ($isDelivery) {
            $ribbonLabel = $order->sourceLabel();
            $ribbonBig   = 'توصيل';
        } else {
            $ribbonLabel = $order->sourceLabel();
            $ribbonBig   = 'تيكاوي';
        }

        $customerName = trim((string) ($order->customer_name ?: $order->customer?->name ?: ''));
        $customerPhone = trim((string) ($order->customer_phone ?: $order->customer?->phone ?: ''));
        $deliveryAddress = trim((string) ($order->delivery_address ?? ''));
        $scheduledFor = $order->scheduled_for;
        $externalRef = trim((string) ($order->external_reference ?? ''));

        // Order-level ETA — set the moment status flipped to "preparing"
        // (OrderTimingService::stampPrepStart). Shown as a "due by" badge
        // so the chef sees the order's overall deadline, not just per-item.
        $orderEta = $order->estimated_ready_at;
        $orderOverdue = $orderEta && now()->greaterThan($orderEta);
    @endphp
    {{-- Hero ribbon: table# for dine-in, channel verb (تيكاوي/توصيل) for
         external tickets. Source-coloured for non-dine-in so the chef can
         spot platform orders from across the line. --}}
    <div class="kb-table-ribbon">
        <div class="kb-table-ribbon-inner">
            @if($order->isExternal())
                <span class="kb-source-icon"><i class="bi {{ $order->sourceIcon() }}"></i></span>
            @endif
            <span class="kb-table-label">{{ $ribbonLabel }}</span>
            <span class="kb-table-big">{{ $ribbonBig ?? '—' }}</span>
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

    {{-- External-order info strip: customer + phone + (delivery address /
         scheduled time). Only renders for non-dine-in tickets so the
         dine-in path stays as visually clean as before. --}}
    @if($order->isExternal())
        <div class="kb-ext-info">
            @if($customerName)
                <span class="kb-ext-chip kb-ext-chip--name" title="اسم الزبون">
                    <i class="bi bi-person-fill"></i>
                    <strong>{{ $customerName }}</strong>
                </span>
            @endif
            @if($customerPhone)
                <span class="kb-ext-chip" title="رقم الهاتف">
                    <i class="bi bi-telephone-fill"></i>
                    <span dir="ltr">{{ $customerPhone }}</span>
                </span>
            @endif
            @if($scheduledFor)
                <span class="kb-ext-chip kb-ext-chip--sched" title="وقت محدد لاستلام/تسليم الطلب">
                    <i class="bi bi-alarm-fill"></i>
                    <strong>للساعة {{ $scheduledFor->format('H:i') }}</strong>
                </span>
            @endif
            @if($externalRef)
                <span class="kb-ext-chip kb-ext-chip--ref" title="مرجع التطبيق الخارجي">
                    <i class="bi bi-hash"></i>
                    <span dir="ltr">{{ $externalRef }}</span>
                </span>
            @endif
            @if($isDelivery && $deliveryAddress)
                <div class="kb-ext-address" title="{{ $deliveryAddress }}">
                    <i class="bi bi-geo-alt-fill"></i>
                    <span>{{ \Illuminate\Support\Str::limit($deliveryAddress, 90) }}</span>
                </div>
            @endif
        </div>
    @endif

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

                    {{-- Chef-side cancel: two paths.
                         "لم يبدأ" = return ingredients to stock.
                         "بدأ التحضير" = log them as waste (food can't
                         be reused, but still need to reset the ticket). --}}
                    @if(in_array($it->status, ['approved', 'preparing']))
                        <div class="kb-item-cancel" x-data="{ open: false }">
                            <button type="button" class="kb-item-btn kb-item-btn-cancel"
                                    @click="open = !open" title="إلغاء الصنف">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <div class="kb-cancel-menu" x-show="open" x-cloak @click.outside="open = false">
                                <div class="kb-cancel-title">إلغاء الصنف؟</div>
                                <button type="button" class="kb-cancel-opt kb-cancel-opt--return"
                                        wire:click="cancelItemFromKds({{ $it->id }}, 'return', 'إلغاء من المطبخ — لم يبدأ التحضير')"
                                        @click="open = false">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    <div>
                                        <strong>لم يبدأ التحضير</strong>
                                        <small>إرجاع المكوّنات للمخزون</small>
                                    </div>
                                </button>
                                <button type="button" class="kb-cancel-opt kb-cancel-opt--waste"
                                        wire:click="cancelItemFromKds({{ $it->id }}, 'waste', 'إلغاء من المطبخ — بدأ التحضير')"
                                        @click="open = false">
                                    <i class="bi bi-trash3"></i>
                                    <div>
                                        <strong>بدأ التحضير</strong>
                                        <small>تسجيل المكوّنات كهدر</small>
                                    </div>
                                </button>
                            </div>
                        </div>
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

    /* ─── External-order visual differentiation ───────────────────────
       The chef must spot a takeaway/delivery ticket without reading.
       Three signals combine:
         1. Source-coloured top ribbon (via --source-color)
         2. Source channel icon next to the ribbon label
         3. A dedicated info strip with customer + (address|scheduled)
       Dine-in tickets stay visually identical to the v3 baseline. */
    .kb-card--external .kb-table-ribbon {
        background: linear-gradient(135deg,
            color-mix(in srgb, var(--source-color) 100%, transparent) 0%,
            color-mix(in srgb, var(--source-color) 80%, #000) 100%) !important;
    }
    .kb-card--external {
        border-inline-start: 4px solid var(--source-color, #b97818);
    }
    .kb-source-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px; height: 26px;
        background: rgba(255, 255, 255, .22);
        border: 1px solid rgba(255, 255, 255, .3);
        border-radius: 7px;
        color: #fff;
        font-size: .85rem;
        margin-inline-end: .35rem;
    }

    .kb-ext-info {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        padding: .55rem .7rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        align-items: center;
    }
    .kb-ext-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 99px;
        font-size: .78rem;
        font-weight: 600;
        color: #334155;
        line-height: 1.3;
    }
    .kb-ext-chip i { font-size: .72rem; color: #64748b; }
    .kb-ext-chip strong { font-weight: 800; color: #0f172a; }

    .kb-ext-chip--name {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #3730a3;
    }
    .kb-ext-chip--name i { color: #4f46e5; }
    .kb-ext-chip--name strong { color: #312e81; }

    .kb-ext-chip--sched {
        background: #fef3c7;
        border-color: #fcd34d;
        color: #92400e;
    }
    .kb-ext-chip--sched i { color: #d97706; }
    .kb-ext-chip--sched strong { color: #78350f; }

    .kb-ext-chip--ref {
        background: #f1f5f9;
        font-size: .72rem;
        color: #64748b;
    }
    .kb-ext-chip--ref i { color: #94a3b8; }

    .kb-ext-address {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        flex-basis: 100%;
        padding: 5px 9px;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        border-radius: 8px;
        font-size: .8rem;
        color: #7c2d12;
        line-height: 1.45;
    }
    .kb-ext-address i {
        color: #ea580c;
        font-size: .85rem;
        flex: 0 0 auto;
        margin-top: 1px;
    }

    /* ─── Chef-side cancel button + popover ──────────────────────────
       Two-path picker: return (kitchen never touched it) vs waste
       (chef started prep). The popover anchors to the cancel button
       and stays compact so it doesn't crowd the row on narrow KDS
       screens. */
    .kb-item-cancel { position: relative; }
    .kb-item-btn-cancel {
        background: rgba(239, 68, 68, .08) !important;
        color: #b91c1c !important;
        border: 1px solid rgba(239, 68, 68, .25) !important;
    }
    .kb-item-btn-cancel:hover {
        background: rgba(239, 68, 68, .15) !important;
    }
    .kb-cancel-menu {
        position: absolute;
        top: calc(100% + 6px);
        inset-inline-end: 0;
        z-index: 50;
        min-width: 230px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .15);
        padding: .5rem;
    }
    .kb-cancel-title {
        font-weight: 800;
        font-size: .78rem;
        color: #475569;
        padding: 4px 8px 6px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 4px;
    }
    .kb-cancel-opt {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        padding: 8px 10px;
        background: transparent;
        border: none;
        text-align: start;
        border-radius: 6px;
        cursor: pointer;
        font-family: inherit;
    }
    .kb-cancel-opt:hover { background: #f8fafc; }
    .kb-cancel-opt i { font-size: 1.1rem; }
    .kb-cancel-opt strong { display: block; font-size: .85rem; color: #0f172a; }
    .kb-cancel-opt small { display: block; font-size: .7rem; color: #64748b; line-height: 1.2; }
    .kb-cancel-opt--return i { color: #15803d; }
    .kb-cancel-opt--waste  i { color: #b91c1c; }
</style>
@endpush
@endonce
