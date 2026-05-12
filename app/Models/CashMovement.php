<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToBranch, HasFactory;

    // branch_id added May 2026 (migration 2026_05_10_230000) — derive
    // from shift.branch_id at write time.
    protected $fillable = ['branch_id', 'shift_id', 'type', 'amount', 'reason', 'user_id'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
