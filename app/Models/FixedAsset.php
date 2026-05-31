<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'asset_number',
        'name',
        'category',
        'description',
        'vendor_name',
        'supplier_id',
        'acquisition_date',
        'in_service_date',
        'cost',
        'salvage_value',
        'foreign_cost',
        'foreign_salvage_value',
        'currency_code',
        'exchange_rate',
        'useful_life_months',
        'depreciation_method',
        'payment_method',
        'status',
        'accumulated_depreciation',
        'depreciated_through',
        'purchase_journal_entry_id',
        'disposal_journal_entry_id',
        'disposed_on',
        'disposal_proceeds',
        'disposal_payment_method',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'in_service_date' => 'date',
        'cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'foreign_cost' => 'decimal:4',
        'foreign_salvage_value' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'useful_life_months' => 'integer',
        'accumulated_depreciation' => 'decimal:4',
        'depreciated_through' => 'date',
        'disposed_on' => 'date',
        'disposal_proceeds' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            if (empty($asset->asset_number)) {
                $asset->asset_number = self::generateNumber();
            }
        });
    }

    public static function generateNumber(): string
    {
        $prefix = 'FA-'.now()->format('Y').'-';
        $last = self::withoutGlobalScopes()
            ->where('asset_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('asset_number');
        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'purchase_journal_entry_id');
    }

    public function disposalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    public function depreciableAmount(): float
    {
        return round(max(0, (float) $this->cost - (float) $this->salvage_value), 4);
    }

    public function bookValue(): float
    {
        return round(max(0, (float) $this->cost - (float) $this->accumulated_depreciation), 4);
    }

    public function remainingDepreciableAmount(): float
    {
        return round(max(0, $this->depreciableAmount() - (float) $this->accumulated_depreciation), 4);
    }

    public function monthlyDepreciationAmount(): float
    {
        if ((int) $this->useful_life_months <= 0) {
            return 0.0;
        }

        return round($this->depreciableAmount() / (int) $this->useful_life_months, 4);
    }

    public function depreciationAmountForPeriod(mixed $periodEnd): float
    {
        if ($this->status === 'disposed' || ! $this->in_service_date || $this->in_service_date->gt($periodEnd)) {
            return 0.0;
        }

        return round(min($this->monthlyDepreciationAmount(), $this->remainingDepreciableAmount()), 4);
    }
}
