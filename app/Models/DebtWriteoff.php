<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtWriteoff extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id', 'number', 'invoice_id', 'amount', 'status', 'reason', 'notes',
        'written_off_by', 'written_off_at', 'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'written_off_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $last = self::withoutGlobalScopes()->where('number', 'like', "WO-{$today}-%")->max('number');
        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;
        while (self::withoutGlobalScopes()->where('number', sprintf('WO-%s-%04d', $today, $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('WO-%s-%04d', $today, $sequence);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
