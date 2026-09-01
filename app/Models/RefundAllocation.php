<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['refund_id', 'payment_id', 'method', 'amount', 'reference'];

    protected $casts = ['amount' => 'decimal:4'];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope('posted');
    }
}
