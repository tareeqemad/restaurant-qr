<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModifierRecipeItem extends Model
{
    use HasFactory;

    protected $fillable = ['modifier_id', 'ingredient_id', 'quantity', 'unit_id', 'ingredient_unit_id'];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Per-ingredient unit (tbsp/scoop). Same mechanism RecipeItem uses
     * so modifier recipes can also be written in chef-speak.
     */
    public function ingredientUnit(): BelongsTo
    {
        return $this->belongsTo(IngredientUnit::class);
    }
}
