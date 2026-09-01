<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use App\Services\LoyaltyService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Internal diner file. Customers never authenticate; staff and QR ordering
 * resolve this record by its canonical phone number.
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'avatar',
        'birthday',
        'gender',
        'preferences',
        'default_branch_id',
        'credit_limit',
        'advance_balance',
        'loyalty_customer_id',
        'status',
        'blocked_reason',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'preferences' => 'array',
            'credit_limit' => 'decimal:2',
            'advance_balance' => 'decimal:2',
        ];
    }

    // ========== Relations ==========

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class)->latest('reserved_for');
    }

    /**
     * Every order this customer has placed — across branches. Sorted newest
     * first so portal "history" screens can paginate without an explicit
     * orderBy each time.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->latest();
    }

    /** Invoices issued to this customer (anywhere). */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issued_at');
    }

    public function advanceTransactions(): HasMany
    {
        return $this->hasMany(CustomerAdvanceTransaction::class)->latest('id');
    }

    /** All table sessions linked to this customer (their visit history). */
    public function tableSessions(): HasMany
    {
        return $this->hasMany(TableSession::class)->latest('opened_at');
    }

    public function loyaltyCustomer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCustomer::class);
    }

    public static function findByPhone(string $phone, bool $withTrashed = false): ?self
    {
        $canonical = PhoneNumber::normalize($phone);
        if ($canonical === '') {
            return null;
        }

        $query = $withTrashed ? static::withTrashed() : static::query();
        $matches = $query->whereIn('phone', PhoneNumber::lookupVariants($phone))->get();

        return $matches->firstWhere('phone', $canonical) ?? $matches->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    // ========== Debt / credit ledger ==========

    /**
     * Invoices that closed with an unpaid balance and were parked against
     * this customer's account. They're the canonical debt ledger — no
     * separate `customer_debts` table exists because the per-invoice
     * `balance` column already does the job (and stays consistent under
     * Refund/Payment writes for free).
     *
     * Filter on `balance > 0` so a debt that was settled later by a return
     * visit drops out of the open list automatically.
     */
    public function openDebtInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class)
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->orderBy('settled_on_account_at');
    }

    /**
     * Total currently outstanding on all open debt invoices, in major
     * currency units. Cheap aggregate query (single SUM); call on demand
     * rather than caching to avoid stale numbers after a fresh payment.
     */
    public function outstandingDebt(): float
    {
        // A customer's debt is GLOBAL to the business, not per-branch: the
        // credit ceiling is one column on this row and collection (FIFO in
        // BillingService::payCustomerDebt) drains debts across every branch.
        // Unscope BranchScope so the ceiling check, the debt board, and the
        // settle-on-account preview all read the same total the collector
        // actually settles — otherwise a debt at another branch is invisible
        // to the cashier while their payment silently lands on it.
        return (float) $this->invoices()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNotNull('settled_on_account_at')
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['cancelled', 'unpaid_writeoff'])
            ->sum('balance');
    }

    /**
     * Remaining headroom under the customer's credit ceiling. Returns null
     * when no ceiling is set (treat as "unlimited"); otherwise the live
     * difference, which can drop to zero or negative if the cashier
     * extended credit beyond the cap (e.g. before the cap was lowered).
     */
    public function creditAvailable(): ?float
    {
        if ($this->credit_limit === null) {
            return null;
        }

        return max(0, (float) $this->credit_limit - $this->outstandingDebt());
    }

    /**
     * True when the customer cannot take on any more debt without the
     * cashier explicitly raising the ceiling first. `null` credit_limit is
     * treated as "never blocked" so a legacy customer without a configured
     * cap continues to be served.
     */
    public function isCreditMaxedOut(): bool
    {
        return $this->credit_limit !== null && $this->creditAvailable() <= 0.001;
    }

    // ========== Display ==========

    public function getInitialAttribute(): string
    {
        $name = trim((string) $this->name);

        return $name === '' ? '?' : mb_substr($name, 0, 1, 'UTF-8');
    }

    // ========== Cashier-side creation ==========

    /** @return array{0: Customer} */
    public static function createFromCashier(
        string $name,
        string $phone,
        ?string $email = null,
        ?int $defaultBranchId = null,
    ): array {
        $normalizedPhone = PhoneNumber::normalize($phone);

        $customer = DB::transaction(function () use ($name, $normalizedPhone, $email, $defaultBranchId) {
            $loyalty = app(LoyaltyService::class)->findOrCreate($normalizedPhone, $name, $email);

            return static::create([
                'name' => $name,
                'phone' => $normalizedPhone,
                'email' => $email,
                'default_branch_id' => $defaultBranchId,
                'loyalty_customer_id' => $loyalty->id,
                'status' => 'active',
            ]);
        });

        return [$customer];
    }
}
