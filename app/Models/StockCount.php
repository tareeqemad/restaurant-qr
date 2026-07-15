<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCount extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'count_date', 'storage_location_id', 'status', 'notes',
        'created_by', 'finalized_by', 'finalized_at', 'cancelled_at',
    ];

    protected $casts = [
        'count_date'   => 'date',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany { return $this->hasMany(StockCountItem::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function finalizer(): BelongsTo { return $this->belongsTo(User::class, 'finalized_by'); }

    public function isEditable(): bool { return $this->status === 'draft'; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'     => 'مسودة',
            'finalized' => 'مُعتمد',
            'cancelled' => 'ملغي',
            default     => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'finalized' => 'success',
            'draft'     => 'secondary',
            'cancelled' => 'danger',
            default     => 'light',
        };
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');

        // The unique index on `number` is GLOBAL, so the sequence must be
        // computed globally too: unscoped (BranchScope would restart every
        // branch at 0001 and collide) and trashed-inclusive (a soft-deleted
        // count still occupies its number). MAX beats last-id+1 — deletions
        // never make it reissue a taken number. Fixed-width zero padding
        // makes the lexicographic MAX also the numeric max.
        $last = self::withoutGlobalScopes()->withTrashed()
            ->where('number', 'like', "CNT-{$today}-%")
            ->max('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (self::withoutGlobalScopes()->withTrashed()
            ->where('number', sprintf('CNT-%s-%04d', $today, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('CNT-%s-%04d', $today, $seq);
    }

    // Summary helpers for reports & UI
    public function itemsCountedCount(): int
    {
        return $this->items()->whereNotNull('counted_qty')->count();
    }

    public function itemsTotalCount(): int
    {
        return $this->items()->count();
    }

    public function totalVarianceCost(): float
    {
        return (float) $this->items()->sum('variance_cost');
    }
}
