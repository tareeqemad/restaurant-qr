<?php

namespace App\Notifications;

use App\Support\BranchContext;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base for all in-app notifications.
 *
 * Concrete subclasses override:
 *   - typeKey()  — stable string id for filtering ("order.new", "stock.low", etc.)
 *   - severity() — info|success|warning|danger (drives icon color in the UI)
 *   - icon()     — Bootstrap Icon class
 *   - title()    — short label shown bold
 *   - body()     — one-line description
 *   - actionUrl() / actionLabel() — where the user lands on click
 *
 * Two non-obvious design choices:
 *
 * 1) `branch_id` is captured at dispatch time from the caller's BranchContext
 *    rather than read at render time. This keeps a notification rooted in
 *    the branch where the event actually happened, even if the recipient
 *    later switches their active branch in the header.
 *
 * 2) Channel defaults to 'database' only. We don't push to mail/broadcast
 *    here — the unified bell is the single inbox, and the polling refresh
 *    keeps the count fresh without needing a websocket connection.
 *    If you later add Reverb, override channels() in subclasses you want
 *    to broadcast (and make sure they implement toBroadcast()).
 */
abstract class BaseNotification extends Notification
{
    use Queueable;

    public ?int $branchId = null;

    public function __construct()
    {
        // Snapshot the active branch at dispatch time. May be null for
        // owner-level CLI / global events (LowStock summary across all
        // branches, etc.) — recipients with branch filters just won't
        // see those rows.
        $this->branchId = BranchContext::current();
    }

    /** Channels this notification is delivered through.
     *  We use our custom DatabaseChannel (extends Laravel's) so the row
     *  gets branch_id/type_key/severity hoisted into proper columns. */
    public function via(object $notifiable): array
    {
        return [\App\Notifications\Channels\DatabaseChannel::class];
    }

    /** Persisted JSON payload — read by the inbox UI. */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type_key'     => $this->typeKey(),
            'severity'     => $this->severity(),
            'icon'         => $this->icon(),
            'title'        => $this->title(),
            'body'         => $this->body(),
            'action_url'   => $this->actionUrl(),
            'action_label' => $this->actionLabel(),
            'branch_id'    => $this->branchId,
            // Optional small payload for badges/links — subclasses can
            // override `extra()` to attach related entity ids/numbers.
            'extra'        => $this->extra(),
        ];
    }

    /** Custom columns on the notifications row (we surface a few payload keys
     *  as columns so the inbox can filter by them efficiently). */
    public function databaseType(object $notifiable): string
    {
        return static::class;
    }

    // ─── Subclass contract ───────────────────────────────────────────────

    abstract public function typeKey(): string;
    abstract public function severity(): string;
    abstract public function icon(): string;
    abstract public function title(): string;
    abstract public function body(): string;

    public function actionUrl(): ?string
    {
        return null;
    }

    public function actionLabel(): ?string
    {
        return null;
    }

    public function extra(): array
    {
        return [];
    }
}
