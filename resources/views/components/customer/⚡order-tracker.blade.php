<?php

use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Customer order tracker (Livewire).
 *
 * Shows all orders belonging to the current table session and refreshes
 * itself via `wire:poll.visible.5s` — same pattern used by the kitchen,
 * bar, cashier and tables boards. The customer sees status changes
 * (pending → preparing → ready) within ~5 s of the kitchen marking them,
 * with no page refresh and no dependency on a websocket server.
 *
 * `visible` modifier pauses polling when the tab is backgrounded, so the
 * phone in the customer's pocket doesn't keep hitting the server. Once all
 * orders are delivered/cancelled the page doesn't need polling anymore,
 * but we keep it on — the owner can reopen a session and the board will
 * pick up changes automatically. Cost per poll ≈ 1 small SQL query + a
 * Livewire DOM diff. Negligible.
 */
new class extends Component
{
    public int $sessionId;

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    #[Computed]
    public function orders()
    {
        return Order::with(['items.modifiers', 'items.station'])
            ->where('table_session_id', $this->sessionId)
            ->latest()
            ->get();
    }

    /** Called by wire:poll to clear the computed cache so the next render
     *  re-queries the DB. No-op if nothing has changed on the server side —
     *  Livewire will send an empty DOM diff. */
    public function refreshFromBroadcast(): void
    {
        unset($this->orders);
    }
}
?>

<div class="track-board" wire:poll.visible.5s="refreshFromBroadcast">
    @php
        $orders   = $this->orders;
        $active   = $orders->whereNotIn('status', ['cancelled', 'completed']);
        $finished = $orders->whereIn('status', ['cancelled', 'completed']);
    @endphp

    @if($orders->isEmpty())
        <div class="track-empty">
            <i class="bi bi-inbox"></i>
            <h5 class="mt-3 fw-bold" style="color: var(--brand-dark);">لا توجد طلبات بعد</h5>
            <p class="text-muted">ابدأ بتصفّح القائمة وإضافة ما يعجبك</p>
            <a href="{{ route('customer.menu') }}" class="btn-track-primary d-inline-flex mt-3" style="width:auto; padding:12px 28px;">
                <i class="bi bi-list-ul"></i> تصفّح القائمة
            </a>
        </div>
    @else
        {{-- Grand total if multiple active orders --}}
        @if($active->count() > 1)
            <div class="grand-bar">
                <div>
                    <div class="lbl">إجمالي طلباتك</div>
                    <div class="amt">{{ \App\Helpers\Money::format($active->sum('total')) }}</div>
                </div>
                <div class="text-end">
                    <div class="lbl">عدد الطلبات</div>
                    <div class="amt">{{ $active->count() }}</div>
                </div>
            </div>
        @endif

        @foreach($active as $order)
            @include('customer.partials.track-card', ['order' => $order])
        @endforeach

        @if($active->count())
            <a href="{{ route('customer.menu') }}" class="btn-track-primary"
               style="background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%); box-shadow: 0 8px 24px rgba(184,135,42,.4); color: var(--brand-dark);">
                <i class="bi bi-plus-circle-fill"></i> إضافة المزيد من الأصناف
            </a>
        @endif

        @if($finished->count())
            <details class="mt-3">
                <summary class="completed-toggle">
                    <i class="bi bi-clock-history"></i>
                    طلبات سابقة ({{ $finished->count() }})
                </summary>
                <div class="mt-2">
                    @foreach($finished as $order)
                        @include('customer.partials.track-card', ['order' => $order])
                    @endforeach
                </div>
            </details>
        @endif
    @endif
</div>
