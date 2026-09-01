<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'branch_id', 'number', 'invoice_id', 'kind', 'status',
        'revenue_total', 'tax_total', 'service_total', 'delivery_total', 'tip_total', 'total',
        'reason', 'notes', 'metadata', 'issued_by', 'issued_at',
        'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'revenue_total' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'service_total' => 'decimal:4',
        'delivery_total' => 'decimal:4',
        'tip_total' => 'decimal:4',
        'total' => 'decimal:4',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $last = self::withoutGlobalScopes()
            ->where('number', 'like', "CN-{$today}-%")
            ->max('number');
        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        while (self::withoutGlobalScopes()->where('number', sprintf('CN-%s-%04d', $today, $sequence))->exists()) {
            $sequence++;
        }

        return sprintf('CN-%s-%04d', $today, $sequence);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
