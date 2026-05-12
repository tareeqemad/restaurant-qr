<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'phone',
        'email',
        'city',
        'address',
        'settings',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];

    // ========== Relations ==========

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')
            ->withPivot(['is_primary', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Roles defined specifically for this branch.
     *
     * Returns 0 rows in current schema — per-branch role overrides were
     * removed in May 2026 (the team always relied on the global
     * `users.role`). Relation kept as a no-op so calling code still
     * works without if-checks; consider removing once all callers are
     * audited.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    // ========== Scopes ==========

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ========== Settings access ==========

    /**
     * Per-branch JSON `settings` value with a hard fallback. Used today by
     * the remote-ordering pipeline (delivery time, delivery fee, prep
     * buffer) — admins later get a Branch settings UI that writes the same
     * keys, but the schema is open so any future per-branch knob can plug
     * in without a migration.
     *
     * Known keys (May 2026):
     *   - delivery_estimated_minutes : int  → typical drive time from this branch
     *   - delivery_fee               : float → flat fee added to delivery orders
     *   - prep_buffer_minutes        : int  → padding on top of the longest item prep
     */
    public function settingValue(string $key, mixed $default = null): mixed
    {
        $bag = $this->settings ?? [];
        return $bag[$key] ?? $default;
    }

    /**
     * Convenience accessors for the order pipeline. Centralised so future
     * defaults changes don't have to be hunted across services + views.
     */
    public function deliveryMinutes(): int
    {
        return (int) $this->settingValue('delivery_estimated_minutes', 30);
    }

    public function deliveryFee(): float
    {
        return (float) $this->settingValue('delivery_fee', 0);
    }

    public function prepBufferMinutes(): int
    {
        return (int) $this->settingValue('prep_buffer_minutes', 5);
    }

    public function customerTaxDisplayMode(): string
    {
        $mode = $this->settingValue('customer_tax_display');

        if (! in_array($mode, ['exclusive', 'inclusive'], true)) {
            $mode = Setting::get('customer_tax_display', 'exclusive');
        }

        return in_array($mode, ['exclusive', 'inclusive'], true) ? $mode : 'exclusive';
    }
}
