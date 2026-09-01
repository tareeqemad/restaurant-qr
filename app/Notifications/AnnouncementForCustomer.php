<?php

namespace App\Notifications;

use App\Models\Announcement;

/**
 * Customer-facing notification carrying a marketing Announcement payload.
 *
 * Delivered as a durable `database` row through the project's custom
 * DatabaseChannel. The retired customer portal no longer supplies a default
 * destination, so a campaign is clickable only when the operator explicitly
 * configured a CTA URL.
 *
 * Layer 2 (Email) and Layer 3 (SMS) will override `via()` here to add more
 * channels — keep all customer-facing announcement messaging in one class.
 */
class AnnouncementForCustomer extends BaseNotification
{
    public function __construct(public Announcement $announcement)
    {
        parent::__construct();
        // Override the auto-captured branch with the announcement's own
        // branch — the dispatch context (admin running the publish action)
        // may be a different branch than the announcement targets.
        $this->branchId = $announcement->branch_id;
    }

    public function typeKey(): string
    {
        return 'announcement.published';
    }

    public function severity(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return $this->announcement->icon ?: 'bi-megaphone-fill';
    }

    public function title(): string
    {
        return $this->announcement->title;
    }

    public function body(): string
    {
        return $this->announcement->body;
    }

    public function actionUrl(): ?string
    {
        return filled($this->announcement->cta_url)
            ? $this->announcement->cta_url
            : null;
    }

    public function actionLabel(): ?string
    {
        if ($this->actionUrl() === null) {
            return null;
        }

        return $this->announcement->cta_text ?: 'عرض التفاصيل';
    }

    public function extra(): array
    {
        $a = $this->announcement;

        return [
            'announcement_id' => $a->id,
            'image_path' => $a->image_path,
            'color' => $a->color ?: '#F9A825',
            'discount_id' => $a->discount_id,
            'starts_at' => optional($a->starts_at)->toIso8601String(),
            'ends_at' => optional($a->ends_at)->toIso8601String(),
        ];
    }
}
