<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'symbol',
        'rate_to_base', 'is_base', 'is_active', 'display_order',
        'rate_updated_at',
    ];

    protected $casts = [
        'rate_to_base'    => 'decimal:6',
        'is_base'         => 'boolean',
        'is_active'       => 'boolean',
        'rate_updated_at' => 'datetime',
    ];

    public static function base(): ?self
    {
        return self::where('is_base', true)->first();
    }

    public static function byCode(string $code): ?self
    {
        return self::where('code', strtoupper($code))->first();
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(CurrencyExchangeRate::class, 'currency_code', 'code');
    }

    public function convertFromBase(float $baseAmount): float
    {
        if ($this->is_base || (float) $this->rate_to_base <= 0) return $baseAmount;
        return $baseAmount / (float) $this->rate_to_base;
    }

    public function convertToBase(float $foreignAmount): float
    {
        return $foreignAmount * (float) $this->rate_to_base;
    }

    public function format(float $amount): string
    {
        return number_format($amount, 2) . ' ' . $this->symbol;
    }
}
