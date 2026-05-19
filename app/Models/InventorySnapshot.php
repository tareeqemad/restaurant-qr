<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Close-of-day inventory record. One row per (ingredient, branch, date)
 * written by `app:snapshot-inventory` nightly. Lets the ops report show
 * a stock trend over time without pivoting the entire movements table
 * every page load.
 *
 * Not branch-scoped via BelongsToBranch on purpose — the scheduled
 * command writes for every branch in a single run, and read-side queries
 * filter by branch explicitly.
 */
class InventorySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'ingredient_id', 'taken_on',
        'quantity_in_base', 'cost_value',
    ];

    protected $casts = [
        'taken_on'         => 'date',
        'quantity_in_base' => 'decimal:4',
        'cost_value'       => 'decimal:2',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
