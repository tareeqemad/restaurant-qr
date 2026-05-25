<?php

namespace App\Notifications;

use App\Models\PendingTransfer;

/**
 * Waiter recorded a bank transfer the diner claims they wired —
 * cashier needs to check the bank app and confirm or reject. Fires on
 * the standard in-app notifications channel, which the header bell
 * picks up via its 30s poll.
 */
class PendingTransferRecordedNotification extends BaseNotification
{
    public function __construct(public PendingTransfer $transfer)
    {
        parent::__construct();
        $this->branchId = $transfer->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'pending_transfer.recorded'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-bank'; }

    public function title(): string
    {
        $tableNo = $this->transfer->tableSession?->table?->number ?? '—';
        $amount  = number_format((float) $this->transfer->amount, 2);
        return "تحويل بانتظار التأكيد — طاولة {$tableNo} ({$amount})";
    }

    public function body(): string
    {
        $sender = $this->transfer->sender_name ?: '—';
        $customer = $this->transfer->customer_name_snapshot;
        return $customer
            ? "المحوّل: {$sender} • الزبون: {$customer}"
            : "المحوّل: {$sender}";
    }

    public function actionUrl(): string
    {
        return route('admin.cashier.transfers.queue');
    }

    public function actionLabel(): string
    {
        return 'افتح قائمة التحويلات';
    }

    public function extra(): array
    {
        return [
            'pending_transfer_id' => $this->transfer->id,
            'table_session_id'    => $this->transfer->table_session_id,
            'amount'              => (float) $this->transfer->amount,
        ];
    }
}
