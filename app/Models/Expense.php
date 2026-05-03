<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Expense entry — branch-scoped, three-state approval workflow,
 * auto-bridged into the cashier's till on approval when paid in cash.
 *
 *   pending_approval → approved (creates cash_movement if cash)
 *                    → rejected (with reason)
 *
 * The static `nextNumber()` helper produces EXP-YYYYMMDD-NNNN sequences
 * unique within the active branch, so cashiers can reference them by
 * voice/SMS.
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
        'payment_method',
        'payment_reference',
        'vendor_name',
        'supplier_id',
        'attachment_path',
        'expense_date',
        'shift_id',
        'cash_movement_id',
        'status',
        'rejection_reason',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'expense_date'  => 'date',
        'approved_at'   => 'datetime',
    ];

    /**
     * Auto-generate expense_number on create. Format: EXP-YYYYMMDD-NNNN
     * with NNNN = sequence within (branch, date). Branch context is
     * already stamped by BelongsToBranch::creating, so we read it here.
     */
    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (empty($expense->expense_number)) {
                $expense->expense_number = static::nextNumber($expense->branch_id, $expense->expense_date);
            }
        });
    }

    public static function nextNumber($branchId, $date): string
    {
        $date = $date instanceof \DateTimeInterface ? $date : \Carbon\Carbon::parse($date);
        $prefix = 'EXP-' . $date->format('Ymd');

        // Use BranchContext::unscoped so the count covers the whole branch
        // — we don't want sequence gaps just because BranchScope is on.
        $count = BranchContext::unscoped(fn () =>
            static::withTrashed()
                ->where('branch_id', $branchId)
                ->whereDate('expense_date', $date)
                ->count()
        );

        return sprintf('%s-%04d', $prefix, $count + 1);
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
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

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
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

    public const PAYMENT_METHODS = [
        'cash'           => 'نقداً',
        'card'           => 'بطاقة',
        'bank_transfer'  => 'تحويل بنكي',
        'cheque'         => 'شيك',
        'other'          => 'أخرى',
    ];
}
