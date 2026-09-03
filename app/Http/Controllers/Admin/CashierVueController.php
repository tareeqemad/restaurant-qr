<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Helpers\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashierVue\CreateCustomerRequest;
use App\Http\Requests\CashierVue\CreateOrderRequest;
use App\Http\Requests\CashierVue\CustomerAdvanceDepositRequest;
use App\Http\Requests\CashierVue\DiscountRequest;
use App\Http\Requests\CashierVue\PaymentRequest;
use App\Http\Requests\CashierVue\ReasonRequest;
use App\Http\Requests\CashierVue\RefundRequest;
use App\Http\Requests\CashierVue\SettleOnAccountRequest;
use App\Http\Requests\CashierVue\SplitInvoiceRequest;
use App\Http\Requests\CashierVue\SplitPaymentRequest;
use App\Http\Requests\CashierVue\TokenRequest;
use App\Http\Requests\CashierVue\TransferRecordRequest;
use App\Http\Requests\CashierVue\TransferRejectRequest;
use App\Http\Requests\CashierVue\TransferVerifyRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAdvanceTransaction;
use App\Models\Invoice;
use App\Models\InvoiceSplit;
use App\Models\Lookup;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PendingTransfer;
use App\Models\Refund;
use App\Models\Scopes\BranchScope;
use App\Models\Setting;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\CustomerAdvanceService;
use App\Services\CreditNoteService;
use App\Services\CustomerIdentityService;
use App\Services\InventoryService;
use App\Services\OrderDiscountService;
use App\Services\OrderService;
use App\Services\PendingTransferService;
use App\Services\RefundService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\LiveRefreshPulse;
use App\Support\PaymentMethods;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Read model for the Vue cashier workspace.
 *
 * This endpoint deliberately returns a compact operational snapshot: polling can replace
 * it without touching any form the cashier is currently typing into. Money
 * writes remain server-authoritative and will be added as narrow commands
 * around the existing services, never copied into Vue.
 */
