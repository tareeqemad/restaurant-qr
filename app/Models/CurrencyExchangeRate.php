<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'currency_code',
        'base_currency_code',
        'rate',
        'valid_from',
        'valid_to',
        'is_active',
        'source',
        'note',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
