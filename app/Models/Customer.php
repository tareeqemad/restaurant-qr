<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Authenticated diner. Lives in a separate table from staff Users and uses
 * a separate auth guard ("customer") so the two domains never share a
 * session or a permission model.
 *
 * Login identity:
 *   - Phone is canonical (Arabic-market norm, used on register form).
 *   - Email is optional but unique when present.
 *   - findForLogin() lets the controller accept either as the identifier.
 */
class Customer extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'avatar',
        'birthday',
        'gender',
        'preferences',
        'default_branch_id',
        'loyalty_customer_id',
        'phone_verified_at',
        'email_verified_at',
        'last_login_at',
        'status',
        'blocked_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'birthday'          => 'date',
            'preferences'       => 'array',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
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

    /** All table sessions linked to this customer (their visit history). */
    public function tableSessions(): HasMany
    {
        return $this->hasMany(TableSession::class)->latest('opened_at');
    }

    public function loyaltyCustomer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCustomer::class);
    }

    // ========== Auth helpers ==========

    /**
     * Resolve a customer for login by phone OR email — whatever they typed.
     * Normalises phone to digits-only so "+970-59…" matches "97059…".
     */
    public static function findForLogin(string $identifier): ?self
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return static::where('email', $identifier)->first();
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        return static::where('phone', $identifier)
            ->orWhere('phone', $digits)
            ->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    // ========== Display ==========

    public function getInitialAttribute(): string
    {
        $name = trim((string) $this->name);
        return $name === '' ? '?' : mb_substr($name, 0, 1, 'UTF-8');
    }

    // ========== Cashier-side creation ==========

    /**
     * Create a Customer at the counter — name + phone are mandatory, the
     * password is a random 6-digit PIN we hand back to the cashier ONCE in
     * plain text so they can read it to the diner (or write it on the
     * receipt). The diner uses {phone, PIN} to log into the portal and is
     * expected to change the password from their account settings.
     *
     * Phone normalisation is delegated to LoyaltyService since the same
     * "digits-only" rule is used across both auth identifiers and loyalty
     * lookups; keeping the rule in one place stops them from drifting apart.
     *
     * Returns: [Customer model, plaintext PIN]. Caller MUST surface the PIN
     * immediately — once flushed from memory it cannot be recovered (only
     * reset via the standard portal reset flow).
     *
     * @return array{0: Customer, 1: string}
     */
    public static function createFromCashier(
        string  $name,
        string  $phone,
        ?string $email = null,
        ?int    $defaultBranchId = null,
    ): array {
        $normalizedPhone = preg_replace('/\D+/', '', trim($phone)) ?? $phone;

        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $customer = static::create([
            'name'              => $name,
            'phone'             => $normalizedPhone,
            'email'             => $email,
            'password'          => $pin,           // hashed by the cast
            'default_branch_id' => $defaultBranchId,
            'status'            => 'active',
        ]);

        return [$customer, $pin];
    }
}
