<?php

namespace App\Notifications;

use App\Models\Refund;

class RefundIssuedNotification extends BaseNotification
{
    public function __construct(public Refund $refund)
    {
        parent::__construct();
        $this->branchId = $refund->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'refund.issued'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-arrow-counterclockwise'; }

    public function title(): string
    {
        $amount = number_format((float) $this->refund->amount, 2);
        return "استرداد جديد: {$amount} ₪";
    }

    public function body(): string
    {
        $invoiceNo = $this->refund->invoice?->number ?? '—';
        $reason    = $this->refund->reason ?: 'بدون سبب محدد';
        return "فاتورة {$invoiceNo} · {$reason}";
    }

    public function actionUrl(): string
    {
        return route('admin.refunds.index');
    }

    public function actionLabel(): string
    {
        return 'افتح الاستردادات';
    }

    public function extra(): array
    {
        return [
            'refund_id'  => $this->refund->id,
            'invoice_id' => $this->refund->invoice_id,
            'amount'     => (float) $this->refund->amount,
        ];
    }
}
