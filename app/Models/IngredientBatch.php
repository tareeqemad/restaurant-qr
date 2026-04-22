<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class IngredientBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ingredient_id', 'batch_number',
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
    public function source(): MorphTo        { return $this->morphTo(); }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
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
    public function scopeFifo($query)
    {
        return $query
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('expiry_date', 'asc')
            ->orderBy('received_date', 'asc');
    }
}
