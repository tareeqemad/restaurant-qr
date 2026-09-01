<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A promotion attached to a menu item OR a whole category. See the
 * migration for the design rationale. PromotionService is the only
 * thing that should call resolve()-style logic — the model is kept
 * thin and stays a faithful representation of the row.
 *
 * NOTE: this model does NOT use the `BelongsToBranch` trait. Promotions
 * are MIXED reference data — chain-wide (branch_id = null) AND branch-
 * specific (branch_id set) rows coexist on the same table. The trait
 * would (a) silently overwrite a deliberately-null branch_id on insert
 * with the current context, and (b) strip null-branch rows from every
 * query via BranchScope. Branch filtering lives in PromotionService
 * instead, where the (`whereNull OR =branchId`) shape is explicit.
 */
class MenuPromotion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'description',
        'type', 'value', 'min_subtotal',
        'usage_limit', 'usage_count',
        'target_type', 'target_id',
        'starts_at', 'ends_at',
        'time_from', 'time_to', 'days_of_week',
        'channels', 'excluded_item_ids',
        'audience', 'free_modifier_ids',
        'bxgy_buy_qty', 'bxgy_get_qty',
        'active', 'priority',
        'created_by_user_id',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'days_of_week' => 'array',
        'channels' => 'array',
        'excluded_item_ids' => 'array',
        'free_modifier_ids' => 'array',
        'active' => 'boolean',
    ];

    public const AUDIENCE_EVERYONE = 'everyone';

    public const AUDIENCE_BIRTHDAY_MONTH = 'birthday_month';

    public const TYPE_SALE_PRICE = 'sale_price';

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED_OFF = 'fixed_off';

    public const TARGET_MENU_ITEM = 'menu_item';

    public const TARGET_CATEGORY = 'category';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'target_id')
            ->where('menu_promotions.target_type', self::TARGET_MENU_ITEM);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'target_id')
            ->where('menu_promotions.target_type', self::TARGET_CATEGORY);
    }

    /**
     * Apply this promotion's discount expression to a base menu price.
     * Returns the resulting price. Never goes below zero (a 200% off
     * percent is clamped to free, not negative).
     */
    public function applyTo(float $basePrice): float
    {
        return match ($this->type) {
            // A value above the catalogue price is not an offer. Clamp it to
            // the base price so a configuration mistake can never charge a
            // customer more while displaying an "offer" badge.
            self::TYPE_SALE_PRICE => max(0, min($basePrice, (float) $this->value)),
            self::TYPE_PERCENT => max(0, round($basePrice * (1 - ((float) $this->value / 100)), 2)),
            self::TYPE_FIXED_OFF => max(0, round($basePrice - (float) $this->value, 2)),
            default => $basePrice,
        };
    }

    /**
     * "Is this promo currently in its active window?" — combines all
     * the schedule fields. PromotionService::resolveForItem uses this
     * to filter candidates before priority/specificity tie-breaking.
     */
    public function isLiveAt(Carbon $when): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->starts_at && $when->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $when->gt($this->ends_at)) {
            return false;
        }

        // Day-of-week filter: 0 = Sunday … 6 = Saturday (matches Carbon's dayOfWeek)
        if (! empty($this->days_of_week)) {
            if (! in_array($when->dayOfWeek, array_map('intval', $this->days_of_week), true)) {
                return false;
            }
        }

        // Time-of-day window — both endpoints required if either is set.
        if ($this->time_from || $this->time_to) {
            if (! $this->time_from || ! $this->time_to) {
                return false;
            }
            $current = $when->format('H:i:s');
            $from = $this->time_from instanceof Carbon ? $this->time_from->format('H:i:s') : (string) $this->time_from;
            $to = $this->time_to   instanceof Carbon ? $this->time_to->format('H:i:s') : (string) $this->time_to;
            // Same-day window (15:00-17:00). Overnight (22:00-02:00) flips the compare.
            if ($from <= $to) {
                if ($current < $from || $current > $to) {
                    return false;
                }
            } else {
                if ($current < $from && $current > $to) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * "Does the given menu_item participate in this promo?" — covers
     * the category-with-exclusions case:
     *
     *   - Item-targeted promo → must match target_id
     *   - Category-targeted promo → item must be in the category AND
     *     not in excluded_item_ids
     */
    public function appliesToItem(MenuItem $item): bool
    {
        if ($this->target_type === self::TARGET_MENU_ITEM) {
            return (int) $this->target_id === (int) $item->id;
        }
        if ($this->target_type === self::TARGET_CATEGORY) {
            if ((int) $this->target_id !== (int) $item->category_id) {
                return false;
            }
            $excluded = collect($this->excluded_item_ids ?? [])->map(fn ($i) => (int) $i)->all();

            return ! in_array((int) $item->id, $excluded, true);
        }

        return false;
    }

    /**
     * "Is this channel allowed by the promo's channels filter?"
     * Null `channels` (the default) = every channel. Empty array = no
     * channels (defensive — treat the same as "all").
     */
    public function allowsChannel(?string $channel): bool
    {
        if (empty($this->channels)) {
            return true;
        }
        if ($channel === null) {
            return false;
        }

        return in_array($channel, $this->channels, true);
    }

    /**
     * "Does the given cart subtotal meet the min_subtotal threshold?"
     * Null = no threshold → always passes. Used by OrderService after
     * recalculate to decide whether to keep the promo attached.
     */
    public function meetsMinSubtotal(?float $subtotal): bool
    {
        $min = $this->min_subtotal !== null ? (float) $this->min_subtotal : 0;
        if ($min <= 0) {
            return true;
        }

        return $subtotal !== null && $subtotal + 0.001 >= $min;
    }

    /**
     * "Has this promo been exhausted on its usage limit?" — null limit
     * = unlimited. The check is intentionally separate from the atomic
     * INCREMENT (in OrderService) so the resolver can predict whether
     * the promo will even fire before any DB writes.
     */
    public function isExhausted(): bool
    {
        if ($this->usage_limit === null) {
            return false;
        }

        return (int) $this->usage_count >= (int) $this->usage_limit;
    }

    /**
     * "Does the given customer qualify for this promo's audience?"
     * - everyone: always true (the no-op default).
     * - birthday_month: customer must have a birthday whose month
     *   matches the moment we're resolving for. Guests (no customer)
     *   fail birthday_month — promos targeting birthdays only ever
     *   fire for logged-in / identified customers.
     */
    public function matchesAudience(?Customer $customer, Carbon $when): bool
    {
        return match ($this->audience ?? self::AUDIENCE_EVERYONE) {
            self::AUDIENCE_EVERYONE => true,
            self::AUDIENCE_BIRTHDAY_MONTH => $customer?->birthday
                                                && $customer->birthday->month === $when->month,
            default => true,
        };
    }

    /** True when this promo is a Buy-X-Get-Y-free configuration. */
    public function isBxgy(): bool
    {
        return $this->bxgy_buy_qty !== null && $this->bxgy_get_qty !== null
            && (int) $this->bxgy_buy_qty > 0 && (int) $this->bxgy_get_qty > 0;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SALE_PRICE => 'سعر عرض ثابت',
            self::TYPE_PERCENT => 'نسبة مئوية',
            self::TYPE_FIXED_OFF => 'خصم مبلغ ثابت',
            default => $this->type,
        };
    }

    public function valueLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SALE_PRICE => number_format((float) $this->value, 2).' ش.إ',
            self::TYPE_PERCENT => rtrim(rtrim((string) $this->value, '0'), '.').'%',
            self::TYPE_FIXED_OFF => '−'.number_format((float) $this->value, 2).' ش.إ',
            default => (string) $this->value,
        };
    }

    /**
     * Human-readable "when does this run?" — collapses the schedule into
     * one short phrase the dashboard can show next to the promo name.
     */
    public function scheduleLabel(): string
    {
        $parts = [];
        if ($this->starts_at || $this->ends_at) {
            $from = $this->starts_at?->format('Y-m-d') ?? '∞';
            $to = $this->ends_at?->format('Y-m-d') ?? '∞';
            $parts[] = "{$from} → {$to}";
        }
        if (! empty($this->days_of_week)) {
            $names = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            $picked = collect($this->days_of_week)
                ->map(fn ($d) => $names[(int) $d] ?? $d)
                ->implode('، ');
            $parts[] = $picked;
        }
        if ($this->time_from && $this->time_to) {
            $from = $this->time_from instanceof Carbon ? $this->time_from->format('H:i') : substr((string) $this->time_from, 0, 5);
            $to = $this->time_to   instanceof Carbon ? $this->time_to->format('H:i') : substr((string) $this->time_to, 0, 5);
            $parts[] = "{$from} – {$to}";
        }

        return empty($parts) ? 'دائم' : implode(' · ', $parts);
    }
}
