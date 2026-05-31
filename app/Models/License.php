<?php

namespace App\Models;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class License extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GRACE = 'grace';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid',
        'license_key',
        'customer_name',
        'customer_phone',
        'customer_email',
        'restaurant_name',
        'status',
        'period_months',
        'starts_at',
        'expires_at',
        'grace_days',
        'max_branches',
        'last_payment_at',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'expires_at' => 'date',
        'last_payment_at' => 'datetime',
        'period_months' => 'integer',
        'grace_days' => 'integer',
        'max_branches' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $license): void {
            $license->uuid ??= (string) Str::ulid();
            $license->license_key ??= static::generateKey();
            $license->grace_days ??= (int) config('license.grace_days', 14);
        });
    }

    public static function generateKey(): string
    {
        return 'RQ-'.Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8));
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LicensePayment::class)->latest('paid_at');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class)->latest('last_seen_at');
    }

    public function graceEndsAt()
    {
        return $this->expires_at?->copy()->addDays($this->grace_days ?? config('license.grace_days', 14));
    }

    public function effectiveStatus(?CarbonInterface $now = null): string
    {
        $now ??= now();

        if ($this->status !== self::STATUS_ACTIVE) {
            return $this->status;
        }

        if ($this->starts_at && $now->lt($this->starts_at->copy()->startOfDay())) {
            return self::STATUS_PENDING;
        }

        if ($this->expires_at && $now->gt($this->expires_at->copy()->endOfDay())) {
            return $this->graceEndsAt() && $now->lte($this->graceEndsAt()->endOfDay())
                ? self::STATUS_GRACE
                : self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    public function signedPayload(?string $branchUuid = null, ?LicenseActivation $activation = null): array
    {
        return [
            'license_key' => $this->license_key,
            'license_uuid' => $this->uuid,
            'customer_name' => $this->customer_name,
            'restaurant_name' => $this->restaurant_name,
            'status' => $this->effectiveStatus(),
            'starts_at' => $this->starts_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'grace_ends_at' => $this->graceEndsAt()?->toDateString(),
            'max_branches' => $this->max_branches,
            'branch_uuid' => $branchUuid,
            'activation_uuid' => $activation?->uuid,
            'server_time' => now()->toIso8601String(),
        ];
    }

    public function recordActivation(
        string $branchUuid,
        ?string $branchId = null,
        ?string $appUrl = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): LicenseActivation {
        $branchUuid = trim($branchUuid);
        if ($branchUuid === '') {
            throw new DomainException('Branch UUID is required for license activation.');
        }

        return DB::transaction(function () use ($branchUuid, $branchId, $appUrl, $ip, $userAgent) {
            $license = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $activation = $license->activations()
                ->where('branch_uuid', $branchUuid)
                ->lockForUpdate()
                ->first();

            if (! $activation) {
                $activeCount = $license->activations()
                    ->where('status', LicenseActivation::STATUS_ACTIVE)
                    ->count();

                if ($activeCount >= max(1, (int) $license->max_branches)) {
                    throw new DomainException('This license has reached its branch activation limit.');
                }

                $activation = $license->activations()->create([
                    'branch_uuid' => $branchUuid,
                    'status' => LicenseActivation::STATUS_ACTIVE,
                    'activated_at' => now(),
                ]);
            }

            if ($activation->status !== LicenseActivation::STATUS_ACTIVE) {
                throw new DomainException('This branch activation is not active.');
            }

            $activation->forceFill([
                'branch_id' => $branchId ?: $activation->branch_id,
                'app_url' => $appUrl ?: $activation->app_url,
                'last_seen_at' => now(),
                'last_ip' => $ip ? mb_substr($ip, 0, 45) : $activation->last_ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 2000) : $activation->user_agent,
            ])->save();

            return $activation->fresh();
        });
    }

    public function renew(
        int $periodMonths,
        ?float $amount = null,
        ?CarbonInterface $paidAt = null,
        ?int $receivedByUserId = null,
        ?string $notes = null,
        ?string $reference = null
    ): LicensePayment {
        $paidAt ??= now();
        $today = $paidAt->copy()->startOfDay();

        $startsAt = $this->expires_at && $this->expires_at->greaterThanOrEqualTo($today)
            ? $this->expires_at->copy()->addDay()
            : $today;
        $expiresAt = $startsAt->copy()->addMonthsNoOverflow($periodMonths)->subDay();

        $payment = $this->payments()->create([
            'period_months' => $periodMonths,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'method' => 'cash',
            'reference' => $reference,
            'received_by_user_id' => $receivedByUserId,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'notes' => $notes,
        ]);

        $this->forceFill([
            'status' => self::STATUS_ACTIVE,
            'period_months' => $periodMonths,
            'starts_at' => $this->starts_at ?? $today,
            'expires_at' => $expiresAt,
            'last_payment_at' => $paidAt,
        ])->save();

        return $payment;
    }

    public function statusLabel(): string
    {
        return match ($this->effectiveStatus()) {
            self::STATUS_ACTIVE => 'فعّالة',
            self::STATUS_GRACE => 'فترة سماح',
            self::STATUS_PENDING => 'لم تبدأ',
            self::STATUS_SUSPENDED => 'موقوفة',
            self::STATUS_CANCELLED => 'ملغاة',
            self::STATUS_EXPIRED => 'منتهية',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->effectiveStatus()) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_GRACE, self::STATUS_PENDING => 'warning',
            self::STATUS_SUSPENDED, self::STATUS_CANCELLED, self::STATUS_EXPIRED => 'danger',
            default => 'secondary',
        };
    }
}
