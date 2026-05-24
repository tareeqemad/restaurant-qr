<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'ingredient_id', 'unit_id',
        'ingredient_unit_id',
        'quantity_ordered', 'unit_price', 'subtotal',
        'quantity_received', 'fully_received_at',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered'  => 'decimal:4',
        'unit_price'        => 'decimal:4',
        'subtotal'          => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'fully_received_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Optional pack-size override — when set, this PO line is in the
     * ingredient's alternate unit (e.g. "كرتون 24 علبة") and the
     * receipt service multiplies by its factor instead of going
     * through the global UnitConverter.
     */
    public function ingredientUnit(): BelongsTo
    {
        return $this->belongsTo(IngredientUnit::class);
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function supplierInvoiceItems(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function outstandingQty(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }

    public function receivedPercent(): float
    {
        $ordered = (float) $this->quantity_ordered;
        if ($ordered <= 0) return 0;
        return min(100, ($this->quantity_received / $ordered) * 100);
    }
}
