<?php

use App\Models\Invoice;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\RefundService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cashier Dashboard (Livewire)
 *
 * A single-screen cashier experience:
 *   left side  = searchable list of active table sessions
 *   right side = selected session's orders, invoice, payment keypad, history
 *
 * All actions (issue invoice / record payment / refund) run without a full
 * page reload. Broadcasted updates from other cashiers auto-refresh the view.
 */
new class extends Component
{
    public string $search = '';
    public ?int $selectedSessionId = null;

    // Payment form state
    public string $paymentAmount = '';
    public string $paymentMethod = 'cash';
    public string $paymentReference = '';
    public string $paymentNotes = '';

    // Refund form state
    public bool $refundOpen = false;
    public string $refundAmount = '';
    public string $refundMethod = 'cash';
    public string $refundReason = '';

    public function mount(?int $session = null): void
    {
        $this->selectedSessionId = $session;
    }

    // ─── Computed (reactive) ──────────────────────────────────────────

    #[Computed]
    public function sessions()
    {
        $query = TableSession::with(['table', 'orders', 'invoice'])
            ->where('status', 'active')
            ->orderByDesc('last_activity_at');

        if (strlen(trim($this->search)) > 0) {
            $s = trim($this->search);
            $query->whereHas('table', fn($q) => $q->where('number', 'like', "%{$s}%"))
                  ->orWhere('customer_name', 'like', "%{$s}%");
        }

        return $query->get();
    }

    #[Computed]
    public function selectedSession(): ?TableSession
    {
        if (!$this->selectedSessionId) return null;
        return TableSession::with([
            'table', 'orders.items.modifiers',
            'invoice.payments', 'invoice.refunds.processor',
        ])->find($this->selectedSessionId);
    }

    #[Computed]
    public function todayStats(): array
    {
        return [
            'invoices' => Invoice::whereDate('created_at', today())->count(),
            'revenue'  => (float) Invoice::whereDate('created_at', today())
                                ->where('status', 'paid')->sum('paid_total'),
            'cash'     => (float) \App\Models\Payment::whereDate('created_at', today())
                                ->where('method', 'cash')->sum('amount'),
            'card'     => (float) \App\Models\Payment::whereDate('created_at', today())
                                ->where('method', 'card')->sum('amount'),
        ];
    }

    // ─── Actions ──────────────────────────────────────────────────────

    public function selectSession(int $id): void
    {
        $this->selectedSessionId = $id;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen', 'refundAmount', 'refundReason']);
        $this->paymentMethod = 'cash';

        // Pre-fill amount with balance on the next render
        $session = $this->selectedSession;
        if ($session?->invoice && $session->invoice->balance > 0) {
            $this->paymentAmount = (string) number_format((float) $session->invoice->balance, 2, '.', '');
        }
    }

    public function clearSelection(): void
    {
        $this->selectedSessionId = null;
        $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes', 'refundOpen']);
    }

    public function setAmount(string|float $amount): void
    {
        $this->paymentAmount = (string) $amount;
    }

    public function setMethod(string $method): void
    {
        $this->paymentMethod = $method;
    }

    public function issueInvoice(BillingService $billing): void
    {
        $session = $this->selectedSession;
        if (!$session || $session->invoice) return;

        try {
            $billing->issueInvoice($session, auth()->id());
            $this->dispatch('toast', type: 'success', message: 'تم إصدار الفاتورة');
            unset($this->selectedSession);   // bust cache
            $session = $this->selectedSession;
            if ($session?->invoice) {
                $this->paymentAmount = (string) number_format((float) $session->invoice->balance, 2, '.', '');
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function recordPayment(BillingService $billing): void
    {
        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentMethod' => ['required', 'in:cash,card,transfer,app,credit'],
        ], attributes: [
            'paymentAmount' => 'قيمة الدفعة',
            'paymentMethod' => 'طريقة الدفع',
        ]);

        $session = $this->selectedSession;
        if (!$session?->invoice) {
            $this->dispatch('toast', type: 'error', message: 'لم تُصدر الفاتورة بعد');
            return;
        }

        try {
            $billing->addPayment(
                $session->invoice,
                (float) $this->paymentAmount,
                $this->paymentMethod,
                auth()->id(),
                $this->paymentReference ?: null,
                $this->paymentNotes ?: null,
            );
            $this->dispatch('toast', type: 'success', message: "تم تسجيل دفعة بقيمة {$this->paymentAmount}");
            $this->reset(['paymentAmount', 'paymentReference', 'paymentNotes']);
            unset($this->selectedSession);

            // Pre-fill with new balance (in case of partial payment, customer may pay again)
            $session = $this->selectedSession;
            if ($session?->invoice && (float) $session->invoice->balance > 0) {
                $this->paymentAmount = (string) number_format((float) $session->invoice->balance, 2, '.', '');
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function openRefund(): void
    {
        $session = $this->selectedSession;
        if (!$session?->invoice) return;

        $this->refundOpen = true;
        $refundable = max(0, (float) $session->invoice->paid_total - (float) ($session->invoice->refunded_total ?? 0));
        $this->refundAmount = (string) number_format($refundable, 2, '.', '');
        $this->refundMethod = 'cash';
        $this->refundReason = '';
    }

    public function closeRefund(): void
    {
        $this->refundOpen = false;
        $this->reset(['refundAmount', 'refundMethod', 'refundReason']);
    }

    public function submitRefund(RefundService $refundService): void
    {
        $this->validate([
            'refundAmount' => ['required', 'numeric', 'min:0.01'],
            'refundMethod' => ['required', 'in:cash,card,transfer,app,credit,other'],
            'refundReason' => ['required', 'string', 'max:500'],
        ], attributes: [
            'refundAmount' => 'المبلغ',
            'refundMethod' => 'طريقة الاسترداد',
            'refundReason' => 'السبب',
        ]);

        $session = $this->selectedSession;
        if (!$session?->invoice) return;

        try {
            $refundService->issue(
                $session->invoice,
                (float) $this->refundAmount,
                $this->refundMethod,
                $this->refundReason,
                auth()->id(),
            );
            $this->dispatch('toast', type: 'success', message: "تم تسجيل استرداد {$this->refundAmount}");
            $this->closeRefund();
            unset($this->selectedSession);
        } catch (\Throwable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    #[On('order.created')]
    #[On('order.status_changed')]
    #[On('invoice.paid')]
    public function refreshFromBroadcast(): void
    {
        // Computed properties auto-refresh on re-render; this hook just forces it.
        unset($this->sessions, $this->selectedSession);
    }
}
?>

<div class="cashier-dashboard" wire:poll.15s="refreshFromBroadcast">
    {{-- ═════════════════ Today KPI rail ═════════════════ --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-primary">
                <div class="cx-kpi-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <div>
                    <div class="cx-kpi-label">فواتير اليوم</div>
                    <div class="cx-kpi-value">{{ $this->todayStats['invoices'] }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-success">
                <div class="cx-kpi-icon"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <div class="cx-kpi-label">إيراد اليوم</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['revenue']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-accent">
                <div class="cx-kpi-icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <div class="cx-kpi-label">نقدي</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['cash']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="cx-kpi cx-kpi-info">
                <div class="cx-kpi-icon"><i class="bi bi-credit-card"></i></div>
                <div>
                    <div class="cx-kpi-label">بطاقات</div>
                    <div class="cx-kpi-value">{{ \App\Helpers\Money::format($this->todayStats['card']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="cx-grid">
        {{-- ═════════════════ LEFT: Sessions list ═════════════════ --}}
        <aside class="cx-sessions">
            <div class="cx-sessions-head">
                <h3 class="cx-title">
                    <i class="bi bi-people-fill"></i>
                    الطاولات النشطة
                    <span class="cx-chip">{{ $this->sessions->count() }}</span>
                </h3>
            </div>

            <div class="cx-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text"
                    wire:model.live.debounce.250ms="search"
                    placeholder="ابحث برقم الطاولة أو اسم الزبون..."
                    class="cx-search">
                @if($search)
                    <button type="button" wire:click="$set('search', '')" class="cx-search-clear">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </div>

            <div class="cx-sessions-list">
                @forelse($this->sessions as $s)
                    @php
                        $invoice = $s->invoice;
                        $ordersTotal = $s->orders->sum('total');
                        $openMin = (int) $s->opened_at->diffInMinutes(now());
                    @endphp
                    <button type="button"
                        wire:click="selectSession({{ $s->id }})"
                        class="cx-session-card {{ $selectedSessionId === $s->id ? 'is-active' : '' }} {{ $invoice ? 'has-invoice' : '' }}">
                        <div class="cx-session-head">
                            <div class="cx-table-num">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                {{ $s->table?->number ?? '—' }}
                            </div>
                            @if($invoice)
                                @if($invoice->balance > 0)
                                    <span class="cx-status-pill cx-status-due">
                                        {{ \App\Helpers\Money::format($invoice->balance) }} متبقي
                                    </span>
                                @else
                                    <span class="cx-status-pill cx-status-paid">
                                        <i class="bi bi-check-circle-fill"></i> مدفوعة
                                    </span>
                                @endif
                            @else
                                <span class="cx-status-pill cx-status-open">مفتوحة</span>
                            @endif
                        </div>
                        <div class="cx-session-meta">
                            <span><i class="bi bi-receipt"></i> {{ $s->orders->count() }} طلب</span>
                            <span>
                                <i class="bi bi-people"></i> {{ $s->cover_count ?? 1 }}
                            </span>
                            <span><i class="bi bi-clock"></i> {{ $openMin }} د</span>
                        </div>
                        <div class="cx-session-total">
                            {{ \App\Helpers\Money::format($ordersTotal) }}
                        </div>
                    </button>
                @empty
                    <div class="cx-empty">
                        <i class="bi bi-people"></i>
                        <div>ما في طاولات نشطة الآن</div>
                    </div>
                @endforelse
            </div>
        </aside>

        {{-- ═════════════════ RIGHT: Session detail ═════════════════ --}}
        <main class="cx-detail">
            @php $session = $this->selectedSession; @endphp

            @if(!$session)
                <div class="cx-placeholder">
                    <i class="bi bi-cursor-fill"></i>
                    <h4>اختر طاولة من القائمة</h4>
                    <p>اضغط على أي طاولة نشطة لبدء إدارة فاتورتها.</p>
                </div>
            @else
                @php $invoice = $session->invoice; @endphp

                {{-- Detail header --}}
                <div class="cx-detail-head">
                    <div class="d-flex align-items-center gap-3">
                        <div class="cx-detail-table">
                            <i class="bi bi-grid-3x3-gap-fill"></i>
                            <span>طاولة {{ $session->table?->number }}</span>
                        </div>
                        <div class="text-muted small">
                            {{ $session->cover_count ?? 1 }} أشخاص · منذ {{ $session->opened_at->diffForHumans() }}
                            @if($session->customer_name)
                                · <strong>{{ $session->customer_name }}</strong>
                            @endif
                        </div>
                    </div>
                    <button wire:click="clearSelection" class="btn btn-sm btn-light">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="row g-3">
                    {{-- Left column: orders list --}}
                    <div class="col-xl-7">
                        <div class="cx-section">
                            <div class="cx-section-head">
                                <strong><i class="bi bi-list-ul"></i> الطلبات ({{ $session->orders->count() }})</strong>
                            </div>
                            <div class="cx-orders">
                                @foreach($session->orders as $order)
                                    <div class="cx-order">
                                        <div class="cx-order-head">
                                            <strong>{{ $order->number }}</strong>
                                            <span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span>
                                        </div>
                                        @foreach($order->items as $it)
                                            <div class="cx-item {{ $it->status==='cancelled' ? 'is-cancelled' : '' }}">
                                                <span class="cx-item-qty">×{{ $it->quantity }}</span>
                                                <span class="cx-item-name">{{ $it->name_snapshot }}</span>
                                                @if($it->modifiers->count())
                                                    <small class="text-muted d-block w-100 ps-4">{{ $it->modifiers->pluck('name_snapshot')->join(' · ') }}</small>
                                                @endif
                                                <span class="cx-item-price">{{ \App\Helpers\Money::format($it->subtotal) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Right column: invoice + payment --}}
                    <div class="col-xl-5">
                        @if(!$invoice)
                            {{-- No invoice yet → issue button --}}
                            <div class="cx-section">
                                <div class="cx-totals-preview">
                                    @php
                                        $sub = $session->orders->sum('subtotal');
                                        $tax = $session->orders->sum('tax_total');
                                        $svc = $session->orders->sum('service_total');
                                        $tot = $session->orders->sum('total');
                                    @endphp
                                    <div class="d-flex justify-content-between py-1"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($sub) }}</strong></div>
                                    @if($tax > 0)<div class="d-flex justify-content-between py-1"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($tax) }}</strong></div>@endif
                                    @if($svc > 0)<div class="d-flex justify-content-between py-1"><span>الخدمة</span><strong>{{ \App\Helpers\Money::format($svc) }}</strong></div>@endif
                                    <hr>
                                    <div class="d-flex justify-content-between py-1 cx-grand">
                                        <span>الإجمالي</span>
                                        <strong>{{ \App\Helpers\Money::format($tot) }}</strong>
                                    </div>
                                </div>
                                <button wire:click="issueInvoice" wire:loading.attr="disabled" class="cx-btn-lg cx-btn-primary">
                                    <i class="bi bi-receipt"></i>
                                    <span wire:loading.remove>إصدار الفاتورة</span>
                                    <span wire:loading>جارٍ...</span>
                                </button>
                            </div>
                        @else
                            {{-- Invoice totals + payment --}}
                            <div class="cx-section">
                                <div class="cx-invoice-head">
                                    <div>
                                        <small class="text-muted">رقم الفاتورة</small>
                                        <div class="fw-bold" style="font-family: 'Courier New', monospace;">{{ $invoice->number }}</div>
                                    </div>
                                    <span class="badge bg-{{ $invoice->balance > 0 ? 'warning' : 'success' }}">
                                        {{ match($invoice->status) {
                                            'paid'           => 'مدفوعة بالكامل',
                                            'partially_paid' => 'مدفوعة جزئياً',
                                            'issued'         => 'صادرة',
                                            'cancelled'      => 'ملغاة',
                                            'unpaid_writeoff'=> 'شطب',
                                            default          => $invoice->status,
                                        } }}
                                    </span>
                                </div>

                                <div class="cx-totals">
                                    <div class="d-flex justify-content-between"><span>الفرعي</span><strong>{{ \App\Helpers\Money::format($invoice->subtotal) }}</strong></div>
                                    @if($invoice->discount_total > 0)
                                        <div class="d-flex justify-content-between text-success"><span>الخصم</span><strong>−{{ \App\Helpers\Money::format($invoice->discount_total) }}</strong></div>
                                    @endif
                                    @if($invoice->tax_total > 0)
                                        <div class="d-flex justify-content-between"><span>الضريبة</span><strong>{{ \App\Helpers\Money::format($invoice->tax_total) }}</strong></div>
                                    @endif
                                    @if($invoice->service_total > 0)
                                        <div class="d-flex justify-content-between"><span>الخدمة</span><strong>{{ \App\Helpers\Money::format($invoice->service_total) }}</strong></div>
                                    @endif
                                    <div class="cx-grand d-flex justify-content-between mt-2">
                                        <span>الإجمالي</span>
                                        <strong>{{ \App\Helpers\Money::format($invoice->total) }}</strong>
                                    </div>
                                    <div class="d-flex justify-content-between text-success mt-2">
                                        <span>مدفوع</span><strong>{{ \App\Helpers\Money::format($invoice->paid_total) }}</strong>
                                    </div>
                                    @if(($invoice->refunded_total ?? 0) > 0)
                                        <div class="d-flex justify-content-between" style="color:#b91c1c;">
                                            <span>مسترد</span><strong>−{{ \App\Helpers\Money::format($invoice->refunded_total) }}</strong>
                                        </div>
                                    @endif
                                    @if($invoice->balance > 0)
                                        <div class="cx-balance d-flex justify-content-between mt-2">
                                            <span>المتبقي</span>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Payment keypad --}}
                            @if($invoice->balance > 0)
                                <div class="cx-section">
                                    <div class="cx-section-head"><strong><i class="bi bi-cash-stack"></i> تسجيل دفعة</strong></div>

                                    {{-- Quick amount presets --}}
                                    <div class="cx-presets">
                                        <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance, 2, '.', '') }}')" class="cx-preset cx-preset-primary">
                                            <small>المتبقي</small>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance) }}</strong>
                                        </button>
                                        <button type="button" wire:click="setAmount('{{ number_format((float) $invoice->balance / 2, 2, '.', '') }}')" class="cx-preset">
                                            <small>النصف</small>
                                            <strong>{{ \App\Helpers\Money::format($invoice->balance / 2) }}</strong>
                                        </button>
                                        <button type="button" wire:click="setAmount('{{ ceil((float) $invoice->balance) }}')" class="cx-preset">
                                            <small>تقريب لأعلى</small>
                                            <strong>{{ \App\Helpers\Money::format(ceil($invoice->balance)) }}</strong>
                                        </button>
                                    </div>

                                    {{-- Amount input --}}
                                    <div class="cx-amount-wrap">
                                        <input type="number" step="0.01" min="0.01"
                                            wire:model.live="paymentAmount"
                                            class="cx-amount-input"
                                            placeholder="0.00"
                                            autocomplete="off">
                                        <span class="cx-amount-symbol">{{ config('restaurant.currency_symbol') }}</span>
                                    </div>
                                    @error('paymentAmount') <small class="text-danger d-block mb-2">{{ $message }}</small> @enderror

                                    {{-- Method selector --}}
                                    <div class="cx-methods">
                                        @php
                                            $methods = [
                                                'cash'     => ['bi-cash-stack', 'نقدي'],
                                                'card'     => ['bi-credit-card', 'بطاقة'],
                                                'transfer' => ['bi-bank', 'تحويل'],
                                                'app'      => ['bi-phone', 'تطبيق'],
                                                'credit'   => ['bi-hourglass', 'دين'],
                                            ];
                                        @endphp
                                        @foreach($methods as $m => [$icon, $label])
                                            <button type="button"
                                                wire:click="setMethod('{{ $m }}')"
                                                class="cx-method {{ $paymentMethod === $m ? 'is-active' : '' }}">
                                                <i class="bi {{ $icon }}"></i>
                                                <span>{{ $label }}</span>
                                            </button>
                                        @endforeach
                                    </div>

                                    @if(in_array($paymentMethod, ['card', 'transfer', 'app']))
                                        <input type="text" wire:model.blur="paymentReference"
                                            placeholder="رقم المرجع (اختياري)"
                                            class="form-control mb-2">
                                    @endif

                                    {{-- Submit --}}
                                    <button wire:click="recordPayment" wire:loading.attr="disabled"
                                        class="cx-btn-lg cx-btn-success">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span wire:loading.remove>تأكيد الدفعة</span>
                                        <span wire:loading>جارٍ التسجيل...</span>
                                    </button>
                                </div>
                            @endif

                            {{-- Payment history --}}
                            @if($invoice->payments->count())
                                <div class="cx-section">
                                    <div class="cx-section-head"><strong><i class="bi bi-clock-history"></i> سجل الدفعات</strong></div>
                                    @foreach($invoice->payments as $p)
                                        <div class="cx-payment-row">
                                            <div>
                                                <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                                                <span class="badge bg-secondary ms-1">{{ match($p->method) {
                                                    'cash' => 'نقدي', 'card' => 'بطاقة', 'transfer' => 'تحويل',
                                                    'app' => 'تطبيق', 'credit' => 'دين', default => $p->method,
                                                } }}</span>
                                                @if($p->reference)<small class="text-muted">#{{ $p->reference }}</small>@endif
                                            </div>
                                            <small class="text-muted">{{ $p->paid_at?->format('H:i') ?? $p->created_at->format('H:i') }}</small>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Action buttons --}}
                            <div class="cx-actions">
                                <a href="{{ route('admin.cashier.print', $invoice) }}" target="_blank" class="btn btn-light btn-sm">
                                    <i class="bi bi-printer"></i> طباعة
                                </a>
                                <a href="{{ route('admin.cashier.pdf', $invoice) }}" class="btn btn-light btn-sm">
                                    <i class="bi bi-file-pdf"></i> PDF
                                </a>
                                @if((float) $invoice->paid_total > (float) ($invoice->refunded_total ?? 0))
                                    <button type="button" wire:click="openRefund" class="btn btn-sm" style="background:rgba(185,28,28,.1); color:#b91c1c; border:1px solid rgba(185,28,28,.3);">
                                        <i class="bi bi-arrow-counterclockwise"></i> استرداد
                                    </button>
                                @endif
                            </div>

                            {{-- Refund modal --}}
                            @if($refundOpen)
                                <div class="cx-modal-overlay" wire:click.self="closeRefund">
                                    <div class="cx-modal">
                                        <div class="cx-modal-head">
                                            <strong>استرداد مبلغ</strong>
                                            <button wire:click="closeRefund" class="btn-close"></button>
                                        </div>
                                        <div class="p-3">
                                            <div class="mb-2">
                                                <label class="form-label small">المبلغ</label>
                                                <input type="number" step="0.01" min="0.01"
                                                    wire:model="refundAmount" class="form-control">
                                                @error('refundAmount') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small">الطريقة</label>
                                                <select wire:model="refundMethod" class="form-select">
                                                    <option value="cash">نقدي</option>
                                                    <option value="card">بطاقة</option>
                                                    <option value="transfer">تحويل</option>
                                                    <option value="app">تطبيق</option>
                                                    <option value="credit">رصيد زبون</option>
                                                </select>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small">السبب</label>
                                                <textarea wire:model="refundReason" class="form-control" rows="2" placeholder="سبب الاسترداد..."></textarea>
                                                @error('refundReason') <small class="text-danger">{{ $message }}</small> @enderror
                                            </div>
                                            <div class="d-flex gap-2 mt-3">
                                                <button wire:click="closeRefund" class="btn btn-light flex-grow-1">تراجع</button>
                                                <button wire:click="submitRefund" class="btn btn-danger flex-grow-2" style="flex: 2;">
                                                    <i class="bi bi-arrow-counterclockwise"></i> تأكيد الاسترداد
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            @endif
        </main>
    </div>

    {{-- Toast --}}
    <div x-data="{ show: false, type: 'success', msg: '' }"
        x-on:toast.window="type = $event.detail.type; msg = $event.detail.message; show = true; setTimeout(() => show = false, 3500)"
        x-show="show" x-cloak
        x-transition.duration.200ms
        class="cx-toast"
        :class="`cx-toast-${type}`">
        <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'"></i>
        <span x-text="msg"></span>
    </div>
</div>
