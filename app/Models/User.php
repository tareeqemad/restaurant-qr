<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'name_en',
        'username',
        'email',
        'phone',
        'avatar',
        'role',
        'station_id',
        'status',
        'suspended_reason',
        'last_login_at',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ========== Relations ==========

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission')
            ->withPivot('granted');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function activeShift()
    {
        return $this->hasOne(Shift::class)->where('status', 'open')->latest('opened_at');
    }

    public function approvedOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'approved_by_user_id');
    }

    // ========== Role helpers ==========

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin->value;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin->value;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager->value;
    }

    public function isWaiter(): bool
    {
        return $this->role === UserRole::Waiter->value;
    }

    public function isChef(): bool
    {
        return $this->role === UserRole::Chef->value;
    }

    public function isBartender(): bool
    {
        return $this->role === UserRole::Bartender->value;
    }

    public function isCashier(): bool
    {
        return $this->role === UserRole::Cashier->value;
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function getRoleLabelAttribute(): string
    {
        return UserRole::tryFrom($this->role)?->label() ?? $this->role;
    }

    // ========== Status ==========

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canLogin(): bool
    {
        return $this->status === 'active';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isActive() && in_array($this->role, [
            UserRole::SuperAdmin->value,
            UserRole::Admin->value,
            UserRole::Manager->value,
            UserRole::Waiter->value,
            UserRole::Chef->value,
            UserRole::Bartender->value,
            UserRole::Cashier->value,
        ], true);
    }

    public function canAccessStation(string $stationCode): bool
    {
        if ($this->hasAnyRole([UserRole::SuperAdmin->value, UserRole::Admin->value, UserRole::Manager->value])) {
            return true;
        }

        if ($stationCode === 'kitchen' && $this->isChef()) {
            return true;
        }

        if ($stationCode === 'bar' && $this->isBartender()) {
            return true;
        }

        if ($this->station && $this->station->code === $stationCode) {
            return true;
        }

        return false;
    }

    // ========== Permission helpers ==========

    public function hasPermission(string $name): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $direct = $this->permissions()->where('name', $name)->first();
        if ($direct) {
            return (bool) $direct->pivot->granted;
        }

        // Role-based permissions
        $role = Role::where('name', $this->role)->with('permissions')->first();
        return $role?->permissions->contains('name', $name) ?? false;
    }

    // ========== Accessors ==========

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar && str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }
        if ($this->avatar) {
            return asset('storage/'.$this->avatar);
        }
        return asset('assets/dashtic/images/faces/1.jpg');
    }
}
