@extends('layouts.admin')@section('title','كاشير - طاولة '.$session->table?->number)
@section('content')
<x-admin.breadcrumb
    title="طاولة {{ $session->table?->number }}"
    icon="bi-cash-stack"
    subtitle="{{ $session->orders->count() }} طلبات"
    :crumbs="[['label' => 'الكاشير', 'url' => route('admin.cashier.index')]]" />

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
                            <td>{{ $it->quantity }}</td>
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

                @if((float)$inv->balance > 0)
                    <hr>
                    <form action="{{ route('admin.cashier.pay', $inv) }}" method="POST">@csrf
                        <div class="mb-2"><label class="form-label">المبلغ</label><input type="number" step="0.01" name="amount" value="{{ $inv->balance }}" class="form-control" required></div>
                        <div class="mb-2"><label class="form-label">طريقة الدفع</label>
                            <select name="method" class="form-select" required>
                                @foreach(\App\Support\PaymentMethods::catalog() as $code => $meta)
                                    @if($meta['enabled'])
                                        <option value="{{ $code }}">{{ $meta['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2"><input name="reference" class="form-control" placeholder="رقم المرجع (اختياري)"></div>
                        <button class="btn btn-success w-100"><i class="bi bi-cash"></i> تسجيل الدفعة</button>
                    </form>

                    {{-- Settle on Account — separate flow that closes the
                         session and parks the balance on the customer's
                         ledger. Only offered when a customer is linked
                         and at least one payment has been recorded
                         (BillingService::settleOnAccount enforces both;
                         the UI mirrors that to avoid a useless button). --}}
                    @if($inv->customer_id && (float) $inv->paid_total > 0.001)
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
                                    <div class="alert alert-info small">
                                        <strong>الزبون:</strong> {{ $inv->customer->name ?? '—' }}<br>
                                        <strong>المبلغ المؤجل:</strong> {{ \App\Helpers\Money::format($inv->balance) }}<br>
                                        <strong>دينه السابق:</strong>
                                        @php $prev = $inv->customer ? ($inv->customer->outstandingDebt() - (float)$inv->balance) : 0; @endphp
                                        {{ \App\Helpers\Money::format(max(0, $prev)) }}<br>
                                        <strong>إجمالي الدين بعد التأجيل:</strong>
                                        <span class="text-danger">{{ \App\Helpers\Money::format($inv->customer ? $inv->customer->outstandingDebt() + (float)$inv->balance - max(0, $prev) : (float)$inv->balance) }}</span>
                                    </div>
                                    @if($inv->customer && $inv->customer->credit_limit !== null)
                                        @php
                                            $limit = (float) $inv->customer->credit_limit;
                                            $newTotal = ($inv->customer->outstandingDebt() - (float)$inv->balance) + (float)$inv->balance;
                                            $wouldExceed = $newTotal - $limit > 0.01;
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
                        <div class="alert alert-light border mt-2 small mb-0">
                            <i class="bi bi-info-circle"></i>
                            لتأجيل المتبقي كدين، سجّل أولاً ولو دفعة جزئية. لشطب الفاتورة بالكامل استخدم زر "شطب".
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
                                                    <option value="cash">نقدا</option>
                                                    <option value="card">فيزا (إعادة للمستخدم)</option>
                                                    <option value="transfer">تحويل بنكي</option>
                                                    <option value="other">أخرى</option>
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
                    <li class="list-group-item d-flex justify-content-between">
                        <div><span class="badge bg-secondary">{{ $p->method }}</span> {{ $p->paid_at->format('H:i') }}</div>
                        <strong>{{ \App\Helpers\Money::format($p->amount) }}</strong>
                    </li>
                @endforeach
                </ul></div>
            @endif

            {{-- ========= Split Bill ========= --}}
            @if($inv->status !== 'paid' && $inv->status !== 'cancelled')
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
                        <div class="modal fade" id="splitModal"><div class="modal-dialog modal-lg"><div class="modal-content">
                            <form action="{{ route('admin.cashier.split', $inv) }}" method="POST">@csrf
                                <div class="modal-header"><h5>تقسيم فاتورة {{ \App\Helpers\Money::format($inv->total) }}</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
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
                                <div class="modal-footer"><button class="btn btn-primary">حفظ التقسيم</button></div>
                            </form>
                        </div></div></div>

                        @push('scripts')
                        <script>
                        let splitIdx = 0;
                        function addSplitRow(label = null, amount = '') {
                            const i = splitIdx++;
                            const row = `<tr>
                                <td><input name="splits[${i}][label]" class="form-control" value="${label ?? ('الشخص '+(i+1))}"></td>
                                <td><input type="number" step="0.01" min="0" name="splits[${i}][amount]" class="form-control split-amt" value="${amount}" onchange="updateSplitSum()"></td>
                                <td><select name="splits[${i}][method]" class="form-select">
                                    <option value="cash">نقدا</option><option value="card">فيزا</option><option value="transfer">تحويل بنكي</option>
                                </select></td>
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
                        document.addEventListener('DOMContentLoaded', () => {
                            const modal = document.getElementById('splitModal');
                            if (modal) modal.addEventListener('shown.bs.modal', () => {
                                if (document.querySelectorAll('#splitRows tr').length === 0) splitEqual({{ $inv->total }});
                            });
                        });
                        </script>
                        @endpush
                    @else
                        <ul class="list-group list-group-flush mb-2">
                            @foreach($inv->splits as $sp)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div><strong>{{ $sp->label }}</strong> <span class="badge bg-light text-dark">{{ $sp->method }}</span></div>
                                    <div>
                                        <strong>{{ \App\Helpers\Money::format($sp->amount) }}</strong>
                                        @if($sp->paid)
                                            <span class="badge bg-success ms-2"><i class="bi bi-check2"></i> مدفوع</span>
                                        @else
                                            <form action="{{ route('admin.cashier.split.pay', ['invoice' => $inv, 'split' => $sp]) }}" method="POST" class="d-inline ms-2">@csrf
                                                <button class="btn btn-sm btn-success">تأكيد الدفع</button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <small class="text-muted">مدفوع: {{ $inv->splits->where('paid', true)->count() }} / {{ $inv->splits->count() }}</small>
                    @endif
                </div></div>
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
