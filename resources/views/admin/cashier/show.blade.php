@extends('layouts.admin')@section('title','كاشير - طاولة '.$session->table?->number)
@section('content')
@php
    $enabledPaymentMethods = collect(\App\Support\PaymentMethods::catalog())
        ->filter(fn (array $meta) => (bool) $meta['enabled']);
    $splitPaymentMethods = $enabledPaymentMethods
        ->map(fn (array $meta, string $code) => ['code' => $code, 'label' => $meta['label']])
        ->values();

    // Same qty format as the KDS boards («×2» for whole, «×1.5» for
    // fractional) so the cashier never reads «2.00» here and «×2» there.
    $fmtQty = function ($qty): string {
        $qty = (float) $qty;
        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    };
@endphp
<x-admin.breadcrumb
    title="طاولة {{ $session->table?->number }}"
    icon="bi-cash-stack"
    subtitle="{{ $session->orders->count() }} طلبات"
    :crumbs="[['label' => 'الكاشير', 'url' => route('admin.cashier.index')]]" />

{{-- ── Bank transfers claimed for THIS table — verify without leaving the page ── --}}
@if($pendingTransfers->isNotEmpty())
    <div class="card border-warning mb-3">
        <div class="card-header d-flex align-items-center gap-2" style="background:#f8f0de">
            <i class="bi bi-bank2 text-warning"></i>
            <strong>تحويلات بنكية بانتظار تأكيدك ({{ $pendingTransfers->count() }})</strong>
        </div>
        <div class="card-body">
            @foreach($pendingTransfers as $t)
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 {{ ! $loop->last ? 'border-bottom pb-3 mb-3' : '' }}">
                    <div>
                        <div class="fw-bold fs-5">{{ \App\Helpers\Money::format($t->amount) }}</div>
                        <small class="text-muted">
                            المُرسِل: <strong>{{ $t->sender_name }}</strong>
                            · سُجّل بواسطة: {{ $t->recorded_by_user_id ? ($t->recordedBy?->name ?? 'الطاقم') : 'الزبون من التطبيق' }}
                            @if($t->notes) · {{ $t->notes }} @endif
                        </small>
                    </div>
                    <div class="d-flex gap-2 align-items-end">
                        <form method="POST" action="{{ route('admin.cashier.transfers.verify', $t) }}" class="d-flex gap-1 align-items-end">
                            @csrf
                            <div>
                                <label class="form-label small mb-0">المبلغ المؤكد</label>
                                <input type="number" step="0.01" min="0.01" name="verified_amount"
                                       value="{{ number_format((float) $t->amount, 2, '.', '') }}"
                                       class="form-control form-control-sm text-end" style="width:110px">
                            </div>
                            <button class="btn btn-success btn-sm"><i class="bi bi-check-circle-fill"></i> تأكيد</button>
                        </form>
                        <form method="POST" action="{{ route('admin.cashier.transfers.reject', $t) }}"
                              onsubmit="return (this.reason.value = prompt('سبب رفض التحويل؟') || '') !== ''">
                            @csrf
                            <input type="hidden" name="reason">
                            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> رفض</button>
                        </form>
                    </div>
                </div>
            @endforeach
            <div class="small text-muted mt-2">
                <i class="bi bi-info-circle"></i> تأكّد من وصول المبلغ في تطبيق البنك قبل التأكيد. المبلغ الأقل يترك رصيداً متبقياً على الفاتورة.
            </div>
        </div>
    </div>
@endif

