<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAdvanceTransaction extends Model
{
    use BelongsToBranch, HasFactory;

    public const DEPOSIT = 'deposit';

    public const REDEMPTION = 'redemption';

    public const DEPOSIT_REVERSAL = 'deposit_reversal';

    public const REDEMPTION_REVERSAL = 'redemption_reversal';

    public const OPENING_BALANCE = 'opening_balance';

    public const REFUND_CREDIT = 'refund_credit';

    public const REFUND_CREDIT_REVERSAL = 'refund_credit_reversal';

    protected $fillable = [
        'customer_id',
        'branch_id',
        'invoice_id',
        'payment_id',
        'refund_id',
        'reversed_transaction_id',
        'type',
        'amount',
        'balance_after',
        'payment_method',
        'reference',
        'notes',
        'created_by_user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_transaction_id');
    }

    public function signedAmount(): float
    {
        return in_array($this->type, [self::DEPOSIT, self::OPENING_BALANCE, self::REDEMPTION_REVERSAL, self::REFUND_CREDIT], true)
            ? (float) $this->amount
            : -(float) $this->amount;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::DEPOSIT => 'إيداع رصيد',
            self::REDEMPTION => 'استخدام في فاتورة',
            self::DEPOSIT_REVERSAL => 'عكس إيداع',
            self::REDEMPTION_REVERSAL => 'استرجاع رصيد',
            self::OPENING_BALANCE => 'رصيد افتتاحي',
            self::REFUND_CREDIT => 'استرداد إلى رصيد الزبون',
            self::REFUND_CREDIT_REVERSAL => 'عكس استرداد إلى الرصيد',
            default => $this->type,
        };
    }
}
