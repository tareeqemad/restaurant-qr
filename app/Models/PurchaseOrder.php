<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'supplier_id', 'status',
        'subtotal', 'tax_total', 'total',
        'expected_at', 'sent_at', 'received_at', 'cancelled_at', 'cancel_reason',
        'notes', 'created_by', 'received_by',
    ];

    protected $casts = [
        'subtotal'     => 'decimal:4',
        'tax_total'    => 'decimal:4',
        'total'        => 'decimal:4',
        'expected_at'  => 'date',
        'sent_at'      => 'datetime',
        'received_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, ['sent', 'partially_received'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'              => 'مسودة',
            'sent'               => 'مُرسل',
            'partially_received' => 'مستلم جزئياً',
            'received'           => 'مستلم',
            'cancelled'          => 'ملغي',
            default              => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft'              => 'secondary',
            'sent'               => 'info',
            'partially_received' => 'warning',
            'received'           => 'success',
            'cancelled'          => 'danger',
            default              => 'light',
        };
    }

    /**
     * Generate a PO number: PO-YYYYMMDD-NNNN
     */
    public static function generateNumber(): string
    {
        $prefix = 'PO-'.now()->format('Ymd').'-';
        $last = self::where('number', 'like', $prefix.'%')->orderByDesc('id')->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
