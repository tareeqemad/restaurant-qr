<?php

namespace App\Notifications;

use App\Models\BranchTransfer;

/**
 * Sent to staff at the RECEIVING branch when a transfer is dispatched.
 * Their action: open the transfer + click "استلام" once the goods arrive.
 *
 * The branch_id on this notification is set to the RECEIVING branch's id
 * (not the sender's), since that's who needs to act on it. The audience
 * filter in NotifyService ensures only the receiving branch's staff see it.
 */
class BranchTransferSentNotification extends BaseNotification
{
    public function __construct(public BranchTransfer $transfer)
    {
        parent::__construct();
        // Pin to the RECEIVING branch — that's who needs to act on this.
        $this->branchId = $transfer->to_branch_id;
    }

    public function typeKey(): string { return 'branch_transfer.sent'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-truck'; }

    public function title(): string
    {
        return "تحويل قادم: {$this->transfer->number}";
    }

    public function body(): string
    {
        $from  = $this->transfer->fromBranch?->name ?? 'فرع غير معروف';
        $count = $this->transfer->items()->count();
        return "من {$from} · {$count} بند · في الطريق إليكم";
    }

    public function actionUrl(): string
    {
        return route('admin.branch-transfers.show', $this->transfer);
    }

    public function actionLabel(): string
    {
        return 'افتح للاستلام';
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
