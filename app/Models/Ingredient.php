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

    /**
     * Auto-stamp an SKU on insert if the caller didn't supply one. Lets the
     * UI hide the SKU field entirely so the user doesn't have to invent or
     * guess a code, while still leaving the field overridable when an
     * import / migration explicitly sets it.
     */
    protected static function booted(): void
    {
        static::creating(function (Ingredient $ingredient) {
            if (empty($ingredient->sku)) {
                $ingredient->sku = static::generateSku();
            }
        });
    }

    /**
     * Generates the next sequential ING-XXXXX code. Reads the highest
     * existing numeric suffix from `sku` (including soft-deleted rows so
     * we never collide with a tombstoned code) and increments. Pads to
     * five digits — gives 99,999 ingredients of headroom, plenty for any
     * real restaurant.
     */
    public static function generateSku(): string
    {
        $prefix = 'ING-';
        $max = static::withTrashed()
            ->where('sku', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(sku, '.(strlen($prefix) + 1).') AS UNSIGNED)) AS n')
            ->value('n');
        $next = ((int) $max) + 1;
        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

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

    // ═══════════════════════════════════════════════════════════════════
    // Multi-branch helpers
    //
    // Ingredients are GLOBAL (no branch_id), but stock + cost actually
    // varies per branch through `ingredient_stock` (per-location pivot)
    // and `ingredient_batches` (also per-location). These methods give a
    // BRANCH-LOCAL view by aggregating over the branch's storage_locations.
    //
    // Falls back to the global ingredient values when no per-branch data
    // exists, so dashboards that don't yet pass a branch context keep
    // working unchanged.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Total qty of this ingredient sitting in the given branch's storage
     * locations. Sum across all of branch's active locations.
     */
    public function stockAtBranch(int $branchId): float
    {
        return (float) IngredientStock::query()
            ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
            ->where('ingredient_stock.ingredient_id', $this->id)
            ->where('storage_locations.branch_id', $branchId)
            ->sum('ingredient_stock.quantity');
    }

    /**
     * Sum of per-location reorder thresholds for this ingredient at the
     * given branch. Locations without a per-row threshold contribute 0.
     * If NO location has a threshold set, falls back to the global
     * ingredient threshold so existing setups keep working.
     */
    public function reorderThresholdAtBranch(int $branchId): float
    {
        $sum = (float) IngredientStock::query()
            ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
            ->where('ingredient_stock.ingredient_id', $this->id)
            ->where('storage_locations.branch_id', $branchId)
            ->whereNotNull('ingredient_stock.reorder_threshold')
            ->sum('ingredient_stock.reorder_threshold');

        // No per-location thresholds at this branch → use global ingredient threshold
        return $sum > 0 ? $sum : (float) $this->reorder_threshold;
    }

    /**
     * Weighted-average cost of this ingredient at a specific branch,
     * computed from the actual remaining batch costs at the branch's
     * storage locations. This gives a true per-branch COGS basis even
     * when branches buy from different suppliers at different prices.
     *
     * Falls back to the ingredient's global `cost_per_unit` if no batches
     * exist at the branch (e.g., ingredient was just created or branches
     * use the legacy non-batched flow).
     */
    public function costAtBranch(int $branchId): float
    {
        $row = IngredientBatch::query()
            ->join('storage_locations', 'ingredient_batches.storage_location_id', '=', 'storage_locations.id')
            ->where('ingredient_batches.ingredient_id', $this->id)
            ->where('storage_locations.branch_id', $branchId)
            ->where('ingredient_batches.remaining_qty', '>', 0)
            ->selectRaw('
                SUM(ingredient_batches.remaining_qty * ingredient_batches.unit_cost) as total_value,
                SUM(ingredient_batches.remaining_qty) as total_qty
            ')
            ->first();

        $totalQty = (float) ($row?->total_qty ?? 0);
        if ($totalQty <= 0) {
            return (float) $this->cost_per_unit; // Fallback to global avg
        }

        return (float) $row->total_value / $totalQty;
    }

    /**
     * True if the branch has stock at-or-below its (per-location) reorder
     * threshold for this ingredient. Used by per-branch reorder reports.
     */
    public function isLowStockAtBranch(int $branchId): bool
    {
        if (! $this->track_stock) return false;
        $threshold = $this->reorderThresholdAtBranch($branchId);
        if ($threshold <= 0) return false;
        return $this->stockAtBranch($branchId) <= $threshold;
    }
}
