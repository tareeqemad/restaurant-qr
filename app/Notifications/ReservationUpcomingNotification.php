<?php

namespace App\Notifications;

use App\Models\Reservation;

/**
 * Heads-up that a confirmed reservation is starting soon (default: within
 * the next hour). Fired by the `notifications:reservation-reminders`
 * scheduled command, NOT by an HTTP request — so the BranchContext
 * captured in the parent constructor is unreliable. We force-set
 * branchId from the reservation row itself.
 */
class ReservationUpcomingNotification extends BaseNotification
{
    public function __construct(public Reservation $reservation)
    {
        parent::__construct();
        $this->branchId = $reservation->branch_id;
    }

    public function typeKey(): string { return 'reservation.upcoming'; }
    public function severity(): string { return 'info'; }
    public function icon(): string    { return 'bi-calendar-event-fill'; }

    public function title(): string
    {
        $when = $this->reservation->reserved_for?->format('H:i') ?? '—';
        return "حجز قادم الساعة {$when}";
    }

    public function body(): string
    {
        $name  = $this->reservation->customer?->name ?? 'بدون اسم';
        $party = $this->reservation->party_size ?? '—';
        $table = $this->reservation->table?->number
            ? " · طاولة {$this->reservation->table->number}"
            : '';
        return "{$name} · عدد الضيوف {$party}{$table}";
    }

    public function actionUrl(): string
    {
        return route('admin.reservations.edit', $this->reservation);
    }

    public function actionLabel(): string
    {
        return 'افتح الحجز';
    }

    public function extra(): array
    {
        return [
            'reservation_id' => $this->reservation->id,
            'reference'      => $this->reservation->reference,
            'reserved_for'   => $this->reservation->reserved_for?->toIso8601String(),
        ];
    }
}
