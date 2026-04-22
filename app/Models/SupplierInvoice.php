<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'supplier_id', 'purchase_order_id',
        'subtotal', 'tax_total', 'total', 'paid_total', 'balance',
        'status', 'invoice_date', 'due_date', 'paid_at', 'cancelled_at',
        'notes', 'attachment_path', 'created_by',
    ];

    protected $casts = [
        'subtotal'     => 'decimal:4',
        'tax_total'    => 'decimal:4',
        'total'        => 'decimal:4',
        'paid_total'   => 'decimal:4',
        'balance'      => 'decimal:4',
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'paid_at'      => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayment::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'unpaid'          => 'غير مدفوعة',
            'partially_paid'  => 'مدفوعة جزئياً',
            'paid'            => 'مدفوعة',
            'cancelled'       => 'ملغاة',
            default           => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'paid'            => 'success',
            'partially_paid'  => 'warning',
            'unpaid'          => 'danger',
            'cancelled'       => 'secondary',
            default           => 'light',
        };
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid'
            && $this->status !== 'cancelled'
            && $this->due_date
            && $this->due_date->isPast();
    }

    public function recomputeBalance(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $total = (float) $this->total;
        $balance = max(0, $total - $paid);
        $status = match (true) {
            $this->status === 'cancelled' => 'cancelled',
            $balance <= 0.01              => 'paid',
            $paid > 0                     => 'partially_paid',
            default                       => 'unpaid',
        };
        $this->update([
            'paid_total' => $paid,
            'balance'    => $balance,
            'status'     => $status,
            'paid_at'    => $status === 'paid' && !$this->paid_at ? now() : $this->paid_at,
        ]);
    }
}
