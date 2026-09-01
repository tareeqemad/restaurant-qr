@php
    $documentMode = $documentMode ?? 'a4';
    $isReceipt = $documentMode === 'receipt';
    $market = \App\Support\MarketProfile::class;
    $siteName = \App\Helpers\Brand::name();
    $siteInitial = mb_substr(trim($siteName) ?: 'ر', 0, 1, 'UTF-8');
    $branch = $invoice->branch;
    $legalProfile = $branch?->legalProfile;
    $legalName = trim((string) ($legalProfile?->registered_name ?: \App\Models\Setting::get('legal_name', '')));
    $taxNumber = trim((string) ($legalProfile?->tax_number ?: \App\Models\Setting::get('tax_number', '')));
    $invoicePhone = trim((string) ($legalProfile?->invoice_phone ?: $branch?->phone));
    $invoiceAddress = trim((string) ($legalProfile?->invoice_address ?: $branch?->address));
    $currencySymbol = (string) \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol'));
    $receiptFooter = trim((string) \App\Models\Setting::get('receipt_footer', 'شكراً لزيارتكم'));
    $hasTax = (float) $invoice->tax_total > 0.001;
    $invoiceTitle = $hasTax ? 'فاتورة مبيعات ضريبية' : 'فاتورة مبيعات';
    $issuer = $invoice->issuer;

    $invoiceOrders = $invoice->tableSession
        ? $invoice->tableSession->orders
        : collect([$invoice->order])->filter();
    $originLabel = $invoice->tableSession
        ? 'طاولة '.$invoice->tableLabel()
        : (($invoice->order?->order_type === 'delivery' ? 'توصيل' : 'استلام/سفري').' - '.($invoice->order?->sourceLabel() ?? 'طلب مباشر'));
    $orderNumbers = $invoiceOrders->pluck('number')->filter()->values()->join('، ');
    $customerName = trim((string) ($invoice->customer_name
        ?: $invoice->tableSession?->customer_name
        ?: $invoiceOrders->first()?->customer_name));
    $customerPhone = trim((string) ($invoice->customer_phone
        ?: $invoice->tableSession?->customer_phone
        ?: $invoiceOrders->first()?->customer_phone));

    $fmtQty = function ($qty): string {
        $qty = (float) $qty;

        return $qty == floor($qty)
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    };
    $money = fn ($amount): string => number_format((float) $amount, 2).' '.$currencySymbol;

    // Invoice totals freeze at issuance. Later items stay visible, but outside
    // the billed table, until a deliberate invoice resync absorbs them.
    $issuedAt = $invoice->issued_at ?? $invoice->created_at;
    $activeItems = $invoiceOrders
        ->flatMap(fn ($order) => $order->items)
        ->filter(fn ($item) => $item->status !== 'cancelled')
        ->values();
    $unbilledItems = $activeItems
        ->filter(fn ($item) => $issuedAt && $item->created_at && $item->created_at->gt($issuedAt))
        ->values();
    if ($unbilledItems->isNotEmpty()
        && abs($activeItems->sum(fn ($item) => (float) $item->subtotal) - (float) $invoice->subtotal) <= 0.011) {
        $unbilledItems = collect();
    }
    $billedItems = $activeItems
        ->reject(fn ($item) => $unbilledItems->contains(fn ($lateItem) => $lateItem->id === $item->id))
        ->values();

    $splitNotePrefix = 'دفعة جزء: ';
    $statusClass = in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)
        ? ' is-'.$invoice->status
        : '';
@endphp

