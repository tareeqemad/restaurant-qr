<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StorageLocation extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'code', 'name', 'icon', 'color', 'description',
        'is_default', 'active', 'display_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active'     => 'boolean',
    ];

    public function ingredientStocks(): HasMany
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_stock')
            ->withPivot('quantity', 'reorder_threshold')
            ->withTimestamps();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Stock value at this location (Σ qty × cost/unit) */
    public function stockValue(): float
    {
        return (float) IngredientStock::query()
            ->join('ingredients', 'ingredient_stock.ingredient_id', '=', 'ingredients.id')
            ->where('ingredient_stock.storage_location_id', $this->id)
            ->selectRaw('SUM(ingredient_stock.quantity * ingredients.cost_per_unit) as v')
            ->value('v') ?? 0;
    }

    public static function default(): ?self
    {
        return self::where('is_default', true)->where('active', true)->first()
            ?? self::where('active', true)->orderBy('display_order')->first();
    }
}
