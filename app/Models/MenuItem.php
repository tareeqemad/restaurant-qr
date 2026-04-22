<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id', 'station_id', 'sku', 'slug', 'name', 'name_en',
        'description', 'description_en', 'price', 'cost', 'image',
        'prep_time_minutes', 'calories', 'is_available', 'is_featured',
        'unavailable_reason', 'display_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->slug)) {
                $m->slug = Str::slug($m->name_en ?: $m->name) ?: 'item-'.uniqid();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'menu_item_allergens');
    }

    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_item_modifier_group')
            ->withPivot('display_order')
            ->orderBy('menu_item_modifier_group.display_order');
    }

    public function resolvedStationId(): ?int
    {
        return $this->station_id ?? $this->category?->default_station_id;
    }

    public function imageUrl(): string
    {
        if (! $this->image) {
            // SVG data URI food placeholder (gradient + utensil icon)
            $color = urlencode('#fecaca');
            return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 300 225'%3E%3Crect fill='{$color}' width='300' height='225'/%3E%3Ctext x='50%25' y='55%25' text-anchor='middle' font-size='72'%3E🍽️%3C/text%3E%3C/svg%3E";
        }
        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }
        return asset('storage/'.$this->image);
    }
}
