<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToBranch, HasFactory;

    // branch_id added May 2026 (migration 2026_05_10_230000) so all KPI
    // aggregates correctly isolate per-branch. The BillingService and
    // any other writer must derive branch_id from the parent invoice,
    // not from BranchContext, since payments arrive from contexts where
    // the active branch may differ from the invoice's branch (queue
    // workers, customer portal paying for a takeaway).
    protected $fillable = ['branch_id', 'invoice_id', 'method', 'amount', 'reference', 'received_by_user_id', 'shift_id', 'notes', 'paid_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
