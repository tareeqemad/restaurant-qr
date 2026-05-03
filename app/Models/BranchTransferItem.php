<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchTransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_transfer_id',
        'ingredient_id',
        'quantity_base',
        'from_location_id', 'to_location_id',
        'unit_cost',
        'notes',
    ];

    protected $casts = [
        'quantity_base' => 'decimal:4',
        'unit_cost'     => 'decimal:4',
    ];

    public function transfer(): BelongsTo    { return $this->belongsTo(BranchTransfer::class, 'branch_transfer_id'); }
    public function ingredient(): BelongsTo  { return $this->belongsTo(Ingredient::class); }
    public function fromLocation(): BelongsTo{ return $this->belongsTo(StorageLocation::class, 'from_location_id'); }
    public function toLocation(): BelongsTo  { return $this->belongsTo(StorageLocation::class, 'to_location_id'); }

    public function lineCost(): float
    {
        return (float) $this->quantity_base * (float) $this->unit_cost;
    }
}
