<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLocalizedFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Category extends Model
{
    use BelongsToBranch, HasFactory, HasLocalizedFields, SoftDeletes;

    protected $fillable = [
        'branch_id', 'slug', 'name', 'name_en', 'description', 'description_en',
        'image', 'icon', 'color', 'default_station_id', 'display_order', 'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->slug)) {
                $m->slug = Str::slug($m->name_en ?: $m->name) ?: 'cat-'.uniqid();
            }
        });
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'default_station_id');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function imageUrl(): string
    {
        if (! $this->image) {
            return static::placeholderImageUrl();
        }
        if (str_starts_with($this->image, 'http')) {
            // Remote URLs are returned as-is — liveness is handled by the
            // client-side onerror fallback + `menu:repair-dead-images`.
            return $this->image;
        }
        // Local path whose file vanished from disk (e.g. an upload dir that
        // wasn't in the deploy whitelist on a manual git-pull deploy) →
        // placeholder instead of a broken <img>.
        if (! Storage::disk('public')->exists($this->image)) {
            return static::placeholderImageUrl();
        }

        return asset('storage/'.$this->image);
    }

    /** Same fallback used when `image` is NULL — kept in one place. */
    public static function placeholderImageUrl(): string
    {
        return asset('assets/dashtic/images/media/media-1.jpg');
    }
}
