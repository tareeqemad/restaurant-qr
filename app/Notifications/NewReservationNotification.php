<?php

namespace App\Notifications;

use App\Models\Reservation;

class NewReservationNotification extends BaseNotification
{
    public function __construct(public Reservation $reservation)
    {
        parent::__construct();
        $this->branchId = $reservation->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'reservation.new'; }
    public function severity(): string { return 'info'; }
    public function icon(): string    { return 'bi-calendar-plus-fill'; }

    public function title(): string
    {
        return "حجز جديد: {$this->reservation->reference}";
    }

    public function body(): string
    {
        $name  = $this->reservation->customer?->name ?? 'بدون اسم';
        $when  = optional($this->reservation->reserved_for)->format('Y-m-d H:i') ?? '—';
        $party = $this->reservation->party_size ?? '—';
        return "{$name} · {$when} · عدد الضيوف {$party}";
    }

    public function actionUrl(): string
    {
        return route('admin.reservations.edit', $this->reservation);
    }

    public function actionLabel(): string
    {
        return 'مراجعة الحجز';
    }

    public function extra(): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'reference'      => $this->reservation->reference,
        ];
    }
}
