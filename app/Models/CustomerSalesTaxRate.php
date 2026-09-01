<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An effective-dated rate for invoices issued by the restaurant to customers.
 * Supplier invoice tax is deliberately outside this schedule.
 */
class CustomerSalesTaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'rate',
        'effective_from',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'rate' => 'decimal:4',
        'effective_from' => 'date',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
