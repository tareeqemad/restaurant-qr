<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LookupGroup extends Model
{
    use SoftDeletes;

    public const GLOBAL = 'global';

    public const BRANCH = 'branch';

    protected $fillable = [
        'code', 'label', 'icon', 'subtitle', 'scope',
        'display_order', 'is_active', 'is_system',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public static function catalogue(): Collection
    {
        return Cache::remember('lookup-groups.active', now()->addMinutes(5), fn () => static::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get());
    }

    public static function isPerBranch(string $code): bool
    {
        return static::catalogue()->firstWhere('code', $code)?->scope === static::BRANCH;
    }

    public static function forgetCatalogue(): void
    {
        Cache::forget('lookup-groups.active');
    }

    protected static function booted(): void
    {
        $forget = fn (LookupGroup $group) => static::forgetCatalogue();
        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
    }
}
