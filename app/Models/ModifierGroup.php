<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ModifierGroup extends Model
{
    use BelongsToBranch, HasFactory;

    protected $fillable = ['branch_id', 'slug', 'name', 'name_en', 'min_select', 'max_select', 'required', 'display_order', 'active'];

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->slug)) {
                $m->slug = Str::slug($m->name_en ?: $m->name) ?: 'grp-'.uniqid();
            }
        });
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->where('active', true)->orderBy('display_order');
    }

    public function allModifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_modifier_group');
    }
}
