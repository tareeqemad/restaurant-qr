<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id', 'number', 'table_session_id', 'table_number_snapshot', 'order_id', 'customer_id', 'issued_by_user_id',
        'subtotal', 'discount_total', 'tax_total', 'service_total', 'delivery_fee', 'tip',
        'total', 'paid_total', 'refunded_total', 'balance', 'status',
        'customer_name', 'customer_phone', 'notes',
        'issued_at', 'paid_at', 'cancelled_at',
        // Debt-ledger flags — set by BillingService::settleOnAccount when
        // a partially-paid invoice is parked on the customer's account.
        'settled_on_account_at', 'settled_on_account_by_user_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'service_total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'tip' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'refunded_total' => 'decimal:4',
        'balance' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'settled_on_account_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = self::generateNumber();
            }
            // Snapshot the table number so the printed receipt always
            // shows what the customer ACTUALLY sat at, even if the
            // table is renumbered or deleted years later.
            if (empty($m->table_number_snapshot)) {
                if (! empty($m->table_session_id)) {
                    $m->table_number_snapshot = TableSession::query()
                        ->whereKey($m->table_session_id)
                        ->value('table_number_snapshot');
                }
                if (empty($m->table_number_snapshot) && ! empty($m->order_id)) {
                    $m->table_number_snapshot = Order::query()
                        ->whereKey($m->order_id)
                        ->value('table_number_snapshot');
                }
            }
        });

        static::saving(function (self $m) {
            $hasTableSession = ! is_null($m->table_session_id);
            $hasDirectOrder = ! is_null($m->order_id);

            if ($hasTableSession === $hasDirectOrder) {
                throw new \InvalidArgumentException('Invoice must belong to exactly one origin: table session or direct order.');
            }
        });
    }

    public static function generateNumber(): string
    {
        $today = now()->format('Ymd');
        $seq = self::whereDate('created_at', now()->toDateString())->count() + 1;
        return sprintf('INV-%s-%04d', $today, $seq);
    }

    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Total per-item promotion savings across EVERY order on this
     * invoice. Dine-in invoices set `table_session_id` and leave
     * `order_id` null (the session holds one or many orders), so a
     * naive `$this->order?->promoSavings()` would silently zero out
     * the entire restaurant's promo accounting.
     *
     * Resolution order:
     *   1. `order_id` set → portal/cashier single-order invoice → ask the order directly.
     *   2. `table_session_id` set → dine-in → sum across all orders in the session.
     *   3. Neither set → orphan invoice (manual?), return 0.
     *
     * BXGY/cashier ad-hoc discounts are intentionally excluded — they
     * already live in `OrderDiscount` rows that drive `discount_total`,
     * and double-counting them on 4090 would silently double-debit
     * the sales-discount account.
     */
    public function promoSavings(): float
    {
        if ($this->order_id && $this->order) {
            return $this->order->promoSavings();
        }
        if ($this->table_session_id) {
            $session = $this->tableSession()->with('orders.items.modifiers')->first();
            if ($session) {
                return round((float) $session->orders->sum(fn ($o) => $o->promoSavings()), 2);
            }
        }
        return 0.0;
    }

    /**
     * Every journal entry sourced from this invoice — original posting,
     * reversals, and post-issuance re-posts. The `OrderDiscountService`
     * uses this to decide whether a discount change should trigger a
     * reverse+repost cycle (only when at least one un-reversed entry
     * already exists).
     */
    public function journalEntries()
    {
        return $this->morphMany(\App\Models\JournalEntry::class, 'source');
    }

    /**
     * Human label for the table this invoice belongs to. Prefers the
     * snapshot frozen at issuance — that's the value the customer saw
     * on their printed receipt and the cashier confirmed.
     *
     * If snapshot is missing (legacy row pre-migration), walk the FK
     * chain: session → table, then order → table, then '—'.
     */
    public function tableLabel(): string
    {
        if (! empty($this->table_number_snapshot)) {
            return $this->table_number_snapshot;
        }
        return $this->tableSession?->table?->number
            ?? $this->order?->table?->number
            ?? '—';
    }

    /**
     * After a refund completes, the invoice's payment math changes:
     *   netPaid    = paid_total − refunded_total
     *   balance    = total − netPaid
     *   status     = unpaid | partially_paid | paid (with refund nuance)
     *
     * Without this method, an invoice that's been refunded 30 of 100
     * still reads `balance = 0, status = paid`, blocking any re-collect
     * attempt and confusing the customer-debt widget.
     *
     * `paid_total` itself stays IMMUTABLE — it's a faithful sum of the
     * Payment rows. Subtracting refunds from it would break that
     * history. The derived `netPaid` is what the cashier UI should
     * display going forward.
     */
    public function recomputeBalanceAfterRefund(): void
    {
        $this->refresh();
        $net = $this->netPaid();
        $balance = max(0, round((float) $this->total - $net, 2));

        // Status transitions after refund:
        //   - balance = 0 AND net > 0 → still "paid" (no money owed, all paid)
        //   - balance > 0 AND net > 0 → "partially_paid" (refund opened owed amount)
        //   - balance > 0 AND net = 0 → "issued" (fully refunded, but original invoice stands)
        //   - balance = 0 AND net = 0 → "cancelled" (everything reversed — leave as-is, manual decision)
        $status = match (true) {
            $balance <= 0.001 && $net > 0      => 'paid',
            $balance > 0 && $net > 0           => 'partially_paid',
            $balance > 0 && $net <= 0.001      => 'issued',
            default                             => $this->status,   // leave anything weird untouched
        };

        $this->update([
            'balance'  => $balance,
            'status'   => $status,
            // Clear paid_at when balance reopens so the dashboards stop
            // showing a misleading "paid X minutes ago" timestamp.
            'paid_at'  => $status === 'paid' ? $this->paid_at : null,
        ]);
    }

    /**
     * Identified portal customer for this invoice. Set if the diner was
     * logged into the portal at QR-scan time, registered via the cashier
     * mid-meal, or was matched by phone at checkout. The flat
     * `customer_name`/`customer_phone` fields stay as a snapshot for
     * receipt printing even if the FK is later nulled.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** Net cash received = paid − refunded */
    public function netPaid(): float
    {
        return max(0, (float) $this->paid_total - (float) $this->refunded_total);
    }

    /** How much more can still be refunded */
    public function refundableBalance(): float
    {
        return max(0, (float) $this->paid_total - (float) $this->refunded_total);
    }

    public function isFullyRefunded(): bool
    {
        return $this->refunded_total > 0
            && abs((float) $this->refunded_total - (float) $this->paid_total) < 0.01;
    }

    public function splits(): HasMany
    {
        return $this->hasMany(InvoiceSplit::class);
    }

    /** Arabic label for the invoice status — used in every cashier surface. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'paid'            => 'مدفوعة بالكامل',
            'partially_paid'  => 'مدفوعة جزئياً',
            'issued'          => 'صادرة',
            'cancelled'       => 'ملغاة',
            'unpaid_writeoff' => 'مشطوبة',
            default           => $this->status,
        };
    }

    /** Bootstrap badge color matching the status. */
    public function statusColor(): string
    {
        return match ($this->status) {
            'paid'            => 'success',
            'partially_paid'  => 'info',
            'issued'          => 'warning',
            'cancelled'       => 'danger',
            'unpaid_writeoff' => 'dark',
            default           => 'secondary',
        };
    }
}
