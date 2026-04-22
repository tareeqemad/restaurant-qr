<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Modifier extends Model
{
    use HasFactory;

    protected $fillable = ['modifier_group_id', 'name', 'name_en', 'price_delta', 'active', 'display_order'];

    protected $casts = [
        'price_delta' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(ModifierRecipeItem::class);
    }
}
