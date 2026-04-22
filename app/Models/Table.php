<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Table extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['number', 'name', 'qr_token', 'capacity', 'zone', 'status', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];

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

    public function qrUrl(): string
    {
        return url('/menu/'.$this->qr_token);
    }
}
