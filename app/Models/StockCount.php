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
        $prefix = 'CNT-'.now()->format('Ymd').'-';
        $last = self::where('number', 'like', $prefix.'%')->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
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
