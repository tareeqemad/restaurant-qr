<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceipt extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'number',
        'purchase_order_id',
        'supplier_id',
        'received_by',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');

        // The unique index on `number` is GLOBAL, so the sequence must be
        // computed globally too: unscoped (BranchScope would restart every
        // branch at 0001 and collide). MAX beats last-id+1 — deletions
        // never make it reissue a taken number. No withTrashed() here:
        // this model has no SoftDeletes. Fixed-width zero padding makes
        // the lexicographic MAX also the numeric max.
        $last = self::withoutGlobalScopes()
            ->where('number', 'like', "GRN-{$today}-%")
            ->max('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (self::withoutGlobalScopes()
            ->where('number', sprintf('GRN-%s-%04d', $today, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('GRN-%s-%04d', $today, $seq);
    }
}
