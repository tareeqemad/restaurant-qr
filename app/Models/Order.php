<?php

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'table_id', 'table_number_snapshot', 'table_session_id', 'customer_id', 'customer_name', 'customer_phone',
        'customer_address_id', 'order_type', 'status',
        'order_source', 'external_reference', 'delivery_receiver', 'platform_commission_pct',
        'created_by_user_id', 'approved_by_user_id', 'cancelled_by_user_id',
        // Staff-meal mode: when set, the order is consumed by THIS employee
        // and gets charged to their monthly allowance (not invoiced normally).
        'staff_consumer_user_id',
        'customer_notes', 'delivery_address', 'internal_notes', 'cancelled_reason',
        'subtotal', 'discount_total', 'tax_total', 'service_total', 'delivery_fee', 'tip', 'total',
        'tax_rate', 'service_rate',
        'submitted_at', 'scheduled_for',
        'estimated_prep_minutes', 'estimated_ready_at', 'estimated_delivered_at',
        'approved_at', 'prep_started_at', 'ready_at', 'delivered_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'tip' => 'decimal:2',
        'total' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'service_rate' => 'decimal:2',
        'platform_commission_pct' => 'decimal:2',
        'estimated_prep_minutes' => 'integer',
        'submitted_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'estimated_ready_at' => 'datetime',
        'estimated_delivered_at' => 'datetime',
        'approved_at' => 'datetime',
        'prep_started_at' => 'datetime',
        'ready_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = self::generateNumber();
            }
            // Inherit the linked customer from the parent table session, so
            // an order placed AFTER the cashier links a session to a customer
            // automatically carries the FK without every caller remembering
            // to set it.
            if (empty($m->customer_id) && ! empty($m->table_session_id)) {
                $m->customer_id = TableSession::query()
                    ->whereKey($m->table_session_id)
                    ->value('customer_id');
            }
            // Snapshot the table number at order creation. Three lookup
            // paths in order of preference:
            //   1. session's pre-snapshotted number (cheapest, already there)
            //   2. live `tables` row (covers direct-order flows without a session)
            //   3. nothing (no table assigned — e.g. takeaway / delivery)
            if (empty($m->table_number_snapshot)) {
                if (! empty($m->table_session_id)) {
                    $m->table_number_snapshot = TableSession::query()
                        ->whereKey($m->table_session_id)
                        ->value('table_number_snapshot');
                }
                if (empty($m->table_number_snapshot) && $m->table_id) {
                    $m->table_number_snapshot = Table::withTrashed()->find($m->table_id)?->number;
                }
            }
        });
    }

    /**
     * Human label for this order's table. Prefers the snapshot so
     * renaming/deleting the table doesn't rewrite history. Falls back
     * to the live FK for legacy rows, then '—'.
     */
    public function tableLabel(): string
    {
        return $this->table_number_snapshot
            ?? $this->table?->number
            ?? '—';
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $seq = self::whereDate('created_at', now()->toDateString())->count() + 1;

        return sprintf('ORD-%s-%04d', $today, $seq);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    /**
     * Identified portal customer for this order, if any. Anonymous walk-ins
     * leave this null. Inherited from the parent TableSession when the
     * cashier links a session to a customer mid-meal — see CartController
     * for how the inheritance is stamped on order creation.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function deliveryAssignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Total per-item promotion savings on this order.
     *
     * Two sources combine into ONE figure:
     *   1. Line-level snapshot (`unit_price_original` vs `unit_price`) —
     *      sale_price / percent / fixed_off promos.
     *   2. Modifier snapshot (`price_delta_original` vs `price_delta`) —
     *      free_modifier_ids promos.
     *
     * BXGY savings are NOT included here — those already live in
     * `OrderDiscount` rows and surface via `discount_total` /
     * `invoice.discount_total`. Counting them again would double-debit
     * the SALES_DISCOUNTS account.
     *
     * Cancelled lines are excluded — they shouldn't carry revenue or
     * discount weight on the books.
     */
    public function promoSavings(): float
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('modifiers')->get();

        $total = 0.0;
        foreach ($items as $line) {
            if ($line->status === OrderItemStatus::Cancelled->value) {
                continue;
            }

            // Line-level savings
            if ($line->unit_price_original !== null
                && (float) $line->unit_price_original > (float) $line->unit_price) {
                $total += ((float) $line->unit_price_original - (float) $line->unit_price)
                          * (float) $line->quantity;
            }

            // Modifier-level savings
            $mods = $line->relationLoaded('modifiers') ? $line->modifiers : $line->modifiers()->get();
            foreach ($mods as $mod) {
                if ($mod->price_delta_original !== null
                    && (float) $mod->price_delta_original > (float) $mod->price_delta) {
                    $total += ((float) $mod->price_delta_original - (float) $mod->price_delta)
                              * (float) $line->quantity;
                }
            }
        }

        return round($total, 2);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * The employee who consumed this order on their meal allowance.
     * Non-null = staff meal (no regular invoice issued; the order is
     * charged to the employee's monthly tab via StaffMealService).
     */
    public function staffConsumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_consumer_user_id');
    }

    public function isStaffMeal(): bool
    {
        return ! is_null($this->staff_consumer_user_id);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function statusLabel(): string
    {
        return OrderStatus::tryFrom($this->status)?->label() ?? $this->status;
    }

    public function statusColor(): string
    {
        return OrderStatus::tryFrom($this->status)?->color() ?? 'secondary';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending->value, OrderStatus::Approved->value], true);
    }

    public function isCancellable(): bool
    {
        return ! in_array($this->status, [OrderStatus::Completed->value, OrderStatus::Cancelled->value], true);
    }

    /**
     * Cancel rule for "kill the entire ticket": only allowed before the
     * kitchen has fired it (status in pending/approved). Once a ticket is
     * preparing/ready/delivered, the right move is item-level cancel
     * through cancelItem() — bulk-cancel after that point would waste
     * already-fired prep and confuse the line. Used by both the diner's
     * track view and the admin order-detail "cancel entire order" button.
     */
    public function canCancelEntireOrder(): bool
    {
        return in_array($this->status, [OrderStatus::Pending->value, OrderStatus::Approved->value], true);
    }

    /**
     * Can this approval still be reversed? Only while the order is Approved and
     * the kitchen hasn't physically started any item (none past Approved). The
     * service re-checks this, but the views use it to decide whether to show
     * the "فك الاعتماد" button. Loads `items` if not already eager-loaded.
     */
    public function canUnapprove(): bool
    {
        if ($this->status !== OrderStatus::Approved->value) {
            return false;
        }

        return $this->items
            ->where('status', '!=', OrderItemStatus::Cancelled->value)
            ->every(fn ($i) => in_array($i->status, [
                OrderItemStatus::Pending->value,
                OrderItemStatus::Approved->value,
            ], true));
    }

    // ─── Source / Delivery-platform helpers ────────────────────────────

    public function source(): ?OrderSource
    {
        return OrderSource::tryFrom($this->order_source);
    }

    public function sourceLabel(): string
    {
        return $this->source()?->label() ?? $this->order_source;
    }

    public function sourceColor(): string
    {
        return $this->source()?->color() ?? '#6b7280';
    }

    public function sourceIcon(): string
    {
        return $this->source()?->icon() ?? 'bi-box';
    }

    public function isExternal(): bool
    {
        return $this->order_source !== 'dine_in';
    }

    /** Net revenue to the restaurant after platform commission. */
    public function netRevenue(): float
    {
        return (float) $this->total * (1 - (float) $this->platform_commission_pct / 100);
    }

    public function commissionAmount(): float
    {
        return (float) $this->total - $this->netRevenue();
    }
}
