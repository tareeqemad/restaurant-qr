<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_count_id', 'ingredient_id',
        'system_qty', 'counted_qty', 'variance', 'variance_cost',
        'notes',
    ];

    protected $casts = [
        'system_qty'    => 'decimal:4',
        'counted_qty'   => 'decimal:4',
        'variance'      => 'decimal:4',
        'variance_cost' => 'decimal:4',
    ];

    public function stockCount(): BelongsTo { return $this->belongsTo(StockCount::class); }
    public function ingredient(): BelongsTo { return $this->belongsTo(Ingredient::class); }

    public function hasVariance(): bool
    {
        return !is_null($this->counted_qty) && abs((float) $this->variance) > 0.0001;
    }

    public function isOver(): bool  { return (float) $this->variance > 0; }
    public function isShort(): bool { return (float) $this->variance < 0; }
}
