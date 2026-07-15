<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number',
        'from_branch_id', 'to_branch_id',
        'status',
        'created_by_user_id', 'sent_by_user_id', 'received_by_user_id',
        'sent_at', 'received_at', 'cancelled_at',
        'notes', 'cancel_reason',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'received_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function fromBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function toBranch(): BelongsTo   { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function items(): HasMany        { return $this->hasMany(BranchTransferItem::class); }
    public function creator(): BelongsTo    { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function sender(): BelongsTo     { return $this->belongsTo(User::class, 'sent_by_user_id'); }
    public function receiver(): BelongsTo   { return $this->belongsTo(User::class, 'received_by_user_id'); }

    public function isDraft(): bool      { return $this->status === 'draft'; }
    public function isInTransit(): bool  { return $this->status === 'in_transit'; }
    public function isReceived(): bool   { return $this->status === 'received'; }
    public function isCancelled(): bool  { return $this->status === 'cancelled'; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'       => 'مسودة',
            'in_transit'  => 'في الطريق',
            'received'    => 'مستلم',
            'cancelled'   => 'ملغي',
            default       => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft'       => 'secondary',
            'in_transit'  => 'warning',
            'received'    => 'success',
            'cancelled'   => 'danger',
            default       => 'light',
        };
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');

        // The unique index on `number` is GLOBAL. This model has no
        // BranchScope (transfers span two branches), but SoftDeletes hides
        // tombstoned rows from the default query — a soft-deleted transfer
        // still occupies its number, so the lookup must be trashed-inclusive
        // or the next transfer reissues it and hits the unique index. MAX
        // beats last-id+1; fixed-width zero padding makes the lexicographic
        // MAX also the numeric max.
        $last = self::withTrashed()
            ->where('number', 'like', "BT-{$today}-%")
            ->max('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (self::withTrashed()
            ->where('number', sprintf('BT-%s-%04d', $today, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('BT-%s-%04d', $today, $seq);
    }
}
