<?php

namespace App\Notifications;

use App\Models\TableSession;

class WaiterHelpNotification extends BaseNotification
{
    public function __construct(public TableSession $session)
    {
        parent::__construct();
        $this->branchId = $session->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'table.help'; }
    public function severity(): string { return 'danger'; }
    public function icon(): string { return 'bi-hand-index-thumb-fill'; }
    public function title(): string { return 'نداء من طاولة '.$this->session->tableLabel(); }

    public function body(): string
    {
        return trim((string) $this->session->help_request_note) ?: 'الزبون يحتاج الجرسون الآن.';
    }

    public function actionUrl(): string
    {
        return route('admin.orders.index', ['table_id' => $this->session->table_id]);
    }

    public function actionLabel(): string { return 'أنا ذاهب'; }

    public function extra(): array
    {
        return ['session_id' => $this->session->id, 'table_id' => $this->session->table_id];
    }
}