{{-- ── Cashier records a transfer claim manually (customer told them directly) ── --}}
@if(auth()->user()?->can('create', \App\Models\Payment::class))
    <div class="mb-3">
        <a class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" href="#cashierRecordTransfer" role="button">
            <i class="bi bi-plus-circle"></i> تسجيل تحويل بنكي يدوياً
        </a>
        <div class="collapse mt-2" id="cashierRecordTransfer">
            <form method="POST" action="{{ route('admin.cashier.transfers.store', $session) }}"
                  class="card card-body row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label small">اسم المُرسِل *</label>
                    <input type="text" name="sender_name" maxlength="120" required class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">المبلغ *</label>
                    <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" required class="form-control form-control-sm text-end">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">هاتف الزبون (اختياري)</label>
                    <input type="text" name="customer_phone" maxlength="32" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-save"></i> حفظ</button>
                </div>
            </form>
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
        <div class="card-body">
            @foreach($session->orders as $order)
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <strong>{{ $order->number }}</strong>
                        <span class="badge bg-{{ $order->statusColor() }}">{{ $order->statusLabel() }}</span>
                    </div>
                    <table class="table table-sm"><thead class="bg-light"><tr><th>الصنف</th><th>كمية</th><th>سعر</th><th>مجموع</th></tr></thead>
                    <tbody>
                    @foreach($order->items as $it)
                        <tr class="{{ $it->status==='cancelled' ? 'opacity-50' : '' }}">
                            <td>{{ $it->name_snapshot }}
                                @if($it->modifiers->count())<small class="text-muted d-block">{{ $it->modifiers->pluck('name_snapshot')->join('، ') }}</small>@endif
                            </td>
                            <td>×{{ $fmtQty($it->quantity) }}</td>
                            <td>{{ \App\Helpers\Money::format($it->unit_price + $it->modifiers_total) }}</td>
                            <td>{{ \App\Helpers\Money::format($it->subtotal) }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                </div>
            @endforeach
        </div></div>
    </div>

    <div class="col-lg-4">
        {{-- Portal customer link panel — sits ABOVE the invoice summary so
             the cashier sees identity before money. --}}
        <div class="card mb-3"><div class="card-body">
            <livewire:admin.cashier-customer-link :session-id="$session->id" />
        </div></div>

        {{-- Existing-debt banner — only when the session has a linked
             customer AND they already owe us from prior visits. Lets the
             cashier ask "are you also settling your old debt?" before
             the diner pulls out cash. --}}
        @php
            $sessionCustomer = $session->customer;
            $existingDebt = $sessionCustomer?->outstandingDebt() ?? 0;
        @endphp
        @if($sessionCustomer && $existingDebt > 0.001)
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
                <div class="flex-grow-1">
                    <strong class="d-block">دين قديم على هذا الزبون</strong>
                    <small>{{ $sessionCustomer->name }} عليه
                        <strong class="text-danger">{{ \App\Helpers\Money::format($existingDebt) }}</strong>
                        من زيارات سابقة. اعرض عليه التسديد الآن.</small>
                    <div class="mt-2">
                        <a href="{{ route('admin.customers.debts.show', $sessionCustomer) }}"
                           class="btn btn-sm btn-outline-dark" target="_blank">
                            <i class="bi bi-wallet"></i> فتح سجل ديونه
                        </a>
                    </div>
                </div>
            </div>
        @endif

        @if(! $session->invoice)
            <div class="card"><div class="card-body">
                <h5 class="fw-bold mb-3">ملخص الفاتورة</h5>
                @php
                    $sub = $session->orders->sum('subtotal');
                    $tax = $session->orders->sum('tax_total');
                    $svc = $session->orders->sum('service_total');
                    $tot = $session->orders->sum('total');
                @endphp
                <div class="d-flex justify-content-between"><span>الفرعي:</span><strong>{{ \App\Helpers\Money::format($sub) }}</strong></div>
                <div class="d-flex justify-content-between"><span>الضريبة:</span><strong>{{ \App\Helpers\Money::format($tax) }}</strong></div>
                <div class="d-flex justify-content-between"><span>الخدمة:</span><strong>{{ \App\Helpers\Money::format($svc) }}</strong></div>
                <hr>
                <div class="d-flex justify-content-between"><strong>الإجمالي:</strong><strong class="text-primary fs-5">{{ \App\Helpers\Money::format($tot) }}</strong></div>
                <form action="{{ route('admin.cashier.issue', $session) }}" method="POST" class="mt-3 d-grid">@csrf
                    <button class="btn btn-primary btn-lg"><i class="bi bi-receipt"></i> إصدار الفاتورة</button>
                </form>
            </div></div>
        @else
            @php $inv = $session->invoice; @endphp
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between mb-2"><h5 class="fw-bold mb-0">{{ $inv->number }}</h5>
                    <span class="badge bg-{{ $inv->statusColor() }}">{{ $inv->statusLabel() }}</span></div>
                <div class="d-flex justify-content-between"><span>الفرعي:</span>{{ \App\Helpers\Money::format($inv->subtotal) }}</div>
                <div class="d-flex justify-content-between"><span>الضريبة:</span>{{ \App\Helpers\Money::format($inv->tax_total) }}</div>
                <div class="d-flex justify-content-between"><span>الخدمة:</span>{{ \App\Helpers\Money::format($inv->service_total) }}</div>
                <hr>
                <div class="d-flex justify-content-between"><strong>الإجمالي:</strong><strong class="fs-5">{{ \App\Helpers\Money::format($inv->total) }}</strong></div>
                <div class="d-flex justify-content-between text-success"><span>مدفوع:</span><strong>{{ \App\Helpers\Money::format($inv->paid_total) }}</strong></div>
                @if((float)($inv->refunded_total ?? 0) > 0)
                    <div class="d-flex justify-content-between" style="color:#b91c1c;">
                        <span>مسترد:</span><strong>{{ \App\Helpers\Money::format($inv->refunded_total) }}</strong>
                    </div>
                @endif
                <div class="d-flex justify-content-between text-danger"><span>متبقي:</span><strong>{{ \App\Helpers\Money::format($inv->balance) }}</strong></div>

                {{-- Loud residual-balance flag — a short-paid transfer (cashier
                     confirmed less than the balance) leaves the table OPEN. This
                     draws the eye so the diner isn't waved off on a half-paid bill. --}}
                @if($inv->status === 'partially_paid' && (float)$inv->balance > 0.001 && ! $inv->settled_on_account_at)
                    <div class="alert alert-danger d-flex align-items-center gap-2 mt-2 mb-0 py-2 px-2 small">
                        <i class="bi bi-exclamation-triangle-fill fs-6"></i>
                        <span>الطاولة <strong>غير مغلقة</strong> — متبقٍ
                            <strong>{{ \App\Helpers\Money::format($inv->balance) }}</strong>. حصّله أو أجّله كدين.</span>
                    </div>
                @endif

                {{-- Parked-debt banner + un-park. A settled-on-account invoice
                     stays partially_paid with balance > 0 (the balance IS the
                     ledger), so without this banner it looks like a live
                     half-paid checkout. Un-park reverses an accidental park —
                     guards live in BillingService::unparkSettleOnAccount. --}}
                @if($inv->settled_on_account_at)
                    <div class="alert alert-warning mt-2 mb-0 py-2 px-2 small">
                        <i class="bi bi-journal-text"></i>
                        مؤجّلة <strong>كدين على الزبون</strong> منذ {{ $inv->settled_on_account_at->format('Y-m-d H:i') }}
                        — المتبقي <strong>{{ \App\Helpers\Money::format($inv->balance) }}</strong> يظهر في سجل ديونه،
                        وأي دفعة هنا تسدد منه مباشرة.
                        @can('create', \App\Models\Payment::class)
                            @if((float) $inv->balance > 0.001)
                                <form method="POST" action="{{ route('admin.cashier.unpark', $inv) }}" class="mt-2 mb-0"
                                      onsubmit="return confirm('إلغاء تأجيل هذا الدين؟ سترجع الفاتورة إلى التحصيل العادي وتُحذف من سجل ديون الزبون.')">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-dark w-100">
                                        <i class="bi bi-arrow-counterclockwise"></i> إلغاء التأجيل
                                    </button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @endif

                @if((float)$inv->balance > 0)
                    <hr>
                    {{-- When the bill is split, collect via the per-split buttons
                         only. A direct payment here would shrink the balance and
                         strand the (now-too-large) split buttons as unpayable. --}}
                    @if($inv->splits->isEmpty())
                    <form id="cashPayForm" action="{{ route('admin.cashier.pay', $inv) }}" method="POST"
                          onsubmit="setTimeout(() => { const b = this.querySelector('button'); if (b) b.disabled = true; }, 0)">@csrf
                        <input type="hidden" name="_idem" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div class="mb-2"><label class="form-label">المبلغ</label><input type="number" step="0.01" id="payAmount" name="amount" value="{{ $inv->balance }}" class="form-control" required></div>
                        <div class="mb-2"><label class="form-label">طريقة الدفع</label>
                            <select name="method" class="form-select" required>
                                @foreach($enabledPaymentMethods as $code => $meta)
                                    <option value="{{ $code }}">{{ $meta['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Cash-tendered → change-due helper. Display only: no `name`
                             so it's never POSTed; the applied amount is capped at the
                             balance (the server caps too), so change reflects reality. --}}
                        <div class="mb-2">
                            <label class="form-label">المبلغ المستلم نقداً <small class="text-muted">(لحساب الباقي)</small></label>
                            <input type="number" step="0.01" min="0" id="cashTendered" inputmode="decimal"
                                   class="form-control text-end" placeholder="المبلغ الذي دفعه الزبون">
                        </div>
                        <div id="changeDueBox" class="alert alert-info py-2 px-2 mb-2 d-none justify-content-between align-items-center">
                            <span>الباقي (فكة):</span><strong id="changeDueVal" class="fs-6">0.00</strong>
                        </div>
                        <div class="mb-2"><input name="reference" class="form-control" placeholder="رقم المرجع (اختياري)"></div>
                        <button class="btn btn-success w-100"><i class="bi bi-cash"></i> تسجيل الدفعة</button>
                    </form>
                    @push('scripts')
                    <script>
                    (function () {
                        const form = document.getElementById('cashPayForm');
                        if (!form) return;
                        const amountEl = document.getElementById('payAmount');
                        const tenderedEl = document.getElementById('cashTendered');
                        const box = document.getElementById('changeDueBox');
                        const val = document.getElementById('changeDueVal');
                        const balance = {{ (float) $inv->balance }};
                        function recompute() {
                            const tendered = parseFloat(tenderedEl.value);
                            const applied = Math.min(parseFloat(amountEl.value) || 0, balance);
                            if (!isFinite(tendered) || tendered <= applied) { box.classList.add('d-none'); box.classList.remove('d-flex'); return; }
                            val.textContent = (tendered - applied).toFixed(2);
                            box.classList.remove('d-none'); box.classList.add('d-flex');
                        }
                        amountEl.addEventListener('input', recompute);
                        tenderedEl.addEventListener('input', recompute);
                    })();
                    </script>
                    @endpush
                    @else
                    {{-- Compact splits summary AT the payment area — the split
                         card sits below the fold, so without this the cashier
                         scrolls to learn who still owes what. --}}
                    @php $paidSplits = $inv->splits->where('paid', true); @endphp
                    <div class="alert alert-info small py-2 px-2 mb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-scissors"></i> مقسّمة إلى {{ $inv->splits->count() }} أجزاء</span>
                            <strong>مدفوع {{ $paidSplits->count() }}/{{ $inv->splits->count() }}
                                ({{ \App\Helpers\Money::format($paidSplits->sum('amount')) }})</strong>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            @foreach($inv->splits as $sp)
                                <span class="badge {{ $sp->paid ? 'bg-success' : 'bg-white text-dark border' }}">
                                    <i class="bi {{ $sp->paid ? 'bi-check2' : 'bi-hourglass' }}"></i>
                                    {{ $sp->label }} · {{ \App\Helpers\Money::format($sp->amount) }}
                                </span>
                            @endforeach
                        </div>
                        <div class="mt-1 text-muted">حصّل من أزرار الأجزاء في بطاقة التقسيم بالأسفل.</div>
                    </div>
                    @endif

                    {{-- Settle on Account — separate flow that closes the
                         session and parks the balance on the customer's
                         ledger. Only offered when a customer is linked
                         and at least one payment has been recorded
                         (BillingService::settleOnAccount enforces both;
                         the UI mirrors that to avoid a useless button). --}}
                    {{-- Already-parked invoices skip the button: a second settle
                         would only bounce off the service's idempotency guard. --}}
                    @if($inv->customer_id && (float) $inv->paid_total > 0.001 && ! $inv->settled_on_account_at)
                        <button type="button" class="btn btn-warning w-100 mt-2"
                                data-bs-toggle="modal" data-bs-target="#settleOnAccount">
                            <i class="bi bi-journal-text"></i>
                            تأجيل المتبقي ({{ \App\Helpers\Money::format($inv->balance) }}) كدين
                        </button>
                        <div class="modal fade" id="settleOnAccount"><div class="modal-dialog"><div class="modal-content">
                            <form action="{{ route('admin.cashier.settle_on_account', $inv) }}" method="POST">@csrf
                                <div class="modal-header">
                                    <h5><i class="bi bi-journal-text"></i> تأجيل المتبقي كدين</h5>
                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    @php
                                        // Preview math: at render time the invoice is NOT flagged
                                        // yet, so outstandingDebt() is already "debt from OTHER
                                        // invoices" (settleOnAccount computes the exact same way).
                                        // The old preview subtracted this balance from it as if the
                                        // flag were set, understating every number by one invoice.
                                        $settleOtherDebt = $inv->customer?->outstandingDebt() ?? 0.0;
                                        $settleResulting = $settleOtherDebt + (float) $inv->balance;
                                    @endphp
                                    <div class="alert alert-info small">
                                        <strong>الزبون:</strong> {{ $inv->customer->name ?? '—' }}<br>
                                        <strong>دينه الحالي (فواتير أخرى):</strong> {{ \App\Helpers\Money::format($settleOtherDebt) }}<br>
                                        <strong>المبلغ المؤجل من هذه الفاتورة:</strong> {{ \App\Helpers\Money::format($inv->balance) }}<br>
                                        <strong>إجمالي الدين بعد التأجيل:</strong>
                                        <span class="text-danger">{{ \App\Helpers\Money::format($settleResulting) }}</span>
                                    </div>
                                    @if($inv->customer && $inv->customer->credit_limit !== null)
                                        @php
                                            $limit = (float) $inv->customer->credit_limit;
                                            // Same tolerance as the service guard (0.01) so the
                                            // modal never promises what settleOnAccount will refuse.
                                            $wouldExceed = $settleResulting - $limit > 0.01;
                                        @endphp
                                        <div class="alert {{ $wouldExceed ? 'alert-danger' : 'alert-light' }} small">
                                            <strong>الحد الائتماني:</strong> {{ \App\Helpers\Money::format($limit) }}
                                            @if($wouldExceed)
                                                <br><i class="bi bi-x-octagon-fill"></i> يتجاوز الحد — لن تُقبل العملية. ارفع الحد أولاً أو حصّل نقداً.
                                            @endif
                                        </div>
                                    @endif
                                    <label class="form-label">ملاحظة (اختياري)</label>
                                    <textarea name="notes" class="form-control" rows="2"
                                              placeholder="مثلاً: وعد الزبون بالتسديد الأسبوع القادم"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                    <button class="btn btn-warning">
                                        <i class="bi bi-check2"></i> تأكيد تأجيل المتبقي
                                    </button>
                                </div>
                            </form>
                        </div></div></div>
                    @elseif((float) $inv->paid_total <= 0.001 && (float) $inv->balance > 0 && $inv->customer_id)
                        {{-- Zero-paid parking is refused by BillingService::settleOnAccount
                             itself (a full ticket on credit with no cash collected is the
                             write-off path, not a debt) — so the button stays hidden and
                             this hint explains the service's rule instead of teasing an
                             action that would only bounce with an error. --}}
                        <div class="alert alert-light border mt-2 small mb-0">
                            <i class="bi bi-info-circle"></i>
                            <strong>لماذا لا يظهر زر التأجيل؟</strong>
                            النظام يرفض تأجيل فاتورة <u>بدون أي دفعة</u> كدين — سجّل ولو دفعة جزئية
                            رمزية أولاً (إثبات نية السداد)، أو استخدم زر "شطب" إن كان الزبون لن يدفع إطلاقاً.
                        </div>
                    @elseif((float) $inv->balance > 0 && ! $inv->customer_id)
                        <div class="alert alert-light border mt-2 small mb-0">
                            <i class="bi bi-info-circle"></i>
                            لتأجيل المتبقي كدين، اربط زبوناً بالجلسة أولاً (من اللوحة فوق).
                        </div>
                    @endif
                @endif

                <div class="mt-3 d-flex gap-2">
                    <a href="{{ route('admin.cashier.print', $inv) }}" target="_blank" class="btn btn-outline-primary flex-grow-1"><i class="bi bi-printer"></i> طباعة</a>
                    <a href="{{ route('admin.cashier.pdf', $inv) }}" class="btn btn-outline-danger flex-grow-1"><i class="bi bi-file-pdf"></i> PDF</a>
                </div>

                @if($inv->status === 'issued')
                    <button class="btn btn-sm btn-outline-danger w-100 mt-2" data-bs-toggle="modal" data-bs-target="#cancelInv">إلغاء الفاتورة</button>
                    <div class="modal fade" id="cancelInv"><div class="modal-dialog"><div class="modal-content">
                        <form action="{{ route('admin.cashier.cancel', $inv) }}" method="POST">@csrf
                            <div class="modal-header"><h5>إلغاء فاتورة</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body"><textarea name="reason" class="form-control" placeholder="السبب" required></textarea></div>
                            <div class="modal-footer"><button class="btn btn-danger">تأكيد</button></div>
                        </form>
                    </div></div></div>
                @endif

                @if(in_array($inv->status, ['issued', 'partially_paid']))
                    <button class="btn btn-sm btn-outline-dark w-100 mt-1" data-bs-toggle="modal" data-bs-target="#writeoff">شطب (الزبون ما دفع)</button>
                    <div class="modal fade" id="writeoff"><div class="modal-dialog"><div class="modal-content">
                        <form action="{{ route('admin.cashier.writeoff', $inv) }}" method="POST">@csrf
                            <div class="modal-header"><h5>شطب فاتورة</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body"><textarea name="reason" class="form-control" placeholder="السبب" required></textarea></div>
                            <div class="modal-footer"><button class="btn btn-dark">تأكيد</button></div>
                        </form>
                    </div></div></div>
                @endif

                {{-- Refund button — only if any payment was made and there's refundable balance --}}
                @if((float) $inv->paid_total > (float) ($inv->refunded_total ?? 0))
                    @can('create', \App\Models\Refund::class)
                        <button class="btn btn-sm w-100 mt-1" style="background:rgba(185,28,28,.1); color:#b91c1c; border:1px solid rgba(185,28,28,.3);"
                                data-bs-toggle="modal" data-bs-target="#refundInv">
                            <i class="bi bi-arrow-counterclockwise"></i> استرداد مبلغ
                        </button>
                        <div class="modal fade" id="refundInv">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="{{ route('admin.refunds.store', $inv) }}">
                                        @csrf
                                        <div class="modal-header">
                                            <h5>استرداد مبلغ — {{ $inv->number }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            @php
                                                $refundable = max(0, (float)$inv->paid_total - (float)($inv->refunded_total ?? 0));
                                            @endphp
                                            <div class="alert alert-warning small mb-3">
                                                <strong>المبلغ المدفوع:</strong> {{ \App\Helpers\Money::format($inv->paid_total) }}<br>
                                                <strong>المسترد سابقاً:</strong> {{ \App\Helpers\Money::format($inv->refunded_total ?? 0) }}<br>
                                                <strong>القابل للاسترداد:</strong> {{ \App\Helpers\Money::format($refundable) }}
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">المبلغ المسترد <span class="text-danger">*</span></label>
                                                <input type="number" step="0.01" min="0.01" max="{{ $refundable }}"
                                                    name="amount" value="{{ $refundable }}" class="form-control" required>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">طريقة الاسترداد <span class="text-danger">*</span></label>
                                                <select name="method" class="form-select" required>
                                                    @foreach(\App\Models\Refund::ACTIVE_METHODS as $method)
                                                        <option value="{{ $method }}">{{ \App\Models\Refund::METHODS[$method] ?? $method }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">سبب الاسترداد <span class="text-danger">*</span></label>
                                                <textarea name="reason" class="form-control" rows="2" required
                                                    placeholder="مثلاً: صنف تالف / خطأ في الطلب / شكوى زبون"></textarea>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">ملاحظات (اختياري)</label>
                                                <textarea name="notes" class="form-control" rows="2"></textarea>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label">رقم مرجع (اختياري)</label>
                                                <input type="text" name="reference" class="form-control" placeholder="للاستردادات البنكية">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                            <button class="btn" style="background:#b91c1c; color:white;">
                                                <i class="bi bi-arrow-counterclockwise"></i> تأكيد الاسترداد
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endcan
                @endif

                {{-- Show refunds history for this invoice --}}
                @if($inv->refunds && $inv->refunds->count())
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold small text-muted mb-2">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            استردادات سابقة ({{ $inv->refunds->count() }})
                        </h6>
                        @foreach($inv->refunds as $ref)
                            <div class="d-flex justify-content-between small py-1 border-bottom">
                                <div>
                                    <code>{{ $ref->number }}</code>
                                    <span class="badge bg-{{ $ref->statusColor() }} ms-1">{{ $ref->statusLabel() }}</span>
                                </div>
                                <div class="text-danger fw-bold">
                                    −{{ \App\Helpers\Money::format($ref->amount) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div></div>

            @if($inv->payments->count())
                <div class="card mt-3"><div class="card-header"><strong>الدفعات</strong></div>
                <ul class="list-group list-group-flush">
                @foreach($inv->payments as $p)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-secondary">{{ \App\Support\PaymentMethods::label($p->method) }}</span> {{ $p->paid_at->format('H:i') }}
                            {{-- Split label ("دفعة جزء: …") + reference so the cashier can
                                 eyeball which person/slip a line belongs to without receipts. --}}
                            @if($p->notes && str_starts_with($p->notes, 'دفعة جزء: '))
                                <small class="text-muted d-block">{{ $p->notes }}</small>
                            @endif
                            @if($p->reference)
                                <small class="text-muted d-block" dir="ltr">#{{ $p->reference }}</small>
                            @endif
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                            {{-- Void a mistaken payment (wrong method/amount) — reverses its
                                 ledger entry and reopens the invoice. Not for real refunds. --}}
                            @if($inv->status !== 'cancelled')
                                <form method="POST" action="{{ route('admin.cashier.payments.void', $p) }}"
                                      onsubmit="return (this.reason.value = prompt('سبب إلغاء الدفعة؟ (خطأ في المبلغ أو طريقة الدفع…)') || '') !== ''">
                                    @csrf
                                    <input type="hidden" name="reason">
                                    <button class="btn btn-sm btn-outline-danger py-0 px-1" title="إلغاء الدفعة (عكس القيد)">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
                </ul></div>
            @endif

            {{-- ========= Split Bill ========= --}}
            @if($inv->status !== 'paid' && $inv->status !== 'cancelled')
                @php
                    // Rows can be regrouped only while NOTHING is paid — a paid
                    // split is anchored to a committed payment, so «تعديل» after
                    // that is refund territory (the controller 422s it too).
                    $splitsEditable = $inv->splits->count() > 0
                        && $inv->splits->where('paid', true)->isEmpty()
                        && ! $inv->payments->count();
                    $existingSplitRows = $inv->splits
                        ->map(fn ($sp) => ['label' => $sp->label, 'amount' => (float) $sp->amount, 'method' => $sp->method])
                        ->values();
                    // paySplit stamps its payment notes with «دفعة جزء: {label}» —
                    // match on that + the amount to surface the payment reference
                    // back on the paid row (no schema link split→payment exists).
                    $splitRef = fn ($sp) => $inv->payments
                        ->first(fn ($p) => $p->notes === 'دفعة جزء: '.$sp->label
                            && abs((float) $p->amount - (float) $sp->amount) < 0.005)
                        ?->reference;
                @endphp
                <div class="card mt-3"><div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-scissors"></i> تقسيم الفاتورة</strong>
                    @if($inv->splits->count() && ! $inv->payments->count())
                        <form action="{{ route('admin.cashier.split.clear', $inv) }}" method="POST" class="d-inline">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('إزالة التقسيم؟')"><i class="bi bi-x"></i></button>
                        </form>
                    @endif
                </div>
                <div class="card-body">
                    @if($inv->splits->count() === 0)
                        <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#splitModal"><i class="bi bi-people"></i> تقسيم الفاتورة</button>
                        {{-- One-tap equal presets — build the rows AND open the
                             modal pre-populated, instead of open → type count →
                             press «تقسيم بالتساوي» → save (the ~10-tap side door). --}}
                        <div class="d-flex gap-2 mt-2">
                            @foreach([2, 3, 4] as $n)
                                <button type="button" class="btn btn-sm btn-light border flex-fill" onclick="openSplitPreset({{ $n }})">
                                    بالتساوي ×{{ $n }}
                                </button>
                            @endforeach
                        </div>
                    @else
                        <ul class="list-group list-group-flush mb-2">
                            @foreach($inv->splits as $sp)
                                <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div><strong>{{ $sp->label }}</strong> <span class="badge bg-light text-dark">{{ \App\Support\PaymentMethods::label($sp->method) }}</span></div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <strong>{{ \App\Helpers\Money::format($sp->amount) }}</strong>
                                        @if($sp->paid)
                                            <span class="badge bg-success"><i class="bi bi-check2"></i> مدفوع</span>
                                            @if($ref = $splitRef($sp))
                                                <span class="badge bg-light text-dark border" title="رقم المرجع" dir="ltr">#{{ $ref }}</span>
                                            @endif
                                        @else
                                            <form action="{{ route('admin.cashier.split.pay', ['invoice' => $inv, 'split' => $sp]) }}" method="POST" class="d-inline-flex align-items-center gap-1">@csrf
                                                {{-- Reference only makes sense for traceable methods —
                                                     cash has no slip number to key in. --}}
                                                @if(in_array($sp->method, ['card', 'transfer'], true))
                                                    <input name="reference" maxlength="255" class="form-control form-control-sm" style="width:135px" placeholder="رقم المرجع (اختياري)">
                                                @endif
                                                <button class="btn btn-sm btn-success">تأكيد الدفع</button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">مدفوع: {{ $inv->splits->where('paid', true)->count() }} / {{ $inv->splits->count() }}</small>
                            @if($splitsEditable)
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#splitModal">
                                    <i class="bi bi-pencil"></i> تعديل التقسيم
                                </button>
                            @endif
                        </div>
                    @endif
                </div></div>

                {{-- Split modal + script — shared by CREATE (no splits yet) and
                     EDIT (unpaid splits, pre-filled rows, save replaces all). --}}
                @if($inv->splits->count() === 0 || $splitsEditable)
                        <div class="modal fade" id="splitModal"><div class="modal-dialog modal-lg"><div class="modal-content">
                            <form action="{{ route('admin.cashier.split', $inv) }}" method="POST">@csrf
                                <div class="modal-header"><h5>{{ $splitsEditable ? 'تعديل تقسيم فاتورة' : 'تقسيم فاتورة' }} {{ \App\Helpers\Money::format($inv->total) }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    @if($splitsEditable)
                                        <div class="alert alert-warning small py-2">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            الحفظ يستبدل التقسيم الحالي بالكامل (لا يوجد جزء مدفوع بعد).
                                        </div>
                                    @endif
                                    <div class="mb-3 d-flex gap-2 align-items-end">
                                        <div><label class="form-label">عدد الأشخاص</label>
                                            <input type="number" min="2" max="20" value="2" id="splitCount" class="form-control" style="width:100px;"></div>
                                        <button type="button" class="btn btn-light" onclick="splitEqual({{ $inv->total }})">تقسيم بالتساوي</button>
                                    </div>
                                    <table class="table" id="splitTable">
                                        <thead><tr><th>التسمية</th><th>المبلغ</th><th>الدفع</th><th></th></tr></thead>
                                        <tbody id="splitRows"></tbody>
                                        <tfoot><tr><th colspan="1">الإجمالي</th><th colspan="3"><span id="splitSum" class="fw-bold">0.00</span> / <strong>{{ $inv->total }}</strong></th></tr></tfoot>
                                    </table>
                                    <button type="button" class="btn btn-sm btn-light" onclick="addSplitRow()"><i class="bi bi-plus"></i> إضافة جزء</button>
                                </div>
                                <div class="modal-footer"><button class="btn btn-primary">{{ $splitsEditable ? 'استبدال التقسيم' : 'حفظ التقسيم' }}</button></div>
                            </form>
                        </div></div></div>

                        @push('scripts')
                        <script>
                        let splitIdx = 0;
                        const splitPaymentMethods = @json($splitPaymentMethods);
                        // Edit mode: the current rows, so the modal opens pre-filled
                        // with what the cashier is regrouping (empty on create).
                        const existingSplits = @json($existingSplitRows);
                        function escapeHtml(value) {
                            return String(value).replace(/[&<>"']/g, char => ({
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#039;',
                            }[char]));
                        }
                        function splitMethodOptions(selected = null) {
                            return splitPaymentMethods
                                .map(method => `<option value="${escapeHtml(method.code)}"${method.code === selected ? ' selected' : ''}>${escapeHtml(method.label)}</option>`)
                                .join('');
                        }
                        function addSplitRow(label = null, amount = '', method = null) {
                            const i = splitIdx++;
                            const row = `<tr>
                                <td><input name="splits[${i}][label]" class="form-control" value="${escapeHtml(label ?? ('الشخص '+(i+1)))}"></td>
                                <td><input type="number" step="0.01" min="0.01" name="splits[${i}][amount]" class="form-control split-amt" value="${amount}" onchange="updateSplitSum()"></td>
                                <td><select name="splits[${i}][method]" class="form-select">${splitMethodOptions(method)}</select></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateSplitSum();"><i class="bi bi-x"></i></button></td>
                            </tr>`;
                            document.getElementById('splitRows').insertAdjacentHTML('beforeend', row);
                            updateSplitSum();
                        }
                        function updateSplitSum() {
                            const total = Array.from(document.querySelectorAll('.split-amt')).reduce((s,e) => s + (parseFloat(e.value) || 0), 0);
                            document.getElementById('splitSum').textContent = total.toFixed(2);
                        }
                        function splitEqual(totalAmount) {
                            document.getElementById('splitRows').innerHTML = ''; splitIdx = 0;
                            const n = parseInt(document.getElementById('splitCount').value) || 2;
                            const share = Math.floor((totalAmount / n) * 100) / 100;
                            const remainder = +(totalAmount - share * n).toFixed(2);
                            for (let k = 0; k < n; k++) {
                                const amt = k === n - 1 ? (share + remainder).toFixed(2) : share.toFixed(2);
                                addSplitRow('الشخص '+(k+1), amt);
                            }
                        }
                        function loadExistingSplits() {
                            document.getElementById('splitRows').innerHTML = ''; splitIdx = 0;
                            existingSplits.forEach(s => addSplitRow(s.label, Number(s.amount).toFixed(2), s.method));
                        }
                        // One-tap preset: set the count, build the equal rows,
                        // THEN open the modal — it appears ready to save.
                        function openSplitPreset(n) {
                            document.getElementById('splitCount').value = n;
                            splitEqual({{ $inv->total }});
                            bootstrap.Modal.getOrCreateInstance(document.getElementById('splitModal')).show();
                        }
                        document.addEventListener('DOMContentLoaded', () => {
                            const modal = document.getElementById('splitModal');
                            if (modal) modal.addEventListener('shown.bs.modal', () => {
                                if (document.querySelectorAll('#splitRows tr').length) return;
                                // Edit mode starts from the saved rows; create mode
                                // from an equal split of the current count.
                                if (existingSplits.length) loadExistingSplits();
                                else splitEqual({{ $inv->total }});
                            });
                        });
                        </script>
                        @endpush
                @endif
            @endif
        @endif
    </div>
</div>

@push('scripts')
@livewireScripts
<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('flash', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            const msg  = payload?.message ?? '';
            const type = payload?.type ?? 'info';
            if (window.notify) window.notify(msg, type); else alert(msg);
        });
    });
</script>
@endpush
@endsection
