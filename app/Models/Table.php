<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Table extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'name', 'qr_token', 'capacity', 'zone_lookup_id',
        'status', 'needs_cleaning_since', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'needs_cleaning_since' => 'datetime',
    ];

    /** The party left but nobody has wiped it down yet. */
    public function needsCleaning(): bool
    {
        return $this->needs_cleaning_since !== null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->qr_token)) {
                $m->qr_token = Str::random(24);
            }
        });
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)->where('status', 'active')->latest('opened_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The zone this table belongs to (indoor / outdoor / VIP / …).
     * Backed by a row in the global `lookups` table (group='zones').
     * `withTrashed()` so historical tables still resolve their zone
     * label even after the lookup row was soft-deleted from admin.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Lookup::class, 'zone_lookup_id')->withTrashed();
    }

    /**
     * Public URL the printed QR code points to.
     *
     * Prefers `restaurant.menu_base_url` (the LAN address on a local/on-prem
     * server) so customers on the restaurant WiFi can scan and order even
     * while the internet is down. Falls back to APP_URL via url() for pure
     * cloud deployments.
     */
    public function qrUrl(): string
    {
        $base = trim((string) config('restaurant.menu_base_url'));

        return $base !== ''
            ? rtrim($base, '/').'/menu/'.$this->qr_token
            : url('/menu/'.$this->qr_token);
    }
}