@if($isReceipt)
<main class="invoice-receipt" data-invoice-document="receipt">
    <header class="receipt-head">
        <div class="receipt-mark">{{ $siteInitial }}</div>
        <h1>{{ $siteName }}</h1>
        @if($legalName && $legalName !== $siteName)<p>{{ $legalName }}</p>@endif
        @if($branch)
            <p>{{ $branch->name }}@if($invoicePhone) · <span class="code-value">{{ $invoicePhone }}</span>@endif</p>
            @if($invoiceAddress)<p>{{ $invoiceAddress }}</p>@endif
        @endif
        <div class="receipt-title">{{ $invoiceTitle }}</div>
        <div class="receipt-number">{{ $invoice->number }}</div>
        <span class="status-badge{{ $statusClass }}">{{ $invoice->statusLabel() }}</span>
    </header>

    <hr class="receipt-rule">
    <table class="receipt-meta">
        <tr><td>التاريخ</td><td><span class="code-value">{{ ($invoice->issued_at ?? $invoice->created_at)?->format('Y-m-d H:i') }}</span></td></tr>
        <tr><td>نوع الطلب</td><td>{{ $originLabel }}</td></tr>
        @if($orderNumbers)<tr><td>رقم الطلب</td><td><span class="code-value">{{ $orderNumbers }}</span></td></tr>@endif
        <tr><td>الكاشير</td><td>{{ $issuer?->name ?? 'النظام' }}</td></tr>
        @if($customerName || $customerPhone)
            <tr><td>الزبون</td><td>{{ $customerName ?: 'زبون' }}@if($customerPhone)<br><span class="code-value">{{ $customerPhone }}</span>@endif</td></tr>
        @endif
        @if($hasTax && $taxNumber)
            <tr><td>{{ $market::taxNumberLabel() }}</td><td><span class="code-value">{{ $taxNumber }}</span></td></tr>
        @endif
    </table>
    <hr class="receipt-rule">

    <table class="items-table">
        <thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
        <tbody>
        @forelse($billedItems as $item)
            <tr>
                <td>
                    <span class="item-name">{{ $item->name_snapshot }}</span>
                    @if($item->modifiers->isNotEmpty())<span class="item-detail">{{ $item->modifiers->pluck('name_snapshot')->join('، ') }}</span>@endif
                    @if($item->wasDiscounted())<span class="item-detail item-discount">وفر {{ $money($item->discountSavings()) }} · السعر قبل الخصم {{ $money($item->unit_price_original) }}</span>@endif
                </td>
                <td>×{{ $fmtQty($item->quantity) }}</td>
                <td><bdi>{{ $money((float) $item->unit_price + (float) $item->modifiers_total) }}</bdi></td>
                <td><bdi>{{ $money($item->subtotal) }}</bdi></td>
            </tr>
        @empty
            <tr><td colspan="4">لا توجد أصناف مفوترة.</td></tr>
        @endforelse
        </tbody>
    </table>

    <hr class="receipt-rule">
    <table class="totals-table">
        <tr><td>المجموع الفرعي</td><td><bdi>{{ $money($invoice->subtotal) }}</bdi></td></tr>
        @if((float) $invoice->discount_total > 0)<tr><td>الخصم</td><td><bdi>-{{ $money($invoice->discount_total) }}</bdi></td></tr>@endif
        @if($hasTax)<tr><td>{{ $market::taxLabel() }}</td><td><bdi>{{ $money($invoice->tax_total) }}</bdi></td></tr>@endif
        @if((float) $invoice->service_total > 0)<tr><td>{{ $market::serviceLabel() }}</td><td><bdi>{{ $money($invoice->service_total) }}</bdi></td></tr>@endif
        @if((float) $invoice->delivery_fee > 0)<tr><td>رسوم التوصيل</td><td><bdi>{{ $money($invoice->delivery_fee) }}</bdi></td></tr>@endif
        @if((float) $invoice->tip > 0)<tr><td>إكرامية</td><td><bdi>{{ $money($invoice->tip) }}</bdi></td></tr>@endif
        <tr class="grand"><td>الإجمالي</td><td><bdi>{{ $money($invoice->total) }}</bdi></td></tr>
        <tr><td>المدفوع</td><td><bdi>{{ $money($invoice->paid_total) }}</bdi></td></tr>
        @if((float) ($invoice->refunded_total ?? 0) > 0)<tr><td>المسترد</td><td><bdi>-{{ $money($invoice->refunded_total) }}</bdi></td></tr>@endif
        <tr class="balance"><td>المتبقي</td><td><bdi>{{ $money($invoice->balance) }}</bdi></td></tr>
    </table>

    @if($invoice->settled_on_account_at && (float) $invoice->balance > 0.001)
        <div class="receipt-alert">المتبقي مسجل ديناً على حساب الزبون بتاريخ <span class="code-value">{{ $invoice->settled_on_account_at->format('Y-m-d') }}</span></div>
    @endif

    @if($unbilledItems->isNotEmpty())
        <div class="receipt-alert is-danger"><strong>طلبات غير مفوترة</strong><br>أضيفت بعد إصدار هذه الفاتورة ولا تدخل في إجماليها.</div>
        <table class="items-table">
            <tbody>
            @foreach($unbilledItems as $item)
                <tr><td><span class="item-name">{{ $item->name_snapshot }}</span></td><td>×{{ $fmtQty($item->quantity) }}</td><td><bdi>{{ $money((float) $item->unit_price + (float) $item->modifiers_total) }}</bdi></td><td><bdi>{{ $money($item->subtotal) }}</bdi></td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if($invoice->payments->isNotEmpty())
        <hr class="receipt-rule">
        <p class="section-label">الدفعات</p>
        <table class="payments-table">
        @foreach($invoice->payments as $payment)
            @php
                $splitLabel = str_starts_with((string) $payment->notes, $splitNotePrefix)
                    ? trim(mb_substr($payment->notes, mb_strlen($splitNotePrefix)))
                    : null;
                $paymentNote = $splitLabel ? null : trim((string) $payment->notes);
            @endphp
            <tr>
                <td>
                    {{ \App\Support\PaymentMethods::label($payment->method) }}@if($splitLabel) · {{ $splitLabel }}@endif
                    <span class="item-detail">استلمها {{ $payment->receiver?->name ?? 'النظام' }} · <span class="code-value">{{ $payment->paid_at?->format('Y-m-d H:i') }}</span></span>
                    @if($payment->reference)<span class="item-detail">المرجع: <span class="code-value">{{ $payment->reference }}</span></span>@endif
                    @if($paymentNote)<span class="item-detail">{{ $paymentNote }}</span>@endif
                </td>
                <td><bdi>{{ $money($payment->amount) }}</bdi></td>
            </tr>
        @endforeach
        </table>
    @endif

    @if(trim((string) $invoice->notes))
        <div class="receipt-alert"><strong>ملاحظة الفاتورة</strong><br>{{ $invoice->notes }}</div>
    @endif

    <footer class="receipt-footer">
        @if($receiptFooter)<strong>{{ $receiptFooter }}</strong>@endif
        <span>مرجع الفاتورة: <span class="code-value">{{ $invoice->number }}</span></span>
    </footer>
