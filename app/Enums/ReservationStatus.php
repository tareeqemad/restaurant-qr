<?php

namespace App\Enums;

/**
 * Reservation lifecycle.
 *
 *   pending     — created, awaiting branch confirmation (only when the
 *                 requested slot is over capacity).
 *   confirmed   — auto-confirmed (capacity available) or manually
 *                 confirmed by a manager.
 *   seated      — guest arrived and was seated; ties to a Table row.
 *   completed   — guest paid and left; reservation closed normally.
 *   cancelled   — cancelled by the customer or branch before seating.
 *   no_show     — passed grace period without arrival.
 *
 * Allowed transitions (enforced in Reservation::transitionTo):
 *   pending     → confirmed | cancelled
 *   confirmed   → seated | cancelled | no_show
 *   seated      → completed | cancelled
 *   completed   → (terminal)
 *   cancelled   → (terminal)
 *   no_show     → (terminal)
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Seated = 'seated';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('portal.reservations.status_pending'),
            self::Confirmed => __('portal.reservations.status_confirmed'),
            self::Seated => __('portal.reservations.status_seated'),
            self::Completed => __('portal.reservations.status_completed'),
            self::Cancelled => __('portal.reservations.status_cancelled'),
            self::NoShow => __('portal.reservations.status_no_show'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Seated => 'info',
            self::Completed => 'secondary',
            self::Cancelled => 'danger',
            self::NoShow => 'dark',
        };
    }

    /** Reservations a customer can still cancel through a customer-facing channel. */
    public function isCancellableByCustomer(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }

    /** Terminal states — no further transitions allowed. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow], true);
    }

    /** Allowed forward transitions per state. */
    public function nextStates(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Seated, self::Cancelled, self::NoShow],
            self::Seated => [self::Completed, self::Cancelled],
            default => [],
        };
    }
}
