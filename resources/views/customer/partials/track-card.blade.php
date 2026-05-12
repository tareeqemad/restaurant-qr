@php
    // Map status to 3-step progress index
    $stepIndex = match($order->status) {
        'pending' => 0,
        'approved', 'preparing' => 1,
        'ready', 'delivered', 'completed' => 2,
        'cancelled' => -1,
        default => 0,
    };
    $steps = [
        ['label' => 'تم الإرسال'],
        ['label' => 'قيد التحضير'],
        ['label' => 'جاهز للتقديم'],
    ];
    $fillPct = $stepIndex <= 0 ? 0 : ($stepIndex / 2) * 84;  // 84% accounts for padding

    // Pick a "title" for the order: first non-cancelled item name, or "طلبك"
    $title = optional($order->items->firstWhere('status', '!=', 'cancelled'))->name_snapshot
        ?? $order->items->first()?->name_snapshot
        ?? 'طلبك';
    if ($order->items->count() > 1) $title .= ' + ' . ($order->items->count() - 1);
@endphp

<div class="track-card">
    <div class="track-head">
        <h3 class="track-title">{{ $title }}</h3>
        <div class="track-number">{{ $order->number }}</div>
        <div class="track-meta">
            <i class="bi bi-clock"></i> {{ $order->created_at->diffForHumans() }}
            @if($order->items->count() > 0)
                · {{ $order->items->count() }} صنف
            @endif
        </div>
    </div>

    @if($order->status === 'cancelled')
        <div class="track-cancelled">
            <i class="bi bi-x-circle fs-4"></i>
            <div>تم إلغاء الطلب</div>
            @if($order->cancelled_reason)
                <small class="fw-normal mt-1 d-block opacity-75">{{ $order->cancelled_reason }}</small>
            @endif
        </div>
    @elseif($order->status === 'completed')
        <div class="track-cancelled" style="background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand);">
            <i class="bi bi-check-circle-fill fs-4"></i>
            <div>طلب مكتمل · تم الدفع</div>
        </div>
    @else
        {{-- 3-step progress tracker --}}
        <div class="stepper">
            <div class="stepper-bg"></div>
            <div class="stepper-fill" style="width: {{ $fillPct }}%;"></div>
            @foreach($steps as $i => $step)
                <div class="step {{ $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'current' : '') }}">
                    <div class="step-circle"><span>{{ $i + 1 }}</span></div>
                    <div class="step-label">{{ $step['label'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Live countdown: kicks in only while the order is `preparing`.
             OrderTimingService stamped `estimated_ready_at` on the moment
             status flipped to preparing; we ship that as an ISO timestamp
             to a tiny JS ticker. When time runs out the badge swaps to
             "جاهز قريباً" instead of negative numbers (less anxiety). --}}
        @if($order->status === 'preparing' && $order->estimated_ready_at)
            @php
                $etaIso       = $order->estimated_ready_at->toIso8601String();
                $etaUnix      = $order->estimated_ready_at->getTimestamp();
                $nowUnix      = now()->getTimestamp();
                $initialSecs  = max(0, $etaUnix - $nowUnix);
                $initialMins  = (int) ceil($initialSecs / 60);
            @endphp
            <div class="track-eta"
                 data-track-eta="{{ $etaIso }}"
                 data-eta-unix="{{ $etaUnix }}">
                <div class="track-eta__icon">
                    <i class="bi bi-stopwatch-fill"></i>
                </div>
                <div class="track-eta__body">
                    <div class="track-eta__label">الوقت المتبقي للتجهيز</div>
                    <div class="track-eta__time" data-track-eta-display>
                        @if($initialSecs > 0)
                            <span class="track-eta__num">{{ $initialMins }}</span>
                            <span class="track-eta__unit">دقيقة تقريباً</span>
                        @else
                            <span class="track-eta__soon">جاهز قريباً…</span>
                        @endif
                    </div>
                </div>
            </div>
        @elseif($order->status === 'ready')
            <div class="track-eta track-eta--ready">
                <div class="track-eta__icon">
                    <i class="bi bi-bag-check-fill"></i>
                </div>
                <div class="track-eta__body">
                    <div class="track-eta__label">طلبك جاهز!</div>
                    <div class="track-eta__time">
                        <span class="track-eta__soon">يُسلَّم لك حالاً</span>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- Items --}}
    <div class="track-items">
        @foreach($order->items as $it)
            <div class="track-item {{ $it->status }}">
                <div class="track-item-left">
                    <div class="track-item-name">{{ $it->name_snapshot }}</div>
                    @if($it->modifiers->count())
                        <div class="track-item-mods">{{ $it->modifiers->pluck('name_snapshot')->join('، ') }}</div>
                    @endif
                    @if($it->notes)
                        <div class="track-item-notes">📝 {{ $it->notes }}</div>
                    @endif
                    @if($it->status === 'cancelled' && $it->cancelled_reason)
                        <div class="track-item-cancel-reason">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>سبب الإلغاء: {{ $it->cancelled_reason }}</span>
                        </div>
                    @endif
                </div>
                <div class="track-item-right">
                    <div class="track-item-qty">×{{ (int) $it->quantity }}</div>
                    <div class="track-item-price">{{ \App\Helpers\Money::format($it->subtotal) }}</div>
                    <div class="mt-1">
                        <span class="track-item-badge badge-{{ $it->status }}">
                            @switch($it->status)
                                @case('pending') بانتظار @break
                                @case('approved') معتمد @break
                                @case('preparing') <i class="bi bi-fire"></i> تحضير @break
                                @case('ready') <i class="bi bi-bag-check"></i> جاهز @break
                                @case('served') قُدّم @break
                                @case('cancelled') ملغى @break
                            @endswitch
                        </span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Summary --}}
    <div class="track-summary">
        <span class="label">الإجمالي</span>
        <span class="amount">{{ \App\Helpers\Money::format($order->total) }}</span>
    </div>

    {{-- Live countdown JS + styles — pushed once for the whole page even
         if there are multiple cards (the ticker walks every [data-track-eta]
         element on each tick). --}}
    @once
        @push('styles')
        <style>
            .track-eta {
                display: flex;
                align-items: center;
                gap: 14px;
                padding: 12px 16px;
                margin: 14px 0;
                background: linear-gradient(135deg, #fff7ed, #ffedd5);
                border: 1px solid #fdba74;
                border-radius: 14px;
                color: #7c2d12;
            }
            .track-eta--ready {
                background: linear-gradient(135deg, #ecfdf5, #d1fae5);
                border-color: #6ee7b7;
                color: #065f46;
            }
            .track-eta__icon {
                width: 44px; height: 44px;
                flex-shrink: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, .7);
                border-radius: 12px;
                font-size: 1.35rem;
            }
            .track-eta--ready .track-eta__icon { color: #16a34a; }
            .track-eta__body { flex: 1; min-width: 0; }
            .track-eta__label {
                font-size: .78rem;
                font-weight: 600;
                opacity: .85;
                margin-bottom: 2px;
            }
            .track-eta__time {
                display: inline-flex;
                align-items: baseline;
                gap: 6px;
            }
            .track-eta__num {
                font-size: 1.8rem;
                font-weight: 900;
                font-variant-numeric: tabular-nums;
                line-height: 1;
            }
            .track-eta__unit { font-size: .8rem; font-weight: 600; opacity: .85; }
            .track-eta__soon {
                font-size: 1.05rem;
                font-weight: 800;
                animation: trackEtaPulse 1.6s ease-in-out infinite;
            }
            @keyframes trackEtaPulse {
                0%, 100% { opacity: 1; }
                50%      { opacity: .55; }
            }
        </style>
        @endpush

        @push('scripts')
        <script>
            // Live ETA ticker — updates the "X minutes remaining" badge
            // every 5s. Five seconds is fine: we display minutes, not
            // seconds, so finer ticks waste CPU without visible effect.
            // When time runs out the badge swaps to the "جاهز قريباً..."
            // pulse — no negative numbers / "متأخر" anxiety for the
            // customer. The Livewire poll on the parent component
            // refreshes the actual order data every 5s independently.
            (function () {
                function tickEta() {
                    const now = Math.floor(Date.now() / 1000);
                    document.querySelectorAll('[data-track-eta]').forEach(el => {
                        const target  = parseInt(el.dataset.etaUnix || '0', 10);
                        const display = el.querySelector('[data-track-eta-display]');
                        if (! display || ! target) return;
                        const remaining = target - now;
                        if (remaining <= 0) {
                            display.innerHTML = '<span class="track-eta__soon">جاهز قريباً…</span>';
                        } else {
                            const minutes = Math.max(1, Math.ceil(remaining / 60));
                            display.innerHTML =
                                '<span class="track-eta__num">' + minutes + '</span>' +
                                '<span class="track-eta__unit">دقيقة تقريباً</span>';
                        }
                    });
                }
                tickEta();
                setInterval(tickEta, 5000);
                // Also re-tick after Livewire updates the DOM (poll refresh).
                document.addEventListener('livewire:navigated', tickEta);
                if (window.Livewire) {
                    window.Livewire.hook('morph.added', tickEta);
                }
            })();
        </script>
        @endpush
    @endonce

    {{-- Actions --}}
    @if($order->canCancelEntireOrder())
        <div class="track-actions">
            <button type="button" class="btn-track-cancel" data-bs-toggle="modal" data-bs-target="#cancel{{ $order->id }}">
                <i class="bi bi-x-circle"></i>
                إلغاء الطلب
            </button>
        </div>

        {{-- Cancel modal --}}
        <div class="modal fade" id="cancel{{ $order->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius: 20px; border: 0;">
                    <form action="{{ route('customer.orders.cancel', $order) }}" method="POST">@csrf
                        <div class="modal-header" style="border: 0;">
                            <h5 class="modal-title fw-bold">إلغاء {{ $order->number }}</h5>
                            <button class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>هل أنت متأكد من إلغاء هذا الطلب؟</p>
                            <label class="form-label fw-bold">سبب الإلغاء (اختياري)</label>
                            <textarea name="reason" class="form-control" rows="2" placeholder="مثلاً: غيّرت رأيي"></textarea>
                        </div>
                        <div class="modal-footer" style="border: 0;">
                            <button type="button" class="btn btn-light fw-bold" data-bs-dismiss="modal">تراجع</button>
                            <button class="btn-track-cancel" style="width: auto; padding: 8px 20px;">تأكيد الإلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