</main>
@else
<main class="invoice-a4" data-invoice-document="a4">
    <header>
        <table class="a4-head">
            <tr>
                <td class="brand-mark-cell"><div class="brand-mark">{{ $siteInitial }}</div></td>
                <td class="brand-copy">
                    <h1>{{ $siteName }}</h1>
                    @if($legalName && $legalName !== $siteName)<p>{{ $legalName }}</p>@endif
                    @if($branch)<p>{{ $branch->name }}@if($invoiceAddress) · {{ $invoiceAddress }}@endif @if($invoicePhone) · <span class="code-value">{{ $invoicePhone }}</span>@endif</p>@endif
                    @if($hasTax && $taxNumber)<p>{{ $market::taxNumberLabel() }}: <span class="code-value">{{ $taxNumber }}</span></p>@endif
                </td>
                <td class="document-copy">
                    <h2>{{ $invoiceTitle }}</h2>
                    <div class="document-number">{{ $invoice->number }}</div>
                    <span class="status-badge{{ $statusClass }}">{{ $invoice->statusLabel() }}</span>
                </td>
            </tr>
        </table>
        <div class="accent-line"></div>
    </header>

    <table class="meta-table">
        <tr>
            <td><span>تاريخ الإصدار</span><br><strong class="code-value">{{ ($invoice->issued_at ?? $invoice->created_at)?->format('Y-m-d H:i') }}</strong></td>
            <td><span>نوع الطلب</span><br><strong>{{ $originLabel }}</strong></td>
            <td><span>رقم الطلب</span><br><strong class="code-value">{{ $orderNumbers ?: '—' }}</strong></td>
            <td><span>أصدرها</span><br><strong>{{ $issuer?->name ?? 'النظام' }}</strong></td>
        </tr>
        <tr>
            <td><span>الفرع</span><br><strong>{{ $branch?->name ?? '—' }}</strong></td>
            <td><span>الزبون</span><br><strong>{{ $customerName ?: 'زبون نقدي' }}</strong></td>
            <td><span>رقم الجوال</span><br><strong class="code-value">{{ $customerPhone ?: '—' }}</strong></td>
            <td><span>حالة الفاتورة</span><br><strong>{{ $invoice->statusLabel() }}</strong></td>
        </tr>
    </table>

    <h3 class="section-title">تفاصيل الأصناف</h3>
    <table class="items-table">
        <thead><tr><th>#</th><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead>
        <tbody>
        @forelse($billedItems as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                    <span class="item-name">{{ $item->name_snapshot }}</span>
                    @if($item->modifiers->isNotEmpty())<span class="item-detail">الإضافات: {{ $item->modifiers->pluck('name_snapshot')->join('، ') }}</span>@endif
                    @if($item->wasDiscounted())<span class="item-detail item-discount">توفير {{ $money($item->discountSavings()) }} · السعر قبل الخصم {{ $money($item->unit_price_original) }}</span>@endif
                </td>
                <td>×{{ $fmtQty($item->quantity) }}</td>
                <td><bdi>{{ $money((float) $item->unit_price + (float) $item->modifiers_total) }}</bdi></td>
                <td><bdi>{{ $money($item->subtotal) }}</bdi></td>
            </tr>
        @empty
            <tr><td colspan="5">لا توجد أصناف مفوترة.</td></tr>
        @endforelse
        </tbody>
    </table>

    <table class="summary-layout">
        <tr>
            <td class="summary-side">
                @if($customerName || $customerPhone)
                    <div class="customer-card">
                        <strong>بيانات الزبون</strong>
                        <span>{{ $customerName ?: 'زبون' }}@if($customerPhone) · <span class="code-value">{{ $customerPhone }}</span>@endif</span>
                    </div>
                @endif
                @if($invoice->payments->isNotEmpty())
                    <div class="payment-card">
                        <strong>تفاصيل الدفعات</strong>
                        <table class="payments-table">
                        @foreach($invoice->payments as $payment)
                            @php
                                $splitLabel = str_starts_with((string) $payment->notes, $splitNotePrefix)
                                    ? trim(mb_substr($payment->notes, mb_strlen($splitNotePrefix)))
                                    : null;
                                $paymentNote = $splitLabel ? null : trim((string) $payment->notes);
                            @endphp
                            <tr>
                                <td>
                                    {{ \App\Support\PaymentMethods::label($payment->method) }}@if($splitLabel) · {{ $splitLabel }}@endif
                                    <span class="item-detail">استلمها {{ $payment->receiver?->name ?? 'النظام' }} · <span class="code-value">{{ $payment->paid_at?->format('Y-m-d H:i') }}</span></span>
                                    @if($payment->reference)<span class="item-detail">المرجع: <span class="code-value">{{ $payment->reference }}</span></span>@endif
                                    @if($paymentNote)<span class="item-detail">{{ $paymentNote }}</span>@endif
                                </td>
                                <td><bdi>{{ $money($payment->amount) }}</bdi></td>
                            </tr>
                        @endforeach
                        </table>
                    </div>
                @endif
                @if($invoice->settled_on_account_at && (float) $invoice->balance > 0.001)
                    <div class="notice-card is-warning"><strong>رصيد على حساب الزبون</strong><span>المتبقي مسجل ديناً بتاريخ <span class="code-value">{{ $invoice->settled_on_account_at->format('Y-m-d') }}</span>.</span></div>
                @endif
                @if(trim((string) $invoice->notes))
                    <div class="notice-card"><strong>ملاحظة الفاتورة</strong><span>{{ $invoice->notes }}</span></div>
                @endif
            </td>
            <td class="summary-total">
                <table class="totals-table">
                    <tr><td>المجموع الفرعي</td><td><bdi>{{ $money($invoice->subtotal) }}</bdi></td></tr>
                    @if((float) $invoice->discount_total > 0)<tr><td>الخصم</td><td><bdi>-{{ $money($invoice->discount_total) }}</bdi></td></tr>@endif
                    @if($hasTax)<tr><td>{{ $market::taxLabel() }}</td><td><bdi>{{ $money($invoice->tax_total) }}</bdi></td></tr>@endif
                    @if((float) $invoice->service_total > 0)<tr><td>{{ $market::serviceLabel() }}</td><td><bdi>{{ $money($invoice->service_total) }}</bdi></td></tr>@endif
                    @if((float) $invoice->delivery_fee > 0)<tr><td>رسوم التوصيل</td><td><bdi>{{ $money($invoice->delivery_fee) }}</bdi></td></tr>@endif
                    @if((float) $invoice->tip > 0)<tr><td>إكرامية</td><td><bdi>{{ $money($invoice->tip) }}</bdi></td></tr>@endif
                    <tr class="grand"><td>الإجمالي</td><td><bdi>{{ $money($invoice->total) }}</bdi></td></tr>
                    <tr><td>المدفوع</td><td><bdi>{{ $money($invoice->paid_total) }}</bdi></td></tr>
                    @if((float) ($invoice->refunded_total ?? 0) > 0)<tr><td>المسترد</td><td><bdi>-{{ $money($invoice->refunded_total) }}</bdi></td></tr>@endif
                    <tr class="balance"><td>المتبقي</td><td><bdi>{{ $money($invoice->balance) }}</bdi></td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if($unbilledItems->isNotEmpty())
        <section class="unbilled">
            <h3>طلبات غير مفوترة</h3>
            <p>هذه الأصناف أضيفت بعد إصدار الفاتورة ولا تدخل في الإجمالي أعلاه. تُحصّل بمستند مستقل.</p>
            <table class="items-table">
                <tbody>
                @foreach($unbilledItems as $item)
                    <tr><td>{{ $loop->iteration }}</td><td><span class="item-name">{{ $item->name_snapshot }}</span></td><td>×{{ $fmtQty($item->quantity) }}</td><td><bdi>{{ $money((float) $item->unit_price + (float) $item->modifiers_total) }}</bdi></td><td><bdi>{{ $money($item->subtotal) }}</bdi></td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <footer class="invoice-footer">
        @if($receiptFooter)<strong>{{ $receiptFooter }}</strong>@endif
        <span>فاتورة إلكترونية · المرجع <span class="code-value">{{ $invoice->number }}</span></span>
    </footer>
</main>
@endif
