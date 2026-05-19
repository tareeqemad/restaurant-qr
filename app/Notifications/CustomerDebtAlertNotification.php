<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Models\Invoice;

/**
 * Fires when a customer's running debt crosses their credit_limit. We
 * deliberately only ping when there IS a ceiling AND it's been reached
 * or exceeded — quieter inbox, louder when it matters.
 *
 * Severity escalates from `info` (right at the cap) to `warning` (above
 * it). Stays out of `danger` because customer debt isn't a same-night
 * fire — managers can address it during their normal review.
 */
class CustomerDebtAlertNotification extends BaseNotification
{
    public function __construct(
        public Customer $customer,
        public float    $outstanding,
        public float    $creditLimit,
        public ?Invoice $invoice = null,
    ) {
        parent::__construct();
        // Branch context falls back to the customer's default branch when
        // no invoice is in play (e.g. a payment that closed all their
        // debts still pings managers of the customer's home branch).
        $this->branchId = $invoice?->branch_id ?? $customer->default_branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'customer.debt_limit'; }

    public function severity(): string
    {
        // > 0.01 ש"ח past the cap counts as "over", anything else is
        // exactly-at-cap → still actionable but not yet escalating.
        return $this->outstanding - $this->creditLimit > 0.01 ? 'warning' : 'info';
    }

    public function icon(): string
    {
        return 'bi-wallet2';
    }

    public function title(): string
    {
        return $this->outstanding > $this->creditLimit
            ? "تجاوز حد الدين: {$this->customer->name}"
            : "بلغ الحد الائتماني: {$this->customer->name}";
    }

    public function body(): string
    {
        $debt  = number_format($this->outstanding, 2);
        $limit = number_format($this->creditLimit, 2);
        return "إجمالي الدين الحالي: {$debt} ش.إ · الحد: {$limit} ش.إ";
    }

    public function actionUrl(): string
    {
        return route('admin.customers.debts.show', $this->customer);
    }

    public function actionLabel(): string
    {
        return 'افتح سجل الزبون';
    }

    public function extra(): array
    {
        return [
            'customer_id'   => $this->customer->id,
            'outstanding'   => $this->outstanding,
            'credit_limit'  => $this->creditLimit,
            'invoice_id'    => $this->invoice?->id,
        ];
    }
}
