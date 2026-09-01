<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemPriceHistory extends Model
{
    use BelongsToBranch, HasFactory;

    public const INITIAL = 'initial';

    public const BASE_PRICE_CHANGE = 'base_price_change';

    protected $fillable = [
        'branch_id',
        'menu_item_id',
        'change_type',
        'old_price',
        'new_price',
        'reason',
        'changed_by_user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
