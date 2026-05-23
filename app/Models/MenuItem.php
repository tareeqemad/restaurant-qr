<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuItem extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'category_id', 'station_id', 'sku', 'slug', 'name', 'name_en',
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

    // ════════════════════════════════════════════════════════════════════
    //  Stock availability — the customer must NEVER be able to add an
    //  item to their cart when the kitchen can't make it. `is_available`
    //  is a manual toggle (intent: "we choose not to sell this right now");
    //  these methods compute the live STOCK truth on top of it so a
    //  manager doesn't have to keep flipping a switch every time chicken
    //  runs out.
    //
    //  Composites are recursively expanded via InventoryService so a
    //  menu item using a composite ingredient is gated by the raw inputs
    //  of that composite, not the composite's nominal stock.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Shortage list for fulfilling `$quantity` of this item right now.
     * Empty array means the kitchen has enough stock. Each entry contains
     * the ingredient name + required vs. available qty for clear UI.
     *
     * Items with no recipe (e.g. internet cards) always return an empty
     * array — they don't consume stock.
     *
     * @return array<int,array{ingredient:string,ingredient_id:int,required:float,available:float}>
     */
    public function stockShortages(float $quantity = 1.0): array
    {
        if ($this->recipeItems->isEmpty() && ! $this->relationLoaded('recipeItems')) {
            $this->load('recipeItems.ingredient');
        }
        if ($this->recipeItems->isEmpty()) {
            return [];   // No recipe → not a tracked item (e.g. wifi card)
        }

        return app(\App\Services\InventoryService::class)
            ->checkStockForOrderPreview([[
                'menu_item_id' => $this->id,
                'quantity'     => $quantity,
                'modifier_ids' => [],
            ]]);
    }

    /**
     * True iff the kitchen has enough stock to fulfill `$quantity` of this
     * item RIGHT NOW. Cheap shortcut around stockShortages().
     */
    public function isInStock(float $quantity = 1.0): bool
    {
        return empty($this->stockShortages($quantity));
    }

    /**
     * The composite gate the customer-side flow should ask: both the
     * manual `is_available` flag AND live stock must allow ordering.
     */
    public function availableToOrder(float $quantity = 1.0): bool
    {
        if (! $this->is_available) return false;
        return $this->isInStock($quantity);
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

    /**
     * Recipe cost computed using PER-BRANCH ingredient costs (from the
     * branch's actual remaining batches). This gives a true per-branch
     * COGS even when branches buy from different suppliers at different
     * prices — the global `cost` column is a single average that distorts
     * branch-level P&L.
     *
     * Falls back to `cost` (the denormalized global value) when no
     * batches exist for the branch (e.g., the ingredient is brand-new).
     *
     * NOTE: this is computed on-demand and intentionally NOT cached. The
     * P&L report calls it per row × period, which is fine since each
     * call is at most a few small queries thanks to per-branch helpers.
     */
    public function costAtBranch(int $branchId): float
    {
        $this->loadMissing(['recipeItems.ingredient.baseUnit']);

        $total = 0.0;
        foreach ($this->recipeItems as $r) {
            $ing = $r->ingredient;
            if (! $ing) continue;

            $qtyBase = \App\Helpers\UnitConverter::convert(
                (float) $r->quantity, $r->unit_id, (int) $ing->base_unit_id
            );
            $total += $qtyBase * $ing->costAtBranch($branchId);
        }

        // Defensive fallback: if recipe is empty or returns 0, surface the
        // denormalized global cost so dashboards never show a misleading 0.
        return $total > 0 ? $total : (float) $this->cost;
    }
}
