<?php

namespace App\Notifications;

use App\Models\BranchTransfer;

/**
 * Sent to staff at the SENDING branch when the receiving branch confirms
 * arrival. Closes the loop so the sender knows their stock is no longer
 * "in transit limbo" — it's safely accounted for at the destination.
 *
 * branch_id is pinned to the SENDING branch since that's the audience.
 */
class BranchTransferReceivedNotification extends BaseNotification
{
    public function __construct(public BranchTransfer $transfer)
    {
        parent::__construct();
        // Pin to the SENDING branch — that's who is waiting for confirmation.
        $this->branchId = $transfer->from_branch_id;
    }

    public function typeKey(): string { return 'branch_transfer.received'; }
    public function severity(): string { return 'success'; }
    public function icon(): string    { return 'bi-check-circle-fill'; }

    public function title(): string
    {
        return "وصل تحويلك: {$this->transfer->number}";
    }

    public function body(): string
    {
        $to    = $this->transfer->toBranch?->name ?? 'الفرع المستلم';
        $count = $this->transfer->items()->count();
        return "{$to} استلم {$count} بند بنجاح";
    }

    public function actionUrl(): string
    {
        return route('admin.branch-transfers.show', $this->transfer);
    }

    public function actionLabel(): string
    {
        return 'افتح التحويل';
    }

    public function extra(): array
    {
        return [
            'transfer_id' => $this->transfer->id,
            'number'      => $this->transfer->number,
            'from_branch' => $this->transfer->from_branch_id,
            'to_branch'   => $this->transfer->to_branch_id,
        ];
    }
}
