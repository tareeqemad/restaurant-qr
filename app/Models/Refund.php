<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Refund extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    // branch_id added May 2026 (migration 2026_05_10_230000) — must be
    // derived from invoice.branch_id by the writer (RefundService /
    // RefundController), not from BranchContext.
    protected $fillable = [
        'branch_id', 'number', 'invoice_id', 'credit_note_id', 'payment_id',
        'amount', 'method', 'reference', 'idempotency_key', 'status',
        'reason', 'notes',
        'processed_by', 'completed_by', 'cancelled_by', 'reversed_by',
        'refunded_at', 'completed_at', 'cancelled_at', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'amount'      => 'decimal:4',
        'refunded_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope('posted');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'معلّق',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            'reversed'  => 'معكوس',
            default     => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'pending'   => 'warning',
            'cancelled' => 'secondary',
            'reversed'  => 'secondary',
            default     => 'light',
        };
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public const METHODS = [
        'original' => 'إلى طرق الدفع الأصلية',
        'cash'     => 'نقدا',
        'card'     => 'فيزا',
        'transfer' => 'تحويل بنكي',
        'palpay' => 'PalPay',
        'jawwal_pay' => 'Jawwal Pay',
        'customer_advance' => 'رصيد الزبون',
        'mixed' => 'حسب الدفعات الأصلية',
        'other'    => 'أخرى',
        // Legacy values still resolve to a label for historical refunds
        // (see AccountingService::cashAccountForMethod for the account
        // mapping). New refunds can't be created with these because the
        // validation array is built from the keys above.
        'app'      => 'محفظة (قديم)',
        'credit'   => 'آجل (قديم)',
    ];

    /** Methods the UI is allowed to offer for NEW refunds. */
    public const ACTIVE_METHODS = ['original', 'cash', 'card', 'transfer', 'palpay', 'jawwal_pay', 'customer_advance'];

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');

        // The unique index on `number` is GLOBAL, so the sequence must be
        // computed globally too: unscoped (BranchScope would restart every
        // branch at 0001 and collide) and trashed-inclusive (a soft-deleted
        // refund still occupies its number). MAX beats last-id+1 — deletions
        // never make it reissue a taken number. Fixed-width zero padding
        // makes the lexicographic MAX also the numeric max.
        $last = self::withoutGlobalScopes()->withTrashed()
            ->where('number', 'like', "REF-{$today}-%")
            ->max('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (self::withoutGlobalScopes()->withTrashed()
            ->where('number', sprintf('REF-%s-%04d', $today, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('REF-%s-%04d', $today, $seq);
    }
}
