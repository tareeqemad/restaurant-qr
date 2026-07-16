<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TableSession extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'table_id', 'table_number_snapshot',
        'customer_id', 'token', 'cover_count', 'status',
        'customer_name', 'customer_phone', 'opened_by_user_id', 'assigned_waiter_id',
        'opened_at', 'closed_at', 'last_activity_at',
        'bill_requested_at', 'bill_request_note',
        'help_requested_at', 'help_request_note', 'help_ack_by_user_id',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'bill_requested_at' => 'datetime',
        'help_requested_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->token)) {
                $m->token = Str::random(40);
            }
            if (empty($m->opened_at)) {
                $m->opened_at = now();
            }
            if (empty($m->last_activity_at)) {
                $m->last_activity_at = now();
            }
            // Snapshot the table number so renaming/deleting the table
            // later doesn't silently rewrite this session's display.
            if (empty($m->table_number_snapshot) && $m->table_id) {
                $m->table_number_snapshot = Table::withTrashed()->find($m->table_id)?->number;
            }
        });

        // Seating a new party ends any bussing debt on that table — either it
        // got wiped down or nobody is tracking it. This is the safety valve
        // that stops needs_cleaning_since from getting stuck on a floor that
        // ignores the feature, and it covers every path that OPENS a session
        // (QR scan, waiter POS) without each having to remember.
        //
        // Transfer is deliberately NOT covered here: it moves a session by
        // updating table_id, so nothing is created and this never fires.
        // TableSessionTransferService clears the target explicitly instead.
        static::created(function (self $m) {
            if (! $m->table_id) {
                return;
            }

            Table::withoutGlobalScopes()
                ->whereKey($m->table_id)
                ->whereNotNull('needs_cleaning_since')
                ->update(['needs_cleaning_since' => null]);
        });
    }

    /**
     * The human label for this session's table, in priority order:
     *   1. snapshot (set at open, immune to rename/delete)
     *   2. live FK lookup (for legacy rows pre-snapshot migration)
     *   3. '—' fallback
     */
    public function tableLabel(): string
    {
        return $this->table_number_snapshot
            ?? $this->table?->number
            ?? '—';
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * The portal Customer this session is linked to, if any. Null for fully
     * anonymous walk-in sessions; set when either the cashier registered a
     * walk-in account or the diner scanned the QR while logged into the
     * portal and accepted the link prompt.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedWaiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class)->latest();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function touch($attribute = null): bool
    {
        $this->last_activity_at = now();
        return parent::touch($attribute);
    }
}
