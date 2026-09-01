<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per observed price for (ingredient, supplier, branch). Append-only.
 *
 * Use {@see PurchaseOrderService::receive()} to write rows automatically on
 * goods receipt; manual entries can be inserted via the supplier or
 * ingredient detail pages with `source = 'manual'`.
 *
 * The `previous_price_in_base` and `change_pct` columns are computed at
 * insert time inside `recordObservation()` so the price-history view
 * doesn't have to window-function its way through the table.
 */
class IngredientSupplierPrice extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id', 'ingredient_id', 'supplier_id', 'unit_id',
        'unit_price', 'currency_code', 'exchange_rate', 'unit_price_in_base',
        'previous_price_in_base', 'change_pct',
        'purchase_order_id', 'purchase_order_item_id',
        'source', 'recorded_by_user_id', 'notes',
        'observed_at',
    ];

    protected $casts = [
        'unit_price'              => 'decimal:4',
        'exchange_rate'           => 'decimal:8',
        'unit_price_in_base'      => 'decimal:4',
        'previous_price_in_base'  => 'decimal:4',
        'change_pct'              => 'decimal:2',
        'observed_at'             => 'datetime',
    ];

    public function ingredient(): BelongsTo    { return $this->belongsTo(Ingredient::class); }
    public function supplier(): BelongsTo      { return $this->belongsTo(Supplier::class); }
    public function unit(): BelongsTo          { return $this->belongsTo(Unit::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function recordedBy(): BelongsTo    { return $this->belongsTo(User::class, 'recorded_by_user_id'); }

    /**
     * Most recent observation for an (ingredient, supplier) pair within
     * the active branch context. Used to compute `previous_price_in_base`
     * before inserting a new row.
     */
    public static function latestFor(int $ingredientId, int $supplierId, int $branchId): ?self
    {
        return static::where('ingredient_id', $ingredientId)
            ->where('supplier_id', $supplierId)
            ->where('branch_id', $branchId)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    public function changeLabel(): string
    {
        if ($this->change_pct === null) return 'الأول';
        $val = (float) $this->change_pct;
        if (abs($val) < 0.01) return 'بدون تغيير';
        return ($val > 0 ? '+' : '') . number_format($val, 2) . '%';
    }

    public function changeColor(): string
    {
        if ($this->change_pct === null) return 'secondary';
        $val = (float) $this->change_pct;
        if (abs($val) < 0.01) return 'secondary';
        return $val > 0 ? 'danger' : 'success'; // higher cost is bad for the buyer
    }

    public function scopeForIngredient(Builder $q, int $ingredientId): Builder
    {
        return $q->where('ingredient_id', $ingredientId);
    }

    public function scopeForSupplier(Builder $q, int $supplierId): Builder
    {
        return $q->where('supplier_id', $supplierId);
    }
}
