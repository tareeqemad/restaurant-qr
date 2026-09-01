<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use HasFactory;

    public const CONTEXT_EXPENSE_CATEGORY = 'expense_category';
    public const CONTEXT_PAYMENT_METHOD = 'payment_method';
    public const CONTEXT_POSTING_ROLE = 'posting_role';

    protected $fillable = [
        'context',
        'key',
        'account_id',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function keyForLookup(?Lookup $lookup): ?string
    {
        if (! $lookup) {
            return null;
        }

        // Seeded dictionaries are portable business codes. Their generated
        // sync UUID differs between independent installations, so using it
        // here would silently disconnect the default accounting mapping after
        // a clean install. User-created rows still use their stable sync UUID.
        if ($lookup->is_system && $lookup->code) {
            return 'code:'.$lookup->code;
        }

        if ($uuid = $lookup->getAttribute('uuid')) {
            return 'lookup:'.$uuid;
        }

        if ($lookup->code) {
            return 'code:'.$lookup->code;
        }

        return 'id:'.$lookup->id;
    }
}
