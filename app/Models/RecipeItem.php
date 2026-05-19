<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id', 'parent_ingredient_id',
        'ingredient_id', 'quantity', 'unit_id',
        'is_optional', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'is_optional' => 'boolean',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * The composite ingredient this line is part of, when the row
     * represents a sub-recipe instead of a menu-item recipe. Mutually
     * exclusive with menu_item_id (enforced by the service layer).
     */
    public function parentIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'parent_ingredient_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
