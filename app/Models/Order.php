<?php

namespace App\Models;

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
        'number', 'table_id', 'table_session_id', 'customer_id', 'customer_name', 'customer_phone',
        'customer_address_id', 'order_type', 'status',
        'order_source', 'external_reference', 'platform_commission_pct',
        'created_by_user_id', 'approved_by_user_id', 'cancelled_by_user_id',
        'customer_notes', 'delivery_address', 'internal_notes', 'cancelled_reason',
        'subtotal', 'discount_total', 'tax_total', 'service_total', 'delivery_fee', 'tip', 'total',
        'tax_rate', 'service_rate',
        'submitted_at', 'scheduled_for',
        'estimated_prep_minutes', 'estimated_ready_at', 'estimated_delivered_at',
        'approved_at', 'ready_at', 'delivered_at', 'completed_at', 'cancelled_at',
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
        });
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

    // ─── Source / Delivery-platform helpers ────────────────────────────

    public function source(): ?\App\Enums\OrderSource
    {
        return \App\Enums\OrderSource::tryFrom($this->order_source);
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
