<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSplit extends Model
{
    use BelongsToBranch, HasFactory;

    // branch_id added May 2026 (migration 2026_05_10_230000) — derive
    // from invoice.branch_id. Stops cross-branch split-tab leaks.
    protected $fillable = ['branch_id', 'invoice_id', 'label', 'amount', 'method', 'paid', 'paid_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
