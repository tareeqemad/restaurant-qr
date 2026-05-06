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

    {{-- Actions --}}
    @if($order->isCustomerCancellable())
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
