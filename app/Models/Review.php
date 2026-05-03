<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'customer_id',
        'reservation_id',
        'rating',
        'title',
        'body',
        'status',
        'hidden_by_user_id',
        'hidden_at',
        'hidden_reason',
    ];

    protected $casts = [
        'rating'    => 'integer',
        'hidden_at' => 'datetime',
    ];

    // ========== Relations ==========

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by_user_id');
    }

    // ========== Scopes ==========

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeHidden(Builder $query): Builder
    {
        return $query->where('status', 'hidden');
    }

    public function scopeWithRating(Builder $query, int $rating): Builder
    {
        return $query->where('rating', $rating);
    }

    // ========== Moderation ==========

    public function hide(?string $reason, ?User $actor): self
    {
        $this->update([
            'status'             => 'hidden',
            'hidden_at'          => now(),
            'hidden_reason'      => $reason,
            'hidden_by_user_id'  => $actor?->id,
        ]);
        return $this;
    }

    public function unhide(): self
    {
        $this->update([
            'status'             => 'published',
            'hidden_at'          => null,
            'hidden_reason'      => null,
            'hidden_by_user_id'  => null,
        ]);
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isHidden(): bool
    {
        return $this->status === 'hidden';
    }
}
