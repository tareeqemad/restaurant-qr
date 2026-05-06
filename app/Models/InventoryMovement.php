<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'ingredient_id', 'batch_id', 'storage_location_id',
        'type', 'quantity', 'unit_id', 'quantity_in_base',
        'unit_cost', 'total_cost', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'reason', 'waste_reason',
        'user_id', 'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'quantity_in_base' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'stock_before' => 'decimal:4',
        'stock_after' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientBatch::class, 'batch_id');
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    public function wasteReasonEnum(): ?\App\Enums\WasteReason
    {
        return $this->waste_reason
            ? \App\Enums\WasteReason::tryFrom($this->waste_reason)
            : null;
    }
}
