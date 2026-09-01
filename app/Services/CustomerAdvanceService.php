<?php

namespace App\Services;

use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAdvanceTransaction;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Scopes\BranchScope;
use App\Services\Accounting\AccountingService;
use App\Support\PaymentMethods;
use Illuminate\Support\Facades\DB;

class CustomerAdvanceService
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function deposit(
        Customer $customer,
        float $amount,
        string $method,
        int $branchId,
        int $userId,
        ?string $reference = null,
        ?string $notes = null,
        ?Payment $sourcePayment = null,
    ): CustomerAdvanceTransaction {
        if (! PaymentMethods::isEnabled($method)) {
            throw new \RuntimeException('طريقة استلام الرصيد غير مفعلة في إعدادات المطعم.');
        }

        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw new \RuntimeException('قيمة الرصيد المقدم يجب أن تكون أكبر من صفر.');
        }

        $transaction = DB::transaction(function () use ($customer, $amount, $method, $branchId, $userId, $reference, $notes, $sourcePayment) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($customer->isBlocked()) {
                throw new \RuntimeException('ملف الزبون محظور ويحتاج مراجعة الإدارة قبل إضافة رصيد.');
            }

            $balanceAfter = Money::round((float) $customer->advance_balance + $amount);
            $customer->update(['advance_balance' => $balanceAfter]);

            $transaction = CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'invoice_id' => $sourcePayment?->invoice_id,
                'payment_id' => $sourcePayment?->id,
                'type' => CustomerAdvanceTransaction::DEPOSIT,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'payment_method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'created_by_user_id' => $userId,
                'occurred_at' => now(),
            ]);

            $this->accounting->recordCustomerAdvanceDeposit($transaction);

            return $transaction;
        });

        ActivityLog::log(
            'customer_advance.deposited',
            'إضافة رصيد مقدم للزبون '.$customer->name.' بمبلغ '.number_format($amount, 2),
            $transaction,
            ['customer_id' => $customer->id, 'method' => $method, 'balance_after' => (float) $transaction->balance_after],
        );

        return $transaction;
    }

    public function openingBalance(
        Customer $customer,
        float $amount,
        int $branchId,
        int $userId,
        string $postedOn,
        ?string $notes = null,
    ): CustomerAdvanceTransaction {
        $amount = Money::round($amount);
        if ($amount <= 0) {
            throw new \RuntimeException('الرصيد الافتتاحي يجب أن يكون أكبر من صفر.');
        }

        $transaction = DB::transaction(function () use ($customer, $amount, $branchId, $userId, $postedOn, $notes) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $balanceAfter = Money::round((float) $customer->advance_balance + $amount);
            $customer->update(['advance_balance' => $balanceAfter]);

            $transaction = CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'type' => CustomerAdvanceTransaction::OPENING_BALANCE,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'notes' => $notes,
                'created_by_user_id' => $userId,
                'occurred_at' => $postedOn,
            ]);

            $this->accounting->recordCustomerAdvanceOpeningBalance($transaction);

            return $transaction;
        });

        ActivityLog::log(
            'customer_advance.opening_balance',
            'إضافة رصيد مقدم افتتاحي للزبون '.$customer->name.' بمبلغ '.number_format($amount, 2),
            $transaction,
            ['customer_id' => $customer->id, 'balance_after' => (float) $transaction->balance_after],
        );

        return $transaction;
    }

    public function redeemForPayment(Customer $customer, Payment $payment, int $userId): CustomerAdvanceTransaction
    {
        if ($payment->method !== PaymentMethods::CUSTOMER_ADVANCE) {
            throw new \LogicException('لا يمكن خصم الرصيد على دفعة ليست من رصيد الزبون.');
        }

        return DB::transaction(function () use ($customer, $payment, $userId) {
            $payment->loadMissing('invoice');
            if ((int) $payment->invoice?->customer_id !== (int) $customer->id) {
                throw new \RuntimeException('رصيد الزبون لا يطابق صاحب الفاتورة.');
            }

            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $amount = Money::round((float) $payment->amount);
            if ($amount - (float) $customer->advance_balance > 0.001) {
                throw new \RuntimeException('رصيد الزبون المقدم غير كافٍ لهذه الدفعة.');
            }

            $balanceAfter = Money::round((float) $customer->advance_balance - $amount);
            $customer->update(['advance_balance' => $balanceAfter]);

            return CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $payment->branch_id,
                'invoice_id' => $payment->invoice_id,
                'payment_id' => $payment->id,
                'type' => CustomerAdvanceTransaction::REDEMPTION,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'notes' => 'استخدام الرصيد في الفاتورة '.$payment->invoice?->number,
                'created_by_user_id' => $userId,
                'occurred_at' => $payment->paid_at ?: now(),
            ]);
        });
    }

    /**
     * A payment can have two wallet effects: a redemption that paid the bill,
     * or a cash-change deposit. Both must unwind before the payment disappears.
     */
    public function reversePaymentEffects(Payment $payment, int $userId, string $reason): void
    {
        $effects = CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
            ->where('payment_id', $payment->id)
            ->whereIn('type', [CustomerAdvanceTransaction::DEPOSIT, CustomerAdvanceTransaction::REDEMPTION])
            ->whereNotIn('id', CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                ->whereNotNull('reversed_transaction_id')
                ->select('reversed_transaction_id'))
            ->orderBy('id')
            ->get();

        foreach ($effects as $effect) {
            if ($effect->type === CustomerAdvanceTransaction::DEPOSIT) {
                $this->reverseDeposit($effect, $userId, $reason);

                continue;
            }

            DB::transaction(function () use ($effect, $userId, $reason) {
                $customer = Customer::query()->whereKey($effect->customer_id)->lockForUpdate()->firstOrFail();
                $balanceAfter = Money::round((float) $customer->advance_balance + (float) $effect->amount);
                $customer->update(['advance_balance' => $balanceAfter]);

                CustomerAdvanceTransaction::create([
                    'customer_id' => $customer->id,
                    'branch_id' => $effect->branch_id,
                    'invoice_id' => $effect->invoice_id,
                    'payment_id' => $effect->payment_id,
                    'reversed_transaction_id' => $effect->id,
                    'type' => CustomerAdvanceTransaction::REDEMPTION_REVERSAL,
                    'amount' => $effect->amount,
                    'balance_after' => $balanceAfter,
                    'notes' => 'عكس استخدام الرصيد: '.$reason,
                    'created_by_user_id' => $userId,
                    'occurred_at' => now(),
                ]);
            });
        }
    }

    public function reverseDeposit(CustomerAdvanceTransaction $deposit, int $userId, string $reason): CustomerAdvanceTransaction
    {
        return DB::transaction(function () use ($deposit, $userId, $reason) {
            $deposit = CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                ->whereKey($deposit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposit->type !== CustomerAdvanceTransaction::DEPOSIT) {
                throw new \RuntimeException('يمكن عكس عمليات الإيداع النقدي فقط من هذا الإجراء.');
            }
            if (CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                ->where('reversed_transaction_id', $deposit->id)
                ->exists()) {
                throw new \RuntimeException('تم عكس عملية الرصيد مسبقاً.');
            }

            $customer = Customer::query()->whereKey($deposit->customer_id)->lockForUpdate()->firstOrFail();
            if ((float) $deposit->amount - (float) $customer->advance_balance > 0.001) {
                throw new \RuntimeException('تعذّر عكس الإيداع لأن الزبون استخدم جزءاً من هذا الرصيد.');
            }

            $balanceAfter = Money::round((float) $customer->advance_balance - (float) $deposit->amount);
            $customer->update(['advance_balance' => $balanceAfter]);

            $reversal = CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $deposit->branch_id,
                'invoice_id' => $deposit->invoice_id,
                'payment_id' => $deposit->payment_id,
                'reversed_transaction_id' => $deposit->id,
                'type' => CustomerAdvanceTransaction::DEPOSIT_REVERSAL,
                'amount' => $deposit->amount,
                'balance_after' => $balanceAfter,
                'payment_method' => $deposit->payment_method,
                'reference' => $deposit->reference,
                'notes' => 'عكس الإيداع: '.$reason,
                'created_by_user_id' => $userId,
                'occurred_at' => now(),
            ]);

            $this->accounting->reverseCustomerAdvanceDeposit($deposit, $reversal, $userId, $reason);

            ActivityLog::log(
                'customer_advance.deposit_reversed',
                'عكس إيداع رصيد مقدم للزبون '.$customer->name.' — '.$reason,
                $reversal,
                ['original_transaction_id' => $deposit->id, 'balance_after' => $balanceAfter],
            );

            return $reversal;
        });
    }

    /** Credit a completed refund to the customer's stored balance. GL is
     * posted by RefundService, so this method only maintains the sub-ledger. */
    public function creditRefundAllocations(Refund $refund, int $userId): ?CustomerAdvanceTransaction
    {
        $refund->loadMissing('invoice.customer', 'allocations');
        $amount = Money::round((float) $refund->allocations
            ->where('method', PaymentMethods::CUSTOMER_ADVANCE)
            ->sum('amount'));
        if ($amount <= 0.001) {
            return null;
        }
        $customer = $refund->invoice?->customer;
        if (! $customer) {
            throw new \RuntimeException('لا يمكن إضافة الاسترداد إلى الرصيد دون زبون مرتبط بالفاتورة.');
        }

        return DB::transaction(function () use ($refund, $customer, $amount, $userId) {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                ->where('refund_id', $refund->id)
                ->where('type', CustomerAdvanceTransaction::REFUND_CREDIT)
                ->exists()) {
                return CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                    ->where('refund_id', $refund->id)
                    ->where('type', CustomerAdvanceTransaction::REFUND_CREDIT)
                    ->first();
            }

            $balanceAfter = Money::round((float) $customer->advance_balance + $amount);
            $customer->update(['advance_balance' => $balanceAfter]);

            return CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $refund->branch_id,
                'invoice_id' => $refund->invoice_id,
                'refund_id' => $refund->id,
                'type' => CustomerAdvanceTransaction::REFUND_CREDIT,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'notes' => 'استرداد '.$refund->number.' إلى رصيد الزبون',
                'created_by_user_id' => $userId,
                'occurred_at' => $refund->refunded_at ?: now(),
            ]);
        });
    }

    public function reverseRefundCredits(Refund $refund, int $userId, string $reason): void
    {
        $credits = CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
            ->where('refund_id', $refund->id)
            ->where('type', CustomerAdvanceTransaction::REFUND_CREDIT)
            ->whereNotIn('id', CustomerAdvanceTransaction::withoutGlobalScope(BranchScope::class)
                ->whereNotNull('reversed_transaction_id')->select('reversed_transaction_id'))
            ->lockForUpdate()
            ->get();

        foreach ($credits as $credit) {
            $customer = Customer::query()->whereKey($credit->customer_id)->lockForUpdate()->firstOrFail();
            if ((float) $credit->amount - (float) $customer->advance_balance > 0.001) {
                throw new \RuntimeException('لا يمكن عكس الاسترداد لأن الزبون استخدم جزءاً من الرصيد الناتج عنه.');
            }
            $balanceAfter = Money::round((float) $customer->advance_balance - (float) $credit->amount);
            $customer->update(['advance_balance' => $balanceAfter]);
            CustomerAdvanceTransaction::create([
                'customer_id' => $customer->id,
                'branch_id' => $credit->branch_id,
                'invoice_id' => $credit->invoice_id,
                'refund_id' => $refund->id,
                'reversed_transaction_id' => $credit->id,
                'type' => CustomerAdvanceTransaction::REFUND_CREDIT_REVERSAL,
                'amount' => $credit->amount,
                'balance_after' => $balanceAfter,
                'notes' => 'عكس رصيد استرداد: '.$reason,
                'created_by_user_id' => $userId,
                'occurred_at' => now(),
            ]);
        }
    }
}
