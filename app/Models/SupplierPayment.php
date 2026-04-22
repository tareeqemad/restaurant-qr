<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id', 'amount', 'method', 'reference',
        'paid_on', 'notes', 'paid_by', 'shift_id',
    ];

    protected $casts = [
        'amount'  => 'decimal:4',
        'paid_on' => 'date',
    ];

    public function invoice(): BelongsTo  { return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id'); }
    public function payer(): BelongsTo    { return $this->belongsTo(User::class, 'paid_by'); }
    public function shift(): BelongsTo    { return $this->belongsTo(Shift::class); }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'          => 'نقدي',
            'bank_transfer' => 'تحويل بنكي',
            'cheque'        => 'شيك',
            'card'          => 'بطاقة',
            'credit_note'   => 'إشعار دائن',
            'other'         => 'أخرى',
            default         => $this->method,
        };
    }
}
