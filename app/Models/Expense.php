<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Expense entry — branch-scoped, three-state approval workflow.
 *
 *   pending_approval → approved (posts the accounting effect)
 *                    → rejected (with reason)
 *
 * The static `nextNumber()` helper produces EXP-YYYYMMDD-NNNN sequences
 * that are GLOBAL per expense date (the DB unique index on
 * `expense_number` spans all branches), so cashiers can reference them
 * by voice/SMS without two branches ever minting the same number.
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'expense_number',
        'expense_category_id',
        'description',
        'notes',
        'amount',
        'currency_code',
        'exchange_rate',
        'payment_method',
        'payment_reference',
        'vendor_name',
        'supplier_id',
        'attachment_path',
        'expense_date',
        'status',
        'rejection_reason',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'expense_date'  => 'date',
        'approved_at'   => 'datetime',
    ];

    /**
     * Auto-generate expense_number on create. Format: EXP-YYYYMMDD-NNNN
     * with NNNN = GLOBAL sequence within the expense date.
     */
    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (empty($expense->expense_number)) {
                $expense->expense_number = static::nextNumber($expense->expense_date);
            }
        });
    }

    public static function nextNumber($date): string
    {
        $date = $date instanceof \DateTimeInterface ? $date : \Carbon\Carbon::parse($date);
        $prefix = 'EXP-'.$date->format('Ymd');

        // The unique index on `expense_number` is GLOBAL, so the sequence
        // must be computed globally too: unscoped (a per-branch sequence
        // collides the moment two branches book an expense on the same
        // date) and trashed-inclusive (a soft-deleted expense still
        // occupies its number). MAX beats COUNT+1 — deletions never make
        // it reissue a taken number. Fixed-width zero padding makes the
        // lexicographic MAX also the numeric max.
        $last = static::withoutGlobalScopes()->withTrashed()
            ->where('expense_number', 'like', "{$prefix}-%")
            ->max('expense_number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (static::withoutGlobalScopes()->withTrashed()
            ->where('expense_number', sprintf('%s-%04d', $prefix, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('%s-%04d', $prefix, $seq);
    }

    // ─── Relations ─────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The category lookup row. `withTrashed()` so historical expenses still
     * resolve their label even after the category was soft-deleted from
     * the lookups admin screen.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'expense_category_id')->withTrashed();
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending_approval');
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', 'approved');
    }

    public function scopeRejected(Builder $q): Builder
    {
        return $q->where('status', 'rejected');
    }

    public function scopeForCategory(Builder $q, int $categoryId): Builder
    {
        return $q->where('expense_category_id', $categoryId);
    }

    public function scopeBetween(Builder $q, $from, $to): Builder
    {
        return $q->whereBetween('expense_date', [$from, $to]);
    }

    // ─── State helpers ─────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === 'pending_approval'; }
    public function isApproved(): bool  { return $this->status === 'approved'; }
    public function isRejected(): bool  { return $this->status === 'rejected'; }
    public function isCash(): bool      { return $this->payment_method === 'cash'; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending_approval' => 'في الانتظار',
            'approved'         => 'معتمد',
            'rejected'         => 'مرفوض',
            default            => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending_approval' => 'warning',
            'approved'         => 'success',
            'rejected'         => 'danger',
            default            => 'secondary',
        };
    }

    public function categoryLabel(): string
    {
        return $this->category?->label ?? '—';
    }

    public function paymentMethodLabel(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    public function baseAmount(): float
    {
        return round((float) $this->amount * (float) ($this->exchange_rate ?: 1), 4);
    }

    public function formatMoney(?float $amount = null): string
    {
        return number_format($amount ?? (float) $this->amount, 2).' '.($this->currency_code ?: 'ILS');
    }

    public const PAYMENT_METHODS = [
        'cash'           => 'نقداً',
        'bank_transfer'  => 'تحويل بنكي',
    ];
}
