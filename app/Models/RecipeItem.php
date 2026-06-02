<?php

namespace App\Models;

use App\Helpers\UnitConverter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_item_id', 'parent_ingredient_id',
        'ingredient_id', 'quantity', 'unit_id',
        'ingredient_unit_id',
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
        // withTrashed: a soft-deleted ingredient still referenced by a recipe
        // row must resolve, otherwise cost/stock-preview code that type-hints
        // Ingredient receives null and 500s. The menu UI can then surface the
        // stale line instead of crashing the whole page.
        return $this->belongsTo(Ingredient::class)->withTrashed();
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Per-ingredient unit (tablespoon, scoop, ladle…) — when set,
     * deduction math uses this row's factor_to_base instead of the
     * global UnitConverter, so the recipe can be written in chef-
     * speak ("1 tbsp sugar") without requiring a global tbsp unit.
     */
    public function ingredientUnit(): BelongsTo
    {
        return $this->belongsTo(IngredientUnit::class);
    }

    /**
     * Convert this recipe line to the ingredient's base unit. Single
     * source of truth — every consumer (deduction preview, cost
     * calc, theoretical-vs-actual report) walks the same logic so
     * unit math can't drift between callers.
     */
    public function quantityInBase(): float
    {
        $qty = (float) $this->quantity;

        // Path 1: ingredient-specific unit (preferred when set).
        if ($this->ingredient_unit_id && $this->ingredientUnit) {
            return $qty * (float) $this->ingredientUnit->factor_to_base;
        }

        // Path 2: global unit conversion (legacy + free-form ingredients).
        $ingredient = $this->ingredient;
        if (! $ingredient) {
            return $qty;
        }

        return UnitConverter::convert(
            $qty,
            $this->unit_id,
            $ingredient->base_unit_id,
        );
    }
}
