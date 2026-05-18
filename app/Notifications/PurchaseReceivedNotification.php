<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;

class PurchaseReceivedNotification extends BaseNotification
{
    /** @param bool $partial true when only some lines were received */
    public function __construct(public PurchaseOrder $po, public bool $partial = false)
    {
        parent::__construct();
        $this->branchId = $po->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'purchase.received'; }
    public function severity(): string { return $this->partial ? 'info' : 'success'; }
    public function icon(): string    { return 'bi-box-seam'; }

    public function title(): string
    {
        $verb = $this->partial ? 'استلام جزئي' : 'استلام كامل';
        return "{$verb}: {$this->po->number}";
    }

    public function body(): string
    {
        $supplier = $this->po->supplier?->name ?? 'مورد غير محدد';
        $total    = number_format((float) $this->po->total_amount, 2);
        return "المورّد: {$supplier} · القيمة: {$total} ₪";
    }

    public function actionUrl(): string
    {
        return route('admin.purchase-orders.show', $this->po);
    }

    public function actionLabel(): string
    {
        return 'افتح أمر الشراء';
    }

    public function extra(): array
    {
        return [
            'purchase_order_id' => $this->po->id,
            'po_number'         => $this->po->number,
            'partial'           => $this->partial,
        ];
    }
}
