<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Marketing announcement / promo campaign aimed at portal customers.
 *
 * Lifecycle:
 *   draft  → scheduled (if starts_at in future) → published → expired/archived
 *
 * The status column tracks where the campaign is, but the SOURCE OF TRUTH
 * for "is this currently visible to a customer" is the activeNow() scope:
 *   status=published AND (starts_at <= now OR null) AND (ends_at > now OR null)
 *
 * Don't bypass the scope to read "active" elsewhere — the schedule windows
 * matter, and we'd silently leak expired or unscheduled campaigns.
 */
class Announcement extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'title', 'body', 'image_path', 'icon', 'color',
        'cta_text', 'cta_url', 'discount_id',
        'audience_type', 'audience_filter',
        'starts_at', 'ends_at',
        'status', 'recipients_count',
        'published_at', 'published_by_user_id', 'created_by_user_id',
    ];

    protected $casts = [
        'audience_filter'  => 'array',
        'starts_at'        => 'datetime',
        'ends_at'          => 'datetime',
        'published_at'     => 'datetime',
        'recipients_count' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────────

    /** Active right now: published + within the schedule window. */
    public function scopeActiveNow($q)
    {
        $now = now();
        return $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function scopeForCustomer($q, Customer $customer)
    {
        return $q->where(function ($w) use ($customer) {
            $w->whereNull('branch_id')
              ->orWhere('branch_id', $customer->default_branch_id);
        });
    }

    /**
     * Filter to announcements aimed at anonymous walk-ins on the QR menu.
     * Used by the customer menu page to render a conversion banner under
     * the hero when no portal customer is linked to the table session.
     * Pass the branch_id of the table so we honor per-branch targeting
     * (null branch_id on the announcement = global, applies everywhere).
     */
    public function scopeForGuests($q, ?int $branchId = null)
    {
        $q->where('audience_type', 'guests');
        if ($branchId !== null) {
            $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId));
        }
        return $q;
    }

    // ─── Status helpers ────────────────────────────────────────────────

    public function isDraft(): bool      { return $this->status === 'draft'; }
    public function isScheduled(): bool  { return $this->status === 'scheduled'; }
    public function isPublished(): bool  { return $this->status === 'published'; }
    public function isExpired(): bool    { return $this->status === 'expired'; }

    /** True iff the schedule window has the announcement live RIGHT NOW. */
    public function isCurrentlyLive(): bool
    {
        if (! $this->isPublished()) return false;
        $now = now();
        if ($this->starts_at && $this->starts_at->gt($now)) return false;
        if ($this->ends_at && $this->ends_at->lte($now))    return false;
        return true;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'     => 'مسودة',
            'scheduled' => 'مجدول',
            'published' => $this->isCurrentlyLive() ? 'منشور' : 'منتهٍ/خارج النطاق',
            'expired'   => 'منتهٍ',
            'archived'  => 'مؤرشف',
            default     => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft'     => 'secondary',
            'scheduled' => 'info',
            'published' => $this->isCurrentlyLive() ? 'success' : 'warning',
            'expired'   => 'warning',
            'archived'  => 'secondary',
            default     => 'secondary',
        };
    }

    public function audienceLabel(): string
    {
        $filter = $this->audience_filter ?? [];
        return match ($this->audience_type) {
            'all'            => 'كل الزبائن',
            'tier'           => 'أعضاء المستوى ' . ($filter['min_tier'] ?? 'silver') . ' فأعلى',
            'inactive_days'  => 'الزبائن الذين لم يزوروا منذ ' . ($filter['days'] ?? 30) . ' يوم',
            'specific'       => 'زبائن محددون (' . count($filter['customer_ids'] ?? []) . ')',
            'guests'         => 'الزوّار غير المسجَّلين (بانر على المنيو)',
            default          => $this->audience_type,
        };
    }

    /**
     * True when the announcement targets anonymous menu visitors. Used by
     * the admin index/cards to show "بانر على المنيو" instead of the
     * recipient count (which is always 0 for this channel).
     */
    public function isGuestsAudience(): bool
    {
        return $this->audience_type === 'guests';
    }
}
