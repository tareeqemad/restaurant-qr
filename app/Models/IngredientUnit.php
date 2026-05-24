<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-ingredient pack size (alternate unit). A row here means "1 of
 * THIS unit equals `factor_to_base` of the ingredient's base unit".
 *
 * Example for ingredient "علبة كولا" (base = 1 can):
 *   - "حبة"       → factor 1   (the base itself, optional row)
 *   - "كرتون 24"  → factor 24
 *   - "بالة 144"  → factor 144
 *
 * Inventory ALWAYS stores in the base unit. Alternate units are
 * conversion shortcuts for purchase + (eventually) counter sale.
 */
class IngredientUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_id', 'name', 'factor_to_base',
        'barcode', 'purchase_price', 'sale_price',
        'is_default_purchase', 'active',
    ];

    protected $casts = [
        'factor_to_base'      => 'decimal:4',
        'purchase_price'      => 'decimal:4',
        'sale_price'          => 'decimal:4',
        'is_default_purchase' => 'boolean',
        'active'              => 'boolean',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Convert a quantity expressed in THIS unit to the base unit.
     * E.g. 5 cartons × 24 cans-per-carton = 120 cans.
     */
    public function toBase(float $quantityInThisUnit): float
    {
        return $quantityInThisUnit * (float) $this->factor_to_base;
    }
}
