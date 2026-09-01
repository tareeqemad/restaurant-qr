<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IngredientBatch extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'ingredient_id', 'storage_location_id', 'batch_number',
        'received_date', 'expiry_date',
        'initial_qty', 'remaining_qty', 'unit_cost',
        'source_type', 'source_id',
        'notes',
    ];

    protected $casts = [
        'received_date' => 'date',
        'expiry_date'   => 'date',
        'initial_qty'   => 'decimal:4',
        'remaining_qty' => 'decimal:4',
        'unit_cost'     => 'decimal:4',
    ];

    public function ingredient(): BelongsTo { return $this->belongsTo(Ingredient::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function source(): MorphTo        { return $this->morphTo(); }

    public function isExpired(): bool
    {
        // A date-only expiry remains usable for the whole printed day.
        return $this->expiry_date && $this->expiry_date->lt(now()->startOfDay());
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expiry_date ? now()->startOfDay()->diffInDays($this->expiry_date, false) : null;
    }

    public function isNearExpiry(int $withinDays = 7): bool
    {
        $d = $this->daysUntilExpiry();
        return $d !== null && $d >= 0 && $d <= $withinDays;
    }

    public function isDepleted(): bool
    {
        return (float) $this->remaining_qty <= 0.0001;
    }

    /**
     * FIFO order: earliest expiry first. Batches without expiry go last
     * (they won't go bad, so pull from dated batches first).
     */
    public function scopeFifo($query, bool $includeExpired = false)
    {
        return $query
            ->where('remaining_qty', '>', 0)
            // Expired lots are quarantined operationally. They remain in the
            // ledger until written off as waste. Waste/count reconciliation
            // explicitly opts in to seeing them; production never does.
            ->when(! $includeExpired, function ($q) {
                $q->where(function ($valid) {
                    $valid->whereNull('expiry_date')
                        ->orWhereDate('expiry_date', '>=', now()->toDateString());
                });
            })
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date', 'asc')
            ->orderBy('received_date', 'asc')
            // Final deterministic tiebreaker: received_date is a DATE (no time),
            // so same-day lots would otherwise consume in engine-defined order
            // and book non-deterministic COGS. id is monotonic with receipt order.
            ->orderBy('id', 'asc');
    }
}