class CashierVueController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $this->validatedFilters($request);

        return AdminShell::render('Admin/Cashier/Index', [
            'initialState' => $this->statePayload($filters),
            'catalog' => $this->menuCatalog(),
            'options' => $this->screenOptions($request),
            'endpoints' => [
                'tables' => route('admin.tables.index'),
                'state' => '/admin/cashier/api/state',
                'commands' => [
                    'issue_session' => '/admin/cashier/api/sessions/:session/invoice',
                    'issue_order' => '/admin/cashier/api/orders/:order/invoice',
                    'pay' => '/admin/cashier/api/invoices/:invoice/payments',
                    'refund' => '/admin/cashier/api/invoices/:invoice/refunds',
                    'discount_session' => '/admin/cashier/api/sessions/:session/discounts',
                    'discount_order' => '/admin/cashier/api/orders/:order/discounts',
                    'remove_discount' => '/admin/cashier/api/discounts/:discount/remove',
                    'split' => '/admin/cashier/api/invoices/:invoice/splits',
                    'pay_split' => '/admin/cashier/api/invoices/:invoice/splits/:split/pay',
                    'clear_splits' => '/admin/cashier/api/invoices/:invoice/splits/clear',
                    'record_transfer' => '/admin/cashier/api/sessions/:session/transfers',
                    'verify_transfer' => '/admin/cashier/api/transfers/:transfer/verify',
                    'reject_transfer' => '/admin/cashier/api/transfers/:transfer/reject',
                    'void_payment' => '/admin/cashier/api/payments/:payment/void',
                    'settle_on_account' => '/admin/cashier/api/invoices/:invoice/settle-on-account',
                    'unpark' => '/admin/cashier/api/invoices/:invoice/unpark',
                    'writeoff' => '/admin/cashier/api/invoices/:invoice/writeoff',
                    'cancel_invoice' => '/admin/cashier/api/invoices/:invoice/cancel',
                    'close_empty_session' => '/admin/cashier/api/sessions/:session/close-empty',
                    'create_order' => '/admin/cashier/api/orders',
                    'create_customer' => '/admin/cashier/api/customers',
                    'customer_lookup' => '/admin/cashier/api/customers/lookup',
                    'customer_advance' => '/admin/cashier/api/customers/advances',
                    'reverse_customer_advance' => '/admin/cashier/api/customers/advances/:transaction/reverse',
                    'approve_order' => '/admin/cashier/api/orders/:order/approve',
                    'cancel_item' => '/admin/cashier/api/order-items/:item/cancel',
                ],
            ],
        ]);
    }

    public function show(TableSession $session): RedirectResponse
    {
        $this->authorize('viewAny', Payment::class);

        return redirect()->route('admin.cashier.index', ['session' => $session->id]);
    }

    public function state(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $this->validatedFilters($request);
        $version = $this->pulseVersion();

        // Six skipped 10-second polls are followed by a forced read in the
        // client, because wait-age badges change even without a model event.
        if (! $request->boolean('full')
            && trim((string) $request->query('since')) !== ''
            && hash_equals($version, trim((string) $request->query('since')))) {
            return response()->json([
                'ok' => true,
                'message' => null,
                'data' => [
                    'changed' => false,
                    'version' => $version,
                    'generated_at' => now()->toAtomString(),
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => null,
            'data' => $this->statePayload($filters),
        ]);
    }

    public function issueSession(TableSession $session, BillingService $billing): JsonResponse
    {
        $this->authorize('create', Payment::class);

        return $this->command(function () use ($session, $billing) {
            if ($session->orders()
                ->whereHas('changeRequests', fn ($change) => $change
                    ->where('status', OrderChangeRequest::STATUS_PENDING))
                ->exists()) {
                throw new \RuntimeException('يوجد طلب تعديل أو إلغاء معلّق — عالجه قبل إصدار الفاتورة.');
            }

            $invoice = $billing->issueInvoice($session, auth()->id());

            return [
                'message' => "تم إصدار الفاتورة {$invoice->number}.",
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function issueOrder(Order $order, BillingService $billing): JsonResponse
    {
        $this->authorize('create', Payment::class);
        abort_unless($order->table_session_id === null, 404);

        return $this->command(function () use ($order, $billing) {
            if ($order->changeRequests()->where('status', OrderChangeRequest::STATUS_PENDING)->exists()) {
                throw new \RuntimeException('يوجد طلب تعديل أو إلغاء معلّق — عالجه قبل إصدار الفاتورة.');
            }

            $invoice = $billing->issueInvoiceForOrder($order, auth()->id());

            return [
                'message' => "تم إصدار الفاتورة {$invoice->number}.",
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    /**
     * Correct one line from the cashier workspace before an invoice exists.
     * Pending food is returned; fired food stays consumed and is audited as
     * waste. OrderService owns all invoice, stock and concurrency guards.
     */
    public function cancelItem(
        ReasonRequest $request,
        OrderItem $item,
        OrderService $orders,
    ): JsonResponse {
        $item->loadMissing('order');
        $this->authorize('cancelItem', $item->order);
        $data = $request->validated();

        return $this->idempotentCommand('order-item-cancel:'.$item->id, $data['token'], function () use ($data, $item, $orders) {
            $item->refresh();
            if ($item->status === OrderItemStatus::Cancelled->value) {
                return [
                    'message' => 'هذا الصنف ملغى مسبقاً؛ تم تحديث كشف الحساب.',
                    'data' => ['item_id' => (int) $item->id, 'disposition' => null],
                ];
            }

            $disposition = in_array($item->status, [
                OrderItemStatus::Preparing->value,
                OrderItemStatus::Ready->value,
                OrderItemStatus::Served->value,
            ], true) ? 'waste' : 'return';
            $reason = trim($data['reason']);

            $orders->cancelItem(
                item: $item,
                userId: auth()->id(),
                reason: $reason,
                disposition: $disposition,
                wasteReason: $disposition === 'waste' ? $reason : null,
            );

            return [
                'message' => $disposition === 'waste'
                    ? 'تم إلغاء الصنف، تحديث الحساب، وتسجيل مكوناته كهدر لأنه دخل التحضير.'
                    : 'تم إلغاء الصنف وتحديث الحساب وإرجاع مكوناته المتاحة للمخزون.',
                'data' => [
                    'item_id' => (int) $item->id,
                    'disposition' => $disposition,
                ],
            ];
        });
    }

    /**
     * The first command is intentionally the ordinary payment path alone.
     * It proves the JSON envelope, policy, service/ledger reuse, split guard,
     * and retry token before any other financial command joins the pilot.
     */
    public function pay(
        PaymentRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('payment:'.$invoice->id, $data['token'], function () use ($data, $invoice, $billing) {
            if ($invoice->splits()->exists()) {
                throw new \RuntimeException('هذه الفاتورة مقسّمة — حصّل كل جزء من قائمة التقسيم.');
            }

            $payment = $billing->addPayment(
                invoice: $invoice,
                amount: (float) $data['amount'],
                method: $data['method'],
                userId: auth()->id(),
                reference: isset($data['reference']) ? trim((string) $data['reference']) ?: null : null,
                notes: isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
                tenderedAmount: isset($data['tendered_amount']) ? (float) $data['tendered_amount'] : null,
                saveChangeAsAdvance: (bool) ($data['save_change_as_advance'] ?? false),
            );

            $advanceAdded = ($data['save_change_as_advance'] ?? false)
                ? max(0, Money::round((float) ($data['tendered_amount'] ?? 0) - (float) $data['amount']))
                : 0;

            return [
                'message' => $advanceAdded > 0
                    ? 'تم تسجيل الدفعة وحفظ الباقي رصيداً مقدماً للزبون.'
                    : 'تم تسجيل الدفعة.',
                'data' => [
                    'payment_id' => (int) $payment->id,
                    'invoice_id' => (int) $invoice->id,
                    'advance_added' => $advanceAdded,
                ],
            ];
        });
    }

    public function lookupCustomer(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $phone = PhoneNumber::normalize((string) $request->query('phone'));
        if (! preg_match('/^0\d{9}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'أدخل رقم جوال فلسطينياً من 10 أرقام مثل 0592632026.']);
        }

        $customer = Customer::findByPhone($phone);

        return response()->json([
            'ok' => true,
            'message' => $customer ? 'تم العثور على ملف الزبون.' : 'الرقم غير مسجّل؛ سيُنشأ ملف جديد عند حفظ الرصيد.',
            'data' => [
                'found' => (bool) $customer,
                'customer' => $customer ? $this->advanceCustomerPayload($customer) : null,
            ],
        ]);
    }

    public function depositCustomerAdvance(
        CustomerAdvanceDepositRequest $request,
        CustomerIdentityService $identity,
        CustomerAdvanceService $advances,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();
        $phone = PhoneNumber::normalize($data['phone']);

        return $this->idempotentCommand('customer-advance:'.$phone, $data['token'], function () use ($data, $identity, $advances) {
            $branchId = (int) BranchContext::current();
            if (! $branchId) {
                throw new \RuntimeException('اختر فرعاً محدداً قبل استلام رصيد للزبون.');
            }

            $resolved = $identity->resolveOrCreate(
                phone: $data['phone'],
                name: $data['name'] ?? null,
                defaultBranchId: $branchId,
                source: 'cashier_advance',
            );

            $advances->deposit(
                customer: $resolved['customer'],
                amount: (float) $data['amount'],
                method: $data['method'],
                branchId: $branchId,
                userId: auth()->id(),
                reference: isset($data['reference']) ? trim((string) $data['reference']) ?: null : null,
                notes: isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
            );

            return [
                'message' => 'تم حفظ الرصيد المقدم على رقم '.$resolved['customer']->phone.' وترحيل القيد المحاسبي.',
                'data' => [
                    'customer' => $this->advanceCustomerPayload($resolved['customer']->fresh()),
                    'created' => (bool) $resolved['created'],
                ],
            ];
        });
    }

    public function reverseCustomerAdvance(
        ReasonRequest $request,
        int $transaction,
        CustomerAdvanceService $advances,
    ): JsonResponse {
        $data = $request->validated();
        $scope = 'customer-advance-reverse:'.$transaction;

        if (Cache::has($this->idempotencyKey($scope, $data['token']))) {
            return $this->duplicateCommandResponse();
        }

        $movement = CustomerAdvanceTransaction::query()->findOrFail($transaction);
        $user = auth()->user();
        $allowed = $user?->hasPermission('payments.void')
            || ($user?->hasPermission('payments.void_own')
                && (int) $movement->created_by_user_id === (int) $user->id
                && $movement->occurred_at?->isToday());
        abort_unless($allowed, 403);

        return $this->idempotentCommand($scope, $data['token'], function () use ($data, $movement, $advances) {
            $advances->reverseDeposit($movement, auth()->id(), trim($data['reason']));
            $customer = Customer::query()->findOrFail($movement->customer_id);

            return [
                'message' => 'تم عكس إيداع الرصيد وقيده المحاسبي مع الاحتفاظ بسجل التدقيق.',
                'data' => ['customer' => $this->advanceCustomerPayload($customer)],
            ];
        });
    }

    public function refund(
        RefundRequest $request,
        Invoice $invoice,
        RefundService $refunds,
    ): JsonResponse {
        $this->authorize('create', Refund::class);
        $data = $request->validated();

        return $this->idempotentCommand('refund:'.$invoice->id, $data['token'], function () use ($data, $invoice, $refunds) {
            $refund = $refunds->issue(
                invoice: $invoice,
                amount: (float) $data['amount'],
                method: $data['method'],
                reason: trim($data['reason']),
                userId: auth()->id(),
                opts: [
                    'reference' => isset($data['reference']) ? trim((string) $data['reference']) ?: null : null,
                    'notes' => isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
                    'lines' => $data['lines'] ?? [],
                    'idempotency_key' => 'cashier:refund:'.$invoice->id.':'.$data['token'],
                ],
            );

            return [
                'message' => 'تم تسجيل الاسترداد.',
                'data' => ['refund_id' => (int) $refund->id],
            ];
        });
    }

    public function discountSession(
        DiscountRequest $request,
        TableSession $session,
        OrderDiscountService $discounts,
    ): JsonResponse {
        $this->authorize('apply', OrderDiscount::class);
        $data = $request->validated();

        return $this->command(function () use ($data, $session, $discounts) {
            $created = $discounts->applyToSession($session, $data, auth()->user());

            return [
                'message' => 'تم تطبيق الخصم وإعادة احتساب الفاتورة.',
                'data' => ['discount_ids' => collect($created)->pluck('id')->map(fn ($id) => (int) $id)->values()],
            ];
        });
    }

    public function discountOrder(
        DiscountRequest $request,
        Order $order,
        OrderDiscountService $discounts,
    ): JsonResponse {
        $this->authorize('apply', OrderDiscount::class);
        $data = $request->validated();

        return $this->command(function () use ($data, $order, $discounts) {
            $discount = $discounts->applyToOrder($order, $data, auth()->user());

            return [
                'message' => 'تم تطبيق الخصم وإعادة احتساب الفاتورة.',
                'data' => ['discount_ids' => [(int) $discount->id]],
            ];
        });
    }

    public function removeDiscount(
        TokenRequest $request,
        int $discount,
        OrderDiscountService $discounts,
    ): JsonResponse {
        $data = $request->validated();

        return $this->idempotentCommand('discount-remove:'.$discount, $data['token'], function () use ($discount, $discounts) {
            $model = OrderDiscount::query()->findOrFail($discount);
            $this->authorize('remove', $model);
            $discounts->remove($model, auth()->user());

            return [
                'message' => 'تمت إزالة الخصم وإعادة احتساب الفاتورة.',
                'data' => ['discount_id' => $discount],
            ];
        });
    }

    public function splitInvoice(
        SplitInvoiceRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('invoice-split:'.$invoice->id, $data['token'], function () use ($data, $invoice, $billing) {
            if ($invoice->splits()->where('paid', true)->exists()) {
                throw ValidationException::withMessages([
                    'splits' => 'لا يمكن تعديل التقسيم بعد دفع أحد الأجزاء — ألغِ دفعة الجزء أولاً أو أكمل البقية.',
                ]);
            }

            $billing->splitInvoice($invoice, $data['splits']);

            return [
                'message' => 'تم حفظ تقسيم الفاتورة.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function paySplit(
        SplitPaymentRequest $request,
        Invoice $invoice,
        InvoiceSplit $split,
        BillingService $billing,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        abort_unless($split->invoice_id === $invoice->id, 404);
        $data = $request->validated();

        return $this->idempotentCommand('split-payment:'.$split->id, $data['token'], function () use ($data, $invoice, $split, $billing) {
            $payment = $billing->paySplit(
                $split,
                auth()->id(),
                isset($data['reference']) ? trim((string) $data['reference']) ?: null : null,
            );

            return [
                'message' => "تم تحصيل جزء {$split->label}.",
                'data' => [
                    'invoice_id' => (int) $invoice->id,
                    'split_id' => (int) $split->id,
                    'payment_id' => (int) $payment->id,
                ],
            ];
        });
    }

    public function clearSplits(
        TokenRequest $request,
        Invoice $invoice,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('split-clear:'.$invoice->id, $data['token'], function () use ($invoice) {
            DB::transaction(function () use ($invoice) {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($locked->payments()->exists()) {
                    throw new \RuntimeException('لا يمكن إزالة التقسيم بعد تسجيل دفعات.');
                }
                $locked->splits()->delete();
            });

            return [
                'message' => 'تم إلغاء تقسيم الفاتورة.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function verifyTransfer(
        TransferVerifyRequest $request,
        PendingTransfer $transfer,
        PendingTransferService $transfers,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('transfer-verify:'.$transfer->id, $data['token'], function () use ($data, $transfer, $transfers) {
            $amount = isset($data['verified_amount'])
                ? (float) $data['verified_amount']
                : (float) $transfer->amount;
            $result = $transfers->verify(
                $transfer,
                $amount,
                auth()->id(),
                isset($data['verification_notes']) ? trim((string) $data['verification_notes']) ?: null : null,
            );

            $warning = null;
            if ($result['remaining_balance'] > 0.001) {
                $warning = 'تم تأكيد التحويل، لكن بقي على الفاتورة '.
                    Money::format($result['remaining_balance']).
                    ' — حصّله أو أجّله كدين قبل إغلاق الطاولة.';
            }

            return [
                'message' => 'تم تأكيد التحويل وتسجيله كدفعة.',
                'warning' => $warning,
                'data' => [
                    'transfer_id' => (int) $transfer->id,
                    'invoice_id' => (int) $result['invoice']->id,
                    'payment_id' => (int) $result['payment']->id,
                    'remaining_balance' => $result['remaining_balance'],
                ],
            ];
        });
    }

    public function recordTransfer(
        TransferRecordRequest $request,
        TableSession $session,
        PendingTransferService $transfers,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('transfer-record:'.$session->id, $data['token'], function () use ($data, $session, $transfers) {
            if ($session->status !== 'active') {
                throw new \RuntimeException('لا يمكن تسجيل حوالة على جلسة مغلقة.');
            }

            $transfer = $transfers->record(
                session: $session,
                amount: (float) $data['amount'],
                senderName: trim($data['sender_name']),
                recordedByUserId: auth()->id(),
                notes: isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
                phone: isset($data['customer_phone']) ? trim((string) $data['customer_phone']) ?: null : null,
                typedName: isset($data['customer_name']) ? trim((string) $data['customer_name']) ?: null : null,
            );

            return [
                'message' => 'تم تسجيل الحوالة بانتظار مطابقتها مع حساب البنك.',
                'data' => ['transfer_id' => (int) $transfer->id],
            ];
        });
    }

    public function rejectTransfer(
        TransferRejectRequest $request,
        PendingTransfer $transfer,
        PendingTransferService $transfers,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('transfer-reject:'.$transfer->id, $data['token'], function () use ($data, $transfer, $transfers) {
            $transfers->reject($transfer, trim($data['reason']), auth()->id());

            return [
                'message' => 'تم رفض التحويل وتسجيل السبب.',
                'data' => ['transfer_id' => (int) $transfer->id],
            ];
        });
    }

    public function voidPayment(
        ReasonRequest $request,
        int $payment,
        BillingService $billing,
    ): JsonResponse {
        $data = $request->validated();
        $scope = 'payment-void:'.$payment;

        // A successful void removes the payment row. Check the caller-scoped
        // retry key first so a repeated click still reports "already handled"
        // instead of leaking through model lookup as a misleading 404.
        if (Cache::has($this->idempotencyKey($scope, $data['token']))) {
            return $this->duplicateCommandResponse();
        }

        $model = Payment::query()->findOrFail($payment);
        $this->authorize('void', $model);

        return $this->idempotentCommand($scope, $data['token'], function () use ($data, $payment, $model, $billing) {
            $invoiceId = (int) $model->invoice_id;
            $billing->voidPayment($model, auth()->id(), trim($data['reason']));

            return [
                'message' => 'تم إلغاء الدفعة وعكس قيدها.',
                'data' => ['payment_id' => $payment, 'invoice_id' => $invoiceId],
            ];
        });
    }

    public function settleOnAccount(
        SettleOnAccountRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        abort_unless(auth()->user()?->hasPermission('payments.settle_on_account'), 403);
        $data = $request->validated();

        return $this->idempotentCommand('settle-on-account:'.$invoice->id, $data['token'], function () use ($data, $invoice, $billing) {
            $billing->settleOnAccount(
                $invoice,
                auth()->id(),
                isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
                $data['due_date'] ?? null,
            );

            return [
                'message' => 'تم تسجيل رصيد الفاتورة ديناً على الزبون وإغلاق الجلسة.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function unparkInvoice(
        TokenRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        abort_unless(auth()->user()?->hasPermission('payments.settle_on_account'), 403);
        $data = $request->validated();

        return $this->idempotentCommand('invoice-unpark:'.$invoice->id, $data['token'], function () use ($invoice, $billing) {
            $billing->unparkSettleOnAccount($invoice, auth()->id());

            return [
                'message' => 'تم إلغاء تأجيل الدين وعادت الفاتورة للتحصيل المباشر.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function writeOffInvoice(
        ReasonRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        abort_unless(auth()->user()?->hasPermission('payments.writeoff'), 403);
        $data = $request->validated();

        return $this->idempotentCommand('invoice-writeoff:'.$invoice->id, $data['token'], function () use ($data, $invoice, $billing) {
            $billing->writeOffInvoice($invoice, auth()->id(), trim($data['reason']));

            return [
                'message' => 'تم شطب الرصيد وتسجيل قيد الديون المعدومة.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function cancelInvoice(
        ReasonRequest $request,
        Invoice $invoice,
        BillingService $billing,
    ): JsonResponse {
        abort_unless(auth()->user()?->hasPermission('payments.cancel_invoice'), 403);
        $data = $request->validated();

        return $this->idempotentCommand('invoice-cancel:'.$invoice->id, $data['token'], function () use ($data, $invoice, $billing) {
            $billing->cancelInvoice($invoice, auth()->id(), trim($data['reason']));

            return [
                'message' => 'تم إلغاء الفاتورة وعكس قيد إصدارها.',
                'data' => ['invoice_id' => (int) $invoice->id],
            ];
        });
    }

    public function closeEmptySession(
        TokenRequest $request,
        TableSession $session,
        BillingService $billing,
    ): JsonResponse {
        $this->authorize('create', Payment::class);
        $data = $request->validated();

        return $this->idempotentCommand('session-close-empty:'.$session->id, $data['token'], function () use ($session, $billing) {
            $billing->closeSessionWithoutBilling($session, auth()->id(), 'إغلاق من شاشة الكاشير');

            return [
                'message' => 'تم إغلاق الجلسة الخالية وتحرير الطاولة.',
                'data' => ['session_id' => (int) $session->id],
            ];
        });
    }

    public function createOrder(
        CreateOrderRequest $request,
        OrderService $orders,
        CustomerIdentityService $identity,
    ): JsonResponse {
        $this->authorize('create', Order::class);
        $authorizationOrder = new Order;
        $authorizationOrder->forceFill([
            'branch_id' => (int) BranchContext::current(),
            'table_session_id' => null,
            'order_type' => 'takeaway',
            'order_source' => 'phone',
        ]);
        $this->authorize('approve', $authorizationOrder);
        $data = $request->validated();

        return $this->idempotentCommand('order-create', $data['token'], function () use ($data, $orders, $identity) {
            return DB::transaction(function () use ($data, $orders, $identity) {
                $branch = Branch::query()->whereKey(BranchContext::current())->where('is_active', true)->firstOrFail();
                $phone = trim((string) $data['customer_phone']);
                $name = trim((string) ($data['customer_name'] ?? ''));
                $resolved = $identity->resolveOrCreate(
                    phone: $phone,
                    name: $name ?: null,
                    defaultBranchId: $branch->id,
                    source: 'cashier_order',
                );
                $customer = $resolved['customer'];

                $order = $orders->createCashierOrder(
                    customer: $customer,
                    branch: $branch,
                    // These compatibility values are server-owned. Operationally
                    // this is one "phone order" flow with no delivery obligation.
                    type: 'takeaway',
                    source: 'phone',
                    cart: $data['cart'],
                    opts: [
                        'customer_name' => $name ?: null,
                        'customer_phone' => $phone,
                        'customer_notes' => isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
                    ],
                    createdByUserId: auth()->id(),
                );
                $order = $orders->approve($order, auth()->id());

                return [
                    'message' => "تم إنشاء الطلب {$order->number} وإرساله لمحطات التحضير.",
                    'data' => [
                        'order_id' => (int) $order->id,
                        'status' => $order->status,
                        'sent_to_kitchen' => true,
                        'matched_customer_id' => (int) $customer->id,
                        'customer_created' => (bool) $resolved['created'],
                    ],
                ];
            });
        });
    }

    public function createCustomer(
        CreateCustomerRequest $request,
        CustomerIdentityService $identity,
    ): JsonResponse {
        $this->authorize('create', Customer::class);
        $data = $request->validated();

        return $this->idempotentCommand('customer-create', $data['token'], function () use ($data, $identity) {
            $resolved = $identity->resolveOrCreate(
                phone: $data['phone'],
                name: $data['name'],
                defaultBranchId: BranchContext::current(),
                source: 'cashier_directory',
            );

            return [
                'message' => $resolved['created'] ? 'تم تسجيل الزبون وربط ملف النقاط.' : 'الزبون مسجّل مسبقاً؛ تم فتح نفس الملف.',
                'data' => [
                    'id' => (int) $resolved['customer']->id,
                    'name' => $resolved['customer']->name,
                    'phone' => $resolved['customer']->phone,
                    'created' => $resolved['created'],
                ],
            ];
        });
    }

    public function approveOrder(
        TokenRequest $request,
        Order $order,
        OrderService $orders,
    ): JsonResponse {
        $this->authorize('approve', $order);
        $data = $request->validated();

        return $this->idempotentCommand('order-approve:'.$order->id, $data['token'], function () use ($order, $orders) {
            $orders->approve($order, auth()->id());

            return [
                'message' => 'تم اعتماد الطلب وإرساله لمحطات التحضير.',
                'data' => ['order_id' => (int) $order->id],
            ];
        });
    }

    /** @return array{mode:string,filter:string,search:string,session_id:?int,order_id:?int} */
    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'mode' => ['nullable', 'in:all,tables,remote'],
            'filter' => ['nullable', 'in:checkout,all'],
            'search' => ['nullable', 'string', 'max:80'],
            'session_id' => ['nullable', 'integer', 'min:1'],
            'session' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'since' => ['nullable', 'string', 'max:80'],
            'full' => ['nullable', 'boolean'],
        ]);

        return [
            'mode' => (string) ($data['mode'] ?? 'all'),
            'filter' => (string) ($data['filter'] ?? 'checkout'),
            'search' => trim((string) ($data['search'] ?? '')),
            'session_id' => isset($data['session_id'])
                ? (int) $data['session_id']
                : (isset($data['session']) ? (int) $data['session'] : null),
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : null,
        ];
    }

    private function statePayload(array $filters): array
    {
        $sessions = $this->sessionQueue($filters);
        $remoteOrders = $this->remoteQueue($filters);
        $transfers = $this->pendingTransferQueue();
        $changes = $this->pendingChangeQueue();

        return [
            'changed' => true,
            'version' => $this->pulseVersion(),
            'generated_at' => now()->toAtomString(),
            'filters' => $filters,
            'counts' => [
                'active_sessions' => TableSession::query()->where('status', 'active')->count(),
                'checkout_sessions' => $sessions->where('needs_checkout', true)->count(),
                'remote_unpaid' => $remoteOrders->where('needs_checkout', true)->count(),
                'pending_transfers' => $transfers->count(),
                'pending_changes' => $changes->count(),
            ],
            'attention' => $this->attentionQueue($sessions, $remoteOrders, $transfers, $changes),
            'queues' => [
                'sessions' => $sessions->values(),
                'remote_orders' => $remoteOrders->values(),
                'pending_transfers' => $transfers->values(),
                'pending_changes' => $changes->values(),
            ],
            'workspace' => $this->workspace($filters),
            'today' => $this->todayStats(),
            'abilities' => $this->abilities(),
        ];
    }

    private function sessionQueue(array $filters): Collection
    {
        if ($filters['mode'] === 'remote') {
            return collect();
        }

        $query = TableSession::query()
            ->with([
                'table:id,number',
                'customer:id,name,phone',
                'invoice:id,table_session_id,number,status,total,balance,settled_on_account_at',
                'orders:id,table_session_id,status,total,submitted_at',
                'orders.changeRequests:id,order_id,status',
            ])
            ->where('status', 'active')
            ->orderByDesc('bill_requested_at')
            ->orderByDesc('last_activity_at');

        $hasSearch = $filters['search'] !== '';
        if ($filters['filter'] === 'checkout' && ! $hasSearch) {
            $selected = $filters['session_id'];
            $query->where(function ($needsMoney) use ($selected) {
                $needsMoney->whereNotNull('bill_requested_at')
                    ->orWhereHas('invoice', fn ($invoice) => $invoice
                        ->where('status', '!=', 'cancelled')
                        ->where('balance', '>', 0.001))
                    ->orWhere(function ($unbilled) {
                        $unbilled->whereDoesntHave('invoice', fn ($invoice) => $invoice
                            ->where('status', '!=', 'cancelled'))
                            ->whereHas('orders', fn ($orders) => $orders
                                ->where('status', '!=', OrderStatus::Cancelled->value)
                                ->where('total', '>', 0));
                    })
                    ->orWhereHas('orders.changeRequests', fn ($change) => $change
                        ->where('status', OrderChangeRequest::STATUS_PENDING));

                if ($selected) {
                    $needsMoney->orWhere('table_sessions.id', $selected);
                }
            });
        }

        if ($hasSearch) {
            $search = $filters['search'];
            $query->where(function ($lookup) use ($search) {
                $lookup->whereHas('table', fn ($table) => $table->where('number', 'like', "%{$search}%"))
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return $query->limit(120)->get()->map(function (TableSession $session) {
            $invoice = $session->invoice?->status === 'cancelled' ? null : $session->invoice;
            $pendingChanges = $session->orders
                ->flatMap->changeRequests
                ->where('status', OrderChangeRequest::STATUS_PENDING)
                ->count();
            $waitMinutes = $session->bill_requested_at
                ? max(0, (int) $session->bill_requested_at->diffInMinutes(now()))
                : null;
            $billableTotal = (float) $session->orders
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->sum('total');
            $needsCheckout = $session->bill_requested_at !== null
                || ($invoice && (float) $invoice->balance > 0.001)
                || (! $invoice && $billableTotal > 0.001)
                || $pendingChanges > 0;

            return [
                'id' => (int) $session->id,
                'kind' => 'session',
                'label' => 'طاولة '.$session->tableLabel(),
                'table_number' => (string) $session->tableLabel(),
                'customer' => $session->customer?->name ?? $session->customer_name,
                'covers' => max(1, (int) $session->cover_count),
                'orders_count' => $session->orders->count(),
                'total' => $billableTotal,
                'invoice' => $invoice ? [
                    'id' => (int) $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'balance' => (float) $invoice->balance,
                    'parked' => $invoice->settled_on_account_at !== null,
                ] : null,
                'bill_requested_at' => $this->dateTime($session->bill_requested_at),
                'wait_minutes' => $waitMinutes,
                'pending_changes' => $pendingChanges,
                'needs_checkout' => $needsCheckout,
                'urgency' => $pendingChanges > 0 || ($waitMinutes !== null && $waitMinutes >= 8)
                    ? 'critical'
                    : (($waitMinutes !== null && $waitMinutes >= 3) ? 'warning' : 'normal'),
                'last_activity_at' => $this->dateTime($session->last_activity_at),
            ];
        });
    }

    private function remoteQueue(array $filters): Collection
    {
        if ($filters['mode'] === 'tables') {
            return collect();
        }

        $query = Order::query()
            ->with([
                'customer:id,name,phone',
                // Order::invoice() is latestOfMany(). MySQL joins invoices to
                // an aggregate subquery, so every selected column must be
                // qualified or `order_id` becomes ambiguous after invoicing.
                'invoice' => fn ($invoice) => $invoice->select([
                    'invoices.id',
                    'invoices.order_id',
                    'invoices.number',
                    'invoices.status',
                    'invoices.total',
                    'invoices.balance',
                ]),
            ])
            ->withCount('items')
            ->whereNull('table_session_id')
            ->where(function ($activeOrToday) {
                $activeOrToday->whereIn('status', OrderStatus::active())
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->whereDate('created_at', today()));
            })
            ->latest('submitted_at');

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $digits = preg_replace('/\D+/', '', $search) ?? '';
            $query->where(function ($lookup) use ($search, $digits) {
                $lookup->where('number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));

                if ($digits !== '' && $digits !== $search) {
                    $lookup->orWhere('customer_phone', 'like', "%{$digits}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('phone', 'like', "%{$digits}%"));
                }
            });
        }

        return $query->limit(60)->get()->map(function (Order $order) {
            $invoice = $order->invoice?->status === 'cancelled' ? null : $order->invoice;
            $needsCheckout = ! $invoice || (float) $invoice->balance > 0.001;

            return [
                'id' => (int) $order->id,
                'kind' => 'order',
                'label' => $order->number,
                'number' => $order->number,
                'type' => $order->order_type,
                'source' => $order->order_source,
                'status' => $order->status,
                'status_label' => $order->statusLabel(),
                'customer' => $order->customer?->name ?? $order->customer_name,
                'phone' => $order->customer?->phone ?? $order->customer_phone,
                'items_count' => (int) $order->items_count,
                'total' => (float) $order->total,
                'invoice' => $invoice ? [
                    'id' => (int) $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'balance' => (float) $invoice->balance,
                ] : null,
                'needs_checkout' => $needsCheckout,
                'urgency' => $needsCheckout ? 'warning' : 'normal',
                'submitted_at' => $this->dateTime($order->submitted_at),
            ];
        });
    }

    private function pendingTransferQueue(): Collection
    {
        return PendingTransfer::query()
            ->with(['tableSession.table:id,number', 'recordedBy:id,name'])
            ->pending()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (PendingTransfer $transfer) => [
                'id' => (int) $transfer->id,
                'session_id' => (int) $transfer->table_session_id,
                'table_number' => (string) ($transfer->tableSession?->tableLabel() ?? '—'),
                'amount' => (float) $transfer->amount,
                'sender_name' => $transfer->sender_name,
                'customer_name' => $transfer->customer_name_snapshot,
                'customer_phone' => $transfer->customer_phone_snapshot,
                'notes' => $transfer->notes,
                'has_proof' => filled($transfer->proof_path),
                'proof_url' => filled($transfer->proof_path)
                    ? "/admin/cashier/transfers/{$transfer->id}/proof"
                    : null,
                'recorded_by' => $transfer->recordedBy?->name,
                'created_at' => $this->dateTime($transfer->created_at),
            ]);
    }

    private function pendingChangeQueue(): Collection
    {
        return OrderChangeRequest::query()
            ->with(['order:id,number,table_session_id,table_id', 'order.table:id,number', 'orderItem:id,name_snapshot'])
            ->where('status', OrderChangeRequest::STATUS_PENDING)
            ->oldest()
            ->limit(50)
            ->get()
            ->map(fn (OrderChangeRequest $change) => [
                'id' => (int) $change->id,
                'session_id' => $change->order?->table_session_id ? (int) $change->order->table_session_id : null,
                'order_id' => $change->order_id ? (int) $change->order_id : null,
                'order_number' => $change->order?->number,
                'table_number' => (string) ($change->order?->tableLabel() ?? '—'),
                'type' => $change->type,
                'type_label' => $change->typeLabel(),
                'item' => $change->orderItem?->name_snapshot,
                'quantity' => $change->requested_quantity !== null ? (float) $change->requested_quantity : null,
                'note' => $change->request_note,
                'created_at' => $this->dateTime($change->created_at),
            ]);
    }

    private function attentionQueue(
        Collection $sessions,
        Collection $remoteOrders,
        Collection $transfers,
        Collection $changes,
    ): Collection {
        $items = collect();

        foreach ($transfers as $transfer) {
            $items->push([
                'key' => 'transfer:'.$transfer['id'],
                'type' => 'transfer',
                'severity' => 'critical',
                'title' => 'تحويل بنكي بانتظار التأكيد',
                'subtitle' => 'طاولة '.$transfer['table_number'].' · '.$transfer['sender_name'],
                'amount' => $transfer['amount'],
                'created_at' => $transfer['created_at'],
                'selection' => ['kind' => 'session', 'id' => $transfer['session_id']],
            ]);
        }

        foreach ($changes as $change) {
            $selectionKind = $change['session_id'] ? 'session' : 'order';
            $selectionId = $change['session_id'] ?: $change['order_id'];
            $items->push([
                'key' => 'change:'.$change['id'],
                'type' => 'change',
                'severity' => 'critical',
                'title' => $change['type_label'],
                'subtitle' => trim(($change['table_number'] !== '—' ? 'طاولة '.$change['table_number'].' · ' : '').($change['item'] ?? $change['order_number'])),
                'amount' => null,
                'created_at' => $change['created_at'],
                'selection' => ['kind' => $selectionKind, 'id' => $selectionId],
            ]);
        }

        foreach ($sessions->where('needs_checkout', true) as $session) {
            $items->push([
                'key' => 'session:'.$session['id'],
                'type' => 'bill',
                'severity' => $session['urgency'],
                'title' => $session['invoice']
                    ? 'رصيد بانتظار التحصيل'
                    : ($session['bill_requested_at'] ? 'الزبون طلب الفاتورة' : 'طلبات بانتظار الفوترة'),
                'subtitle' => $session['label'],
                'amount' => $session['invoice']['balance'] ?? $session['total'],
                'created_at' => $session['bill_requested_at'] ?? $session['last_activity_at'],
                'selection' => ['kind' => 'session', 'id' => $session['id']],
            ]);
        }

        foreach ($remoteOrders->where('needs_checkout', true) as $order) {
            $items->push([
                'key' => 'order:'.$order['id'],
                'type' => 'remote',
                'severity' => 'warning',
                'title' => 'طلب هاتفي بانتظار التحصيل',
                'subtitle' => $order['number'],
                'amount' => $order['invoice']['balance'] ?? $order['total'],
                'created_at' => $order['submitted_at'],
                'selection' => ['kind' => 'order', 'id' => $order['id']],
            ]);
        }

        $weight = ['critical' => 0, 'warning' => 1, 'normal' => 2];

        return $items->unique('key')->sort(function (array $a, array $b) use ($weight) {
            $severity = ($weight[$a['severity']] ?? 9) <=> ($weight[$b['severity']] ?? 9);

            return $severity !== 0
                ? $severity
                : strcmp((string) $a['created_at'], (string) $b['created_at']);
        })->take(120)->values();
    }

    private function workspace(array $filters): ?array
    {
        if ($filters['session_id']) {
            return $this->sessionWorkspace($filters['session_id']);
        }

        if ($filters['order_id']) {
            return $this->remoteOrderWorkspace($filters['order_id']);
        }

        return null;
    }

    private function sessionWorkspace(int $sessionId): ?array
    {
        $session = TableSession::query()->with([
            'table',
            'customer.loyaltyCustomer',
            'orders' => fn ($orders) => $orders->oldest('submitted_at'),
            'orders.items.modifiers',
            'orders.items.exclusions',
            'orders.items.promotion',
            'orders.items.station:id,name',
            'orders.items.cancelledBy:id,name',
            'orders.discounts.appliedBy',
            'orders.discounts.categoryLookup',
            'invoice.payments.receiver',
            'invoice.refunds.processor',
            'invoice.splits',
        ])->find($sessionId);

        if (! $session) {
            return null;
        }

        $session->customer?->loadCount('tableSessions');
        $billable = (float) $session->orders
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total');

        return [
            'kind' => 'session',
            'id' => (int) $session->id,
            'label' => 'طاولة '.$session->tableLabel(),
            'status' => $session->status,
            'covers' => max(1, (int) $session->cover_count),
            'customer' => $this->customerPayload($session->customer, $session->customer_name, $session->customer_phone),
            'orders' => $session->orders
                ->map(fn (Order $order) => $this->orderPayload($order, (bool) $session->invoice))
                ->values(),
            'fulfillment' => $this->fulfillmentPayload($session->orders),
            'invoice' => $this->invoicePayload($session->invoice),
            'pending_transfers' => $this->pendingTransferQueue()->where('session_id', $session->id)->values(),
            'pending_changes' => $this->pendingChangeQueue()->where('session_id', $session->id)->values(),
            'can_close_without_billing' => $session->status === 'active'
                && ! $session->invoice
                && $billable <= 0.001,
            'bill_requested_at' => $this->dateTime($session->bill_requested_at),
            'bill_request_note' => $session->bill_request_note,
        ];
    }

    private function remoteOrderWorkspace(int $orderId): ?array
    {
        $order = Order::query()
            ->whereNull('table_session_id')
            ->with([
                'customer.loyaltyCustomer',
                'items.modifiers',
                'items.exclusions',
                'items.promotion',
                'items.station:id,name',
                'items.cancelledBy:id,name',
                'discounts.appliedBy',
                'discounts.categoryLookup',
                'invoice.payments.receiver',
                'invoice.refunds.processor',
                'invoice.splits',
            ])
            ->find($orderId);

        if (! $order) {
            return null;
        }

        $order->customer?->loadCount('tableSessions');

        return [
            'kind' => 'order',
            'id' => (int) $order->id,
            'label' => $order->number,
            'status' => $order->status,
            'type' => $order->order_type,
            'source' => $order->order_source,
            'customer' => $this->customerPayload($order->customer, $order->customer_name, $order->customer_phone),
            'orders' => [$this->orderPayload($order, (bool) $order->invoice)],
            'fulfillment' => $this->fulfillmentPayload(collect([$order])),
            'invoice' => $this->invoicePayload($order->invoice),
            'pending_transfers' => [],
            'pending_changes' => [],
            'can_close_without_billing' => false,
            'delivery' => [
                'address' => $order->delivery_address,
                'fee' => (float) $order->delivery_fee,
            ],
        ];
    }

    private function orderPayload(Order $order, bool $hasInvoice = false): array
    {
        $canCancelItem = ! $hasInvoice
            && (auth()->user()?->can('cancelItem', $order) ?? false);

        return [
            'id' => (int) $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'can_approve' => auth()->user()?->can('approve', $order) ?? false,
            'total' => (float) $order->total,
            'notes' => $order->customer_notes,
            'submitted_at' => $this->dateTime($order->submitted_at),
            'items' => $order->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'original_unit_price' => $item->unit_price_original !== null ? (float) $item->unit_price_original : null,
                'modifiers_total' => (float) $item->modifiers_total,
                'subtotal' => (float) $item->subtotal,
                'status' => $item->status,
                'status_label' => $item->statusLabel(),
                'station' => $item->station?->name,
                'notes' => $item->notes,
                'promotion' => $item->promotion?->name,
                'can_cancel' => $canCancelItem
                    && $item->status !== OrderItemStatus::Cancelled->value,
                'cancelled_reason' => $item->cancelled_reason,
                'cancelled_by' => $item->cancelledBy?->name,
                'cancelled_at' => $this->dateTime($item->cancelled_at),
                'exclusions' => $item->exclusions->map(fn ($exclusion) => [
                    'id' => (int) $exclusion->id,
                    'name' => $exclusion->name_snapshot,
                ])->values(),
                'modifiers' => $item->modifiers->map(fn ($modifier) => [
                    'id' => (int) $modifier->id,
                    'name' => $modifier->name_snapshot,
                    'price_delta' => (float) $modifier->price_delta,
                ])->values(),
            ])->values(),
            'discounts' => $order->discounts->map(fn ($discount) => [
                'id' => (int) $discount->id,
                'name' => $discount->name_snapshot,
                'type' => $discount->type,
                'value' => (float) $discount->value,
                'amount' => (float) $discount->amount,
                'reason' => $discount->reason,
                'category' => $discount->categoryLookup?->label,
                'applied_by' => $discount->appliedBy?->name,
            ])->values(),
        ];
    }

    /**
     * Payment and production are intentionally separate workflows. Exposing
     * this snapshot prevents the cashier from mistaking a paid invoice for a
     * free table while kitchen/bar still have work or the waiter still has a
     * ready handoff to confirm.
     */
    private function fulfillmentPayload(Collection $orders): array
    {
        $items = $orders
            ->reject(fn (Order $order) => $order->status === OrderStatus::Cancelled->value)
            ->flatMap(fn (Order $order) => $order->items)
            ->reject(fn ($item) => $item->status === OrderItemStatus::Cancelled->value)
            ->values();

        $pieces = fn (Collection $lines): float => (float) $lines->sum(
            fn ($item) => (float) $item->quantity,
        );
        $waitingStatuses = [
            OrderItemStatus::Pending->value,
            OrderItemStatus::Approved->value,
        ];

        $stations = $items
            ->reject(fn ($item) => $item->status === OrderItemStatus::Served->value)
            ->groupBy(fn ($item) => $item->station?->name ?: 'بدون محطة')
            ->map(fn (Collection $stationItems, string $name) => [
                'name' => $name,
                'waiting' => $pieces($stationItems->whereIn('status', $waitingStatuses)),
                'preparing' => $pieces($stationItems->where('status', OrderItemStatus::Preparing->value)),
                'ready' => $pieces($stationItems->where('status', OrderItemStatus::Ready->value)),
            ])->values();

        return [
            'total' => $pieces($items),
            'waiting' => $pieces($items->whereIn('status', $waitingStatuses)),
            'preparing' => $pieces($items->where('status', OrderItemStatus::Preparing->value)),
            'ready' => $pieces($items->where('status', OrderItemStatus::Ready->value)),
            'served' => $pieces($items->where('status', OrderItemStatus::Served->value)),
            'complete' => $items->isEmpty()
                || $items->every(fn ($item) => $item->status === OrderItemStatus::Served->value),
            'stations' => $stations,
        ];
    }

    private function invoicePayload(?Invoice $invoice): ?array
    {
        if (! $invoice) {
            return null;
        }

        return [
            'id' => (int) $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'status_label' => $invoice->statusLabel(),
            'subtotal' => (float) $invoice->subtotal,
            'discount_total' => (float) $invoice->discount_total,
            'tax_total' => (float) $invoice->tax_total,
            'service_total' => (float) $invoice->service_total,
            'delivery_fee' => (float) $invoice->delivery_fee,
            'tip' => (float) $invoice->tip,
            'total' => (float) $invoice->total,
            'paid_total' => (float) $invoice->paid_total,
            'refunded_total' => (float) $invoice->refunded_total,
            'credited_total' => (float) $invoice->credited_total,
            'adjusted_total' => $invoice->adjustedTotal(),
            'net_paid' => $invoice->netPaid(),
            'balance' => (float) $invoice->balance,
            'refundable_balance' => $invoice->refundableBalance(),
            'refund_items' => app(CreditNoteService::class)->refundableItems($invoice),
            'parked' => $invoice->settled_on_account_at !== null,
            'issued_at' => $this->dateTime($invoice->issued_at),
            'paid_at' => $this->dateTime($invoice->paid_at),
            'payments' => $invoice->payments->map(fn (Payment $payment) => [
                'id' => (int) $payment->id,
                'method' => $payment->method,
                'method_label' => PaymentMethods::label($payment->method),
                'amount' => (float) $payment->amount,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'receiver' => $payment->receiver?->name,
                'paid_at' => $this->dateTime($payment->paid_at),
                'can_void' => auth()->user()?->can('void', $payment) ?? false,
            ])->values(),
            'refunds' => $invoice->refunds->map(fn (Refund $refund) => [
                'id' => (int) $refund->id,
                'number' => $refund->number,
                'method' => $refund->method,
                'method_label' => $refund->methodLabel(),
                'amount' => (float) $refund->amount,
                'status' => $refund->status,
                'status_label' => $refund->statusLabel(),
                'reason' => $refund->reason,
                'reference' => $refund->reference,
                'processor' => $refund->processor?->name,
                'refunded_at' => $this->dateTime($refund->refunded_at),
            ])->values(),
            'splits' => $invoice->splits->map(fn ($split) => [
                'id' => (int) $split->id,
                'label' => $split->label,
                'amount' => (float) $split->amount,
                'method' => $split->method,
                'paid' => (bool) $split->paid,
                'paid_at' => $this->dateTime($split->paid_at),
            ])->values(),
            'print_url' => "/admin/cashier/invoice/{$invoice->id}/print",
            'pdf_url' => "/admin/cashier/invoice/{$invoice->id}/pdf",
        ];
    }

    private function customerPayload($customer, ?string $fallbackName, ?string $fallbackPhone): array
    {
        if (! $customer) {
            return [
                'id' => null,
                'name' => $fallbackName,
                'phone' => $fallbackPhone,
                'visits' => 0,
                'debt' => 0.0,
                'credit_limit' => null,
                'credit_available' => null,
                'advance_balance' => 0.0,
                'loyalty' => null,
            ];
        }

        return [
            'id' => (int) $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'visits' => (int) ($customer->table_sessions_count ?? 0),
            'debt' => $customer->outstandingDebt(),
            'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
            'credit_available' => $customer->creditAvailable(),
            'advance_balance' => (float) $customer->advance_balance,
            'loyalty' => $customer->loyaltyCustomer ? [
                'tier' => $customer->loyaltyCustomer->tier,
                'tier_label' => $customer->loyaltyCustomer->tierLabel(),
                'points' => (int) $customer->loyaltyCustomer->points_balance,
            ] : null,
        ];
    }

    private function advanceCustomerPayload(Customer $customer): array
    {
        // The wallet belongs to the phone/customer, not to one branch. Keep
        // the movement's branch for auditing, but show the cashier the full
        // balance history wherever the customer visits.
        $transactions = $customer->advanceTransactions()
            ->withoutGlobalScope(BranchScope::class)
            ->with(['invoice:id,number', 'creator:id,name'])
            ->limit(8)
            ->get();
        $reversedIds = CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
            ->whereIn('reversed_transaction_id', $transactions->pluck('id'))
            ->pluck('reversed_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $user = auth()->user();

        return [
            'id' => (int) $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'advance_balance' => (float) $customer->advance_balance,
            'debt' => $customer->outstandingDebt(),
            'transactions' => $transactions->map(fn (CustomerAdvanceTransaction $transaction) => [
                'id' => (int) $transaction->id,
                'type' => $transaction->type,
                'type_label' => $transaction->typeLabel(),
                'amount' => (float) $transaction->amount,
                'signed_amount' => $transaction->signedAmount(),
                'balance_after' => (float) $transaction->balance_after,
                'method_label' => $transaction->payment_method ? PaymentMethods::label($transaction->payment_method) : null,
                'invoice' => $transaction->invoice?->number,
                'creator' => $transaction->creator?->name,
                'occurred_at' => $this->dateTime($transaction->occurred_at),
                'can_reverse' => $transaction->type === CustomerAdvanceTransaction::DEPOSIT
                    && ! in_array((int) $transaction->id, $reversedIds, true)
                    && ($user?->hasPermission('payments.void')
                        || ($user?->hasPermission('payments.void_own')
                            && (int) $transaction->created_by_user_id === (int) $user->id
                            && $transaction->occurred_at?->isToday())),
            ])->values(),
        ];
    }

    private function todayStats(): array
    {
        $user = auth()->user();
        $userId = (int) $user?->id;

        $payments = Payment::query()
            ->where('received_by_user_id', $userId)
            ->whereDate('paid_at', today());
        $tenderPayments = (clone $payments)->where('method', '!=', PaymentMethods::CUSTOMER_ADVANCE);
        $refunds = Refund::query()
            ->where('processed_by', $userId)
            ->whereDate('refunded_at', today())
            ->where('status', 'completed');

        $paymentTotals = (clone $tenderPayments)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($total) => (float) $total)
            ->all();

        $refundTotals = (clone $refunds)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($total) => (float) $total)
            ->all();

        $methods = [];
        foreach (array_unique([
            ...array_keys(PaymentMethods::catalog()),
            ...array_keys($paymentTotals),
            ...array_keys($refundTotals),
        ]) as $method) {
            $methods[$method] = (float) ($paymentTotals[$method] ?? 0)
                - (float) ($refundTotals[$method] ?? 0);
        }

        $gross = (float) array_sum($paymentTotals);
        $refunded = (float) array_sum($refundTotals);

        return [
            'collector_name' => $user?->name ?? 'المستخدم الحالي',
            'payments_count' => (clone $tenderPayments)->count(),
            'refunds_count' => (clone $refunds)->count(),
            'gross' => $gross,
            'refunds' => $refunded,
            'net' => $gross - $refunded,
            'cash' => (float) ($methods['cash'] ?? 0),
            'non_cash' => (float) collect($methods)->except('cash')->sum(),
            'advance_used' => (float) (clone $payments)->where('method', PaymentMethods::CUSTOMER_ADVANCE)->sum('amount'),
            'methods' => $methods,
        ];
    }

    private function abilities(): array
    {
        $user = auth()->user();

        return [
            'create_order' => $user?->can('create', Order::class) ?? false,
            'create_customer' => $user?->can('create', Customer::class) ?? false,
            'collect_payment' => $user?->can('create', Payment::class) ?? false,
            'refund' => $user?->can('create', Refund::class) ?? false,
            'discount' => $user?->can('apply', OrderDiscount::class) ?? false,
            'remove_discount' => $user?->hasPermission('discounts.remove') ?? false,
            'discount_cap' => $user
                ? app(OrderDiscountService::class)->userCap($user)
                : null,
            'void_payment' => $user
                ? ($user->hasPermission('payments.void') || $user->hasPermission('payments.void_own'))
                : false,
            'writeoff' => $user?->hasPermission('payments.writeoff') ?? false,
            'cancel_invoice' => $user?->hasPermission('payments.cancel_invoice') ?? false,
            'settle_on_account' => $user?->hasPermission('payments.settle_on_account') ?? false,
            'record_transfer' => $user?->can('create', Payment::class) ?? false,
            'verify_transfer' => $user?->can('create', Payment::class) ?? false,
        ];
    }

    private function screenOptions(Request $request): array
    {
        return [
            'payment_methods' => collect(PaymentMethods::catalog())
                ->filter(fn (array $method) => (bool) $method['enabled'])
                ->map(fn (array $method, string $code) => [
                    'code' => $code,
                    'label' => $method['label'],
                    'icon' => $method['icon'],
                ])->values(),
            'refund_methods' => collect(Refund::ACTIVE_METHODS)->map(fn (string $code) => [
                'code' => $code,
                'label' => Refund::METHODS[$code] ?? $code,
            ])->values(),
            'discount_categories' => Lookup::for('discount_categories')->map(fn (Lookup $lookup) => [
                'id' => (int) $lookup->id,
                'label' => $lookup->label,
            ])->values(),
            'currency' => [
                'code' => strtoupper((string) config('restaurant.currency', 'ILS')),
                'symbol' => (string) Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪')),
                'decimals' => 2,
            ],
            'branch_id' => BranchContext::current(),
            'csrf_token' => $request->session()->token(),
        ];
    }

    private function menuCatalog(): array
    {
        $items = MenuItem::query()
            ->where('is_available', true)
            ->with(['category', 'station:id,storage_location_id', 'modifierGroups.modifiers', 'recipeItems.ingredient'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
        $hasPromotions = MenuPromotion::query()
            ->where('active', true)
            ->where(fn ($promotion) => $promotion
                ->whereNull('branch_id')
                ->orWhere('branch_id', BranchContext::current()))
            ->exists();
        $inventory = app(InventoryService::class);

        return [
            'categories' => $items->groupBy('category_id')->map(fn (Collection $group) => [
                'id' => (int) $group->first()->category_id,
                'name' => $group->first()->category?->name ?? '',
                'count' => $group->count(),
            ])->sortBy('name')->values(),
            'items' => $items->map(function (MenuItem $item) use ($hasPromotions, $inventory) {
                $promotion = $hasPromotions ? $item->activePromotion() : null;
                $price = $promotion ? (float) $promotion->applyTo((float) $item->price) : (float) $item->price;

                return [
                    'id' => (int) $item->id,
                    'category_id' => (int) $item->category_id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'price' => $price,
                    'original_price' => (float) $item->price,
                    'has_promo' => $promotion && $price < (float) $item->price,
                    'in_stock' => $this->itemInStock($inventory, $item),
                    'modifier_groups' => $item->modifierGroups->map(fn ($group) => [
                        'id' => (int) $group->id,
                        'name' => $group->name,
                        'required' => (bool) $group->required,
                        'min_select' => (int) $group->min_select,
                        'max_select' => (int) $group->max_select,
                        'modifiers' => $group->modifiers->map(fn ($modifier) => [
                            'id' => (int) $modifier->id,
                            'name' => $modifier->name,
                            'price_delta' => (float) $modifier->price_delta,
                        ])->values(),
                    ])->values(),
                ];
            })->values(),
        ];
    }

    private function itemInStock(InventoryService $inventory, MenuItem $item): bool
    {
        $needs = [];
        foreach ($inventory->previewDeductionForItem($item, 1.0) as $line) {
            $ingredientId = (int) $line['ingredient_id'];
            $needs[$ingredientId] ??= ['ingredient' => $line['ingredient'], 'quantity' => 0.0];
            $needs[$ingredientId]['quantity'] += (float) $line['quantity_in_base'];
        }

        foreach ($needs as $need) {
            $locationId = $item->station?->storage_location_id;
            $available = $locationId
                ? $need['ingredient']->usableStockAtLocation((int) $locationId)
                : $need['ingredient']->usableStockAtBranch((int) ($item->branch_id ?: BranchContext::current()));
            if ($need['ingredient']->track_stock
                && $need['quantity'] > $available) {
                return false;
            }
        }

        return true;
    }

    private function pulseVersion(): string
    {
        return LiveRefreshPulse::version(BranchContext::current());
    }

    private function dateTime($value): ?string
    {
        return $value?->toAtomString();
    }

    private function idempotentCommand(string $scope, string $token, \Closure $callback): JsonResponse
    {
        $key = $this->idempotencyKey($scope, $token);

        if (! Cache::add($key, true, now()->addMinutes(10))) {
            return $this->duplicateCommandResponse();
        }

        $response = $this->command($callback);
        if (! $response->isSuccessful()) {
            Cache::forget($key);
        }

        return $response;
    }

    private function idempotencyKey(string $scope, string $token): string
    {
        return 'cashier:'.$scope.':'.auth()->id().':'.$token;
    }

    private function duplicateCommandResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'تم تنفيذ هذه العملية من قبل — مُنع إرسال مكرر.',
        ], 409);
    }

    /** @param \Closure():array{message:string,data?:array,warning?:string} $callback */
    private function command(\Closure $callback): JsonResponse
    {
        try {
            $result = $callback();

            return response()->json([
                'ok' => true,
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
                'warning' => $result['warning'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => 'حدث خطأ غير متوقع — لم تُسجل العملية. حدّث الشاشة وراجع السجل قبل المحاولة.',
            ], 500);
        }
    }
}
