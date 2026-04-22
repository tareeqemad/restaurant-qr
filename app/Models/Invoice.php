<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'table_session_id', 'issued_by_user_id',
        'subtotal', 'discount_total', 'tax_total', 'service_total', 'tip',
        'total', 'paid_total', 'refunded_total', 'balance', 'status',
        'customer_name', 'customer_phone', 'notes',
        'issued_at', 'paid_at', 'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_total' => 'decimal:2',
        'tip' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'refunded_total' => 'decimal:4',
        'balance' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = self::generateNumber();
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
}
