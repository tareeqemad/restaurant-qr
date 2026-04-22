<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot-ish model holding per-location stock for an ingredient.
 *
 * ingredients.current_stock remains as the SUM across locations,
 * refreshed whenever any ingredient_stock row changes.
 */
class IngredientStock extends Model
{
    use HasFactory;

    protected $table = 'ingredient_stock';

    protected $fillable = ['ingredient_id', 'storage_location_id', 'quantity', 'reorder_threshold'];

    protected $casts = [
        'quantity'          => 'decimal:4',
        'reorder_threshold' => 'decimal:4',
    ];

    public function ingredient(): BelongsTo { return $this->belongsTo(Ingredient::class); }
    public function location(): BelongsTo   { return $this->belongsTo(StorageLocation::class, 'storage_location_id'); }

    public function isLowStock(): bool
    {
        $threshold = (float) ($this->reorder_threshold ?? $this->ingredient->reorder_threshold ?? 0);
        return $threshold > 0 && (float) $this->quantity <= $threshold;
    }
}
