<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingredient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'name_en', 'base_unit_id', 'supplier_id',
        'current_stock', 'reorder_threshold', 'cost_per_unit',
        'track_stock', 'active', 'notes',
    ];

    protected $casts = [
        'current_stock' => 'decimal:4',
        'reorder_threshold' => 'decimal:4',
        'cost_per_unit' => 'decimal:4',
        'track_stock' => 'boolean',
        'active' => 'boolean',
    ];

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->current_stock <= $this->reorder_threshold;
    }
}
