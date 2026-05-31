<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'accounting_period_id',
        'account_id',
        'statement_date',
        'book_balance',
        'statement_balance',
        'difference',
        'status',
        'reconciled_at',
        'reconciled_by',
        'notes',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'book_balance' => 'decimal:4',
        'statement_balance' => 'decimal:4',
        'difference' => 'decimal:4',
        'reconciled_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
