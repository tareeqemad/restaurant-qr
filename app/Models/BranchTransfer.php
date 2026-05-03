<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number',
        'from_branch_id', 'to_branch_id',
        'status',
        'created_by_user_id', 'sent_by_user_id', 'received_by_user_id',
        'sent_at', 'received_at', 'cancelled_at',
        'notes', 'cancel_reason',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'received_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function fromBranch(): BelongsTo { return $this->belongsTo(Branch::class, 'from_branch_id'); }
    public function toBranch(): BelongsTo   { return $this->belongsTo(Branch::class, 'to_branch_id'); }
    public function items(): HasMany        { return $this->hasMany(BranchTransferItem::class); }
    public function creator(): BelongsTo    { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function sender(): BelongsTo     { return $this->belongsTo(User::class, 'sent_by_user_id'); }
    public function receiver(): BelongsTo   { return $this->belongsTo(User::class, 'received_by_user_id'); }

    public function isDraft(): bool      { return $this->status === 'draft'; }
    public function isInTransit(): bool  { return $this->status === 'in_transit'; }
    public function isReceived(): bool   { return $this->status === 'received'; }
    public function isCancelled(): bool  { return $this->status === 'cancelled'; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'       => 'مسودة',
            'in_transit'  => 'في الطريق',
            'received'    => 'مستلم',
            'cancelled'   => 'ملغي',
            default       => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'draft'       => 'secondary',
            'in_transit'  => 'warning',
            'received'    => 'success',
            'cancelled'   => 'danger',
            default       => 'light',
        };
    }

    public static function generateNumber(): string
    {
        $prefix = 'BT-' . now()->format('Ymd') . '-';
        $last = self::where('number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
