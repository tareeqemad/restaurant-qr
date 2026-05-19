<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'number', 'table_session_id', 'order_id', 'customer_id', 'issued_by_user_id',
        'subtotal', 'discount_total', 'tax_total', 'service_total', 'delivery_fee', 'tip',
        'total', 'paid_total', 'refunded_total', 'balance', 'status',
        'customer_name', 'customer_phone', 'notes',
        'issued_at', 'paid_at', 'cancelled_at',
        // Debt-ledger flags — set by BillingService::settleOnAccount when
        // a partially-paid invoice is parked on the customer's account.
        'settled_on_account_at', 'settled_on_account_by_user_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'tip' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'refunded_total' => 'decimal:4',
        'balance' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'settled_on_account_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = self::generateNumber();
            }
        });

        static::saving(function (self $m) {
            $hasTableSession = ! is_null($m->table_session_id);
            $hasDirectOrder = ! is_null($m->order_id);

            if ($hasTableSession === $hasDirectOrder) {
                throw new \InvalidArgumentException('Invoice must belong to exactly one origin: table session or direct order.');
            }
        });
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $seq = self::whereDate('created_at', now()->toDateString())->count() + 1;
        return sprintf('INV-%s-%04d', $today, $seq);
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Identified portal customer for this invoice. Set if the diner was
     * logged into the portal at QR-scan time, registered via the cashier
     * mid-meal, or was matched by phone at checkout. The flat
     * `customer_name`/`customer_phone` fields stay as a snapshot for
     * receipt printing even if the FK is later nulled.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Net cash received = paid − refunded */
    public function netPaid(): float
    {
        return max(0, (float) $this->paid_total - (float) $this->refunded_total);
    }

    /** How much more can still be refunded */
    public function refundableBalance(): float
    {
        return max(0, (float) $this->paid_total - (float) $this->refunded_total);
    }

    public function isFullyRefunded(): bool
    {
        return $this->refunded_total > 0
            && abs((float) $this->refunded_total - (float) $this->paid_total) < 0.01;
    }

    public function splits(): HasMany
    {
        return $this->hasMany(InvoiceSplit::class);
    }

    /** Arabic label for the invoice status — used in every cashier surface. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'paid'            => 'مدفوعة بالكامل',
            'partially_paid'  => 'مدفوعة جزئياً',
            'issued'          => 'صادرة',
            'cancelled'       => 'ملغاة',
            'unpaid_writeoff' => 'مشطوبة',
            default           => $this->status,
        };
    }

    /** Bootstrap badge color matching the status. */
    public function statusColor(): string
    {
        return match ($this->status) {
            'paid'            => 'success',
            'partially_paid'  => 'info',
            'issued'          => 'warning',
            'cancelled'       => 'danger',
            'unpaid_writeoff' => 'dark',
            default           => 'secondary',
        };
    }
}
