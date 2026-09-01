<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'number', 'supplier_id', 'status', 'currency_code', 'exchange_rate',
        'subtotal', 'tax_total', 'total',
        'expected_at', 'sent_at', 'received_at', 'cancelled_at', 'cancel_reason',
        'notes', 'created_by', 'approved_by', 'approved_at', 'received_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'tax_total' => 'decimal:4',
        'total' => 'decimal:4',
        'exchange_rate' => 'decimal:8',
        'expected_at' => 'date',
        'sent_at' => 'datetime',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
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

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === 'draft' && ! $this->approved_at;
    }

    public function isApproved(): bool
    {
        return (bool) $this->approved_at;
    }

    public function isApprovable(): bool
    {
        return $this->status === 'draft' && ! $this->approved_at;
    }

    public function isSendable(): bool
    {
        return $this->status === 'draft' && (bool) $this->approved_at;
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, ['sent', 'partially_received'], true);
    }

    public function isInvoiceable(): bool
    {
        return in_array($this->status, ['received', 'partially_received'], true);
    }

    /**
     * True when every received unit on this PO has already been covered
     * by at least one supplier_invoice line. The "register supplier
     * invoice" CTA hides once this is true so the user doesn't keep
     * registering duplicate invoices for goods already billed.
     *
     * Requires items + items.supplierInvoiceItems to be eager-loaded;
     * the show view already does this. 0.0001 tolerance avoids the
     * usual float-compare flakiness on decimal:4 columns.
     */
    public function isFullyInvoiced(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(function ($line) {
            $received = (float) $line->quantity_received;
            if ($received <= 0) {
                return true;
            } // nothing to invoice yet
            // A cancelled supplier invoice has already been reversed in the
            // ledger, so its lines must not close the operational purchasing
            // loop. Keeping it in this sum used to hide the "register invoice"
            // action even though GRNI was open again.
            $invoiced = (float) $line->supplierInvoiceItems
                ->filter(fn ($item) => ! $item->relationLoaded('supplierInvoice')
                    || $item->supplierInvoice?->status !== 'cancelled')
                ->sum(fn ($item) => (float) ($item->received_qty ?? $item->quantity));

            return $invoiced + 0.0001 >= $received;
        });
    }

    public function isCancellable(): bool
    {
        return ! in_array($this->status, ['received', 'cancelled'], true);
    }

    /**
     * Value of goods actually received so far, priced at the PO line price.
     * Requires `items` to be loaded. Used by the index list to show
     * received-vs-outstanding at a glance without opening each PO.
     */
    public function receivedValue(): float
    {
        return (float) $this->items->sum(
            fn ($line) => (float) $line->quantity_received * (float) $line->unit_price
        );
    }

    /** Value still outstanding on the PO (total minus what's been received). */
    public function outstandingValue(): float
    {
        return max(0, (float) $this->total - $this->receivedValue());
    }

    public function baseTotal(): float
    {
        return round((float) $this->total * (float) ($this->exchange_rate ?: 1), 4);
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2).' '.($this->currency_code ?: 'ILS');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'مسودة',
            'sent' => 'مُرسل',
            'partially_received' => 'مستلم جزئياً',
            'received' => 'مستلم',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft' => 'secondary',
            'sent' => 'info',
            'partially_received' => 'warning',
            'received' => 'success',
            'cancelled' => 'danger',
            default => 'light',
        };
    }

    /**
     * Generate a PO number: PO-YYYYMMDD-NNNN
     */
    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');

        // The unique index on `number` is GLOBAL, so the sequence must be
        // computed globally too: unscoped (BranchScope would restart every
        // branch at 0001 and collide) and trashed-inclusive (a soft-deleted
        // PO still occupies its number). MAX beats last-id+1 — deletions
        // never make it reissue a taken number. Fixed-width zero padding
        // makes the lexicographic MAX also the numeric max.
        $last = self::withoutGlobalScopes()->withTrashed()
            ->where('number', 'like', "PO-{$today}-%")
            ->max('number');

        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        // Belt-and-braces against a concurrent insert grabbing the same
        // sequence between MAX and INSERT: bump past any number that
        // appeared in the meantime. Not a full race-proof lock, but it
        // shrinks the window to same-millisecond inserts.
        while (self::withoutGlobalScopes()->withTrashed()
            ->where('number', sprintf('PO-%s-%04d', $today, $seq))
            ->exists()) {
            $seq++;
        }

        return sprintf('PO-%s-%04d', $today, $seq);
    }
}
