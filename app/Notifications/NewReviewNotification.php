<?php

namespace App\Notifications;

use App\Models\Review;

class NewReviewNotification extends BaseNotification
{
    public function __construct(public Review $review)
    {
        parent::__construct();
        $this->branchId = $review->branch_id ?? $this->branchId;
    }

    public function typeKey(): string { return 'review.new'; }

    /** Low ratings (≤2) raise to warning so they pop in the inbox. */
    public function severity(): string
    {
        return ((int) $this->review->rating) <= 2 ? 'warning' : 'info';
    }

    public function icon(): string
    {
        return ((int) $this->review->rating) <= 2
            ? 'bi-star-half'
            : 'bi-star-fill';
    }

    public function title(): string
    {
        $stars = str_repeat('★', (int) $this->review->rating);
        $name  = $this->review->customer?->name ?? 'زائر';
        return "تقييم جديد {$stars} من {$name}";
    }

    public function body(): string
    {
        $body = trim((string) $this->review->body);
        if ($body === '') {
            return $this->review->title ?: 'بدون تعليق';
        }
        return mb_strlen($body) > 90 ? mb_substr($body, 0, 87).'…' : $body;
    }

    public function actionUrl(): string
    {
        return route('admin.reviews.index', ['rating' => $this->review->rating]);
    }

    public function actionLabel(): string
    {
        return 'فتح التقييمات';
    }

    public function extra(): array
    {
        return [
            'review_id' => $this->review->id,
            'rating'    => $this->review->rating,
        ];
    }
}
