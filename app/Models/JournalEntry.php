<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'entry_no',
        'posted_on',
        'description',
        'base_currency_code',
        'currency_code',
        'exchange_rate',
        'source_type',
        'source_id',
        'event_type',
        'status',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'posted_on' => 'date',
        'metadata' => 'array',
        'exchange_rate' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (empty($entry->entry_no)) {
                $entry->entry_no = self::generateNumber($entry->posted_on ?: now());
            }
        });
    }

    public static function generateNumber($date): string
    {
        $date = $date instanceof \DateTimeInterface
            ? \Carbon\Carbon::instance($date)
            : \Carbon\Carbon::parse($date);

        $prefix = 'JE-'.$date->format('Ymd').'-';
        $last = self::where('entry_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('entry_no');
        $seq = $last ? ((int) substr($last, -5)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function totalDebit(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum('credit');
    }
}
