<?php

namespace App\Notifications;

use App\Models\OrderChangeRequest;

class OrderChangeRequestedNotification extends BaseNotification
{
    public function __construct(public OrderChangeRequest $changeRequest)
    {
        parent::__construct();
        $this->branchId = $changeRequest->branch_id ?? $this->branchId;
    }

    public function typeKey(): string
    {
        return 'order.change';
    }

    public function severity(): string
    {
        return 'warning';
    }

    public function icon(): string
    {
        return 'bi-pencil-square';
    }

    public function title(): string
    {
        $table = $this->changeRequest->order?->table?->number;

        return 'تعديل من الزبون'.($table ? " · طاولة {$table}" : '');
    }

    public function body(): string
    {
        $parts = [$this->changeRequest->typeLabel()];
        if ($name = $this->changeRequest->orderItem?->name_snapshot) {
            $parts[] = $name;
        }
        if ($this->changeRequest->requested_quantity !== null) {
            $parts[] = 'الكمية الجديدة '.$this->formatQty((float) $this->changeRequest->requested_quantity);
        }
        if ($note = trim((string) $this->changeRequest->request_note)) {
            $parts[] = mb_strlen($note) > 70 ? mb_substr($note, 0, 67).'…' : $note;
        }

        return implode(' · ', $parts);
    }

    public function actionUrl(): string
    {
        return route('admin.orders.index', [
            'table_id' => $this->changeRequest->order?->table_id,
        ]);
    }

    public function actionLabel(): string
    {
        return 'راجع التعديل';
    }

    public function extra(): array
    {
        return [
            'request_id' => $this->changeRequest->id,
            'order_id' => $this->changeRequest->order_id,
            'table_id' => $this->changeRequest->order?->table_id,
        ];
    }

    protected function formatQty(float $quantity): string
    {
        return $quantity == floor($quantity)
            ? (string) (int) $quantity
            : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}
