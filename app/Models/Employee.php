<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A person employed by the restaurant.
 *
 * Authentication is optional: workers who never use the system remain real
 * employees and can receive meals, attendance and payroll-related movements
 * without fake usernames or passwords.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'user_id', 'name', 'phone', 'job_title', 'status',
        'monthly_meal_allowance', 'meal_debt_ceiling', 'notes',
    ];

    protected $casts = [
        'monthly_meal_allowance' => 'decimal:2',
        'meal_debt_ceiling' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            $employee->code ??= 'EMP-'.strtoupper(substr((string) Str::ulid(), -8));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_employee')
            ->withPivot(['is_primary', 'joined_at'])
            ->withTimestamps();
    }

    public function staffMealCharges(): HasMany
    {
        return $this->hasMany(StaffMealCharge::class)->latest('charged_at');
    }

    public function staffMealUsedInMonth(?Carbon $month = null): float
    {
        $month = $month?->copy()->startOfMonth() ?? now()->startOfMonth();
        $charges = StaffMealCharge::query()
            ->where('employee_id', $this->id)
            ->whereBetween('charged_at', [$month, $month->copy()->endOfMonth()])
            ->where(function ($query): void {
                $query->whereNull('settlement_method')
                    ->orWhere('settlement_method', '!=', 'gift')
                    ->orWhere(fn ($gift) => $gift->where('settlement_method', 'gift')->where('amount', '>', 0));
            })
            ->with('order:id,total')
            ->get();

        $withOrders = $charges->whereNotNull('order_id')->unique('order_id')
            ->sum(fn (StaffMealCharge $charge) => (float) ($charge->order?->total ?? $charge->amount));
        $withoutOrders = $charges->whereNull('order_id')->sum('amount');

        return round((float) $withOrders + (float) $withoutOrders, 2);
    }

    public function staffMealOutstanding(): float
    {
        return (float) $this->staffMealCharges()->whereNull('settled_at')->sum('amount');
    }

    public function staffMealRemainingThisMonth(): ?float
    {
        return $this->monthly_meal_allowance === null
            ? null
            : round((float) $this->monthly_meal_allowance - $this->staffMealUsedInMonth(), 2);
    }

    public function staffMealCeilingHeadroom(): ?float
    {
        return $this->meal_debt_ceiling === null
            ? null
            : round((float) $this->meal_debt_ceiling - $this->staffMealOutstanding(), 2);
    }

    public function staffMealUsagePct(): ?float
    {
        if ($this->monthly_meal_allowance === null || (float) $this->monthly_meal_allowance <= 0) {
            return null;
        }

        return round($this->staffMealUsedInMonth() / (float) $this->monthly_meal_allowance * 100, 1);
    }

    public function accessLabel(): string
    {
        return $this->user_id ? 'له حساب دخول' : 'بدون حساب دخول';
    }

    public function roleLabel(): string
    {
        return $this->job_title ?: ($this->user?->role_label ?? 'موظف');
    }

    /**
     * Transitional importer for existing installations where meals were
     * stored on users. New application flows never require a user account.
     */
    public static function fromUser(User $user): self
    {
        $employee = static::withTrashed()->firstOrNew(['user_id' => $user->id]);
        if (! $employee->exists) {
            $employee->fill([
                'name' => $user->name,
                'phone' => $user->phone,
                'job_title' => $user->role_label ?? $user->role,
                'status' => $user->status === 'active' ? 'active' : 'inactive',
                'monthly_meal_allowance' => $user->monthly_meal_allowance,
                'meal_debt_ceiling' => $user->meal_debt_ceiling,
            ])->save();
        } elseif ($employee->trashed()) {
            $employee->restore();
        }

        // During the transition, legacy user fields may still be edited by
        // old clients/tests. Copy only non-null values; employee-only workers
        // and newly linked accounts remain owned by the employee record.
        $legacyValues = array_filter([
            'monthly_meal_allowance' => $user->monthly_meal_allowance,
            'meal_debt_ceiling' => $user->meal_debt_ceiling,
        ], fn ($value) => $value !== null);
        if ($legacyValues) {
            $employee->update($legacyValues);
        }

        $memberships = $user->branches()->get()->mapWithKeys(fn (Branch $branch) => [
            $branch->id => [
                'is_primary' => (bool) $branch->pivot->is_primary,
                'joined_at' => $branch->pivot->joined_at,
            ],
        ])->all();
        if ($memberships) {
            $employee->branches()->syncWithoutDetaching($memberships);
        }

        return $employee->fresh();
    }

    public static function importLegacyMealUsers(): Collection
    {
        return User::query()
            ->whereNotNull('monthly_meal_allowance')
            ->get()
            ->map(fn (User $user) => static::fromUser($user));
    }
}
