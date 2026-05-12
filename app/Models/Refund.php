<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Refund extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    // branch_id added May 2026 (migration 2026_05_10_230000) — must be
    // derived from invoice.branch_id by the writer (RefundService /
    // RefundController), not from BranchContext.
    protected $fillable = [
        'branch_id', 'number', 'invoice_id', 'payment_id',
        'amount', 'method', 'reference', 'status',
        'reason', 'notes',
        'processed_by', 'shift_id', 'refunded_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:4',
        'refunded_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'معلّق',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default     => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'pending'   => 'warning',
            'cancelled' => 'secondary',
            default     => 'light',
        };
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public const METHODS = [
        'cash'     => 'نقدي',
        'card'     => 'بطاقة',
        'transfer' => 'تحويل',
        'app'      => 'محفظة',
        'credit'   => 'آجل',
        'other'    => 'أخرى',
    ];

    public static function generateNumber(): string
    {
        $prefix = 'REF-'.now()->format('Ymd').'-';
        $last = self::where('number', 'like', $prefix.'%')->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
