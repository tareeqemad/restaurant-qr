<?php

namespace App\Notifications;

use App\Models\TableSession;

/**
 * Diner tapped "request bill" from the QR portal — the cashier and the
 * waiter assigned to the table both need to know NOW so the diner isn't
 * left waiting at a finished meal.
 */
class BillRequestedNotification extends BaseNotification
{
    public function __construct(public TableSession $session)
    {
        parent::__construct();
        $this->branchId = $session->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'bill.requested'; }
    public function severity(): string { return 'warning'; }
    public function icon(): string    { return 'bi-receipt-cutoff'; }

    public function title(): string
    {
        $tableNo = $this->session->table?->number ?? '—';
        return "طلب فاتورة من طاولة {$tableNo}";
    }

    public function body(): string
    {
        $note = trim((string) $this->session->bill_request_note);
        if ($note !== '') {
            return mb_strlen($note) > 90 ? mb_substr($note, 0, 87).'…' : $note;
        }
        $when = $this->session->bill_requested_at?->format('H:i') ?? '—';
        return "تم الطلب الساعة {$when} — جهّز الفاتورة فوراً.";
    }

    public function actionUrl(): string
    {
        // The cashier flow opens at the table (sessions index/show) — adjust
        // the route name if your project uses a different cashier surface.
        return route('admin.tables.index');
    }

    public function actionLabel(): string
    {
        return 'افتح الطاولات';
    }

    public function extra(): array
    {
        return [
            'session_id' => $this->session->id,
            'table_id'   => $this->session->table_id,
        ];
    }
}
