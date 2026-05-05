<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_item_id',
        'ingredient_id',
        'unit_id',
        'storage_location_id',
        'batch_id',
        'quantity_received',
        'quantity_in_base',
        'unit_price',
        'unit_price_in_base',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'quantity_in_base' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'unit_price_in_base' => 'decimal:4',
        'subtotal' => 'decimal:4',
    ];

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngredientBatch::class, 'batch_id');
    }
}
