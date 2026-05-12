<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\AnnouncementForCustomer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Build the recipient list for an announcement, fan it out as one
 * `notifications` row per matched customer, and stamp the campaign
 * as published.
 *
 * Audience filters supported (kept tight on purpose — every new audience
 * type adds another report column to maintain):
 *
 *   - all              : every active customer
 *   - tier             : customers whose loyalty tier is >= filter.min_tier
 *                        (bronze < silver < gold)
 *   - inactive_days    : customers whose last_login_at is older than N days
 *                        (or never logged in)
 *   - specific         : explicit customer_ids array
 *   - guests           : DIFFERENT CHANNEL — no notification fan-out, no
 *                        customer rows touched. Rendered live as a banner
 *                        on the public menu screen for anonymous QR
 *                        sessions. buildAudience() returns an empty
 *                        collection on purpose — the announcement still
 *                        gets `status=published` so the menu can pick it
 *                        up via the activeNow() + audience_type filter.
 *
 * Branch scope: if the announcement has a branch_id, recipients are
 * narrowed to customers whose default_branch_id matches. Null branch_id
 * = global, all customers eligible regardless of their home branch.
 */
class AnnouncementService
{
    public function publish(Announcement $announcement, User $publisher): Announcement
    {
        return DB::transaction(function () use ($announcement, $publisher) {
            $announcement->refresh();

            if ($announcement->status === 'published') {
                throw new \RuntimeException('الإعلان منشور مسبقاً.');
            }

            $audience = $this->buildAudience($announcement);

            // Idempotent: send via Laravel's notification system. Each row
            // lands on the customer's polymorphic `notifiable` slot. The
            // default DatabaseChannel will write to `notifications` table.
            if ($audience->isNotEmpty()) {
                Notification::send($audience, new AnnouncementForCustomer($announcement));
            }

            $announcement->update([
                'status'              => 'published',
                'published_at'        => now(),
                'published_by_user_id'=> $publisher->id,
                'recipients_count'    => $audience->count(),
            ]);

            ActivityLog::log(
                'announcement.published',
                "نشر الإعلان «{$announcement->title}» إلى {$audience->count()} زبون",
                $announcement,
                [
                    'announcement_id' => $announcement->id,
                    'audience_type'   => $announcement->audience_type,
                    'audience_filter' => $announcement->audience_filter,
                    'recipients'      => $audience->count(),
                ]
            );

            return $announcement->refresh();
        });
    }

    /**
     * Resolve an announcement's audience filter into a concrete Customer
     * collection. Public so the admin "Preview audience size" button can
     * call it without going through the full publish flow.
     */
    public function buildAudience(Announcement $announcement): Collection
    {
        // Guests channel — no Customer rows exist for walk-ins, so there
        // are no notifications to send. Return early with an empty
        // collection; publish() will still flip status to published and
        // the menu view picks the announcement up by audience_type.
        if ($announcement->audience_type === 'guests') {
            return collect();
        }

        $query = Customer::query()
            ->where('status', 'active')
            ->whereNotNull('phone'); // the floor — must at least have a phone

        // Branch scope (null branch on the announcement = global)
        if ($announcement->branch_id) {
            $query->where('default_branch_id', $announcement->branch_id);
        }

        $filter = $announcement->audience_filter ?? [];

        switch ($announcement->audience_type) {
            case 'tier':
                $minTier = $filter['min_tier'] ?? 'silver';
                $tiersAtOrAbove = $this->tiersAtOrAbove($minTier);
                $query->whereHas('loyaltyCustomer', fn ($q) =>
                    $q->whereIn('tier', $tiersAtOrAbove)
                );
                break;

            case 'inactive_days':
                $days = max(1, (int) ($filter['days'] ?? 30));
                $cutoff = now()->subDays($days);
                $query->where(function ($w) use ($cutoff) {
                    $w->whereNull('last_login_at')
                      ->orWhere('last_login_at', '<', $cutoff);
                });
                break;

            case 'specific':
                $ids = array_map('intval', $filter['customer_ids'] ?? []);
                if (empty($ids)) {
                    return collect();
                }
                $query->whereIn('id', $ids);
                break;

            case 'all':
            default:
                // No additional filter
                break;
        }

        return $query->get();
    }

    /**
     * Mark a published campaign as no longer active. Doesn't delete the
     * notifications already sent — that's intentional, customers keep the
     * history. Just flips the source row so it stops being shown in the
     * dashboard banner.
     */
    public function unpublish(Announcement $announcement, User $user): void
    {
        $announcement->update(['status' => 'archived']);
        ActivityLog::log(
            'announcement.unpublished',
            "إلغاء نشر الإعلان «{$announcement->title}»",
            $announcement,
            ['announcement_id' => $announcement->id, 'by_user_id' => $user->id]
        );
    }

    /**
     * Sweep: any published announcement whose `ends_at` has passed gets
     * flipped to `expired`. Cheap query, suitable for a daily scheduled
     * task once the cron is wired.
     */
    public function expireOverdue(): int
    {
        return Announcement::query()
            ->where('status', 'published')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    protected function tiersAtOrAbove(string $minTier): array
    {
        $ladder = ['bronze', 'silver', 'gold'];
        $idx = array_search($minTier, $ladder, true);
        if ($idx === false) {
            return $ladder;
        }
        return array_slice($ladder, $idx);
    }
}
