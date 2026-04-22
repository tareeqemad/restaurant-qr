<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoyaltyCustomer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone', 'name', 'email', 'birthday',
        'points_balance', 'lifetime_points', 'tier',
        'last_visit_at', 'total_visits', 'total_spent',
        'notes',
    ];

    protected $casts = [
        'birthday'        => 'date',
        'last_visit_at'   => 'datetime',
        'total_spent'     => 'decimal:4',
        'points_balance'  => 'integer',
        'lifetime_points' => 'integer',
        'total_visits'    => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class)->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tierLabel(): string
    {
        return match ($this->tier) {
            'bronze' => 'برونزي',
            'silver' => 'فضي',
            'gold'   => 'ذهبي',
            default  => $this->tier,
        };
    }

    public function tierColor(): string
    {
        return match ($this->tier) {
            'bronze' => '#a97142',
            'silver' => '#9ca3af',
            'gold'   => '#d4a550',
            default  => '#6b7280',
        };
    }

    /** How many points do they earn per base-currency unit spent? */
    public function earnRate(): int
    {
        return match ($this->tier) {
            'silver' => 15,
            'gold'   => 20,
            default  => 10,   // bronze
        };
    }

    /** How much cash discount is 1 point worth? */
    public function redemptionValue(): float
    {
        return 0.01;   // 100 points = 1 currency unit — also used by LoyaltyService
    }
}
